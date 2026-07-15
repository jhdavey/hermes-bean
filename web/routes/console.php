<?php

use App\Models\User;
use App\Services\AgentProfileService;
use App\Services\PlanHistoryService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('plan-history:prune', function (PlanHistoryService $history) {
    $totals = $history->pruneAllUsers();

    $this->info('Pruned plan-limited history: '.json_encode($totals));

    return self::SUCCESS;
})->purpose('Delete user data that is older than each user plan history window');

Artisan::command('tasks:purge-completed', function (PlanHistoryService $history) {
    $totals = $history->pruneAllUsers();

    $this->info('Pruned plan-limited history: '.json_encode($totals));

    return self::SUCCESS;
})->purpose('Compatibility alias for plan-history:prune');

Artisan::command('admin:user {email} {--password=} {--name=Hey Bean Admin}', function (string $email): int {
    $password = (string) ($this->option('password') ?: env('ADMIN_PASSWORD', ''));
    if ($password === '') {
        $this->error('Provide --password or ADMIN_PASSWORD.');

        return self::FAILURE;
    }

    $user = User::updateOrCreate(
        ['email' => strtolower(trim($email))],
        [
            'name' => (string) $this->option('name'),
            'password' => Hash::make($password),
            'is_admin' => true,
            'subscription_tier' => 'pro',
        ],
    );

    app(AgentProfileService::class)->ensureForUser($user);
    app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

    $this->info("Admin user ready: {$user->email}");

    return self::SUCCESS;
})->purpose('Create or update an admin user without committing credentials');

Artisan::command('admin:grant {email}', function (string $email): int {
    $user = User::where('email', strtolower(trim($email)))->first();
    if (! $user) {
        $this->error('No user found for '.$email.'.');

        return self::FAILURE;
    }

    $user->forceFill([
        'is_admin' => true,
        'subscription_tier' => 'pro',
    ])->save();

    $this->info("Admin permissions granted: {$user->email}");

    return self::SUCCESS;
})->purpose('Grant admin permissions to an existing user without changing their password');

Schedule::command('plan-history:prune')->daily();
Schedule::command('calendar-events:materialize-recurring')->daily();
Schedule::command('reminders:send-due-notifications')->everyMinute();
// Deadline enforcement must run independently of the worker executing the
// request. A blocked provider/queue worker therefore cannot strand a voice
// turn beyond its hard or rolling no-progress deadline.
Schedule::command('browser-voice:enforce-deadlines')->everySecond()->withoutOverlapping(1);
