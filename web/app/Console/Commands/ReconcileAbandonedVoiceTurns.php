<?php

namespace App\Console\Commands;

use App\Services\VoiceTurnAbandonmentService;
use Illuminate\Console\Command;

class ReconcileAbandonedVoiceTurns extends Command
{
    protected $signature = 'voice-turns:reconcile-abandoned {--limit=500 : Maximum accepted turns to inspect}';

    protected $description = 'Classify stale accepted Realtime voice turns that never received a terminal update';

    public function handle(VoiceTurnAbandonmentService $reconciler): int
    {
        $result = $reconciler->reconcile(limit: max(1, (int) $this->option('limit')));

        $this->info(sprintf(
            'Reconciled %d stale accepted voice turn(s) as abandoned; inspected %d candidate(s), skipped %d.',
            $result['abandoned_count'],
            $result['examined_count'],
            $result['skipped_count'],
        ));

        return self::SUCCESS;
    }
}
