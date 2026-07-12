<?php

namespace App\Console\Commands;

use App\Services\VoiceTurnLifecycleService;
use Illuminate\Console\Command;

class EnforceBrowserVoiceTurnDeadlines extends Command
{
    protected $signature = 'browser-voice:enforce-deadlines {--turn-id=}';

    protected $description = 'Terminalize Browser Voice v2 turns that exceeded progress or hard deadlines';

    public function handle(VoiceTurnLifecycleService $lifecycle): int
    {
        $turnId = $this->option('turn-id');
        $failed = $lifecycle->enforceDeadlines(
            is_numeric($turnId) ? (int) $turnId : null,
        );

        $this->info("Terminalized {$failed} overdue Browser Voice v2 turn(s).");

        return self::SUCCESS;
    }
}
