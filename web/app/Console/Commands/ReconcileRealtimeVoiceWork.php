<?php

namespace App\Console\Commands;

use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\VoiceTurn;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\RealtimeVoiceApplicationEventHandler;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Console\Command;

class ReconcileRealtimeVoiceWork extends Command
{
    protected $signature = 'voice:reconcile-realtime-work';

    protected $description = 'Resume undispatched typed voice work and receipt-ready Realtime compositions';

    public function handle(
        VoiceTurnLifecycleService $lifecycle,
        RealtimeVoiceApplicationEventHandler $realtime,
    ): int {
        AssistantRun::query()
            ->whereNotNull('voice_turn_id')
            ->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)
            ->where('status', 'queued')
            ->whereNull('dispatch_requested_at')
            ->whereHas('voiceTurn', fn ($query) => $query
                ->where('source', 'browser_voice_realtime')
                ->whereIn('state', [VoiceTurnState::Accepted->value, VoiceTurnState::Running->value]))
            ->orderByDesc('priority')
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(function (AssistantRun $run) use ($lifecycle): void {
                if (! $lifecycle->jobRequiresDispatch($run)) {
                    return;
                }
                ProcessAssistantRun::dispatch($run->id)
                    ->onQueue((string) config('services.voice_realtime.operation_queue', 'voice-high'));
                $lifecycle->markJobDispatched($run);
            });

        VoiceTurn::query()
            ->where('source', 'browser_voice_realtime')
            ->where('state', VoiceTurnState::Running->value)
            ->whereHas('runs', fn ($query) => $query
                ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
                ->whereIn('status', ['queued', 'running']))
            ->orderBy('id')
            ->limit(200)
            ->get()
            ->each(fn (VoiceTurn $turn) => $realtime->afterOperationFinished($turn));

        return self::SUCCESS;
    }
}
