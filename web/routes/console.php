<?php

use App\Models\Task;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
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

Schedule::command('tasks:purge-completed')->daily();
