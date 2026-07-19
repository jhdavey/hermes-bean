<?php

use App\Models\User;
use App\Services\Bean\Quality\BeanQualityLabService;
use App\Services\PlanHistoryService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
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

Artisan::command('bean:evaluate {--json=} {--markdown=} {--ci} {--production-smoke} {--recent=200}', function (BeanQualityLabService $lab): int {
    if (! (bool) $this->option('production-smoke')) {
        $this->error('Seeded Bean evaluation was removed with the Hermes-first runtime. Use --production-smoke to audit recorded Hermes traces.');

        return self::FAILURE;
    }

    $recent = (int) $this->option('recent');
    $report = $lab->productionSmoke($recent);

    $defaultBase = storage_path('app/bean-quality');
    File::ensureDirectoryExists($defaultBase);
    $stamp = now()->format('Ymd-His');
    $jsonPath = (string) ($this->option('json') ?: $defaultBase.'/bean-quality-'.$stamp.'.json');
    $markdownPath = (string) ($this->option('markdown') ?: $defaultBase.'/bean-quality-'.$stamp.'.md');
    $lab->writeJsonReport($report, $jsonPath);
    $lab->writeMarkdownReport($report, $markdownPath);

    $this->info('Bean Quality Production Smoke Report');
    $this->line('Traces audited: '.($report['trace_count'] ?? 0));
    $this->line('Flagged traces: '.($report['flagged_trace_count'] ?? 0));
    $this->line('JSON: '.$jsonPath);
    $this->line('Markdown: '.$markdownPath);

    return self::SUCCESS;
})->purpose('Run the read-only Bean production trace audit');

Schedule::command('plan-history:prune')->daily();
Schedule::command('calendar-events:materialize-recurring')->daily();
Schedule::command('reminders:send-due-notifications')->everyMinute();
Schedule::command('bean:evaluate --production-smoke --recent=500 --json='.storage_path('app/bean-quality/latest-production-audit.json').' --markdown='.storage_path('app/bean-quality/latest-production-audit.md'))->dailyAt('03:15')->withoutOverlapping();
