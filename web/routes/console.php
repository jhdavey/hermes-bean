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
    $recent = (int) $this->option('recent');
    $report = (bool) $this->option('production-smoke')
        ? $lab->productionSmoke($recent)
        : $lab->evaluate();

    $defaultBase = storage_path('app/bean-quality');
    File::ensureDirectoryExists($defaultBase);
    $stamp = now()->format('Ymd-His');
    $jsonPath = (string) ($this->option('json') ?: $defaultBase.'/bean-quality-'.$stamp.'.json');
    $markdownPath = (string) ($this->option('markdown') ?: $defaultBase.'/bean-quality-'.$stamp.'.md');
    $lab->writeJsonReport($report, $jsonPath);
    $lab->writeMarkdownReport($report, $markdownPath);

    if (($report['mode'] ?? '') === 'production-smoke') {
        $this->info('Bean Quality Production Smoke Report');
        $this->line('Traces audited: '.($report['trace_count'] ?? 0));
        $this->line('Flagged traces: '.($report['flagged_trace_count'] ?? 0));
        $this->line('JSON: '.$jsonPath);
        $this->line('Markdown: '.$markdownPath);

        return self::SUCCESS;
    }

    $this->info('Bean Quality Report');
    $this->line('Overall: '.($report['overall_score'] ?? 0).'/100');
    foreach (($report['categories'] ?? []) as $name => $category) {
        $this->line(str_replace('_', ' ', $name).': '.$category['points'].'/'.$category['weight'].' ('.$category['passed'].'/'.$category['total'].')');
    }
    $this->line('CI gate: '.(data_get($report, 'ci_gate.passed') ? 'PASS' : 'FAIL'));
    $this->line('JSON: '.$jsonPath);
    $this->line('Markdown: '.$markdownPath);

    if ((bool) $this->option('ci') && ! data_get($report, 'ci_gate.passed')) {
        return self::FAILURE;
    }

    return self::SUCCESS;
})->purpose('Run the read-only Bean Quality Lab evaluation harness and report scorecard');

Schedule::command('plan-history:prune')->daily();
Schedule::command('calendar-events:materialize-recurring')->daily();
Schedule::command('reminders:send-due-notifications')->everyMinute();
Schedule::command('bean:evaluate --production-smoke --recent=500 --json='.storage_path('app/bean-quality/latest-production-audit.json').' --markdown='.storage_path('app/bean-quality/latest-production-audit.md'))->dailyAt('03:15')->withoutOverlapping();
