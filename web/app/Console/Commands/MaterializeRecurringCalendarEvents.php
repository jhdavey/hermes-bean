<?php

namespace App\Console\Commands;

use App\Services\RecurringCalendarEventService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

class MaterializeRecurringCalendarEvents extends Command
{
    protected $signature = 'calendar-events:materialize-recurring {--years=1 : How many years ahead to materialize}';

    protected $description = 'Materialize recurring calendar event blocks into future dated calendar events.';

    public function handle(RecurringCalendarEventService $recurringEvents): int
    {
        $years = max(1, (int) $this->option('years'));
        $horizon = CarbonImmutable::now('UTC')->addYears($years)->endOfDay();
        $created = $recurringEvents->materializeAll($horizon);

        $this->info("Materialized {$created} recurring calendar event occurrence(s) through {$horizon->toDateString()}.");

        return self::SUCCESS;
    }
}
