<?php

use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('tasks:purge-completed', function () {
    $deleted = Task::whereNotNull('completed_at')
        ->where('completed_at', '<', now()->subDays(10))
        ->whereIn('status', ['completed', 'complete', 'done'])
        ->delete();

    $this->info("Purged {$deleted} completed task(s).");

    return self::SUCCESS;
})->purpose('Delete completed tasks 10 days after completion');

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

    app(\App\Services\AgentProfileService::class)->ensureForUser($user);
    app(\App\Services\WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

    $this->info("Admin user ready: {$user->email}");

    return self::SUCCESS;
})->purpose('Create or update an admin user without committing credentials');

Schedule::command('tasks:purge-completed')->daily();
Schedule::command('calendar-events:materialize-recurring')->daily();
Schedule::command('reminders:send-due-notifications')->everyMinute();
