<?php

namespace App\Services;

use App\Contracts\RealtimeVoiceProviderEventHandler;
use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticOperation;
use App\Data\HermesSemanticOperationResult;
use App\Enums\VoiceRealtimeCommandStatus;
use App\Enums\VoiceRealtimeCommandType;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeEvent;
use App\Models\VoiceRealtimeSession;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * The single Laravel application boundary for provider-side Realtime events.
 * Realtime supplies structured meaning and audio; the existing lifecycle and
 * typed executor remain the only owners of application work and durable finals.
 */
class RealtimeVoiceApplicationEventHandler implements RealtimeVoiceProviderEventHandler
{
    private const PLAN_TOOL = 'bean_turn_plan';

    private const FINALIZE_TOOL = 'bean_turn_finalize';

    private const NEUTRAL_FAILURE = 'I’m sorry, I couldn’t finish that voice request. Please try again.';

    public function __construct(
        private readonly VoiceTurnLifecycleService $lifecycle,
        private readonly RealtimeVoiceCommandService $commands,
        private readonly HermesSemanticOperationExecutor $operations,
        private readonly HermesSemanticContextService $context,
        private readonly HermesSemanticProtocol $protocol,
        private readonly ReceiptGroundedVoiceFinalizer $receiptFinalizer,
        private readonly VoiceTurnPrivacyService $privacy,
        private readonly AiUsageService $usage,
    ) {}

    public function handle(VoiceRealtimeEvent $event): void
    {
        $event->loadMissing('realtimeSession');
        $session = $event->realtimeSession;
        if (! $session instanceof VoiceRealtimeSession) {
            return;
        }

        match ($event->event_type) {
            'input_audio_buffer.committed' => $this->handleCommittedInput($session, $event),
            'response.created' => $this->handleResponseCreated($session, $event),
            'response.output_item.done' => $this->handleOutputItem($session, $event),
            'response.done' => $this->handleResponseDone($session, $event),
            'response.output_audio_transcript.done',
            'response.audio_transcript.done' => $this->verifyApprovedOutput($session, $event),
            'error' => $this->handleProviderError($session, $event),
            default => null,
        };
    }

    public function handleEventFailure(VoiceRealtimeEvent $event): void
    {
        $event->loadMissing('realtimeSession');
        $turn = $this->turnForEvent($event->realtimeSession, $event);
        if ($turn instanceof VoiceTurn) {
            $this->terminalizeTurnFailure(
                $turn,
                'realtime_event_processing_failed',
                (string) ($event->error ?: 'Realtime provider event processing exhausted its retry budget.'),
            );
        }
    }

    public function handleSessionReady(VoiceRealtimeSession $session): void
    {
        VoiceTurn::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->conversation_session_id)
            ->whereNotNull('final_assistant_message_id')
            ->whereIn('state', [
                VoiceTurnState::Completed->value,
                VoiceTurnState::Failed->value,
            ])
            ->where('terminal_at', '>=', now()->subHours(24))
            ->whereDoesntHave('events', function ($events): void {
                $events->where(function ($playback): void {
                    $playback->where('event_type', 'final_audio_started')
                        ->orWhere(function ($started): void {
                            $started->where('event_type', 'playback_started')
                                ->where('payload->purpose', 'final');
                        });
                });
            })
            ->orderByDesc('id')
            ->limit(1)
            ->get()
            ->each(function (VoiceTurn $turn): void {
                $this->authorizeFinal($turn, recoveryReason: 'realtime_session_ready');
            });
    }

    /**
     * Reconcile a browser-reported failure before final audio starts. One
     * additional speech identity is permitted for the same durable final;
     * later reports are terminal delivery evidence, never an unbounded loop.
     */
    public function handleClientPlaybackFailure(VoiceTurn $turn): string
    {
        $turn = $turn->fresh() ?? $turn;
        $speech = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->whereIn('purpose', ['acknowledgement', 'clarification', 'final'])
            ->whereNotNull('speech_item_id')
            ->whereIn('status', [
                VoiceRealtimeCommandStatus::Sending->value,
                VoiceRealtimeCommandStatus::Sent->value,
                VoiceRealtimeCommandStatus::Acknowledged->value,
            ])
            ->latest('id')
            ->first();

        if ($speech instanceof VoiceRealtimeCommand) {
            if ($speech->purpose === 'final' && $this->finalPlaybackStarted($turn)) {
                return 'already_started';
            }
            $speech = $this->commands->markPlaybackFailed($speech);
            $this->lifecycle->recordSemanticEvent($turn, 'speech_playback_failed', [
                'command_id' => $speech->command_id,
                'purpose' => $speech->purpose,
                'speech_item_id' => $speech->speech_item_id,
            ]);
        }

        if ($speech?->purpose === 'acknowledgement') {
            $this->releaseFinalAfterAcknowledgement($turn);

            if (! $turn->state->isTerminal()) {
                return 'acknowledgement_suppressed';
            }

            return $this->authorizeFinal($turn, recoveryReason: 'acknowledgement_playback_failed');
        }

        if ($turn->state === VoiceTurnState::AwaitingClarification) {
            $question = trim((string) data_get($turn->metadata, 'clarification_question'));
            if ($speech?->purpose !== 'clarification' || $question === '') {
                $this->terminalizeTurnFailure(
                    $turn,
                    'clarification_playback_failed',
                    'The active clarification could not be delivered to the browser.',
                );

                return 'clarification_failed';
            }

            $result = $this->authorizeSpeech(
                $turn,
                'clarification',
                $question,
                forceDeliveryAttempt: true,
                recoveryReason: 'browser_playback_failure',
            );
            if (in_array($result, ['exhausted', 'sideband_unavailable'], true)) {
                $this->terminalizeTurnFailure(
                    $turn,
                    'clarification_playback_failed',
                    'Clarification playback exhausted its bounded delivery attempts.',
                );

                return 'clarification_failed';
            }

            return $result;
        }

        return $this->authorizeFinal(
            $turn,
            forceDeliveryAttempt: $speech?->purpose === 'final',
            recoveryReason: 'browser_playback_failure',
        );
    }

    /**
     * A terminal local decoder failure closes the browser's Realtime media
     * connection. If no semantic run exists yet, fail that one pre-admitted
     * turn immediately instead of leaving its plan command aimed at a dying
     * provider call until the no-progress watchdog fires.
     */
    public function handleClientTurnFailure(VoiceTurn $turn, string $stage, string $code): string
    {
        if ($stage !== 'local_wake') {
            return 'not_applicable';
        }

        $safeCode = mb_substr(
            preg_replace('/[^A-Za-z0-9_.-]+/', '_', $code) ?: 'local_wake_failure',
            0,
            80,
        );
        $result = $this->lifecycle->failBeforeSemanticWork(
            $turn,
            'browser_local_wake_failed',
            "The browser local wake transport failed before semantic work started ({$safeCode}).",
            self::NEUTRAL_FAILURE,
            [
                'client_failure_stage' => 'local_wake',
                'client_failure_code' => $safeCode,
            ],
        );
        $terminal = $result['turn'];
        if ($terminal->state === VoiceTurnState::Failed) {
            $this->authorizeFinal(
                $terminal,
                recoveryReason: 'browser_local_wake_failure',
            );
        }

        return $result['status'];
    }

    public function handleCommandFailure(VoiceRealtimeCommand $command): void
    {
        $command->loadMissing('turn');
        $turn = $command->turn;
        if (! $turn instanceof VoiceTurn) {
            return;
        }

        $this->lifecycle->recordSemanticEvent($turn, 'realtime_command_delivery_failed', [
            'command_id' => $command->command_id,
            'command_type' => $command->command_type->value,
            'purpose' => $command->purpose,
        ]);

        // A missed acknowledgement never invalidates already accepted work;
        // the eventual durable final remains authoritative and must no longer
        // wait for an acknowledgement that cannot start.
        if ($command->purpose === 'acknowledgement') {
            $this->releaseFinalAfterAcknowledgement($turn);

            return;
        }

        if ($turn->state->isTerminal()
            && in_array($command->purpose, ['clarification', 'final'], true)) {
            $this->authorizeFinal(
                $turn,
                forceDeliveryAttempt: true,
                recoveryReason: 'realtime_command_delivery_failed',
            );

            return;
        }

        $this->terminalizeTurnFailure(
            $turn,
            'realtime_command_delivery_failed',
            (string) ($command->error ?: 'A realtime sideband command could not be delivered safely.'),
        );
    }

    public function handleSessionFailure(VoiceRealtimeSession $session): void
    {
        VoiceTurn::query()
            ->where('realtime_session_id', $session->id)
            ->whereIn('state', [
                VoiceTurnState::Accepted->value,
                VoiceTurnState::Running->value,
                VoiceTurnState::AwaitingClarification->value,
            ])
            ->orderBy('id')
            ->each(function (VoiceTurn $turn) use ($session): void {
                $transferable = $turn->runs()
                    ->whereIn('handler', [
                        HermesSemanticOperationExecutor::OPERATION_HANDLER,
                        HermesSemanticOperationExecutor::COMPOSITION_HANDLER,
                    ])
                    ->exists();
                $replacement = $transferable ? $this->deliveryBindingForTurn($turn) : null;
                if ($replacement !== null && $replacement['session']->id !== $session->id) {
                    $this->afterOperationFinished($replacement['turn']);

                    return;
                }
                $this->terminalizeTurnFailure(
                    $turn,
                    'realtime_sideband_unavailable',
                    (string) ($session->failure_detail ?: 'The realtime sideband reconnect budget was exhausted.'),
                );
            });
    }

    /**
     * Called after a voice-high worker seals one typed-operation receipt. The
     * queued composition run is deliberately not dispatched to an HTTP model;
     * this warm sideband either applies the narrow receipt template or asks the
     * same Realtime Hermes session for a grounded structured composition.
     */
    public function afterOperationFinished(VoiceTurn $turn): void
    {
        $turn = $turn->fresh(['realtimeSession', 'runs']);
        if (! $turn instanceof VoiceTurn || $turn->state->isTerminal()) {
            return;
        }

        $compositionRun = $turn->runs->first(
            fn (AssistantRun $run): bool => $run->handler === HermesSemanticOperationExecutor::COMPOSITION_HANDLER,
        );
        if (! $compositionRun instanceof AssistantRun) {
            return;
        }

        $operationRuns = $turn->runs->filter(
            fn (AssistantRun $run): bool => $run->handler === HermesSemanticOperationExecutor::OPERATION_HANDLER,
        )->values();
        if ($operationRuns->isEmpty() || $operationRuns->contains(
            fn (AssistantRun $run): bool => ! in_array($run->status, ['completed', 'failed', 'cancelled'], true),
        )) {
            return;
        }

        if ($compositionRun->status === 'queued' && ! $this->lifecycle->claimJobExecution($compositionRun)) {
            return;
        }
        $compositionRun = $compositionRun->fresh();
        if (! $compositionRun instanceof AssistantRun || $compositionRun->status !== 'running') {
            return;
        }

        $interpretation = $this->interpretationFromCompositionRun($compositionRun);
        $results = $this->resultsFromCompositionRun($compositionRun, $interpretation);
        $deterministic = $this->receiptFinalizer->finalize($interpretation, $results);
        if ($deterministic instanceof HermesSemanticComposition) {
            $this->finishComposition($compositionRun, $deterministic, $results, 'receipt_template');

            return;
        }

        $this->requestComposition($turn, $compositionRun, $interpretation, $results);
    }

    public function releaseFinalAfterAcknowledgement(VoiceTurn $turn): void
    {
        $released = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->where('status', VoiceRealtimeCommandStatus::Queued->value)
            ->where('available_at', '>', now())
            ->update([
                'available_at' => now()->addMilliseconds(
                    max(250, (int) config('services.voice_realtime.playback_authorization_grace_ms', 350)),
                ),
                'updated_at' => now(),
            ]);
        if ($released > 0) {
            $this->lifecycle->recordSemanticEvent($turn, 'final_speech_released_after_acknowledgement', [
                'released_command_count' => $released,
            ]);
        }
    }

    private function handleCommittedInput(VoiceRealtimeSession $session, VoiceRealtimeEvent $event): void
    {
        $itemId = trim((string) ($event->provider_input_item_id
            ?: data_get($event->payload, 'item_id')
            ?: data_get($event->payload, 'item.id')));
        if ($itemId === '') {
            return;
        }

        $turn = VoiceTurn::query()
            ->where('realtime_session_id', $session->id)
            ->whereIn('state', [VoiceTurnState::Accepted->value, VoiceTurnState::AwaitingClarification->value])
            ->where('metadata->awaiting_provider_input', true)
            ->orderBy('id')
            ->first();
        if (! $turn instanceof VoiceTurn) {
            return;
        }

        $turn = $this->lifecycle->bindRealtimeInputItem(
            $turn,
            $session,
            $itemId,
            $event->provider_event_id,
        );
        $this->lifecycle->recordSemanticEvent($turn, 'realtime_interpretation_requested', [
            'provider_input_item_id' => $itemId,
            'provider_event_id' => $event->provider_event_id,
            'attempt' => 1,
        ]);
        $this->requestPlan($turn, $itemId, 1);
    }

    private function handleResponseCreated(VoiceRealtimeSession $session, VoiceRealtimeEvent $event): void
    {
        $commandId = trim((string) data_get($event->payload, 'response.metadata.bean_command_id'));
        $responseId = trim((string) ($event->provider_response_id ?: data_get($event->payload, 'response.id')));
        if ($commandId === '') {
            return;
        }

        $command = VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('command_id', $commandId)
            ->first();
        if (! $command instanceof VoiceRealtimeCommand
            || $command->status === VoiceRealtimeCommandStatus::Acknowledged) {
            return;
        }

        $this->commands->acknowledge($command, $responseId !== '' ? $responseId : null);
        if ($command->turn instanceof VoiceTurn) {
            $this->lifecycle->recordSemanticEvent($command->turn, 'realtime_response_bound', [
                'command_id' => $commandId,
                'provider_response_id' => $responseId,
                'purpose' => $command->purpose,
            ]);
        }

        // A durable out-of-order output event may arrive before the provider's
        // response.created binding. Replay it now under the authoritative
        // response ID; downstream command and lifecycle ledgers are idempotent.
        if ($responseId !== '') {
            VoiceRealtimeEvent::query()
                ->where('voice_realtime_session_id', $session->id)
                ->where('provider_response_id', $responseId)
                ->whereIn('event_type', ['response.output_item.done', 'response.done'])
                ->whereKeyNot($event->id)
                ->orderBy('id')
                ->each(function (VoiceRealtimeEvent $deferred) use ($session): void {
                    if ($deferred->event_type === 'response.output_item.done') {
                        $this->handleOutputItem($session, $deferred);
                    } else {
                        $this->handleResponseDone($session, $deferred);
                    }
                });
        }
    }

    private function handleOutputItem(VoiceRealtimeSession $session, VoiceRealtimeEvent $event): void
    {
        $item = data_get($event->payload, 'item');
        if (! is_array($item) || ($item['type'] ?? null) !== 'function_call') {
            return;
        }

        $this->handleFunctionCall($session, $event, $item);
    }

    private function handleResponseDone(VoiceRealtimeSession $session, VoiceRealtimeEvent $event): void
    {
        $usage = data_get($event->payload, 'response.usage');
        $usageSessionId = trim((string) data_get($session->metadata, 'usage_session_id'));
        if (is_array($usage) && $usageSessionId !== '') {
            $result = $this->usage->recordRealtimeUsage(
                $session->user()->firstOrFail(),
                $usageSessionId,
                $event->provider_event_id,
                'response',
                $usage,
            );
            if (($result['availability']['allowed'] ?? true) !== true) {
                $this->failActiveTurn($session, 'voice_usage_limit', (string) ($result['availability']['reason'] ?? self::NEUTRAL_FAILURE));

                return;
            }
        }

        $recognizedFunctionCall = false;
        foreach ((array) data_get($event->payload, 'response.output', []) as $item) {
            if (is_array($item) && ($item['type'] ?? null) === 'function_call') {
                $recognizedFunctionCall = in_array(
                    trim((string) ($item['name'] ?? '')),
                    [self::PLAN_TOOL, self::FINALIZE_TOOL],
                    true,
                ) || $recognizedFunctionCall;
                $this->handleFunctionCall($session, $event, $item);
            }
        }

        $command = $this->responseCommandForEvent($session, $event);
        if (! $command instanceof VoiceRealtimeCommand
            || ! in_array($command->purpose, ['semantic_plan', 'semantic_composition'], true)) {
            return;
        }

        $status = trim((string) data_get($event->payload, 'response.status'));
        if ($status !== '' && $status !== 'completed') {
            $detail = trim((string) (data_get($event->payload, 'response.status_details.error.message')
                ?: data_get($event->payload, 'response.status_details.reason')
                ?: "Realtime semantic response ended with status {$status}."));
            if ($command->turn instanceof VoiceTurn) {
                $this->handleSemanticRejection($command->turn, $command, new RuntimeException($detail));
            }

            return;
        }

        if (! $recognizedFunctionCall && $command->turn instanceof VoiceTurn) {
            $this->handleSemanticRejection(
                $command->turn,
                $command,
                new RuntimeException('Realtime Hermes completed without the required structured tool call.'),
            );
        }
    }

    /** @param array<string, mixed> $item */
    private function handleFunctionCall(
        VoiceRealtimeSession $session,
        VoiceRealtimeEvent $event,
        array $item,
    ): void {
        $callId = trim((string) ($item['call_id'] ?? ''));
        $name = trim((string) ($item['name'] ?? ''));
        if ($callId === '' || ! in_array($name, [self::PLAN_TOOL, self::FINALIZE_TOOL], true)) {
            return;
        }

        $outputCommandId = 'function-output:'.$callId;
        if (VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('command_id', $outputCommandId)
            ->exists()) {
            return;
        }

        $command = $this->responseCommandForEvent($session, $event);
        $turn = $command?->turn;
        if (! $command instanceof VoiceRealtimeCommand || ! $turn instanceof VoiceTurn) {
            return;
        }

        // Reserve the function output before any downstream response.create.
        // The daemon drains commands by ledger id after this handler returns,
        // so the provider always receives its call output first.
        $this->commands->enqueue(
            $session,
            $outputCommandId,
            VoiceRealtimeCommandType::ConversationItemCreate,
            [
                'item' => [
                    'type' => 'function_call_output',
                    'call_id' => $callId,
                    'output' => json_encode(['status' => 'received'], JSON_THROW_ON_ERROR),
                ],
            ],
            $turn,
            'function_output',
        );

        $failure = null;
        try {
            $arguments = json_decode((string) ($item['arguments'] ?? ''), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($arguments)) {
                throw new InvalidArgumentException('The provider function arguments were not an object.');
            }

            if ($name === self::PLAN_TOOL) {
                $this->acceptPlan($turn, $command, $event, $arguments);
            } else {
                $this->acceptComposition($turn, $command, $arguments);
            }
        } catch (Throwable $exception) {
            $failure = $exception;
            $this->handleSemanticRejection($turn, $command, $exception);
        }

        if ($failure instanceof Throwable) {
            Log::warning('Realtime Hermes structured output was rejected.', [
                'voice_turn_id' => $turn->turn_id,
                'command_id' => $command->command_id,
                'tool' => $name,
                'exception' => $failure::class,
            ]);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function acceptPlan(
        VoiceTurn $turn,
        VoiceRealtimeCommand $command,
        VoiceRealtimeEvent $event,
        array $arguments,
    ): void {
        if ($command->purpose !== 'semantic_plan') {
            throw new InvalidArgumentException('The plan call is not bound to a semantic-plan command.');
        }
        $semanticInput = trim((string) ($arguments['semantic_input'] ?? ''));
        $payload = $arguments['interpretation'] ?? null;
        if (! is_array($payload)) {
            throw new InvalidArgumentException('Realtime Hermes did not provide a structured interpretation.');
        }

        $interpretation = HermesSemanticInterpretation::fromProviderPayload($payload);
        $providerInputItemId = trim((string) data_get(
            $command->payload,
            'response.metadata.provider_input_item_id',
        ));
        if ($providerInputItemId === '') {
            throw new InvalidArgumentException('The semantic plan has no bound provider input item.');
        }
        $run = $this->lifecycle->prepareRealtimeInterpretation(
            $turn,
            $semanticInput,
            $providerInputItemId,
            (string) ($event->provider_response_id ?: $command->provider_response_id ?: $event->provider_event_id),
        );
        if ($run->status === 'queued' && ! $this->lifecycle->claimJobExecution($run)) {
            throw new InvalidArgumentException('The semantic interpretation run could not be claimed.');
        }
        $run = $run->fresh();
        if (! $run instanceof AssistantRun || $run->status !== 'running') {
            return;
        }

        $turn = $turn->fresh() ?? $turn;
        $this->lifecycle->markProgress($turn, [
            'run_id' => $run->id,
            'phase' => 'realtime_semantic_interpretation',
            'command_id' => $command->command_id,
        ], 'realtime_sideband');

        match ($interpretation->outcome) {
            HermesSemanticInterpretation::OUTCOME_RESPOND => $this->finishDirectResponse($run, $interpretation),
            HermesSemanticInterpretation::OUTCOME_CLARIFY => $this->finishClarification($run, $interpretation),
            HermesSemanticInterpretation::OUTCOME_EXECUTE => $this->stageExecution($run, $turn, $interpretation),
            default => throw new InvalidArgumentException('Realtime Hermes selected an unsupported outcome.'),
        };
    }

    private function finishDirectResponse(AssistantRun $run, HermesSemanticInterpretation $interpretation): void
    {
        $this->lifecycle->publishSemanticResponseDirectives(
            $run,
            $interpretation->closeAfterResponse,
            $interpretation->responseExpected,
        );
        $this->lifecycle->markJobFinalizing($run, ['semantic_outcome' => $interpretation->outcome]);
        $turn = $this->lifecycle->finishJob(
            $run,
            'completed',
            finalText: (string) $interpretation->responseText,
            metadata: ['semantic_outcome' => $interpretation->outcome, 'transport' => 'realtime_sideband'],
        );
        $this->authorizeFinal($turn);
    }

    private function finishClarification(AssistantRun $run, HermesSemanticInterpretation $interpretation): void
    {
        $turn = $this->lifecycle->requestSemanticClarification(
            $run,
            (string) $interpretation->clarificationQuestion,
            ['transport' => 'realtime_sideband'],
        );
        $this->authorizeSpeech($turn, 'clarification', (string) $interpretation->clarificationQuestion);
    }

    private function stageExecution(
        AssistantRun $run,
        VoiceTurn $turn,
        HermesSemanticInterpretation $interpretation,
    ): void {
        $staged = $this->operations->stage(
            $turn,
            $run,
            $interpretation,
            $this->context->forVoiceTurn($turn),
        );
        foreach ($staged['operation_runs'] as $operationRun) {
            if (! $operationRun instanceof AssistantRun || ! $this->lifecycle->jobRequiresDispatch($operationRun)) {
                continue;
            }
            ProcessAssistantRun::dispatch($operationRun->id)
                ->onQueue((string) config('services.voice_realtime.operation_queue', 'voice-high'));
            $this->lifecycle->markJobDispatched($operationRun);
        }

        $freshTurn = $turn->fresh() ?? $turn;
        if ($freshTurn->acknowledgement_required && filled($freshTurn->acknowledgement_text)) {
            $this->authorizeSpeech($freshTurn, 'acknowledgement', (string) $freshTurn->acknowledgement_text);
        }
    }

    /** @param array<string, mixed> $arguments */
    private function acceptComposition(
        VoiceTurn $turn,
        VoiceRealtimeCommand $command,
        array $arguments,
    ): void {
        if ($command->purpose !== 'semantic_composition') {
            throw new InvalidArgumentException('The composition call is not bound to a composition command.');
        }
        $composition = HermesSemanticComposition::fromProviderPayload($arguments['composition'] ?? $arguments);
        $run = AssistantRun::query()
            ->whereKey($command->payload['response']['metadata']['composition_run_id'] ?? 0)
            ->where('voice_turn_id', $turn->id)
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->firstOrFail();
        $interpretation = $this->interpretationFromCompositionRun($run);
        $results = $this->resultsFromCompositionRun($run, $interpretation);
        $this->finishComposition($run, $composition, $results, 'realtime_hermes');
    }

    /** @param list<HermesSemanticOperationResult> $results */
    private function finishComposition(
        AssistantRun $run,
        HermesSemanticComposition $composition,
        array $results,
        string $source,
    ): void {
        $this->lifecycle->publishSemanticResponseDirectives(
            $run,
            $composition->closeAfterResponse,
            $composition->responseExpected,
        );
        $this->lifecycle->markJobFinalizing($run, [
            'semantic_outcome' => HermesSemanticInterpretation::OUTCOME_EXECUTE,
            'composition_source' => $source,
        ]);
        $turn = $this->lifecycle->finishJob(
            $run,
            'completed',
            finalText: $composition->responseText,
            metadata: [
                'composition_source' => $source,
                'operation_statuses' => collect($results)->mapWithKeys(
                    fn (HermesSemanticOperationResult $result): array => [$result->operationId => $result->status],
                )->all(),
            ],
        );
        $this->authorizeFinal($turn);
    }

    private function requestPlan(
        VoiceTurn $turn,
        string $providerInputItemId,
        int $attempt,
        ?string $feedback = null,
    ): void {
        $session = $turn->realtimeSession()->firstOrFail();
        $inputItemIds = data_get($turn->metadata, 'provider_input_item_ids');
        $requestSequence = max(
            1,
            (int) data_get($turn->metadata, 'semantic_sequence', 1),
            is_array($inputItemIds) ? count($inputItemIds) : 0,
        );
        $commandId = sprintf('plan:%s:%d:%d', $turn->turn_id, $requestSequence, $attempt);
        $trusted = $this->trustedSemanticContext($turn);
        $instructions = $this->protocol->interpretationInstructions()
            ."\n\nInterpret the latest bound user audio item. Call bean_turn_plan exactly once."
            ."\nsemantic_input must be a concise sanitized meaning summary, not a verbatim transcript."
            .($feedback === null ? '' : "\nThe previous structured plan was rejected. Correct this issue: ".$this->privacy->sanitizeTranscript($feedback))
            ."\nTrusted server context:\n".json_encode($trusted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $this->commands->enqueue(
            $session,
            $commandId,
            VoiceRealtimeCommandType::ResponseCreate,
            [
                'response' => [
                    'output_modalities' => ['text'],
                    'instructions' => $instructions,
                    'tools' => [$this->planTool()],
                    'tool_choice' => ['type' => 'function', 'name' => self::PLAN_TOOL],
                    'metadata' => [
                        'bean_command_id' => $commandId,
                        'turn_id' => $turn->turn_id,
                        'purpose' => 'semantic_plan',
                        'provider_input_item_id' => $providerInputItemId,
                        'semantic_sequence' => (string) $requestSequence,
                        'semantic_attempt' => (string) $attempt,
                    ],
                ],
            ],
            $turn,
            'semantic_plan',
        );
    }

    /**
     * @param  list<HermesSemanticOperationResult>  $results
     */
    private function requestComposition(
        VoiceTurn $turn,
        AssistantRun $run,
        HermesSemanticInterpretation $interpretation,
        array $results,
        int $attempt = 1,
        ?string $feedback = null,
    ): void {
        $binding = $this->deliveryBindingForTurn($turn);
        if ($binding === null) {
            throw new RuntimeException('No usable Realtime sideband is available for grounded composition.');
        }
        $turn = $binding['turn'];
        $session = $binding['session'];
        $commandId = sprintf('compose:%s:%d:%d', $turn->turn_id, $run->id, $attempt);
        $request = [
            'interpretation' => $interpretation->toArray(),
            'results' => array_map(
                static fn (HermesSemanticOperationResult $result): array => $result->toArray(),
                $results,
            ),
            'context' => $this->trustedSemanticContext($turn),
        ];
        $instructions = $this->protocol->compositionInstructions()
            ."\n\nCall bean_turn_finalize exactly once using only the sealed request below."
            .($feedback === null ? '' : "\nThe previous composition was rejected. Correct this issue: ".$this->privacy->sanitizeTranscript($feedback))
            ."\nSealed request:\n".json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->commands->enqueue(
            $session,
            $commandId,
            VoiceRealtimeCommandType::ResponseCreate,
            [
                'response' => [
                    'output_modalities' => ['text'],
                    'instructions' => $instructions,
                    'tools' => [$this->compositionTool()],
                    'tool_choice' => ['type' => 'function', 'name' => self::FINALIZE_TOOL],
                    'metadata' => [
                        'bean_command_id' => $commandId,
                        'turn_id' => $turn->turn_id,
                        'purpose' => 'semantic_composition',
                        'composition_run_id' => (string) $run->id,
                        'semantic_attempt' => (string) $attempt,
                    ],
                ],
            ],
            $turn,
            'semantic_composition',
        );
        $this->lifecycle->recordSemanticEvent($turn, 'realtime_composition_requested', [
            'command_id' => $commandId,
            'composition_run_id' => $run->id,
            'attempt' => $attempt,
        ]);
    }

    private function handleSemanticRejection(
        VoiceTurn $turn,
        VoiceRealtimeCommand $command,
        Throwable $exception,
    ): void {
        $turn = $turn->fresh();
        if (! $turn instanceof VoiceTurn || $turn->state->isTerminal()) {
            return;
        }

        $attempt = max(1, (int) data_get($command->payload, 'response.metadata.semantic_attempt', 1));
        $this->lifecycle->recordSemanticEvent($turn, 'realtime_semantic_output_rejected', [
            'command_id' => $command->command_id,
            'purpose' => $command->purpose,
            'attempt' => $attempt,
            'exception_class' => $exception::class,
        ]);
        if ($attempt < 2 && ($turn->hard_deadline_at === null || $turn->hard_deadline_at->isFuture())) {
            $turn = $this->lifecycle->markRetryAttempt($turn, [
                'command_id' => $command->command_id,
                'purpose' => $command->purpose,
                'semantic_attempt' => $attempt + 1,
            ], 'realtime_sideband');
            if ($command->purpose === 'semantic_plan') {
                $this->requestPlan(
                    $turn,
                    (string) data_get($command->payload, 'response.metadata.provider_input_item_id'),
                    $attempt + 1,
                    $exception->getMessage(),
                );

                return;
            }
            if ($command->purpose === 'semantic_composition') {
                $runId = (int) data_get($command->payload, 'response.metadata.composition_run_id', 0);
                $run = AssistantRun::query()->whereKey($runId)->where('voice_turn_id', $turn->id)->first();
                if ($run instanceof AssistantRun) {
                    $interpretation = $this->interpretationFromCompositionRun($run);
                    $this->requestComposition(
                        $turn,
                        $run,
                        $interpretation,
                        $this->resultsFromCompositionRun($run, $interpretation),
                        $attempt + 1,
                        $exception->getMessage(),
                    );

                    return;
                }
            }
        }

        $activeRun = AssistantRun::query()
            ->where('voice_turn_id', $turn->id)
            ->whereIn('status', ['running', 'finalizing', 'queued'])
            ->orderByDesc('id')
            ->first();
        if ($activeRun instanceof AssistantRun) {
            $terminal = $this->lifecycle->finishJob(
                $activeRun,
                'failed',
                failureCategory: 'realtime_semantic_invalid',
                internalDetail: $exception->getMessage(),
                userFacingFailure: self::NEUTRAL_FAILURE,
                sideEffectStatus: $turn->side_effect_status,
                metadata: ['semantic_attempts' => $attempt],
            );
            if ($terminal->state->isTerminal()) {
                $this->authorizeFinal($terminal);
            }

            return;
        }

        $this->terminalizeTurnFailure(
            $turn,
            'realtime_semantic_invalid',
            $exception->getMessage(),
        );
    }

    private function authorizeFinal(
        VoiceTurn $turn,
        bool $forceDeliveryAttempt = false,
        string $recoveryReason = 'final_ready',
    ): string {
        $turn = $turn->fresh(['finalAssistantMessage']) ?? $turn;
        if (! in_array($turn->state, [VoiceTurnState::Completed, VoiceTurnState::Failed], true)
            || $turn->final_assistant_message_id === null) {
            return 'not_eligible';
        }
        if ($this->finalPlaybackStarted($turn)) {
            return 'already_started';
        }

        $text = trim((string) $turn->finalAssistantMessage?->content);
        if ($text === '') {
            return 'not_eligible';
        }

        $binding = $this->deliveryBindingForTurn($turn);
        if ($binding === null) {
            return 'sideband_unavailable';
        }
        $waitForAcknowledgement = ! $forceDeliveryAttempt
            && $this->supersedeOrWaitForAcknowledgement($binding['turn'], $binding['session']);

        return $this->authorizeSpeech(
            $binding['turn'],
            'final',
            $text,
            $waitForAcknowledgement,
            $binding,
            $forceDeliveryAttempt,
            $recoveryReason,
        );
    }

    private function authorizeSpeech(
        VoiceTurn $turn,
        string $purpose,
        string $text,
        bool $waitForAcknowledgement = false,
        ?array $resolvedBinding = null,
        bool $forceDeliveryAttempt = false,
        string $recoveryReason = 'speech_ready',
    ): string {
        $text = $this->privacy->sanitizeTranscript($text);
        if ($text === '' || ! in_array($purpose, ['acknowledgement', 'clarification', 'final'], true)) {
            return 'not_eligible';
        }
        $binding = $resolvedBinding ?? $this->deliveryBindingForTurn($turn);
        if ($binding === null) {
            return 'sideband_unavailable';
        }
        $turn = $binding['turn'];
        $session = $binding['session'];
        $semanticSequence = max(1, (int) data_get($turn->metadata, 'semantic_sequence', 1));
        $speechIdentity = sprintf('%s:%s:%d', $turn->turn_id, $purpose, $semanticSequence);
        $approvedHash = hash('sha256', $text);
        $active = VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', $purpose)
            ->where('approved_text_hash', $approvedHash)
            ->where(function ($query) use ($speechIdentity): void {
                $query->where('speech_item_id', $speechIdentity)
                    ->orWhere('speech_item_id', 'like', $speechIdentity.':delivery:%');
            })
            ->whereIn('status', [
                VoiceRealtimeCommandStatus::Queued->value,
                VoiceRealtimeCommandStatus::Sending->value,
                VoiceRealtimeCommandStatus::Sent->value,
                VoiceRealtimeCommandStatus::Acknowledged->value,
            ])
            ->latest('id')
            ->first();
        if ($active instanceof VoiceRealtimeCommand
            && (! $forceDeliveryAttempt || $this->deliveryAttempt($active->speech_item_id, $speechIdentity) >= 2)) {
            return $this->deliveryAttempt($active->speech_item_id, $speechIdentity) >= 2
                ? 'already_reauthorized'
                : 'already_authorized';
        }

        $highestAttempt = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', $purpose)
            ->where('approved_text_hash', $approvedHash)
            ->where(function ($query) use ($speechIdentity): void {
                $query->where('speech_item_id', $speechIdentity)
                    ->orWhere('speech_item_id', 'like', $speechIdentity.':delivery:%');
            })
            ->pluck('speech_item_id')
            ->reduce(
                fn (int $highest, ?string $speechItemId): int => max(
                    $highest,
                    $this->deliveryAttempt($speechItemId, $speechIdentity),
                ),
                0,
            );
        $deliveryAttempt = $highestAttempt + 1;
        if ($deliveryAttempt > 2) {
            $this->recordSpeechDeliveryExhausted(
                $turn,
                $purpose,
                $recoveryReason,
                $highestAttempt,
            );

            return 'exhausted';
        }

        $speechItemId = $speechIdentity.':delivery:'.$deliveryAttempt;
        $authorizationId = 'speech:'.$speechItemId;
        if (VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('command_id', $authorizationId)
            ->exists()) {
            return $deliveryAttempt > 1 ? 'already_reauthorized' : 'already_authorized';
        }
        $capability = trim((string) data_get($session->metadata, 'playback_capability'));
        if ($capability === '') {
            throw new InvalidArgumentException('The Realtime session has no playback capability.');
        }
        $expiresAt = now()->addMinutes(2)->toIso8601String();
        $metadata = [
            'bean_command_id' => $authorizationId,
            'authorization_id' => $authorizationId,
            'turn_id' => $turn->turn_id,
            'speech_item_id' => $speechItemId,
            'purpose' => $purpose,
            'realtime_session_id' => $session->public_id,
            'controller_generation' => (string) data_get($turn->metadata, 'controller_generation', 0),
            'provider_connection_generation' => (string) data_get($turn->metadata, 'provider_connection_generation', 0),
            'approved_text_sha256' => $approvedHash,
            'playback_capability' => $capability,
            'expires_at' => $expiresAt,
        ];
        $command = $this->commands->enqueue(
            $session,
            $authorizationId,
            VoiceRealtimeCommandType::ResponseCreate,
            [
                'response' => [
                    'output_modalities' => ['audio'],
                    'instructions' => "Speak this approved Bean response exactly, naturally, and without adding or omitting words:\n<approved_response>".$text.'</approved_response>',
                    'tool_choice' => 'none',
                    'metadata' => $metadata,
                ],
            ],
            $turn,
            $purpose,
            $speechItemId,
            (int) data_get($turn->metadata, 'controller_generation', 0),
            $text,
        );
        if ($command->status === VoiceRealtimeCommandStatus::Queued
            && $command->available_at?->lte(now())) {
            $command->forceFill(['available_at' => $waitForAcknowledgement
                ? now()->addMinutes(2)
                : now()->addMilliseconds(
                    max(250, (int) config('services.voice_realtime.playback_authorization_grace_ms', 350)),
                )])->save();
        }
        $this->lifecycle->recordSemanticEvent($turn, 'speech_authorized', [
            'authorization_id' => $authorizationId,
            'turn_id' => $turn->turn_id,
            'speech_item_id' => $speechItemId,
            'purpose' => $purpose,
            'realtime_session_id' => $session->public_id,
            'controller_generation' => (int) data_get($turn->metadata, 'controller_generation', 0),
            'provider_connection_generation' => (int) data_get($turn->metadata, 'provider_connection_generation', 0),
            'approved_text_sha256' => $approvedHash,
            'playback_capability' => $capability,
            'expires_at' => $expiresAt,
            'waiting_for_acknowledgement' => $waitForAcknowledgement,
            'delivery_attempt' => $deliveryAttempt,
            'delivery_recovery_reason' => $deliveryAttempt > 1 ? $recoveryReason : null,
        ]);

        return $deliveryAttempt > 1 ? 'reauthorized' : 'authorized';
    }

    private function supersedeOrWaitForAcknowledgement(
        VoiceTurn $turn,
        VoiceRealtimeSession $session,
    ): bool {
        $acknowledgement = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('voice_realtime_session_id', $session->id)
            ->where('purpose', 'acknowledgement')
            ->latest('id')
            ->first();
        if (! $acknowledgement instanceof VoiceRealtimeCommand) {
            return false;
        }
        if ($acknowledgement->status === VoiceRealtimeCommandStatus::Queued) {
            $superseded = VoiceRealtimeCommand::query()
                ->whereKey($acknowledgement->id)
                ->where('status', VoiceRealtimeCommandStatus::Queued->value)
                ->update([
                    'status' => VoiceRealtimeCommandStatus::Failed->value,
                    'failed_at' => now(),
                    'error' => 'Acknowledgement was superseded by a ready final before provider delivery.',
                    'updated_at' => now(),
                ]);
            if ($superseded === 1) {
                $this->lifecycle->recordSemanticEvent($turn, 'acknowledgement_suppressed_by_final', []);

                return false;
            }
            $acknowledgement = $acknowledgement->fresh();
        }

        return $acknowledgement instanceof VoiceRealtimeCommand
            && in_array($acknowledgement->status, [
                VoiceRealtimeCommandStatus::Sending,
                VoiceRealtimeCommandStatus::Sent,
                VoiceRealtimeCommandStatus::Acknowledged,
            ], true);
    }

    private function deliveryAttempt(?string $speechItemId, string $speechIdentity): int
    {
        if ($speechItemId === $speechIdentity) {
            return 1;
        }
        if (preg_match('/^'.preg_quote($speechIdentity, '/').':delivery:(\d+)$/', (string) $speechItemId, $match) !== 1) {
            return 0;
        }

        return max(1, (int) $match[1]);
    }

    private function recordSpeechDeliveryExhausted(
        VoiceTurn $turn,
        string $purpose,
        string $reason,
        int $attempts,
    ): void {
        $eventType = $purpose.'_speech_delivery_exhausted';
        if (VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', $eventType)
            ->exists()) {
            return;
        }

        $this->lifecycle->recordSemanticEvent($turn, $eventType, [
            'purpose' => $purpose,
            'reason' => $reason,
            'delivery_attempts' => $attempts,
        ]);
    }

    /**
     * Resolve response delivery to the newest live authenticated sideband for
     * this owner/conversation. This is the sole reload handoff: the durable
     * turn is rebound before any replacement command is authorized.
     *
     * @return array{turn: VoiceTurn, session: VoiceRealtimeSession}|null
     */
    private function deliveryBindingForTurn(VoiceTurn $turn): ?array
    {
        $turn = $turn->fresh();
        if (! $turn instanceof VoiceTurn) {
            return null;
        }

        $candidate = VoiceRealtimeSession::query()
            ->where('user_id', $turn->user_id)
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('workspace_id', $turn->workspace_id)
            ->where('controller_generation', '>=', max(
                0,
                (int) data_get($turn->metadata, 'controller_generation', 0),
            ))
            ->where('status', 'ready')
            ->whereNotNull('provider_call_id')
            ->whereNotNull('lease_owner')
            ->where('lease_expires_at', '>', now()->format('Y-m-d H:i:s.u'))
            ->orderByDesc('controller_generation')
            ->orderByDesc('id')
            ->first();

        if (! $candidate instanceof VoiceRealtimeSession) {
            $candidate = $turn->realtimeSession()->first();
            if (! $candidate instanceof VoiceRealtimeSession || $candidate->status->isTerminal()) {
                return null;
            }
        }

        if ((int) $turn->realtime_session_id !== (int) $candidate->id) {
            $previousSessionId = (int) $turn->realtime_session_id;
            $turn = DB::transaction(function () use ($turn, $candidate, $previousSessionId): VoiceTurn {
                $locked = VoiceTurn::query()->lockForUpdate()->findOrFail($turn->id);
                $metadata = is_array($locked->metadata) ? $locked->metadata : [];
                $history = is_array($metadata['realtime_delivery_session_history'] ?? null)
                    ? $metadata['realtime_delivery_session_history']
                    : [];
                $history[] = [
                    'from_session_id' => $previousSessionId,
                    'to_session_id' => $candidate->id,
                    'rebound_at' => now()->toIso8601String(),
                ];
                $locked->forceFill([
                    'realtime_session_id' => $candidate->id,
                    'version' => $locked->version + 1,
                    'metadata' => [
                        ...$metadata,
                        'controller_generation' => $candidate->controller_generation,
                        'provider_connection_generation' => max(0, (int) data_get(
                            $candidate->metadata,
                            'provider_connection_generation',
                            0,
                        )),
                        'realtime_delivery_session_history' => $history,
                    ],
                ])->save();

                VoiceRealtimeCommand::query()
                    ->where('voice_turn_id', $locked->id)
                    ->where('voice_realtime_session_id', $previousSessionId)
                    ->where('status', VoiceRealtimeCommandStatus::Queued->value)
                    ->whereIn('purpose', [
                        'semantic_composition',
                        'acknowledgement',
                        'clarification',
                        'final',
                    ])
                    ->update([
                        'status' => VoiceRealtimeCommandStatus::Failed->value,
                        'failed_at' => now(),
                        'error' => 'Superseded by a newer authenticated Realtime delivery session.',
                        'updated_at' => now(),
                    ]);

                return $locked->refresh();
            });
            $this->lifecycle->recordSemanticEvent($turn, 'realtime_delivery_session_rebound', [
                'from_realtime_session_id' => $previousSessionId,
                'to_realtime_session_id' => $candidate->public_id,
                'controller_generation' => $candidate->controller_generation,
            ]);
        }

        return ['turn' => $turn, 'session' => $candidate];
    }

    private function finalPlaybackStarted(VoiceTurn $turn): bool
    {
        return VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->whereIn('event_type', ['final_audio_started', 'playback_started'])
            ->get(['event_type', 'payload'])
            ->contains(fn (VoiceTurnEvent $event): bool => $event->event_type === 'final_audio_started'
                || ($event->event_type === 'playback_started'
                    && data_get($event->payload, 'purpose') === 'final'));
    }

    private function verifyApprovedOutput(VoiceRealtimeSession $session, VoiceRealtimeEvent $event): void
    {
        $command = $this->responseCommandForEvent($session, $event);
        if (! $command instanceof VoiceRealtimeCommand || $command->approved_text_hash === null) {
            return;
        }
        $actual = trim((string) (data_get($event->payload, 'transcript')
            ?: data_get($event->payload, 'item.content.0.transcript')));
        $approved = $this->approvedTextForCommand($command);
        if ($actual === '' || $approved === '' || $this->normalizedSpeech($actual) === $this->normalizedSpeech($approved)) {
            return;
        }

        $this->commands->enqueue(
            $session,
            'cancel-divergent:'.$event->provider_event_id,
            VoiceRealtimeCommandType::ResponseCancel,
            ['response_id' => (string) ($event->provider_response_id ?: $command->provider_response_id)],
            $command->turn,
            'divergent_output',
        );
        $this->commands->enqueue(
            $session,
            'clear-divergent:'.$event->provider_event_id,
            VoiceRealtimeCommandType::OutputAudioBufferClear,
            [],
            $command->turn,
            'divergent_output',
        );
        if ($command->turn instanceof VoiceTurn && ! $command->turn->state->isTerminal()) {
            $this->lifecycle->fail(
                $command->turn,
                'realtime_output_diverged',
                'The provider output transcript materially differed from the approved response.',
                self::NEUTRAL_FAILURE,
                VoiceTurnSideEffectStatus::Uncertain,
            );
        }
    }

    private function handleProviderError(VoiceRealtimeSession $session, VoiceRealtimeEvent $event): void
    {
        $code = trim((string) data_get($event->payload, 'error.code'));
        $message = trim((string) data_get($event->payload, 'error.message'));
        if (preg_match('/no active response|already.*(?:done|cancel)/i', $message) === 1) {
            return;
        }

        $clientEventId = trim((string) data_get($event->payload, 'error.event_id'));
        $command = $clientEventId === '' ? null : VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('command_id', $clientEventId)
            ->first();
        if ($command instanceof VoiceRealtimeCommand && $command->turn instanceof VoiceTurn) {
            if (in_array($command->purpose, ['semantic_plan', 'semantic_composition'], true)) {
                $this->handleSemanticRejection(
                    $command->turn,
                    $command,
                    new RuntimeException($message !== '' ? $message : 'Realtime semantic response failed.'),
                );
            } else {
                $this->terminalizeTurnFailure(
                    $command->turn,
                    $code !== '' ? 'realtime_'.$code : 'realtime_provider_error',
                    $message !== '' ? $message : 'Realtime provider error.',
                );
            }

            return;
        }

        $this->failActiveTurn(
            $session,
            $code !== '' ? 'realtime_'.$code : 'realtime_provider_error',
            self::NEUTRAL_FAILURE,
            $message,
        );
    }

    private function failActiveTurn(
        VoiceRealtimeSession $session,
        string $category,
        string $userFacing,
        string $detail = '',
    ): void {
        $turn = VoiceTurn::query()
            ->where('realtime_session_id', $session->id)
            ->whereIn('state', [
                VoiceTurnState::Accepted->value,
                VoiceTurnState::Running->value,
                VoiceTurnState::AwaitingClarification->value,
            ])
            ->latest('id')
            ->first();
        if (! $turn instanceof VoiceTurn) {
            return;
        }
        $terminal = $this->lifecycle->fail(
            $turn,
            mb_substr($category, 0, 100),
            $detail !== '' ? $detail : $category,
            $userFacing,
            $turn->side_effect_status,
        );
        $this->authorizeFinal($terminal);
    }

    private function terminalizeTurnFailure(VoiceTurn $turn, string $category, string $detail): void
    {
        $turn = $turn->fresh();
        if (! $turn instanceof VoiceTurn || $turn->state->isTerminal()) {
            return;
        }

        $terminal = $this->lifecycle->fail(
            $turn,
            mb_substr($category, 0, 100),
            $detail,
            self::NEUTRAL_FAILURE,
            $turn->side_effect_status,
        );
        $this->authorizeFinal($terminal);
    }

    private function turnForEvent(
        ?VoiceRealtimeSession $session,
        VoiceRealtimeEvent $event,
    ): ?VoiceTurn {
        if (! $session instanceof VoiceRealtimeSession) {
            return null;
        }

        $command = $this->responseCommandForEvent($session, $event);
        if ($command?->turn instanceof VoiceTurn) {
            return $command->turn;
        }

        $turnId = trim((string) data_get($event->payload, 'response.metadata.turn_id'));
        if ($turnId !== '') {
            $turn = VoiceTurn::query()
                ->where('realtime_session_id', $session->id)
                ->where('turn_id', $turnId)
                ->first();
            if ($turn instanceof VoiceTurn) {
                return $turn;
            }
        }

        if ($event->provider_input_item_id !== null) {
            $turn = VoiceTurn::query()
                ->where('realtime_session_id', $session->id)
                ->where(function ($query) use ($event): void {
                    $query->where('provider_input_item_id', $event->provider_input_item_id)
                        ->orWhereJsonContains('metadata->provider_input_item_ids', $event->provider_input_item_id);
                })
                ->first();
            if ($turn instanceof VoiceTurn) {
                return $turn;
            }
        }

        return VoiceTurn::query()
            ->where('realtime_session_id', $session->id)
            ->whereIn('state', [VoiceTurnState::Accepted->value, VoiceTurnState::AwaitingClarification->value])
            ->latest('id')
            ->first();
    }

    private function responseCommandForEvent(
        VoiceRealtimeSession $session,
        VoiceRealtimeEvent $event,
    ): ?VoiceRealtimeCommand {
        $commandId = trim((string) data_get($event->payload, 'response.metadata.bean_command_id'));
        $responseId = trim((string) ($event->provider_response_id
            ?: data_get($event->payload, 'response.id')
            ?: data_get($event->payload, 'response_id')));

        return VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->when($commandId !== '', fn ($query) => $query->where('command_id', $commandId))
            ->when($commandId === '' && $responseId !== '', fn ($query) => $query->where('provider_response_id', $responseId))
            ->when($commandId === '' && $responseId === '', fn ($query) => $query->whereRaw('1 = 0'))
            ->first();
    }

    /** @return array<string, mixed> */
    private function trustedSemanticContext(VoiceTurn $turn): array
    {
        $session = $turn->realtimeSession()->first();

        return [
            ...$this->context->forVoiceTurn($turn),
            'current_time' => now('UTC')->toIso8601String(),
            'timezone' => data_get($session?->metadata, 'timezone'),
            'locale' => 'en-US',
            'stable_turn_id' => $turn->turn_id,
        ];
    }

    /** @return array<string, mixed> */
    private function planTool(): array
    {
        return [
            'type' => 'function',
            'name' => self::PLAN_TOOL,
            'description' => 'Submit Bean\'s complete structured semantic interpretation. This tool never executes application work.',
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => [
                    'semantic_input' => [
                        'type' => 'string',
                        'description' => 'A concise meaning summary, not a verbatim transcript.',
                    ],
                    'interpretation' => $this->protocol->interpretationSchema(),
                ],
                'required' => ['semantic_input', 'interpretation'],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function compositionTool(): array
    {
        return [
            'type' => 'function',
            'name' => self::FINALIZE_TOOL,
            'description' => 'Submit one receipt-grounded Bean response after application operations have terminalized.',
            'parameters' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => ['composition' => $this->protocol->compositionSchema()],
                'required' => ['composition'],
            ],
        ];
    }

    private function interpretationFromCompositionRun(AssistantRun $run): HermesSemanticInterpretation
    {
        $payload = json_decode((string) $run->input, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($payload) || ! is_array($payload['operations'] ?? null)) {
            throw new InvalidArgumentException('The composition run has no sealed semantic interpretation.');
        }
        $operations = array_map(static function (mixed $operation): HermesSemanticOperation {
            if (! is_array($operation)) {
                throw new InvalidArgumentException('A sealed semantic operation is invalid.');
            }

            return new HermesSemanticOperation(
                id: (string) ($operation['id'] ?? ''),
                tool: (string) ($operation['tool'] ?? ''),
                arguments: is_array($operation['arguments'] ?? null) ? $operation['arguments'] : [],
                dependencies: is_array($operation['dependencies'] ?? null) ? $operation['dependencies'] : [],
            );
        }, $payload['operations']);

        return new HermesSemanticInterpretation(
            outcome: (string) ($payload['outcome'] ?? ''),
            responseText: is_string($payload['response_text'] ?? null) ? $payload['response_text'] : null,
            clarificationQuestion: is_string($payload['clarification_question'] ?? null) ? $payload['clarification_question'] : null,
            acknowledgementText: is_string($payload['acknowledgement_text'] ?? null) ? $payload['acknowledgement_text'] : null,
            closeAfterResponse: (bool) ($payload['close_after_response'] ?? false),
            responseExpected: (bool) ($payload['response_expected'] ?? false),
            operations: $operations,
        );
    }

    /** @return list<HermesSemanticOperationResult> */
    private function resultsFromCompositionRun(
        AssistantRun $run,
        HermesSemanticInterpretation $interpretation,
    ): array {
        $runMap = data_get($run->metadata, 'operation_run_map');
        $runMap = is_array($runMap) ? $runMap : [];

        return array_map(function (HermesSemanticOperation $operation) use ($run, $runMap): HermesSemanticOperationResult {
            $operationRun = AssistantRun::query()
                ->whereKey((int) ($runMap[$operation->id] ?? 0))
                ->where('voice_turn_id', $run->voice_turn_id)
                ->first();
            $receipt = $operationRun instanceof AssistantRun ? $this->operations->receiptForRun($operationRun) : null;
            if (! $operationRun instanceof AssistantRun
                || ! in_array($operationRun->status, ['completed', 'failed', 'cancelled'], true)
                || ! is_array($receipt)
                || ($receipt['operation_id'] ?? null) !== $operation->id
                || ($receipt['tool'] ?? null) !== $operation->tool) {
                throw new InvalidArgumentException("Semantic operation {$operation->id} has no matching terminal receipt.");
            }
            $data = is_array($receipt['data'] ?? null) ? $receipt['data'] : [];
            $data['side_effect_committed'] = ($receipt['side_effect_committed'] ?? false) === true;

            return new HermesSemanticOperationResult(
                operationId: $operation->id,
                tool: $operation->tool,
                status: (string) ($receipt['status'] ?? 'failed'),
                data: $data,
            );
        }, $interpretation->operations);
    }

    private function approvedTextForCommand(VoiceRealtimeCommand $command): string
    {
        $instructions = (string) data_get($command->payload, 'response.instructions', '');
        if (preg_match('/<approved_response>(.*?)<\/approved_response>/s', $instructions, $match) !== 1) {
            return '';
        }

        return trim((string) $match[1]);
    }

    private function normalizedSpeech(string $text): string
    {
        $normalized = mb_strtolower($this->privacy->sanitizeTranscript($text));

        return preg_replace('/[^\pL\pN]+/u', '', $normalized) ?? '';
    }
}
