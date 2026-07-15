<?php

namespace App\Jobs;

use App\Services\VoiceTurnLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Carbon;

class EnforceBrowserVoiceTurnDeadline implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 10;

    public function __construct(
        public readonly int $voiceTurnId,
        public readonly string $deadlineAt,
    ) {}

    public function handle(VoiceTurnLifecycleService $lifecycle): void
    {
        $deadline = Carbon::parse($this->deadlineAt);
        if ($deadline->isFuture()) {
            $queueAt = $deadline->copy();
            if ((int) $queueAt->format('u') > 0) {
                $queueAt = $queueAt->addSecond()->startOfSecond();
            }
            self::dispatch($this->voiceTurnId, $this->deadlineAt)->delay($queueAt);

            return;
        }

        $lifecycle->enforceDeadlines($this->voiceTurnId);
    }
}
