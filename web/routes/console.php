<?php

use App\Models\User;
use App\Services\Bean\LandingBeanRuntimeService;
use App\Services\Bean\Quality\BeanQualityLabService;
use App\Services\Bean\Quality\BeanUxBenchmarkService;
use App\Services\Bean\Quality\BeanUxScenarioCatalogService;
use App\Services\Bean\Quality\BeanUxScenarioEvaluationService;
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

Artisan::command('bean:landing-prune {--hours=}', function (LandingBeanRuntimeService $runtime): int {
    $hours = $this->option('hours');
    $deleted = $runtime->pruneInactive(is_numeric($hours) ? (int) $hours : null);
    $this->info("Pruned {$deleted} inactive landing Bean visitor homes.");

    return self::SUCCESS;
})->purpose('Delete expired anonymous landing Bean Hermes homes');

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

Artisan::command('bean:ux-benchmark {--days=7} {--json=} {--markdown=} {--progress=}', function (BeanUxBenchmarkService $benchmarks): int {
    $days = (int) $this->option('days');
    $report = $benchmarks->report($days);
    $defaultBase = storage_path('app/bean-ux');
    File::ensureDirectoryExists($defaultBase);
    $stamp = now()->format('Ymd-His');
    $jsonPath = (string) ($this->option('json') ?: $defaultBase.'/bean-ux-benchmark-'.$stamp.'.json');
    $markdownPath = (string) ($this->option('markdown') ?: $defaultBase.'/bean-ux-benchmark-'.$stamp.'.md');
    $progressPath = (string) ($this->option('progress') ?: base_path('../docs/bean-world-class-ux-progress.json'));
    $benchmarks->writeReport($report, $jsonPath, $markdownPath, $progressPath);

    $this->info('Bean World-Class UX Benchmark Report');
    $this->line('Window days: '.($report['window_days'] ?? $days));
    $this->line('Runs: '.data_get($report, 'counts.runs', 0));
    $this->line('Task success: '.(data_get($report, 'metrics.task_success_rate') === null ? 'unknown' : round(data_get($report, 'metrics.task_success_rate') * 100, 2).'%'));
    $this->line('Generic failures: '.(data_get($report, 'metrics.generic_failure_rate') === null ? 'unknown' : round(data_get($report, 'metrics.generic_failure_rate') * 100, 2).'%'));
    $this->line('Voice first-command capture: '.(data_get($report, 'metrics.voice.first_command_capture_rate') === null ? 'unknown' : round(data_get($report, 'metrics.voice.first_command_capture_rate') * 100, 2).'%'));
    $this->line('JSON: '.$jsonPath);
    $this->line('Markdown: '.$markdownPath);
    $this->line('Progress: '.$progressPath);

    return self::SUCCESS;
})->purpose('Run Bean world-class UX benchmark scorecard and update durable progress');

Artisan::command('bean:ux-scenarios {--json=} {--markdown=}', function (BeanUxScenarioCatalogService $scenarios): int {
    $catalog = $scenarios->catalog();
    $defaultBase = storage_path('app/bean-ux');
    File::ensureDirectoryExists($defaultBase);
    $jsonPath = (string) ($this->option('json') ?: $defaultBase.'/bean-ux-scenarios.json');
    $markdownPath = (string) ($this->option('markdown') ?: base_path('../docs/bean-ux-evaluation-scenarios.md'));
    $scenarios->write($catalog, $jsonPath, $markdownPath);

    $this->info('Bean UX Scenario Catalog');
    $this->line('Scenarios: '.count($catalog['scenarios'] ?? []));
    $this->line('JSON: '.$jsonPath);
    $this->line('Markdown: '.$markdownPath);

    return self::SUCCESS;
})->purpose('Write the repeatable Bean UX evaluation scenario catalog');

Artisan::command('bean:ux-evaluate-scenarios {--recent=500} {--json=} {--markdown=}', function (BeanUxScenarioEvaluationService $evaluation): int {
    $recent = (int) $this->option('recent');
    $report = $evaluation->report($recent);
    $defaultBase = storage_path('app/bean-ux');
    File::ensureDirectoryExists($defaultBase);
    $stamp = now()->format('Ymd-His');
    $jsonPath = (string) ($this->option('json') ?: $defaultBase.'/bean-ux-evaluation-'.$stamp.'.json');
    $markdownPath = (string) ($this->option('markdown') ?: $defaultBase.'/bean-ux-evaluation-'.$stamp.'.md');
    $evaluation->writeReport($report, $jsonPath, $markdownPath);

    $this->info('Bean UX Scenario Evaluation Report');
    $this->line('Recent runs: '.($report['recent_runs'] ?? $recent));
    $this->line('Traces evaluated: '.($report['trace_count'] ?? 0));
    $this->line('Follow-up/reference resolution: '.(data_get($report, 'semantic_metrics.followup_reference_resolution_rate') === null ? 'unknown' : round(data_get($report, 'semantic_metrics.followup_reference_resolution_rate') * 100, 2).'%'));
    $this->line('Unnecessary clarification rate: '.(data_get($report, 'semantic_metrics.unnecessary_clarification_rate') === null ? 'unknown' : round(data_get($report, 'semantic_metrics.unnecessary_clarification_rate') * 100, 2).'%'));
    $this->line('Immediate context-loss rate: '.(data_get($report, 'semantic_metrics.immediate_context_loss_rate') === null ? 'unknown' : round(data_get($report, 'semantic_metrics.immediate_context_loss_rate') * 100, 2).'%'));
    $this->line('JSON: '.$jsonPath);
    $this->line('Markdown: '.$markdownPath);

    return self::SUCCESS;
})->purpose('Evaluate recorded Bean UX scenarios and semantic continuity metrics');

Schedule::command('plan-history:prune')->daily();
Schedule::command('calendar-events:materialize-recurring')->daily();
Schedule::command('reminders:send-due-notifications')->everyMinute();
Schedule::command('bean:landing-prune')->daily()->withoutOverlapping();
Schedule::command('bean:evaluate --production-smoke --recent=500 --json='.storage_path('app/bean-quality/latest-production-audit.json').' --markdown='.storage_path('app/bean-quality/latest-production-audit.md'))->dailyAt('03:15')->withoutOverlapping();
Schedule::command('bean:ux-benchmark --days=7 --json='.storage_path('app/bean-ux/latest-benchmark.json').' --markdown='.storage_path('app/bean-ux/latest-benchmark.md').' --progress='.base_path('../docs/bean-world-class-ux-progress.json'))->dailyAt('03:30')->withoutOverlapping();
Schedule::command('bean:ux-evaluate-scenarios --recent=500 --json='.storage_path('app/bean-ux/latest-scenario-evaluation.json').' --markdown='.storage_path('app/bean-ux/latest-scenario-evaluation.md'))->dailyAt('03:45')->withoutOverlapping();
