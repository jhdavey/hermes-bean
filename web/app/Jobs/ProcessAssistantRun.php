<?php

namespace App\Jobs;

use App\Data\AssistantRunExecutionClaim;
use App\Enums\VoiceTurnState;
use App\Exceptions\VoiceTurnConflictException;
use App\Models\AssistantRun;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\RealtimeVoiceApplicationEventHandler;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessAssistantRun implements ShouldQueue
{
    use Queueable;

    // Voice jobs use these attempts only for lifecycle-scheduler waits. The
    // durable turn and receipt identities, not queue delivery, own retries.
    public int $tries = 120;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $assistantRunId,
        public readonly ?int $executionGeneration = null,
    ) {}

    public function middleware(): array
    {
        $run = AssistantRun::query()
            ->select(['id', 'conversation_session_id', 'voice_turn_id'])
            ->find($this->assistantRunId);

        // VoiceTurnLifecycleService is the sole voice execution-order owner.
        // A queue middleware lock would introduce a second scheduler.
        if ($run?->voice_turn_id !== null) {
            return [];
        }

        $lockKey = $run?->conversation_session_id
            ? "assistant-session-{$run->conversation_session_id}"
            : "assistant-run-{$this->assistantRunId}";

        return [
            (new WithoutOverlapping($lockKey))
                ->releaseAfter(2)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        HermesRuntimeService $runtime,
        AssistantRunService $runs,
        ?VoiceTurnLifecycleService $voiceTurns = null,
        ?HermesSemanticOperationExecutor $semanticOperations = null,
        ?RealtimeVoiceApplicationEventHandler $realtime = null,
    ): void {
        $run = AssistantRun::with('session', 'userMessage')->find($this->assistantRunId);
        if (! $run || ! $run->session || ! $run->userMessage) {
            return;
        }

        if ($run->voice_turn_id !== null) {
            $this->handleRealtimeVoiceOperation(
                $run,
                $voiceTurns ?? app(VoiceTurnLifecycleService::class),
                $semanticOperations ?? app(HermesSemanticOperationExecutor::class),
                $realtime ?? app(RealtimeVoiceApplicationEventHandler::class),
            );

            return;
        }

        if ($run->status === 'cancelled') {
            return;
        }

        if ($run->status === 'running') {
            $runs->prepareRunForBackgroundResponse($run);

            return;
        }

        if ($run->status !== 'queued' || $this->executionGeneration === null) {
            return;
        }

        $run = $runs->prepareRunForBackgroundResponse($run)
            ->load(['session', 'userMessage']);
        if ($run->status !== 'queued' || ! $run->session || ! $run->userMessage) {
            return;
        }

        $claim = $runs->claimRunExecution($run, $this->executionGeneration);
        if (! $claim instanceof AssistantRunExecutionClaim) {
            return;
        }

        $run = AssistantRun::with(['session', 'userMessage'])->find($claim->runId);
        if (! $run instanceof AssistantRun || ! $run->session || ! $run->userMessage) {
            return;
        }

        try {
            $result = $runtime->sendExistingMessage($run->session->refresh(), $run->userMessage, $claim);
            $runs->finishRuntimeResult($claim, $result);
        } catch (\Throwable $exception) {
            $runs->finishRuntimeResult($claim, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'events' => [],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $run = AssistantRun::with('session')->find($this->assistantRunId);
        if (! $run) {
            return;
        }

        if ($run->voice_turn_id !== null) {
            $this->failRealtimeVoiceOperation(
                $run,
                $exception,
                app(VoiceTurnLifecycleService::class),
                app(HermesSemanticOperationExecutor::class),
                app(RealtimeVoiceApplicationEventHandler::class),
            );

            return;
        }

        if ($this->executionGeneration === null || (int) $run->user_message_id <= 0) {
            return;
        }

        $claim = new AssistantRunExecutionClaim(
            runId: $run->id,
            sessionId: $run->conversation_session_id,
            userMessageId: (int) $run->user_message_id,
            generation: $this->executionGeneration,
        );
        app(AssistantRunService::class)->finishRuntimeResult($claim, [
            'status' => 'failed',
            'error' => $exception->getMessage(),
            'events' => [],
        ]);
    }

    private function handleRealtimeVoiceOperation(
        AssistantRun $run,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticOperationExecutor $operations,
        RealtimeVoiceApplicationEventHandler $realtime,
    ): void {
        $turn = VoiceTurn::find($run->voice_turn_id);
        if (! $turn instanceof VoiceTurn
            || $run->status === 'cancelled'
            || $turn->state === VoiceTurnState::Canceled) {
            return;
        }

        // Interpretation and composition are owned by the warm Realtime
        // sideband. The queue accepts only already validated typed operations.
        if ($run->handler !== HermesSemanticOperationExecutor::OPERATION_HANDLER) {
            $this->failUnsupportedRealtimeVoiceRun($run, $turn, $lifecycle);

            return;
        }

        if (! $this->claimRealtimeVoiceOperation($run, $turn, $lifecycle)) {
            return;
        }

        try {
            $turn = $lifecycle->markProgress($turn, [
                'run_id' => $run->id,
                'phase' => 'typed_operation',
                'operation_id' => data_get($run->metadata, 'semantic_operation_id'),
                'tool' => data_get($run->metadata, 'semantic_tool'),
            ], 'semantic_executor');
            $receipt = $operations->executeRun($turn, $run->fresh());
            $terminal = $this->finishOperationReceipt($run, $receipt, $lifecycle, $operations);
            $realtime->afterOperationFinished($terminal);
        } catch (\Throwable $exception) {
            $this->sealOperationFailure($run, $turn, $exception, $lifecycle, $operations, $realtime);
        }
    }

    private function failRealtimeVoiceOperation(
        AssistantRun $run,
        \Throwable $exception,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticOperationExecutor $operations,
        RealtimeVoiceApplicationEventHandler $realtime,
    ): void {
        $turn = VoiceTurn::find($run->voice_turn_id);
        if (! $turn instanceof VoiceTurn || $turn->state->isTerminal()) {
            return;
        }
        if ($run->handler !== HermesSemanticOperationExecutor::OPERATION_HANDLER) {
            $this->failUnsupportedRealtimeVoiceRun($run, $turn, $lifecycle);

            return;
        }

        $this->sealOperationFailure($run, $turn, $exception, $lifecycle, $operations, $realtime);
    }

    private function sealOperationFailure(
        AssistantRun $run,
        VoiceTurn $turn,
        \Throwable $exception,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticOperationExecutor $operations,
        RealtimeVoiceApplicationEventHandler $realtime,
    ): void {
        try {
            $receipt = $operations->recordFailureReceipt($run->fresh(), $exception);
            $terminal = $this->finishOperationReceipt($run, $receipt, $lifecycle, $operations);
            $realtime->afterOperationFinished($terminal);
        } catch (VoiceTurnConflictException) {
            // Cancellation, a duplicate worker, or the deadline enforcer won.
            $lifecycle->enforceDeadlines($turn->id);
        }

        Log::error('Realtime voice typed operation failed.', [
            'run_id' => $run->id,
            'voice_turn_id' => $turn->id,
            'operation_id' => data_get($run->metadata, 'semantic_operation_id'),
            'exception_class' => $exception::class,
        ]);
    }

    private function failUnsupportedRealtimeVoiceRun(
        AssistantRun $run,
        VoiceTurn $turn,
        VoiceTurnLifecycleService $lifecycle,
    ): void {
        try {
            $lifecycle->fail(
                $turn,
                'invalid_voice_queue_handler',
                "The voice-high queue received unsupported handler {$run->handler}.",
                AssistantRunService::SYSTEM_FAILURE_FINAL,
                metadata: ['run_id' => $run->id, 'handler' => $run->handler],
            );
        } catch (VoiceTurnConflictException) {
            // Another terminalizer already won the durable turn.
        }

        Log::error('Realtime voice queue rejected a non-operation run.', [
            'run_id' => $run->id,
            'voice_turn_id' => $turn->id,
            'handler' => $run->handler,
        ]);
    }

    private function claimRealtimeVoiceOperation(
        AssistantRun $run,
        VoiceTurn $turn,
        VoiceTurnLifecycleService $lifecycle,
    ): bool {
        $lifecycle->enforceDeadlines($turn->id);
        $run = $run->fresh() ?? $run;
        $turn = $turn->fresh() ?? $turn;
        if ($turn->state->isTerminal()
            || in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return false;
        }

        $started = $lifecycle->claimJobExecution($run);
        if (! $started) {
            $currentRun = $run->fresh();
            $currentTurn = $turn->fresh();
            if ($currentRun instanceof AssistantRun
                && $currentTurn instanceof VoiceTurn
                && $currentRun->status === 'queued'
                && ! $currentTurn->state->isTerminal()) {
                $this->job?->release(1);
            }
        }

        return $started;
    }

    /** @param array<string, mixed> $receipt */
    private function finishOperationReceipt(
        AssistantRun $run,
        array $receipt,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticOperationExecutor $operations,
    ): VoiceTurn {
        $receiptStatus = (string) ($receipt['status'] ?? 'failed');
        $status = match ($receiptStatus) {
            'completed', 'skipped' => 'completed',
            'canceled' => 'cancelled',
            'failed' => data_get($receipt, 'data.failure_scope') === 'operation'
                ? 'completed'
                : 'failed',
            default => 'failed',
        };
        $category = trim((string) data_get($receipt, 'data.category')) ?: null;

        return $lifecycle->finishJob(
            $run,
            $status,
            finalText: $this->operationReceiptText($run, $receipt),
            failureCategory: $status === 'failed' ? $category : null,
            internalDetail: $status === 'failed'
                ? trim((string) data_get($receipt, 'data.internal_detail'))
                : null,
            userFacingFailure: null,
            sideEffectStatus: $operations->receiptSideEffectStatus($receipt),
            metadata: [
                'run_id' => $run->id,
                'semantic_operation_receipt' => $receipt,
            ],
        );
    }

    /** @param array<string, mixed> $receipt */
    private function operationReceiptText(AssistantRun $run, array $receipt): string
    {
        $label = trim((string) $run->label) ?: 'Operation';

        return match ((string) ($receipt['status'] ?? 'failed')) {
            'completed' => "{$label} completed.",
            'skipped' => "{$label} was skipped because a dependency did not complete.",
            'canceled' => "{$label} was canceled.",
            default => "{$label} failed.",
        };
    }
}
