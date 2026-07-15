<?php

namespace App\Console\Commands;

use App\Services\VoiceTurnLifecycleService;
use Illuminate\Console\Command;

class EnforceBrowserVoiceTurnDeadlines extends Command
{
    protected $signature = 'browser-voice:enforce-deadlines {--turn-id=}';

    protected $description = 'Terminalize Realtime browser voice turns that exceeded progress or hard deadlines';

    public function handle(VoiceTurnLifecycleService $lifecycle): int
    {
        $turnId = $this->option('turn-id');
        $failed = $lifecycle->enforceDeadlines(
            is_numeric($turnId) ? (int) $turnId : null,
        );

        $this->info("Terminalized {$failed} overdue Realtime browser voice turn(s).");

        return self::SUCCESS;
    }
}
