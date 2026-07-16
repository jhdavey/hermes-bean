<?php

namespace App\Services;

use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\HermesSemanticOperationException;
use App\Exceptions\VoiceTurnConflictException;
use App\Jobs\EnforceBrowserVoiceTurnDeadline;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeSession;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoiceTurnLifecycleService
{
    private const TURN_HARD_DEADLINE_SECONDS = 120;

    private const TURN_NO_PROGRESS_DEADLINE_SECONDS = 10;

    private const INTERPRETATION_HARD_DEADLINE_SECONDS = 2;

    private const APP_READ_HARD_DEADLINE_SECONDS = 4;

    private const APP_WRITE_HARD_DEADLINE_SECONDS = 6;

    private const EXTERNAL_HARD_DEADLINE_SECONDS = 8;

    public function __construct(
        private readonly VoiceTurnPrivacyService $privacy,
        private readonly BrowserVoiceContextAuthorizationService $contextAuthorization,
    ) {}

    /**
     * Durably reserve the stable voice turn before any activated PCM reaches
     * the conversational provider. No semantic text is accepted at this
     * boundary; Realtime Hermes supplies a structured plan through sideband.
     *
     * @param  array<string, mixed>  $input
     */
    public function preAdmitRealtime(
        User $user,
        ConversationSession $session,
        VoiceRealtimeSession $realtimeSession,
        array $input,
    ): VoiceTurn {
        $turnId = trim((string) ($input['turn_id'] ?? ''));
        $controllerGeneration = max(0, (int) ($input['controller_generation'] ?? 0));
        $providerGeneration = max(0, (int) ($input['provider_connection_generation'] ?? 0));
        $inputGeneration = max(0, (int) ($input['input_generation'] ?? 0));
        $conversationContext = [
            'mode' => data_get($input, 'conversation_context.mode') === 'contextual_follow_up'
                ? 'contextual_follow_up'
                : 'new_conversation',
            'epoch' => max(0, (int) data_get($input, 'conversation_context.epoch', 0)),
        ];
        $priorTurn = $this->contextAuthorization->priorTurn($user, $session, $input);
        $fingerprint = hash('sha256', json_encode([
            'session_id' => (int) $session->id,
            'realtime_session_id' => (string) $realtimeSession->public_id,
            'turn_id' => $turnId,
        ], JSON_THROW_ON_ERROR));
        $now = now();

        $result = DB::transaction(function () use (
            $user,
            $session,
            $realtimeSession,
            $input,
            $turnId,
            $controllerGeneration,
            $providerGeneration,
            $inputGeneration,
            $conversationContext,
            $priorTurn,
            $fingerprint,
            $now,
        ): array {
            ConversationSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $lockedRealtime = VoiceRealtimeSession::query()->whereKey($realtimeSession->id)->lockForUpdate()->firstOrFail();
            if ((int) $lockedRealtime->user_id !== (int) $user->id
                || (int) $lockedRealtime->conversation_session_id !== (int) $session->id
                || $lockedRealtime->status->value !== 'ready'
                || $lockedRealtime->lease_owner === null
                || $lockedRealtime->lease_expires_at === null
                || ! $lockedRealtime->lease_expires_at->isFuture()) {
                throw new VoiceTurnConflictException('That Realtime voice session is not active for this conversation.');
            }

            if (AssistantRun::query()
                ->where('conversation_session_id', $session->id)
                ->whereNull('voice_turn_id')
                ->where('client_request_id', $turnId)
                ->exists()) {
                throw new VoiceTurnConflictException('That stable turn ID is already owned by a chat request.');
            }

            $existing = VoiceTurn::query()->where('turn_id', $turnId)->lockForUpdate()->first();
            if ($existing instanceof VoiceTurn) {
                $sameOwner = (int) $existing->user_id === (int) $user->id
                    && (int) $existing->conversation_session_id === (int) $session->id
                    && (int) $existing->realtime_session_id === (int) $lockedRealtime->id;
                $sameAdmission = hash_equals(
                    (string) data_get($existing->metadata, 'pre_admission_fingerprint', ''),
                    $fingerprint,
                );
                if (! $sameOwner || ! $sameAdmission) {
                    throw new VoiceTurnConflictException('That stable turn ID belongs to a different voice admission.');
                }

                $existingMetadata = is_array($existing->metadata) ? $existing->metadata : [];
                $pendingGeneration = (int) ($existingMetadata['pending_input_generation'] ?? -1);
                $lastBoundGeneration = (int) ($existingMetadata['last_bound_input_generation'] ?? -1);
                $pending = ($existingMetadata['awaiting_provider_input'] ?? false) === true;
                if ($pending && $pendingGeneration !== $inputGeneration) {
                    throw new VoiceTurnConflictException('That stable turn already has a different activated input awaiting the provider.');
                }
                if (! $pending
                    && $inputGeneration !== $lastBoundGeneration
                    && $existing->state === VoiceTurnState::AwaitingClarification) {
                    $existing->forceFill([
                        'version' => $existing->version + 1,
                        'metadata' => [
                            ...$existingMetadata,
                            'awaiting_provider_input' => true,
                            'pending_input_generation' => $inputGeneration,
                            'pre_admission_reopened_at' => now()->toIso8601String(),
                        ],
                    ])->save();
                }

                $this->recordEventLocked(
                    $existing,
                    'pre_admission_deduplicated',
                    $existing->state,
                    $existing->state,
                    ['realtime_session_id' => $lockedRealtime->public_id],
                    'admission',
                );

                return ['turn' => $existing->refresh(), 'created' => false];
            }

            $pendingOtherTurn = VoiceTurn::query()
                ->where('realtime_session_id', $lockedRealtime->id)
                ->whereNull('provider_input_item_id')
                ->whereIn('state', [VoiceTurnState::Accepted->value, VoiceTurnState::AwaitingClarification->value])
                ->lockForUpdate()
                ->first();
            if ($pendingOtherTurn instanceof VoiceTurn) {
                throw new VoiceTurnConflictException('That voice session already has an activated turn awaiting provider audio.');
            }

            $turn = VoiceTurn::create([
                'turn_id' => $turnId,
                'user_id' => $user->id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'realtime_session_id' => $lockedRealtime->id,
                'source' => 'browser_voice_realtime',
                'client_kind' => 'browser_voice',
                'display_mode' => 'voice_only',
                'semantic_input' => null,
                'state' => VoiceTurnState::Accepted,
                'version' => 1,
                'idempotency_key' => $turnId,
                'acknowledgement_required' => false,
                'acknowledgement_text' => null,
                'accepted_at' => $now,
                'hard_deadline_at' => $now->copy()->addSeconds(self::TURN_HARD_DEADLINE_SECONDS),
                'no_progress_deadline_at' => $now->copy()->addSeconds(self::TURN_NO_PROGRESS_DEADLINE_SECONDS),
                'side_effect_status' => VoiceTurnSideEffectStatus::None,
                'metadata' => [
                    'origin' => 'spoken_voice',
                    'display_mode' => 'voice_only',
                    'pre_admission_fingerprint' => $fingerprint,
                    'controller_generation' => $controllerGeneration,
                    'provider_connection_generation' => $providerGeneration,
                    'input_generation' => $inputGeneration,
                    'conversation_context' => $conversationContext,
                    'client_context' => [
                        'voice_mode_active' => true,
                        'wake_detection_enabled' => true,
                        'playback_state' => 'unknown',
                    ],
                    'prior_context_authorized' => $priorTurn instanceof VoiceTurn,
                    'prior_turn_id' => $priorTurn?->turn_id,
                    'client_milestones' => $this->privacy->sanitizeDiagnosticPayload(
                        is_array($input['client_milestones'] ?? null) ? $input['client_milestones'] : [],
                    ),
                    'provider_input_item_ids' => [],
                    'awaiting_provider_input' => true,
                    'pending_input_generation' => $inputGeneration,
                    'last_bound_input_generation' => null,
                    'raw_audio_retained' => false,
                    'no_progress_interval_seconds' => self::TURN_NO_PROGRESS_DEADLINE_SECONDS,
                    'semantic_sequence' => 1,
                    'clarification_question' => null,
                    'clarification_sequence' => 0,
                    'clarification_resolutions' => [],
                    'semantic_clarification_history' => [],
                ],
            ]);

            $userMessage = ConversationMessage::firstOrCreate([
                'conversation_session_id' => $session->id,
                'client_turn_id' => $turnId,
                'role' => 'user',
            ], [
                'user_id' => $user->id,
                'origin' => 'spoken_voice',
                'display_mode' => 'voice_only',
                'content' => '',
                'metadata' => [
                    'source' => 'browser_voice_realtime',
                    'voice_turn_id' => $turn->id,
                    'stable_turn_id' => $turnId,
                    'origin' => 'spoken_voice',
                    'display_mode' => 'voice_only',
                ],
            ]);

            $turn->update(['user_message_id' => $userMessage->id]);
            $this->recordEventLocked($turn, 'turn_pre_admitted', null, $turn->state, [
                'realtime_session_id' => $lockedRealtime->public_id,
                'controller_generation' => $controllerGeneration,
                'provider_connection_generation' => $providerGeneration,
                'input_generation' => $inputGeneration,
                'sideband_ready' => $lockedRealtime->status->value === 'ready',
            ], 'admission');
            $session->update(['last_activity_at' => $now]);

            return ['turn' => $turn->refresh(), 'created' => true];
        }, 3);

        /** @var VoiceTurn $turn */
        $turn = $result['turn'];
        if ($result['created']) {
            $this->scheduleDeadlines($turn);
        }

        return $turn->load(['userMessage', 'finalAssistantMessage', 'runs']);
    }

    public function bindRealtimeInputItem(
        VoiceTurn $turn,
        VoiceRealtimeSession $realtimeSession,
        string $providerInputItemId,
        ?string $providerEventId = null,
    ): VoiceTurn {
        $providerInputItemId = trim($providerInputItemId);
        if ($providerInputItemId === '') {
            throw new VoiceTurnConflictException('A provider input item ID is required.');
        }

        return DB::transaction(function () use ($turn, $realtimeSession, $providerInputItemId, $providerEventId): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ((int) $locked->realtime_session_id !== (int) $realtimeSession->id || $locked->state->isTerminal()) {
                throw new VoiceTurnConflictException('That provider input item does not belong to the active voice turn.');
            }
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $itemIds = array_values(array_unique(array_filter(array_map(
                'strval',
                is_array($metadata['provider_input_item_ids'] ?? null) ? $metadata['provider_input_item_ids'] : [],
            ))));
            if (in_array($providerInputItemId, $itemIds, true)) {
                $this->recordEventLocked($locked, 'provider_input_item_deduplicated', $locked->state, $locked->state, [
                    'provider_input_item_id' => $providerInputItemId,
                    'provider_event_id' => $providerEventId,
                ], 'realtime_sideband');

                return $locked->refresh();
            }
            $itemIds[] = $providerInputItemId;
            $inputGeneration = (int) ($metadata['pending_input_generation'] ?? 0);
            $locked->forceFill([
                'provider_input_item_id' => $locked->provider_input_item_id ?: $providerInputItemId,
                'version' => $locked->version + 1,
                'metadata' => [
                    ...$metadata,
                    'provider_input_item_ids' => $itemIds,
                    'awaiting_provider_input' => false,
                    'last_bound_input_generation' => $inputGeneration,
                ],
            ])->save();
            $this->recordEventLocked($locked, 'provider_input_item_bound', $locked->state, $locked->state, [
                'provider_input_item_id' => $providerInputItemId,
                'provider_event_id' => $providerEventId,
            ], 'realtime_sideband');

            return $locked->refresh();
        }, 3);
    }

    /**
     * Cancel a speculative pre-admission that never crossed the provider
     * audio boundary. This is deliberately narrower than normal cancellation:
     * once an input item or run exists, the ordinary terminal lifecycle owns
     * the request and must produce its contractually required outcome.
     */
    public function abandonPendingRealtimeInput(
        VoiceTurn $turn,
        string $reason = 'activated_audio_abandoned',
    ): VoiceTurn {
        return DB::transaction(function () use ($turn, $reason): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state === VoiceTurnState::Canceled) {
                return $locked->refresh();
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $providerItemIds = is_array($metadata['provider_input_item_ids'] ?? null)
                ? array_values(array_filter($metadata['provider_input_item_ids']))
                : [];
            if ($locked->state !== VoiceTurnState::Accepted
                || ($metadata['awaiting_provider_input'] ?? false) !== true
                || $locked->provider_input_item_id !== null
                || $providerItemIds !== []
                || AssistantRun::query()->where('voice_turn_id', $locked->id)->exists()) {
                throw new VoiceTurnConflictException(
                    'Only a pre-admitted voice turn with no provider input or durable work may be abandoned.',
                );
            }

            return $this->terminalize(
                $locked,
                VoiceTurnState::Canceled,
                null,
                VoiceTurnSideEffectStatus::None,
                null,
                null,
                [
                    'reason' => trim($reason) ?: 'activated_audio_abandoned',
                    'abandoned_before_provider_input' => true,
                ],
            );
        }, 3);
    }

    /**
     * Accept only the sanitized semantic summary accompanying a validated
     * Realtime Hermes plan. This is not a transcript and cannot execute work.
     */
    public function prepareRealtimeInterpretation(
        VoiceTurn $turn,
        string $semanticInput,
        string $providerInputItemId,
        string $providerResponseId,
    ): AssistantRun {
        $semanticInput = $this->privacy->sanitizeTranscript($semanticInput);
        if ($semanticInput === '') {
            throw new VoiceTurnConflictException('Realtime Hermes must provide a non-empty semantic summary.');
        }

        $prepared = DB::transaction(function () use ($turn, $semanticInput, $providerInputItemId, $providerResponseId): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state->isTerminal()) {
                throw new VoiceTurnConflictException('A terminal voice turn cannot accept another semantic plan.');
            }
            $itemIds = is_array(data_get($locked->metadata, 'provider_input_item_ids'))
                ? data_get($locked->metadata, 'provider_input_item_ids')
                : [];
            if (! in_array($providerInputItemId, $itemIds, true)) {
                throw new VoiceTurnConflictException('The Realtime semantic plan is not bound to this voice turn input.');
            }

            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $semanticSequence = max(1, (int) ($metadata['semantic_sequence'] ?? 1));
            if ($locked->state === VoiceTurnState::AwaitingClarification) {
                $semanticSequence++;
                $history = is_array($metadata['semantic_clarification_history'] ?? null)
                    ? $metadata['semantic_clarification_history']
                    : [];
                $history[] = [
                    'sequence' => $semanticSequence,
                    'question' => $metadata['clarification_question'] ?? null,
                    'resolved_by_input_item_id' => $providerInputItemId,
                ];
                $metadata['semantic_clarification_history'] = $history;
                $metadata['clarification_question'] = null;
                $locked->state = VoiceTurnState::Accepted;
                $locked->started_at = null;
            }
            $metadata['semantic_sequence'] = $semanticSequence;
            $metadata['latest_provider_response_id'] = $providerResponseId;
            $metadata['semantic_input_kind'] = 'provider_summary';
            $locked->forceFill([
                'semantic_input' => $semanticInput,
                'version' => $locked->version + 1,
                'metadata' => $metadata,
            ])->save();

            ConversationMessage::query()->whereKey($locked->user_message_id)->update([
                'content' => $semanticInput,
                'origin' => 'spoken_voice',
                'display_mode' => 'voice_only',
            ]);
            $this->recordEventLocked($locked, 'realtime_semantic_plan_received', $locked->state, $locked->state, [
                'provider_input_item_id' => $providerInputItemId,
                'provider_response_id' => $providerResponseId,
                'semantic_sequence' => $semanticSequence,
            ], 'realtime_sideband');

            return $locked->refresh();
        }, 3);

        return $this->ensureSemanticInterpretationJob($prepared);
    }

    /**
     * Suspend the stable turn when Hermes determines that meaning is missing.
     * The semantic run records the question; deterministic code owns only the
     * durable pause, deadline clearing, and exactly-once resume boundary.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function requestSemanticClarification(AssistantRun $run, string $question, array $metadata = []): VoiceTurn
    {
        if (trim($question) === '') {
            throw new VoiceTurnConflictException('A semantic clarification requires a question.');
        }

        $turn = DB::transaction(function () use ($run, $question, $metadata): VoiceTurn {
            $voiceTurnId = (int) ($run->voice_turn_id ?? 0);
            if ($voiceTurnId <= 0) {
                throw new VoiceTurnConflictException('Only Realtime browser voice semantic runs can request clarification.');
            }

            $lockedTurn = VoiceTurn::query()->whereKey($voiceTurnId)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedRun->handler !== HermesSemanticOperationExecutor::INTERPRETATION_HANDLER) {
                throw new VoiceTurnConflictException('Only the semantic interpretation run can request clarification.');
            }
            if ($lockedTurn->state->isTerminal()) {
                return $lockedTurn->refresh();
            }

            $turnMetadata = is_array($lockedTurn->metadata) ? $lockedTurn->metadata : [];
            $currentSemanticSequence = max(1, (int) ($turnMetadata['semantic_sequence'] ?? 1));
            $runSemanticSequence = max(0, (int) data_get($lockedRun->metadata, 'semantic_sequence', 0));
            if ($lockedTurn->state === VoiceTurnState::AwaitingClarification) {
                if ((int) ($turnMetadata['semantic_awaiting_run_id'] ?? 0) === (int) $lockedRun->id
                    && $runSemanticSequence === $currentSemanticSequence) {
                    return $lockedTurn->refresh();
                }

                throw new VoiceTurnConflictException('That voice request is already waiting on another semantic clarification.');
            }
            if ($runSemanticSequence !== $currentSemanticSequence) {
                throw new VoiceTurnConflictException('That semantic run is stale for the current voice request sequence.');
            }
            if (! in_array($lockedTurn->state, [VoiceTurnState::Accepted, VoiceTurnState::Running], true)
                || ! in_array($lockedRun->status, ['running', 'finalizing'], true)) {
                throw new VoiceTurnConflictException('That semantic run is not active for clarification.');
            }

            $safeMetadata = $this->privacy->sanitizeDiagnosticPayload($metadata);
            $runMetadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $clarificationSequence = max(0, (int) ($turnMetadata['clarification_sequence'] ?? 0)) + 1;
            $from = $lockedTurn->state;
            $now = now();
            $promptDeliveryDeadline = $now->copy()->addSeconds(30);
            $lockedRun->update([
                'status' => 'completed',
                'result' => [
                    'status' => 'awaiting_clarification',
                    'voice_turn_id' => $lockedTurn->id,
                    'question' => $question,
                    'metadata' => $safeMetadata,
                ],
                'metadata' => [
                    ...$runMetadata,
                    'required' => false,
                    'role' => 'semantic_interpretation',
                    'clarification_requested' => true,
                ],
                'completed_at' => $now,
                'last_progress_at' => $now,
            ]);
            $lockedTurn->forceFill([
                'state' => VoiceTurnState::AwaitingClarification,
                'version' => $lockedTurn->version + 1,
                'hard_deadline_at' => $promptDeliveryDeadline,
                'no_progress_deadline_at' => null,
                'metadata' => [
                    ...$turnMetadata,
                    'clarification_question' => $question,
                    'clarification_sequence' => $clarificationSequence,
                    'clarification_deadline_started_at' => null,
                    'clarification_prompt_delivery_deadline_at' => $promptDeliveryDeadline->toIso8601String(),
                    'semantic_awaiting_run_id' => $lockedRun->id,
                    'semantic_clarification_metadata' => $safeMetadata,
                ],
            ])->saveQuietly();
            $this->recordEventLocked(
                $lockedTurn,
                'clarification_requested',
                $from,
                VoiceTurnState::AwaitingClarification,
                [
                    ...$safeMetadata,
                    'question' => $question,
                    'clarification_sequence' => $clarificationSequence,
                    'run_id' => $lockedRun->id,
                    'semantic_sequence' => $runSemanticSequence,
                ],
                'semantic_interpreter',
            );

            return $lockedTurn->refresh();
        }, 3);

        if ($turn->state === VoiceTurnState::AwaitingClarification && $turn->hard_deadline_at !== null) {
            $this->scheduleDeadlines($turn);
        }

        return $turn->load(['userMessage', 'finalAssistantMessage', 'runs']);
    }

    public function startClarificationDeadline(VoiceTurn $turn, int $seconds = 5): VoiceTurn
    {
        $updated = DB::transaction(function () use ($turn, $seconds): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== VoiceTurnState::AwaitingClarification) {
                return $locked;
            }
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            if (filled($metadata['clarification_deadline_started_at'] ?? null)) {
                return $locked;
            }
            $deadline = now()->addSeconds(max(1, $seconds));
            $locked->forceFill([
                'version' => $locked->version + 1,
                'hard_deadline_at' => $deadline,
                'metadata' => [
                    ...$metadata,
                    'clarification_deadline_started_at' => now()->toIso8601String(),
                ],
            ])->saveQuietly();
            $this->recordEventLocked($locked, 'clarification_deadline_started', $locked->state, $locked->state, [
                'deadline_at' => $deadline->toIso8601String(),
            ], 'browser');

            return $locked->refresh();
        }, 3);

        if ($updated->state === VoiceTurnState::AwaitingClarification && $updated->hard_deadline_at !== null) {
            $this->dispatchDeadlineEnforcement($updated->id, $updated->hard_deadline_at);
        }

        return $updated;
    }

    /** @param array<string, mixed> $payload */
    public function markProgress(VoiceTurn $turn, array $payload = [], string $source = 'worker'): VoiceTurn
    {
        $progressed = DB::transaction(function () use ($turn, $payload, $source): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state->isTerminal()) {
                return $locked;
            }

            $from = $locked->state;
            $progressedAt = now();
            $interval = (int) data_get($locked->metadata, 'no_progress_interval_seconds', 0);
            $updates = [
                'version' => $locked->version + 1,
                'first_progress_at' => $locked->first_progress_at ?? $progressedAt,
                'no_progress_deadline_at' => $interval > 0 ? $progressedAt->copy()->addSeconds($interval) : null,
                'metadata' => [
                    ...(is_array($locked->metadata) ? $locked->metadata : []),
                    'last_progress_at' => $progressedAt->toIso8601String(),
                ],
            ];
            if ($from === VoiceTurnState::Accepted) {
                $updates['state'] = VoiceTurnState::Running;
                $updates['started_at'] = $locked->started_at ?? now();
            }
            $locked->update($updates);
            $this->recordEventLocked($locked, 'progress_recorded', $from, $locked->state, $payload, $source);

            return $locked->refresh();
        }, 3);

        if (! $progressed->state->isTerminal() && $progressed->no_progress_deadline_at !== null) {
            $this->dispatchDeadlineEnforcement($progressed->id, $progressed->no_progress_deadline_at);
        }

        return $progressed;
    }

    /** @param array<string, mixed> $payload */
    public function markRetryAttempt(VoiceTurn $turn, array $payload = [], string $source = 'worker'): VoiceTurn
    {
        return DB::transaction(function () use ($turn, $payload, $source): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state->isTerminal() || $locked->retry_count >= 1) {
                return $locked;
            }

            $locked->update([
                'retry_count' => $locked->retry_count + 1,
                'version' => $locked->version + 1,
            ]);
            $this->recordEventLocked($locked, 'retry_started', $locked->state, $locked->state, $payload, $source);

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function markAcknowledged(VoiceTurn $turn, array $payload = [], string $source = 'browser'): VoiceTurn
    {
        return $this->recordTimestampOnce(
            $turn,
            'acknowledged_at',
            'acknowledgement_started',
            $payload,
            $source,
            function (VoiceTurn $locked): void {
                if (! $locked->acknowledgement_required
                    || blank($locked->acknowledgement_text)
                    || ! in_array($locked->state, [
                        VoiceTurnState::Accepted,
                        VoiceTurnState::Running,
                        VoiceTurnState::Completed,
                    ], true)) {
                    throw new VoiceTurnConflictException('The acknowledgement is not eligible for playback.');
                }
            },
        );
    }

    /** @param array<string, mixed> $payload */
    public function markFinalAudioStarted(
        VoiceTurn $turn,
        string $eventType,
        array $payload = [],
        string $source = 'browser',
    ): VoiceTurn {
        if (! in_array($eventType, ['final_audio_started', 'playback_started'], true)
            || ($eventType === 'playback_started' && data_get($payload, 'purpose') !== 'final')) {
            throw new VoiceTurnConflictException('A final-audio marker requires a final playback event.');
        }

        return DB::transaction(function () use ($turn, $eventType, $payload, $source): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if (VoiceTurnEvent::query()
                ->where('voice_turn_id', $locked->id)
                ->finalAudioStarted()
                ->exists()) {
                return $locked;
            }

            $this->assertDurableFinalReady($locked, 'audio');

            $this->recordEventLocked(
                $locked,
                $eventType,
                $locked->state,
                $locked->state,
                $payload,
                $source,
            );

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function recordBrowserEvent(VoiceTurn $turn, string $eventType, array $payload = []): VoiceTurn
    {
        if ($eventType === 'playback_started' && data_get($payload, 'purpose') === 'final') {
            return $this->markFinalAudioStarted($turn, $eventType, $payload);
        }
        if ($eventType === 'playback_started' && data_get($payload, 'purpose') === 'acknowledgement') {
            return $this->markAcknowledged($turn, $payload);
        }

        return DB::transaction(function () use ($turn, $eventType, $payload): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $this->assertBrowserPlaybackEventEligible($locked, $eventType, $payload);
            $this->recordEventLocked($locked, $eventType, $locked->state, $locked->state, $payload, 'browser');

            return $locked->refresh();
        }, 3);
    }

    /** @param array<string, mixed> $payload */
    public function recordSemanticEvent(VoiceTurn $turn, string $eventType, array $payload = []): VoiceTurn
    {
        $eventType = trim($eventType);
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,99}$/', $eventType) !== 1) {
            throw new VoiceTurnConflictException('A semantic diagnostic event requires a valid event type.');
        }

        return DB::transaction(function () use ($turn, $eventType, $payload): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $this->recordEventLocked(
                $locked,
                $eventType,
                $locked->state,
                $locked->state,
                $payload,
                'semantic_interpreter',
            );

            return $locked->refresh();
        }, 3);
    }

    public function publishSemanticResponseDirectives(
        AssistantRun $run,
        bool $closeAfterResponse,
        bool $responseExpected,
    ): VoiceTurn {
        $turn = DB::transaction(function () use ($run, $closeAfterResponse, $responseExpected): VoiceTurn {
            $voiceTurnId = (int) ($run->voice_turn_id ?? 0);
            if ($voiceTurnId <= 0) {
                throw new VoiceTurnConflictException('Only Realtime browser voice semantic runs can publish response directives.');
            }

            $lockedTurn = VoiceTurn::query()->whereKey($voiceTurnId)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();
            if (! in_array($lockedRun->handler, [
                HermesSemanticOperationExecutor::INTERPRETATION_HANDLER,
                HermesSemanticOperationExecutor::COMPOSITION_HANDLER,
            ], true)) {
                throw new VoiceTurnConflictException('Only a semantic interpretation or composition run can publish response directives.');
            }

            $turnMetadata = is_array($lockedTurn->metadata) ? $lockedTurn->metadata : [];
            $currentSemanticSequence = max(1, (int) ($turnMetadata['semantic_sequence'] ?? 1));
            $runSemanticSequence = max(0, (int) data_get($lockedRun->metadata, 'semantic_sequence', 0));
            if ($lockedTurn->state->isTerminal()
                || $lockedTurn->state === VoiceTurnState::AwaitingClarification
                || $runSemanticSequence !== $currentSemanticSequence
                || ! in_array($lockedRun->status, ['running', 'finalizing'], true)) {
                return $lockedTurn->refresh();
            }

            $directives = is_array($turnMetadata['response_directives'] ?? null)
                ? $turnMetadata['response_directives']
                : [];
            $publishedSequence = max(0, (int) ($directives['semantic_sequence'] ?? 0));
            if ($publishedSequence === $runSemanticSequence
                && array_key_exists('close_after_response', $directives)
                && array_key_exists('response_expected', $directives)) {
                if ((bool) $directives['close_after_response'] !== $closeAfterResponse
                    || (bool) $directives['response_expected'] !== $responseExpected) {
                    throw new VoiceTurnConflictException('That semantic run already published different response directives.');
                }

                return $lockedTurn->refresh();
            }
            if ($publishedSequence > $runSemanticSequence) {
                return $lockedTurn->refresh();
            }

            $lockedTurn->update([
                'version' => $lockedTurn->version + 1,
                'metadata' => [
                    ...$turnMetadata,
                    'response_directives' => [
                        ...$directives,
                        'close_after_response' => $closeAfterResponse,
                        'response_expected' => $responseExpected,
                        'semantic_sequence' => $runSemanticSequence,
                        'run_id' => $lockedRun->id,
                    ],
                ],
            ]);
            $this->recordEventLocked(
                $lockedTurn,
                'semantic_response_directives_published',
                $lockedTurn->state,
                $lockedTurn->state,
                [
                    'run_id' => $lockedRun->id,
                    'semantic_sequence' => $runSemanticSequence,
                    'close_after_response' => $closeAfterResponse,
                    'response_expected' => $responseExpected,
                ],
                'semantic_interpreter',
            );

            return $lockedTurn->refresh();
        }, 3);

        return $turn->load(['userMessage', 'finalAssistantMessage', 'runs']);
    }

    /** @param array<string,mixed> $timing */
    public function acknowledgePlaybackStopDirective(
        VoiceTurn $turn,
        string $directiveId,
        array $timing = [],
    ): VoiceTurn {
        $directiveId = trim($directiveId);
        if ($directiveId === '') {
            throw new VoiceTurnConflictException('A playback Stop acknowledgement requires its directive id.');
        }

        return DB::transaction(function () use ($turn, $directiveId, $timing): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $directive = is_array($metadata['playback_stop_directive'] ?? null)
                ? $metadata['playback_stop_directive']
                : null;
            if (! is_array($directive) || ! hash_equals((string) ($directive['id'] ?? ''), $directiveId)) {
                throw new VoiceTurnConflictException('That playback Stop directive is not active for this turn.');
            }
            if (filled($directive['acknowledged_at'] ?? null)) {
                return $locked->refresh();
            }

            $directive['acknowledged_at'] = now()->toIso8601String();
            $locked->update([
                'version' => $locked->version + 1,
                'metadata' => [...$metadata, 'playback_stop_directive' => $directive],
            ]);
            $this->recordEventLocked(
                $locked,
                'playback_stopped',
                $locked->state,
                $locked->state,
                [
                    ...$this->privacy->sanitizeDiagnosticPayload($timing),
                    'directive_id' => $directiveId,
                ],
                'browser',
            );
            $this->recordEventLocked(
                $locked,
                'playback_stop_directive_acknowledged',
                $locked->state,
                $locked->state,
                [
                    ...$this->privacy->sanitizeDiagnosticPayload($timing),
                    'directive_id' => $directiveId,
                ],
                'browser',
            );

            return $locked->refresh();
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function complete(
        VoiceTurn $turn,
        string $finalText,
        VoiceTurnSideEffectStatus $sideEffectStatus = VoiceTurnSideEffectStatus::None,
        array $metadata = [],
    ): VoiceTurn {
        return $this->terminalize($turn, VoiceTurnState::Completed, $finalText, $sideEffectStatus, null, null, $metadata);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function fail(
        VoiceTurn $turn,
        string $category,
        string $internalDetail,
        string $userFacingText,
        VoiceTurnSideEffectStatus $sideEffectStatus = VoiceTurnSideEffectStatus::NotCommitted,
        array $metadata = [],
    ): VoiceTurn {
        return $this->terminalize(
            $turn,
            VoiceTurnState::Failed,
            $userFacingText,
            $sideEffectStatus,
            $category,
            $internalDetail,
            $metadata,
        );
    }

    /**
     * Fail a browser voice turn only while it still has no semantic or typed
     * work. The turn lock makes this boundary mutually exclusive with
     * prepareRealtimeInterpretation(), so a late client transport failure can
     * never overwrite a plan that Laravel has already accepted.
     *
     * @param  array<string, mixed>  $metadata
     * @return array{status: 'already_terminal'|'work_started'|'terminalized', turn: VoiceTurn}
     */
    public function failBeforeSemanticWork(
        VoiceTurn $turn,
        string $category,
        string $internalDetail,
        string $userFacingText,
        array $metadata = [],
    ): array {
        return DB::transaction(function () use (
            $turn,
            $category,
            $internalDetail,
            $userFacingText,
            $metadata,
        ): array {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state->isTerminal()) {
                return ['status' => 'already_terminal', 'turn' => $locked->refresh()];
            }
            if (AssistantRun::query()->where('voice_turn_id', $locked->id)->exists()) {
                return ['status' => 'work_started', 'turn' => $locked->refresh()];
            }

            return [
                'status' => 'terminalized',
                'turn' => $this->fail(
                    $locked,
                    $category,
                    $internalDetail,
                    $userFacingText,
                    VoiceTurnSideEffectStatus::None,
                    $metadata,
                ),
            ];
        }, 3);
    }

    /** @param array<string, mixed> $metadata */
    public function cancel(VoiceTurn $turn, string $reason = 'user_requested', array $metadata = []): VoiceTurn
    {
        return $this->terminalize(
            $turn,
            VoiceTurnState::Canceled,
            null,
            $turn->side_effect_status,
            null,
            null,
            [...$metadata, 'reason' => $reason],
        );
    }

    /** @param array<string, mixed> $metadata */
    public function cancelJob(AssistantRun $run, string $reason = 'user_requested', array $metadata = []): VoiceTurn
    {
        return DB::transaction(function () use ($run, $reason, $metadata): VoiceTurn {
            $voiceTurnId = (int) ($run->voice_turn_id ?? 0);
            if ($voiceTurnId <= 0) {
                throw new VoiceTurnConflictException('Only Realtime browser voice jobs can be canceled through this lifecycle.');
            }

            $lockedTurn = VoiceTurn::query()->whereKey($voiceTurnId)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();

            // Re-read the semantic receipt under the same turn/run locks so a
            // cancellation cannot overwrite an already committed operation.
            $receipt = $this->semanticOperationReceipt($lockedRun);
            if (is_array($receipt) && ($receipt['side_effect_committed'] ?? false) === true) {
                return $this->finishJob(
                    $lockedRun,
                    'completed',
                    finalText: $this->semanticOperationSummary($lockedRun, $receipt),
                    sideEffectStatus: VoiceTurnSideEffectStatus::Committed,
                    metadata: [
                        ...$metadata,
                        'reason' => $reason,
                        'cancellation_requested_after_commit' => true,
                        'semantic_operation_receipt' => $receipt,
                    ],
                );
            }

            return $this->finishJob(
                $lockedRun,
                'cancelled',
                metadata: [...$metadata, 'reason' => $reason],
            );
        }, 3);
    }

    /**
     * Persist one required job outcome and atomically evaluate the turn barrier.
     * The turn receives its sole final response only after every required run is
     * terminal. Duplicate workers re-evaluate the same barrier without creating
     * another message or regressing a run.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function finishJob(
        AssistantRun $run,
        string $status,
        ?string $finalText = null,
        ?string $failureCategory = null,
        ?string $internalDetail = null,
        ?string $userFacingFailure = null,
        VoiceTurnSideEffectStatus $sideEffectStatus = VoiceTurnSideEffectStatus::None,
        array $metadata = [],
    ): VoiceTurn {
        if (! in_array($status, ['completed', 'failed', 'cancelled'], true)) {
            throw new VoiceTurnConflictException('A voice job barrier outcome must be completed, failed, or cancelled.');
        }
        if ($status === 'completed' && trim((string) $finalText) === '') {
            throw new VoiceTurnConflictException('A completed voice job requires a result for the combined final response.');
        }

        return DB::transaction(function () use ($run, $status, $finalText, $failureCategory, $internalDetail, $userFacingFailure, $sideEffectStatus, $metadata): VoiceTurn {
            $voiceTurnId = (int) ($run->voice_turn_id ?? 0);
            if ($voiceTurnId <= 0) {
                throw new VoiceTurnConflictException('Only Realtime browser voice runs participate in the turn finalizer barrier.');
            }

            $lockedTurn = VoiceTurn::query()->whereKey($voiceTurnId)->lockForUpdate()->firstOrFail();
            /** @var Collection<int, AssistantRun> $runs */
            $runs = AssistantRun::query()
                ->where('voice_turn_id', $lockedTurn->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $lockedRun = $runs->firstWhere('id', $run->id);
            if (! $lockedRun instanceof AssistantRun) {
                throw new VoiceTurnConflictException('The voice job does not belong to that turn.');
            }

            if ($lockedRun->handler === HermesSemanticOperationExecutor::OPERATION_HANDLER) {
                $receipt = $this->semanticOperationReceipt($lockedRun);
                if (! is_array($receipt) && is_array($metadata['semantic_operation_receipt'] ?? null)) {
                    $receipt = $this->privacy->sanitizeDiagnosticPayload($metadata['semantic_operation_receipt']);
                    $runMetadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
                    $lockedRun->update(['metadata' => [...$runMetadata, 'semantic_operation_receipt' => $receipt]]);
                }
                if (is_array($receipt)) {
                    $receiptStatus = (string) ($receipt['status'] ?? 'failed');
                    $status = match ($receiptStatus) {
                        'completed', 'skipped' => 'completed',
                        'canceled' => 'cancelled',
                        'failed' => data_get($receipt, 'data.failure_scope') === 'operation'
                            ? 'completed'
                            : 'failed',
                        default => 'failed',
                    };
                    $finalText = trim((string) $finalText) !== ''
                        ? $finalText
                        : $this->semanticOperationSummary($lockedRun, $receipt);
                    $failureCategory = $status === 'failed'
                        ? (trim((string) data_get($receipt, 'data.category')) ?: ($failureCategory ?: 'semantic_operation_failed'))
                        : null;
                    $internalDetail = $status === 'failed'
                        ? (trim((string) data_get($receipt, 'data.internal_detail')) ?: $internalDetail)
                        : null;
                    $userFacingFailure = $status === 'failed' ? $userFacingFailure : null;
                    $sideEffectStatus = ($receipt['side_effect_committed'] ?? false) === true
                        ? VoiceTurnSideEffectStatus::Committed
                        : ($receiptStatus === 'failed'
                            ? VoiceTurnSideEffectStatus::NotCommitted
                            : VoiceTurnSideEffectStatus::None);
                    $metadata = [...$metadata, 'semantic_operation_receipt' => $receipt];
                }
            }

            if ($lockedTurn->state->isTerminal()) {
                $this->recordEventLocked($lockedTurn, 'job_barrier_deduplicated', $lockedTurn->state, $lockedTurn->state, [
                    'run_id' => $lockedRun->id,
                    'run_status' => $lockedRun->status,
                    'reason' => 'turn_already_terminal',
                ], 'finalizer');

                return $lockedTurn->refresh()->load(['userMessage', 'finalAssistantMessage', 'runs']);
            }

            if (! in_array($lockedRun->status, ['completed', 'failed', 'cancelled'], true)) {
                $safeInternalDetail = $internalDetail === null ? null : $this->privacy->sanitizeTranscript($internalDetail);
                $lockedRun->update([
                    'status' => $status,
                    'result' => [
                        'status' => $status,
                        'voice_turn_id' => $lockedTurn->id,
                        'final_text' => $finalText,
                        'failure_category' => $failureCategory,
                        'user_facing_failure' => $userFacingFailure,
                        'side_effect_status' => $sideEffectStatus->value,
                        'metadata' => $this->privacy->sanitizeDiagnosticPayload($metadata),
                    ],
                    'error' => $status === 'failed' ? $safeInternalDetail : null,
                    'cancelled_at' => $status === 'cancelled' ? now() : null,
                    'completed_at' => now(),
                    'last_progress_at' => now(),
                ]);
                $this->recordEventLocked($lockedTurn, match ($status) {
                    'completed' => 'job_completed',
                    'failed' => 'job_failed',
                    default => 'job_canceled',
                }, $lockedTurn->state, $lockedTurn->state, [
                    ...$metadata,
                    'run_id' => $lockedRun->id,
                    'label' => $lockedRun->label,
                    'status' => $status,
                    'side_effect_status' => $sideEffectStatus->value,
                ], 'finalizer');
                if ($this->sideEffectRank($sideEffectStatus) > $this->sideEffectRank($lockedTurn->side_effect_status)) {
                    $lockedTurn->update(['side_effect_status' => $sideEffectStatus]);
                }
            } else {
                $this->recordEventLocked($lockedTurn, 'job_barrier_deduplicated', $lockedTurn->state, $lockedTurn->state, [
                    'run_id' => $lockedRun->id,
                    'run_status' => $lockedRun->status,
                ], 'finalizer');
            }

            /** @var Collection<int, AssistantRun> $requiredRuns */
            $requiredRuns = AssistantRun::query()
                ->where('voice_turn_id', $lockedTurn->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->filter(fn (AssistantRun $candidate): bool => data_get($candidate->metadata, 'required', true) !== false)
                ->values();
            $pending = $requiredRuns->filter(
                fn (AssistantRun $candidate): bool => ! in_array($candidate->status, ['completed', 'failed', 'cancelled'], true),
            );
            if ($pending->isNotEmpty()) {
                if ($lockedTurn->state === VoiceTurnState::Accepted) {
                    $lockedTurn->update([
                        'state' => VoiceTurnState::Running,
                        'version' => $lockedTurn->version + 1,
                        'started_at' => $lockedTurn->started_at ?? now(),
                    ]);
                }
                $this->recordEventLocked($lockedTurn, 'job_barrier_waiting', $lockedTurn->state, $lockedTurn->state, [
                    'completed_run_id' => $lockedRun->id,
                    'pending_run_ids' => $pending->pluck('id')->all(),
                    'required_run_count' => $requiredRuns->count(),
                ], 'finalizer');

                return $lockedTurn->refresh()->load(['userMessage', 'finalAssistantMessage', 'runs']);
            }

            $failedRuns = $requiredRuns->where('status', 'failed')->values();
            $canceledRuns = $requiredRuns->where('status', 'cancelled')->values();
            $aggregateSideEffect = $this->aggregateJobSideEffectStatus($requiredRuns);
            $barrierMetadata = [
                ...$metadata,
                'barrier_run_ids' => $requiredRuns->pluck('id')->all(),
                'barrier_run_statuses' => $requiredRuns->mapWithKeys(
                    fn (AssistantRun $candidate): array => [(string) $candidate->id => $candidate->status],
                )->all(),
            ];
            $compositionRun = $requiredRuns->first(
                fn (AssistantRun $candidate): bool => data_get($candidate->metadata, 'role') === 'semantic_composition',
            );
            $compositionText = $compositionRun instanceof AssistantRun && $compositionRun->status === 'completed'
                ? (string) data_get($compositionRun->result, 'final_text')
                : '';
            $directResponseRun = $compositionRun === null && $requiredRuns->count() === 1
                ? $requiredRuns->first()
                : null;
            $directResponseText = $directResponseRun instanceof AssistantRun
                && $directResponseRun->handler === HermesSemanticOperationExecutor::INTERPRETATION_HANDLER
                && $directResponseRun->status === 'completed'
                    ? (string) data_get($directResponseRun->result, 'final_text')
                    : '';
            $hermesFinalText = trim($compositionText) !== '' ? $compositionText : $directResponseText;

            if ($failedRuns->isNotEmpty()) {
                $usageLimitText = $failedRuns->count() === 1
                    && data_get($failedRuns->first()?->result, 'failure_category') === 'semantic_usage_limit'
                        ? trim((string) data_get($failedRuns->first()?->result, 'user_facing_failure'))
                        : '';

                return $this->fail(
                    $lockedTurn,
                    $failedRuns->count() === 1
                        ? ((string) data_get($failedRuns->first()?->result, 'failure_category', '') ?: 'required_job_failed')
                        : 'required_jobs_failed',
                    $this->combinedJobFailureDetail($failedRuns),
                    trim($hermesFinalText) !== ''
                        ? $hermesFinalText
                        : ($usageLimitText !== ''
                            ? $usageLimitText
                            : AssistantRunService::SYSTEM_FAILURE_FINAL),
                    $aggregateSideEffect,
                    $barrierMetadata,
                );
            }

            if ($canceledRuns->isNotEmpty()) {
                if ($aggregateSideEffect !== VoiceTurnSideEffectStatus::None) {
                    $lockedTurn->update(['side_effect_status' => $aggregateSideEffect]);
                }

                return $this->cancel($lockedTurn, 'required_job_canceled', $barrierMetadata);
            }

            if (trim($hermesFinalText) === '') {
                return $this->fail(
                    $lockedTurn,
                    'semantic_composition_missing',
                    'Hermes completed the execution plan without a valid composition response.',
                    AssistantRunService::SYSTEM_FAILURE_FINAL,
                    $aggregateSideEffect,
                    $barrierMetadata,
                );
            }

            return $this->complete($lockedTurn, $hermesFinalText, $aggregateSideEffect, $barrierMetadata);
        }, 3);
    }

    /**
     * Atomically seal one Hermes execution plan and materialize its durable
     * operation and composition runs. The lifecycle owns run identity and
     * dependency order; the semantic executor supplies only validated specs.
     *
     * @param  list<array{
     *     id:string,
     *     tool:string,
     *     operation:array<string,mixed>,
     *     lane:string,
     *     label:string,
     *     priority:int,
     *     resource_lock_key:?string
     * }>  $operationSpecs
     * @param  array<string,mixed>  $interpretation
     * @return array{operation_runs:list<AssistantRun>,composition_run:AssistantRun,created_runs:list<AssistantRun>}
     */
    public function stageSemanticExecution(
        VoiceTurn $turn,
        AssistantRun $interpretationRun,
        array $operationSpecs,
        array $interpretation,
    ): array {
        if ($operationSpecs === []) {
            throw new VoiceTurnConflictException('A semantic execution plan requires at least one operation.');
        }

        $result = DB::transaction(function () use ($turn, $interpretationRun, $operationSpecs, $interpretation): array {
            $lockedTurn = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $lockedInterpretation = AssistantRun::query()
                ->whereKey($interpretationRun->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($lockedTurn->state->isTerminal()
                || $lockedInterpretation->handler !== HermesSemanticOperationExecutor::INTERPRETATION_HANDLER
                || ! in_array($lockedInterpretation->status, ['running', 'finalizing'], true)) {
                throw new VoiceTurnConflictException('Only the active semantic interpretation run may stage execution.');
            }
            $this->assertInterpretationDeadlineActive($lockedInterpretation);

            $sequence = max(1, (int) data_get($lockedInterpretation->metadata, 'semantic_sequence', 1));
            $planHash = hash('sha256', json_encode($interpretation, JSON_THROW_ON_ERROR));
            $interpretationMetadata = is_array($lockedInterpretation->metadata)
                ? $lockedInterpretation->metadata
                : [];
            $existingHash = trim((string) data_get($interpretationMetadata, 'semantic_plan.hash', ''));
            if ($existingHash !== '' && ! hash_equals($existingHash, $planHash)) {
                throw new VoiceTurnConflictException('The sealed semantic plan cannot change during execution.');
            }

            $operationRuns = [];
            $createdRuns = [];
            $runIdByOperationId = [];
            foreach ($operationSpecs as $spec) {
                $lane = VoiceTurnLane::tryFrom((string) ($spec['lane'] ?? ''));
                $operation = is_array($spec['operation'] ?? null) ? $spec['operation'] : [];
                $operationId = trim((string) ($spec['id'] ?? ''));
                $tool = trim((string) ($spec['tool'] ?? ''));
                if ($lane === null || $operationId === '' || $tool === '') {
                    throw new VoiceTurnConflictException('A staged semantic operation has an invalid scheduler specification.');
                }

                $dependencyOperationIds = is_array($operation['dependencies'] ?? null)
                    ? array_values($operation['dependencies'])
                    : [];
                $dependencyRunMap = [];
                foreach ($dependencyOperationIds as $dependencyOperationId) {
                    $dependencyRunId = $runIdByOperationId[(string) $dependencyOperationId] ?? null;
                    if (! is_int($dependencyRunId)) {
                        throw new VoiceTurnConflictException("Semantic operation {$operationId} has an unstaged dependency.");
                    }
                    $dependencyRunMap[(string) $dependencyOperationId] = $dependencyRunId;
                }

                $operationHash = hash('sha256', json_encode($operation, JSON_THROW_ON_ERROR));
                $operationKey = 'semantic-'.$sequence.'-operation-'.Str::slug($operationId, '-').'-'.substr($operationHash, 0, 10);
                $idempotencyKey = $lockedTurn->turn_id.':'.$operationKey;
                $operationHardDeadlineAt = $this->semanticOperationHardDeadlineAt($lockedInterpretation, $lane);
                $run = AssistantRun::firstOrCreate([
                    'voice_turn_id' => $lockedTurn->id,
                    'idempotency_key' => $idempotencyKey,
                ], [
                    'user_id' => $lockedTurn->user_id,
                    'workspace_id' => $lockedTurn->workspace_id,
                    'conversation_session_id' => $lockedTurn->conversation_session_id,
                    'user_message_id' => $lockedTurn->user_message_id,
                    'source' => 'browser_voice_realtime',
                    'lane' => $lane->value,
                    'handler' => HermesSemanticOperationExecutor::OPERATION_HANDLER,
                    'label' => mb_substr(trim((string) ($spec['label'] ?? 'Run operation')), 0, 180),
                    'priority' => (int) ($spec['priority'] ?? 0),
                    'resource_lock_key' => filled($spec['resource_lock_key'] ?? null)
                        ? mb_substr(trim((string) $spec['resource_lock_key']), 0, 180)
                        : null,
                    'hard_deadline_at' => $operationHardDeadlineAt,
                    'status' => 'queued',
                    'input' => json_encode($operation, JSON_THROW_ON_ERROR),
                    'metadata' => $this->privacy->sanitizeDiagnosticPayload([
                        'required' => true,
                        'role' => 'semantic_operation',
                        'semantic_sequence' => $sequence,
                        'semantic_operation_id' => $operationId,
                        'semantic_tool' => $tool,
                        'semantic_operation_hash' => $operationHash,
                        'interpretation_run_id' => $lockedInterpretation->id,
                        'dependency_operation_ids' => array_keys($dependencyRunMap),
                        'dependency_run_ids' => array_values($dependencyRunMap),
                        'dependency_run_map' => $dependencyRunMap,
                    ]),
                ]);
                if ($run->wasRecentlyCreated) {
                    $createdRuns[] = $run;
                    $this->recordEventLocked($lockedTurn, 'job_queued', $lockedTurn->state, $lockedTurn->state, [
                        'run_id' => $run->id,
                        'label' => $run->label,
                        'resource_lock_key' => $run->resource_lock_key,
                        'priority' => $run->priority,
                        'lane' => $run->lane,
                        'handler' => $run->handler,
                        'role' => 'semantic_operation',
                        'operation_id' => $operationId,
                        'tool' => $tool,
                        'dependency_run_ids' => array_values($dependencyRunMap),
                    ], 'scheduler');
                }
                $runIdByOperationId[$operationId] = (int) $run->id;
                $operationRuns[] = $run->refresh();
            }

            $planDeadlineRun = collect($operationRuns)
                ->sortByDesc(fn (AssistantRun $run): int => $run->hard_deadline_at?->getTimestamp() ?? 0)
                ->first();
            if (! $planDeadlineRun instanceof AssistantRun || $planDeadlineRun->hard_deadline_at === null) {
                throw new VoiceTurnConflictException('A semantic execution plan requires one lifecycle-owned final deadline.');
            }
            // Composition shares the slowest explicitly staged operation
            // bound. Typed plans therefore finish by 4/6/8 seconds, while a
            // future explicitly represented Semantic operation retains the
            // long-running complex-work deadline instead of being inferred
            // from transcript prose.
            $compositionHardDeadlineAt = $planDeadlineRun->hard_deadline_at->copy();
            $compositionDeadlineLane = VoiceTurnLane::tryFrom((string) $planDeadlineRun->lane)
                ?? VoiceTurnLane::Semantic;
            $compositionKey = $lockedTurn->turn_id.':semantic-'.$sequence.'-composition';
            $compositionRun = AssistantRun::firstOrCreate([
                'voice_turn_id' => $lockedTurn->id,
                'idempotency_key' => $compositionKey,
            ], [
                'user_id' => $lockedTurn->user_id,
                'workspace_id' => $lockedTurn->workspace_id,
                'conversation_session_id' => $lockedTurn->conversation_session_id,
                'user_message_id' => $lockedTurn->user_message_id,
                'source' => 'browser_voice_realtime',
                'lane' => VoiceTurnLane::Semantic->value,
                'handler' => HermesSemanticOperationExecutor::COMPOSITION_HANDLER,
                'label' => 'Prepare response',
                'priority' => 0,
                'resource_lock_key' => null,
                'hard_deadline_at' => $compositionHardDeadlineAt,
                'status' => 'queued',
                'input' => json_encode($interpretation, JSON_THROW_ON_ERROR),
                'metadata' => $this->privacy->sanitizeDiagnosticPayload([
                    'required' => true,
                    'role' => 'semantic_composition',
                    'semantic_sequence' => $sequence,
                    'semantic_plan_hash' => $planHash,
                    'interpretation_run_id' => $lockedInterpretation->id,
                    'operation_run_ids' => array_values($runIdByOperationId),
                    'operation_run_map' => $runIdByOperationId,
                    'dependency_run_ids' => array_values($runIdByOperationId),
                    'semantic_plan_deadline_lane' => $compositionDeadlineLane->value,
                ]),
            ]);
            if ($compositionRun->wasRecentlyCreated) {
                $createdRuns[] = $compositionRun;
                $this->recordEventLocked($lockedTurn, 'job_queued', $lockedTurn->state, $lockedTurn->state, [
                    'run_id' => $compositionRun->id,
                    'label' => $compositionRun->label,
                    'lane' => $compositionRun->lane,
                    'handler' => $compositionRun->handler,
                    'role' => 'semantic_composition',
                    'dependency_run_ids' => array_values($runIdByOperationId),
                ], 'scheduler');
            }

            $this->assertInterpretationDeadlineActive($lockedInterpretation);
            $completedAt = now();
            $acknowledgementText = (string) data_get(
                $interpretation,
                'acknowledgement_text',
                '',
            );
            if (trim($acknowledgementText) !== ''
                && ! ($lockedTurn->acknowledgement_required && filled($lockedTurn->acknowledgement_text))) {
                // Acknowledgement delivery becomes eligible only in the same
                // transaction that seals a fully validated executable plan.
                // Invalid plans that Hermes must repair or clarify can never
                // leak a premature acknowledgement to the browser.
                $lockedTurn->forceFill([
                    'acknowledgement_required' => true,
                    'acknowledgement_text' => $acknowledgementText,
                    'version' => $lockedTurn->version + 1,
                ])->saveQuietly();
                $this->recordEventLocked(
                    $lockedTurn,
                    'semantic_acknowledgement_published',
                    $lockedTurn->state,
                    $lockedTurn->state,
                    [
                        'run_id' => $lockedInterpretation->id,
                        'semantic_sequence' => $sequence,
                        'operation_count' => count($operationSpecs),
                    ],
                    'semantic_interpreter',
                );
            }
            $lockedInterpretation->update([
                'status' => 'completed',
                'result' => [
                    'status' => 'completed',
                    'voice_turn_id' => $lockedTurn->id,
                    'final_text' => 'Semantic plan staged.',
                    'failure_category' => null,
                    'user_facing_failure' => null,
                    'side_effect_status' => VoiceTurnSideEffectStatus::None->value,
                    'metadata' => $this->privacy->sanitizeDiagnosticPayload([
                        'run_id' => $lockedInterpretation->id,
                        'semantic_outcome' => data_get($interpretation, 'outcome'),
                        'operation_run_ids' => array_values($runIdByOperationId),
                        'composition_run_id' => $compositionRun->id,
                    ]),
                ],
                'completed_at' => $completedAt,
                'last_progress_at' => $completedAt,
                'metadata' => [
                    ...$interpretationMetadata,
                    'semantic_plan' => $this->privacy->sanitizeDiagnosticPayload([
                        'schema_version' => 2,
                        'hash' => $planHash,
                        'operation_run_map' => $runIdByOperationId,
                        'composition_run_id' => $compositionRun->id,
                        'sealed_at' => data_get($interpretationMetadata, 'semantic_plan.sealed_at', now()->toIso8601String()),
                    ]),
                ],
            ]);
            if ($lockedTurn->state === VoiceTurnState::Accepted) {
                $lockedTurn->update([
                    'state' => VoiceTurnState::Running,
                    'version' => $lockedTurn->version + 1,
                    'started_at' => $lockedTurn->started_at ?? $completedAt,
                ]);
            }
            $this->recordEventLocked($lockedTurn, 'semantic_execution_staged', $lockedTurn->state, $lockedTurn->state, [
                'interpretation_run_id' => $lockedInterpretation->id,
                'operation_run_ids' => array_values($runIdByOperationId),
                'composition_run_id' => $compositionRun->id,
                'semantic_sequence' => $sequence,
            ], 'scheduler');
            $this->recordEventLocked($lockedTurn, 'job_completed', $lockedTurn->state, $lockedTurn->state, [
                'run_id' => $lockedInterpretation->id,
                'label' => $lockedInterpretation->label,
                'status' => 'completed',
                'side_effect_status' => VoiceTurnSideEffectStatus::None->value,
                'semantic_outcome' => data_get($interpretation, 'outcome'),
            ], 'finalizer');
            $this->recordEventLocked($lockedTurn, 'job_barrier_waiting', $lockedTurn->state, $lockedTurn->state, [
                'completed_run_id' => $lockedInterpretation->id,
                'pending_run_ids' => [
                    ...array_values($runIdByOperationId),
                    $compositionRun->id,
                ],
                'required_run_count' => count($runIdByOperationId) + 2,
            ], 'finalizer');

            return [
                'operation_runs' => $operationRuns,
                'composition_run' => $compositionRun->refresh(),
                'created_runs' => $createdRuns,
            ];
        }, 3);

        foreach ($result['created_runs'] as $createdRun) {
            if ($createdRun->hard_deadline_at === null) {
                continue;
            }

            $this->dispatchDeadlineEnforcement($turn->id, $createdRun->hard_deadline_at);
        }

        return $result;
    }

    private function semanticOperationHardDeadlineAt(
        AssistantRun $interpretationRun,
        VoiceTurnLane $lane,
    ): Carbon {
        $seconds = match ($lane) {
            VoiceTurnLane::AppRead => self::APP_READ_HARD_DEADLINE_SECONDS,
            VoiceTurnLane::AppWrite => self::APP_WRITE_HARD_DEADLINE_SECONDS,
            VoiceTurnLane::External => self::EXTERNAL_HARD_DEADLINE_SECONDS,
            VoiceTurnLane::Semantic => self::TURN_HARD_DEADLINE_SECONDS,
        };
        $origin = $interpretationRun->created_at?->copy() ?? now();

        return $origin->addSeconds($seconds);
    }

    private function assertInterpretationDeadlineActive(AssistantRun $run): void
    {
        if ($run->hard_deadline_at === null || $run->hard_deadline_at->isFuture()) {
            return;
        }

        throw new HermesSemanticOperationException(
            'semantic_deadline',
            'The semantic plan was not durably sealed before its interpretation deadline.',
        );
    }

    public function ensureSemanticInterpretationJob(VoiceTurn $turn): AssistantRun
    {
        if ($turn->state !== VoiceTurnState::Accepted) {
            throw new VoiceTurnConflictException('Only an accepted voice turn can receive a semantic interpretation job.');
        }

        $sequence = max(1, (int) data_get($turn->metadata, 'semantic_sequence', 1));
        $job = $this->createJob(
            turn: $turn,
            label: 'Handle request',
            jobKey: "semantic-{$sequence}",
            lane: VoiceTurnLane::Semantic,
            handler: HermesSemanticOperationExecutor::INTERPRETATION_HANDLER,
            metadata: [
                'required' => true,
                'role' => 'semantic_interpretation',
                'semantic_sequence' => $sequence,
                'scheduling_policy' => [
                    'priority' => 0,
                    'resource_lock_key' => null,
                ],
            ],
            input: $turn->semantic_input,
            hardDeadlineSeconds: self::INTERPRETATION_HARD_DEADLINE_SECONDS,
        );

        return $job['run'];
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    /** @return array{run: AssistantRun, created: bool} */
    private function createJob(
        VoiceTurn $turn,
        string $label,
        string $jobKey,
        VoiceTurnLane $lane,
        string $handler,
        ?string $resourceLockKey = null,
        int $priority = 0,
        array $metadata = [],
        ?string $input = null,
        ?int $hardDeadlineSeconds = null,
    ): array {
        $result = DB::transaction(function () use ($turn, $label, $jobKey, $resourceLockKey, $priority, $metadata, $lane, $handler, $input, $hardDeadlineSeconds): array {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state->isTerminal()) {
                throw new VoiceTurnConflictException('A job cannot be added to a terminal voice turn.');
            }

            $idempotencyKey = $locked->turn_id.':'.Str::slug($jobKey, '-');
            $run = AssistantRun::firstOrCreate([
                'voice_turn_id' => $locked->id,
                'idempotency_key' => $idempotencyKey,
            ], [
                'user_id' => $locked->user_id,
                'workspace_id' => $locked->workspace_id,
                'conversation_session_id' => $locked->conversation_session_id,
                'user_message_id' => $locked->user_message_id,
                'source' => 'browser_voice_realtime',
                'lane' => $lane->value,
                'handler' => $handler,
                'label' => mb_substr(trim($label), 0, 180),
                'priority' => $priority,
                'resource_lock_key' => $resourceLockKey,
                'hard_deadline_at' => $hardDeadlineSeconds === null
                    ? $locked->hard_deadline_at
                    : now()->addSeconds(max(1, $hardDeadlineSeconds)),
                'last_progress_at' => null,
                'status' => 'queued',
                'input' => trim((string) ($input ?? $locked->semantic_input ?? '')),
                'metadata' => $this->privacy->sanitizeDiagnosticPayload([
                    ...$metadata,
                    'required' => data_get($metadata, 'required', true) !== false,
                ]),
            ]);

            if ($run->wasRecentlyCreated) {
                $this->recordEventLocked($locked, 'job_queued', $locked->state, $locked->state, [
                    'run_id' => $run->id,
                    'label' => $run->label,
                    'resource_lock_key' => $run->resource_lock_key,
                    'priority' => $run->priority,
                    'lane' => $run->lane,
                    'handler' => $run->handler,
                    'required' => data_get($run->metadata, 'required', true) !== false,
                ], 'scheduler');
            }

            $created = $run->wasRecentlyCreated;

            return ['run' => $run->refresh(), 'created' => $created];
        }, 3);

        if ($result['created']
            && $hardDeadlineSeconds !== null
            && $result['run']->hard_deadline_at !== null) {
            $this->dispatchDeadlineEnforcement($turn->id, $result['run']->hard_deadline_at);
        }

        return $result;
    }

    public function jobRequiresDispatch(AssistantRun $run): bool
    {
        $fresh = $run->fresh();

        return $fresh instanceof AssistantRun
            && $fresh->status === 'queued'
            && $fresh->dispatch_requested_at === null;
    }

    public function markJobDispatched(AssistantRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $locked = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($locked->dispatch_requested_at === null) {
                $locked->update(['dispatch_requested_at' => now()]);
            }
        }, 3);
    }

    /** @param array<string,mixed> $metadata */
    public function markJobFinalizing(AssistantRun $run, array $metadata = []): void
    {
        DB::transaction(function () use ($run, $metadata): void {
            $locked = AssistantRun::query()
                ->whereKey($run->id)
                ->whereNotNull('voice_turn_id')
                ->lockForUpdate()
                ->first();
            if (! $locked instanceof AssistantRun || $locked->status !== 'running') {
                return;
            }

            $current = is_array($locked->metadata) ? $locked->metadata : [];
            $locked->update([
                'status' => 'finalizing',
                'last_progress_at' => now(),
                'metadata' => [...$current, ...$this->privacy->sanitizeDiagnosticPayload($metadata)],
            ]);
        }, 3);
    }

    /**
     * Seal one typed-operation receipt under the lifecycle owner's turn/run
     * locks. Duplicate workers receive the already sealed receipt; a canceled,
     * failed, completed, or otherwise inactive job cannot accept a late one.
     *
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    public function sealSemanticOperationReceipt(AssistantRun $run, array $receipt): array
    {
        return DB::transaction(function () use ($run, $receipt): array {
            $voiceTurnId = (int) ($run->voice_turn_id ?? 0);
            if ($voiceTurnId <= 0) {
                throw new VoiceTurnConflictException('Only Browser Voice semantic jobs may seal operation receipts.');
            }

            $lockedTurn = VoiceTurn::query()->whereKey($voiceTurnId)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = $this->semanticOperationReceipt($lockedRun);
            if (is_array($existing)) {
                return $existing;
            }
            if ($lockedTurn->state->isTerminal()
                || $lockedRun->status !== 'running'
                || $lockedRun->handler !== HermesSemanticOperationExecutor::OPERATION_HANDLER) {
                throw new VoiceTurnConflictException('That voice job is no longer active for receipt sealing.');
            }

            $receipt = $this->privacy->sanitizeDiagnosticPayload($receipt);
            $operationId = trim((string) ($receipt['operation_id'] ?? ''));
            $tool = trim((string) ($receipt['tool'] ?? ''));
            if ($operationId === ''
                || $operationId !== trim((string) data_get($lockedRun->metadata, 'semantic_operation_id'))
                || $tool === ''
                || $tool !== trim((string) data_get($lockedRun->metadata, 'semantic_tool'))) {
                throw new VoiceTurnConflictException('The semantic operation receipt does not match its durable job.');
            }

            $metadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $lockedRun->update([
                'metadata' => [...$metadata, 'semantic_operation_receipt' => $receipt],
                'last_progress_at' => now(),
            ]);

            return $receipt;
        }, 3);
    }

    /**
     * Publish the deterministic playback directive through the same lifecycle
     * owner that controls its acknowledgement and reload projection.
     *
     * @return array{id:string,operation_run_id:int,issued_at:string,acknowledged_at:null}
     */
    public function issuePlaybackStopDirective(VoiceTurn $turn, AssistantRun $run): array
    {
        return DB::transaction(function () use ($turn, $run): array {
            $lockedTurn = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = data_get($lockedTurn->metadata, 'playback_stop_directive');
            if (is_array($existing)) {
                if ((int) ($existing['operation_run_id'] ?? 0) !== (int) $lockedRun->id
                    || trim((string) ($existing['id'] ?? '')) === '') {
                    throw new VoiceTurnConflictException('That voice turn already owns a different playback Stop directive.');
                }

                return $existing;
            }
            if ($lockedTurn->state->isTerminal()
                || $lockedRun->status !== 'running'
                || $lockedRun->handler !== HermesSemanticOperationExecutor::OPERATION_HANDLER
                || data_get($lockedRun->metadata, 'semantic_tool') !== 'voice.playback.stop') {
                throw new VoiceTurnConflictException('That playback Stop job is no longer active for directive delivery.');
            }

            $directive = [
                'id' => $lockedTurn->turn_id.':playback-stop:'.$lockedRun->id,
                'operation_run_id' => $lockedRun->id,
                'issued_at' => now()->toIso8601String(),
                'acknowledged_at' => null,
            ];
            $metadata = is_array($lockedTurn->metadata) ? $lockedTurn->metadata : [];
            $lockedTurn->update(['metadata' => [...$metadata, 'playback_stop_directive' => $directive]]);

            return $directive;
        }, 3);
    }

    public function claimJobExecution(AssistantRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            ConversationSession::query()->whereKey($run->conversation_session_id)->lockForUpdate()->firstOrFail();
            $lockedTurn = VoiceTurn::query()->whereKey($run->voice_turn_id)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->status !== 'queued' || $lockedTurn->state->isTerminal()) {
                return false;
            }

            $dependencyRunIds = array_values(array_unique(array_filter(array_map(
                'intval',
                is_array(data_get($lockedRun->metadata, 'dependency_run_ids'))
                    ? data_get($lockedRun->metadata, 'dependency_run_ids')
                    : [],
            ), static fn (int $id): bool => $id > 0)));
            if ($dependencyRunIds !== []) {
                $dependencyRuns = AssistantRun::query()
                    ->where('voice_turn_id', $lockedTurn->id)
                    ->whereIn('id', $dependencyRunIds)
                    ->orderBy('id')
                    ->get();
                if ($dependencyRuns->count() !== count($dependencyRunIds)) {
                    throw new VoiceTurnConflictException('A semantic job dependency does not belong to its voice turn.');
                }
                if ($dependencyRuns->contains(
                    fn (AssistantRun $dependency): bool => ! in_array($dependency->status, ['completed', 'failed', 'cancelled'], true),
                )) {
                    $this->recordJobWait($lockedRun, 'dependency');

                    return false;
                }
            }

            if ($lockedRun->lane === VoiceTurnLane::AppWrite->value) {
                $runningBackgroundJobs = AssistantRun::query()
                    ->where('conversation_session_id', $lockedRun->conversation_session_id)
                    ->whereNotNull('voice_turn_id')
                    ->where('id', '!=', $lockedRun->id)
                    ->where('lane', VoiceTurnLane::AppWrite->value)
                    ->whereIn('status', ['running', 'finalizing'])
                    ->count();
                if ($runningBackgroundJobs >= 3) {
                    $this->recordJobWait($lockedRun, 'capacity');

                    return false;
                }

                if ($this->hasRunnableHigherPriorityJob($lockedRun)) {
                    $this->recordJobWait($lockedRun, 'priority');

                    return false;
                }
            }

            if ($lockedRun->resource_lock_key !== null) {
                $resourceBusy = AssistantRun::query()
                    ->where('workspace_id', $lockedRun->workspace_id)
                    ->whereNotNull('voice_turn_id')
                    ->where('id', '!=', $lockedRun->id)
                    ->where('resource_lock_key', $lockedRun->resource_lock_key)
                    ->whereIn('status', ['running', 'finalizing'])
                    ->exists();
                if ($resourceBusy) {
                    $this->recordJobWait($lockedRun, 'resource');

                    return false;
                }
            }

            $metadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $lockedRun->update([
                'status' => 'running',
                'started_at' => $lockedRun->started_at ?? now(),
                'last_progress_at' => now(),
                'metadata' => array_diff_key($metadata, array_flip([
                    'capacity_wait_started_at',
                    'dependency_wait_started_at',
                    'priority_wait_started_at',
                    'resource_wait_started_at',
                ])),
            ]);

            return true;
        }, 3);
    }

    /**
     * Keep a claimed-run compare-and-set boundary under the lifecycle owner's
     * turn/run locks. DB-backed operations execute and seal their receipt
     * inside one call so side effects roll back with a crossed deadline.
     * External providers use one short call for authorization, perform network
     * I/O after it returns, then use a second short call to accept the result;
     * cancellation or deadline enforcement may win between those boundaries.
     *
     * @template TResult
     *
     * @param  \Closure(VoiceTurn, AssistantRun): TResult  $execution
     * @return TResult
     */
    public function withClaimedJobExecution(
        VoiceTurn $turn,
        AssistantRun $run,
        \Closure $execution,
    ): mixed {
        return DB::transaction(function () use ($turn, $run, $execution): mixed {
            $lockedTurn = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();

            $turnDeadlineAt = $lockedTurn->hard_deadline_at?->copy();
            $runDeadlineAt = $lockedRun->hard_deadline_at?->copy();
            $deadlineExpired = ($turnDeadlineAt !== null && ! $turnDeadlineAt->isFuture())
                || ($runDeadlineAt !== null && ! $runDeadlineAt->isFuture());
            if ($lockedTurn->state->isTerminal()
                || $lockedRun->status !== 'running'
                || $deadlineExpired) {
                throw new VoiceTurnConflictException('That voice job is no longer active for execution.');
            }

            $result = $execution($lockedTurn, $lockedRun);
            $deadlineCrossed = ($turnDeadlineAt !== null && ! $turnDeadlineAt->isFuture())
                || ($runDeadlineAt !== null && ! $runDeadlineAt->isFuture());
            if ($deadlineCrossed) {
                throw new VoiceTurnConflictException('That voice job crossed its execution deadline.');
            }

            return $result;
        }, 3);
    }

    public function enforceDeadlines(?int $voiceTurnId = null, ?int $sessionId = null): int
    {
        $query = VoiceTurn::query()
            ->whereIn('state', [
                VoiceTurnState::AwaitingClarification->value,
                VoiceTurnState::Accepted->value,
                VoiceTurnState::Running->value,
            ]);
        if ($voiceTurnId !== null) {
            $query->whereKey($voiceTurnId);
        }
        if ($sessionId !== null) {
            $query->where('conversation_session_id', $sessionId);
        }

        $turns = $query->orderBy('id')->limit($voiceTurnId === null ? 200 : 1)->get();
        $failed = 0;

        foreach ($turns as $turn) {
            $fresh = $turn->fresh();
            if (! $fresh instanceof VoiceTurn || $fresh->state->isTerminal()) {
                continue;
            }

            $failed += $this->enforceExpiredJobDeadlines($fresh);
            $fresh = $fresh->fresh();
            if (! $fresh instanceof VoiceTurn || $fresh->state->isTerminal()) {
                continue;
            }

            $noProgressExpired = $fresh->no_progress_deadline_at !== null
                && $fresh->no_progress_deadline_at->isPast();
            if ($noProgressExpired) {
                $runtimeProgress = $this->latestRuntimeProgressAfterRecordedProgress($fresh);
                if ($runtimeProgress instanceof ActivityEvent) {
                    $fresh = $this->markProgress($fresh, [
                        'activity_event_id' => $runtimeProgress->id,
                        'activity_event_type' => $runtimeProgress->event_type,
                        'run_id' => data_get($runtimeProgress->payload, 'run_id'),
                    ], 'runtime_activity');
                    $noProgressExpired = false;
                }
            }
            $hardDeadlineExpired = $fresh->hard_deadline_at !== null && $fresh->hard_deadline_at->isPast();
            if (! $noProgressExpired && ! $hardDeadlineExpired) {
                continue;
            }

            if ($fresh->state === VoiceTurnState::AwaitingClarification) {
                try {
                    $this->cancel($fresh, 'clarification_timeout', [
                        'deadline_at' => $fresh->hard_deadline_at?->toIso8601String(),
                    ]);
                    $failed++;
                } catch (VoiceTurnConflictException) {
                    // A clarification answer or cancellation won first.
                }

                continue;
            }

            $committedReceiptExists = $fresh->runs()->get()->contains(function (AssistantRun $run): bool {
                $receipt = $this->semanticOperationReceipt($run);

                return is_array($receipt) && ($receipt['side_effect_committed'] ?? false) === true;
            });
            $category = $noProgressExpired ? 'no_progress_timeout' : 'hard_deadline_timeout';
            try {
                $this->fail(
                    $fresh,
                    $category,
                    $noProgressExpired ? 'No meaningful progress was recorded before the deadline.' : 'The hard deadline elapsed.',
                    AssistantRunService::SYSTEM_FAILURE_FINAL,
                    $committedReceiptExists
                        ? VoiceTurnSideEffectStatus::Committed
                        : VoiceTurnSideEffectStatus::NotCommitted,
                    ['deadline_at' => ($noProgressExpired ? $fresh->no_progress_deadline_at : $fresh->hard_deadline_at)?->toIso8601String()],
                );
                $failed++;
            } catch (VoiceTurnConflictException) {
                // Another process terminalized the turn first.
            }
        }

        return $failed;
    }

    private function enforceExpiredJobDeadlines(VoiceTurn $turn): int
    {
        $deadlineBoundary = now()->format('Y-m-d H:i:s.u');
        $committedOperationReceiptExists = $turn->runs()->get()->contains(function (AssistantRun $candidate): bool {
            $receipt = $this->semanticOperationReceipt($candidate);

            return is_array($receipt) && ($receipt['side_effect_committed'] ?? false) === true;
        });
        $expiredRuns = AssistantRun::query()
            ->where('voice_turn_id', $turn->id)
            ->whereIn('status', ['queued', 'running', 'finalizing'])
            ->whereNotNull('hard_deadline_at')
            ->where('hard_deadline_at', '<=', $deadlineBoundary)
            ->orderBy('id')
            ->get();
        $terminalized = 0;

        foreach ($expiredRuns as $run) {
            $lane = VoiceTurnLane::from((string) $run->lane);
            if ($run->handler === HermesSemanticOperationExecutor::COMPOSITION_HANDLER) {
                $lane = VoiceTurnLane::tryFrom((string) data_get($run->metadata, 'semantic_plan_deadline_lane'))
                    ?? $lane;
            }
            try {
                $receipt = $this->semanticOperationReceipt($run->fresh());
                if (is_array($receipt)) {
                    $this->finishJob(
                        $run,
                        ($receipt['status'] ?? null) === 'failed' ? 'failed' : 'completed',
                        finalText: $this->semanticOperationSummary($run, $receipt),
                        failureCategory: (string) data_get($receipt, 'data.category', ''),
                        internalDetail: (string) data_get($receipt, 'data.internal_detail', ''),
                        userFacingFailure: null,
                        sideEffectStatus: ($receipt['side_effect_committed'] ?? false) === true
                            ? VoiceTurnSideEffectStatus::Committed
                            : VoiceTurnSideEffectStatus::None,
                        metadata: [
                            'reconciled_at_job_deadline' => true,
                            'semantic_operation_receipt' => $receipt,
                        ],
                    );
                } else {
                    $deadlineReceipt = $run->handler === HermesSemanticOperationExecutor::OPERATION_HANDLER
                        ? $this->semanticDeadlineFailureReceipt($run)
                        : null;
                    $this->finishJob(
                        $run,
                        'failed',
                        failureCategory: match ($run->handler) {
                            HermesSemanticOperationExecutor::INTERPRETATION_HANDLER => 'semantic_deadline',
                            HermesSemanticOperationExecutor::COMPOSITION_HANDLER => 'semantic_composition_deadline',
                            HermesSemanticOperationExecutor::OPERATION_HANDLER => $lane->value.'_hard_deadline_timeout',
                            default => 'job_hard_deadline_timeout',
                        },
                        internalDetail: 'The required job hard deadline elapsed.',
                        userFacingFailure: AssistantRunService::SYSTEM_FAILURE_FINAL,
                        sideEffectStatus: VoiceTurnSideEffectStatus::NotCommitted,
                        metadata: array_filter([
                            'deadline_at' => $run->hard_deadline_at?->toIso8601String(),
                            'committed_operation_receipt_present' => $run->handler === HermesSemanticOperationExecutor::COMPOSITION_HANDLER
                                ? $committedOperationReceiptExists
                                : null,
                            'semantic_operation_receipt' => $deadlineReceipt,
                        ], static fn (mixed $value): bool => $value !== null),
                    );
                }
                $terminalized++;
            } catch (VoiceTurnConflictException) {
                // A worker or another deadline enforcer won the same job.
            }
        }

        return $terminalized;
    }

    private function latestRuntimeProgressAfterRecordedProgress(VoiceTurn $turn): ?ActivityEvent
    {
        $lastProgressAt = data_get($turn->metadata, 'last_progress_at');
        $after = filled($lastProgressAt) ? $lastProgressAt : $turn->first_progress_at;

        return ActivityEvent::query()
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('payload->message_id', $turn->user_message_id)
            ->when($after !== null, fn ($query) => $query->where('created_at', '>', $after))
            ->whereIn('status', ['started', 'completed', 'succeeded', 'partial'])
            ->latest('id')
            ->first();
    }

    private function terminalize(
        VoiceTurn $turn,
        VoiceTurnState $terminalState,
        ?string $finalText,
        VoiceTurnSideEffectStatus $sideEffectStatus,
        ?string $failureCategory,
        ?string $internalDetail,
        array $metadata,
    ): VoiceTurn {
        if (! $terminalState->isTerminal()) {
            throw new VoiceTurnConflictException('Finalization requires a terminal state.');
        }
        if ($terminalState !== VoiceTurnState::Canceled && trim((string) $finalText) === '') {
            throw new VoiceTurnConflictException('A non-canceled terminal turn requires one final Bean message.');
        }

        $result = DB::transaction(function () use ($turn, $terminalState, $finalText, $sideEffectStatus, $failureCategory, $internalDetail, $metadata): array {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $from = $locked->state;

            if ($from->isTerminal()) {
                if ($from === $terminalState) {
                    $this->recordEventLocked($locked, 'finalization_deduplicated', $from, $terminalState, [
                        'final_assistant_message_id' => $locked->final_assistant_message_id,
                    ], 'finalizer');

                    return ['turn' => $locked->refresh(), 'conflict' => null];
                }

                $this->recordEventLocked($locked, 'finalization_rejected', $from, $terminalState, [
                    'reason' => 'terminal_state_already_recorded',
                ], 'finalizer');

                return ['turn' => $locked->refresh(), 'conflict' => 'A different terminal outcome has already been recorded.'];
            }

            $requiredRuns = AssistantRun::query()
                ->where('voice_turn_id', $locked->id)
                ->orderBy('id')
                ->get()
                ->filter(fn (AssistantRun $run): bool => data_get($run->metadata, 'required', true) !== false)
                ->values();
            $committedSemanticReceiptExists = $requiredRuns->contains(function (AssistantRun $run): bool {
                $receipt = $this->semanticOperationReceipt($run);

                return is_array($receipt) && ($receipt['side_effect_committed'] ?? false) === true;
            });
            if ($committedSemanticReceiptExists
                && $this->sideEffectRank($sideEffectStatus) < $this->sideEffectRank(VoiceTurnSideEffectStatus::Committed)) {
                $sideEffectStatus = VoiceTurnSideEffectStatus::Committed;
            }
            if ($terminalState === VoiceTurnState::Canceled) {
                $committedRunIds = $requiredRuns->filter(function (AssistantRun $run): bool {
                    $receipt = $this->semanticOperationReceipt($run);

                    return is_array($receipt) && ($receipt['side_effect_committed'] ?? false) === true;
                })->pluck('id')->map(fn (mixed $id): int => (int) $id)->values();
                foreach ($requiredRuns->whereIn('id', $committedRunIds) as $committedRun) {
                    $receipt = $this->semanticOperationReceipt($committedRun);
                    if (! is_array($receipt)) {
                        continue;
                    }
                    $committedRun->update([
                        'status' => 'completed',
                        'result' => [
                            'status' => 'completed',
                            'voice_turn_id' => $locked->id,
                            'final_text' => $this->semanticOperationSummary($committedRun, $receipt),
                            'side_effect_status' => VoiceTurnSideEffectStatus::Committed->value,
                            'metadata' => ['semantic_operation_receipt' => $receipt],
                            'reconciled_from_semantic_receipt_during_cancellation' => true,
                        ],
                        'completed_at' => now(),
                        'last_progress_at' => now(),
                    ]);
                }
                if ($committedRunIds->isNotEmpty()) {
                    $sideEffectStatus = VoiceTurnSideEffectStatus::Committed;
                    $metadata = [
                        ...$metadata,
                        'cancellation_after_partial_commit' => true,
                        'committed_run_ids' => $committedRunIds->all(),
                    ];
                }
            }
            if ($terminalState === VoiceTurnState::Completed
                && $requiredRuns->count() > 1
                && ! array_key_exists('barrier_run_ids', $metadata)) {
                throw new VoiceTurnConflictException('A multi-run voice turn must complete through its required-job finalizer barrier.');
            }

            $assistantMessage = null;
            if ($terminalState !== VoiceTurnState::Canceled) {
                $assistantMessage = ConversationMessage::firstOrCreate([
                    'conversation_session_id' => $locked->conversation_session_id,
                    'client_turn_id' => $locked->turn_id,
                    'role' => 'assistant',
                ], [
                    'user_id' => $locked->user_id,
                    'origin' => 'spoken_voice',
                    'display_mode' => 'voice_only',
                    'content' => (string) $finalText,
                    'metadata' => [
                        'source' => 'browser_voice_realtime',
                        'voice_turn_id' => $locked->id,
                        'stable_turn_id' => $locked->turn_id,
                        'final_response' => true,
                        'origin' => 'spoken_voice',
                        'display_mode' => 'voice_only',
                    ],
                ]);
            }

            $locked->update([
                'state' => $terminalState,
                'version' => $locked->version + 1,
                'final_assistant_message_id' => $assistantMessage?->id,
                'terminal_at' => $locked->terminal_at ?? now(),
                'failure_category' => $failureCategory,
                'internal_failure_detail' => $internalDetail === null ? null : $this->privacy->sanitizeTranscript($internalDetail),
                'user_facing_failure_text' => $terminalState === VoiceTurnState::Failed
                    ? (string) $finalText
                    : null,
                'side_effect_status' => $sideEffectStatus,
            ]);

            $activeRuns = AssistantRun::query()
                ->where('voice_turn_id', $locked->id)
                ->whereIn('status', ['queued', 'running', 'finalizing']);
            if ($terminalState === VoiceTurnState::Canceled) {
                $activeRuns
                    ->where('voice_turn_id', $locked->id)
                    ->update([
                        'status' => 'cancelled',
                        'cancelled_at' => now(),
                        'completed_at' => now(),
                    ]);
            } elseif ($terminalState === VoiceTurnState::Failed) {
                $activeRuns->update([
                    'status' => 'failed',
                    'error' => $internalDetail === null ? null : $this->privacy->sanitizeTranscript($internalDetail),
                    'completed_at' => now(),
                    'last_progress_at' => now(),
                ]);
            } else {
                $activeRuns->get()->each(fn (AssistantRun $run) => $run->update([
                    'status' => 'completed',
                    'assistant_message_id' => $assistantMessage?->id,
                    'result' => [
                        'status' => 'completed',
                        'voice_turn_id' => $locked->id,
                        'assistant_message_id' => $assistantMessage?->id,
                        'terminalized_by' => 'voice_turn_finalizer',
                    ],
                    'completed_at' => now(),
                    'last_progress_at' => now(),
                ]));
            }
            if ($terminalState === VoiceTurnState::Completed && $assistantMessage instanceof ConversationMessage) {
                AssistantRun::query()
                    ->where('voice_turn_id', $locked->id)
                    ->where('status', 'completed')
                    ->get()
                    ->each(function (AssistantRun $run) use ($assistantMessage): void {
                        $result = is_array($run->result) ? $run->result : [];
                        $run->update([
                            'assistant_message_id' => $assistantMessage->id,
                            'result' => [
                                ...$result,
                                'assistant_message_id' => $assistantMessage->id,
                            ],
                        ]);
                    });
            }

            $this->recordEventLocked($locked, match ($terminalState) {
                VoiceTurnState::Completed => 'turn_completed',
                VoiceTurnState::Failed => 'turn_failed',
                VoiceTurnState::Canceled => 'turn_canceled',
                default => 'turn_terminalized',
            }, $from, $terminalState, [
                ...$metadata,
                'final_assistant_message_id' => $assistantMessage?->id,
                'failure_category' => $failureCategory,
                'side_effect_status' => $sideEffectStatus->value,
            ], 'finalizer');

            return ['turn' => $locked->refresh(), 'conflict' => null];
        }, 3);

        if (is_string($result['conflict'])) {
            throw new VoiceTurnConflictException($result['conflict']);
        }

        return $result['turn']->load(['userMessage', 'finalAssistantMessage', 'runs']);
    }

    /** @param Collection<int, AssistantRun> $runs */
    private function aggregateJobSideEffectStatus(Collection $runs): VoiceTurnSideEffectStatus
    {
        $values = $runs->map(
            fn (AssistantRun $run): string => (string) data_get($run->result, 'side_effect_status', VoiceTurnSideEffectStatus::None->value),
        );

        foreach ([
            VoiceTurnSideEffectStatus::Uncertain,
            VoiceTurnSideEffectStatus::Committed,
            VoiceTurnSideEffectStatus::NotCommitted,
        ] as $status) {
            if ($values->contains($status->value)) {
                return $status;
            }
        }

        return VoiceTurnSideEffectStatus::None;
    }

    /** @return array<string,mixed>|null */
    private function semanticOperationReceipt(AssistantRun $run): ?array
    {
        $receipt = data_get($run->metadata, 'semantic_operation_receipt');
        if (! is_array($receipt)) {
            $receipt = data_get($run->result, 'metadata.semantic_operation_receipt');
        }

        return is_array($receipt) ? $receipt : null;
    }

    /** @param array<string,mixed> $receipt */
    private function semanticOperationSummary(AssistantRun $run, array $receipt): string
    {
        $label = trim((string) $run->label) ?: 'Operation';

        return match ((string) ($receipt['status'] ?? 'failed')) {
            'completed' => "{$label} completed.",
            'skipped' => "{$label} was skipped because a required earlier operation did not complete.",
            'canceled' => "{$label} was canceled.",
            default => "{$label} failed.",
        };
    }

    /** @return array<string,mixed> */
    private function semanticDeadlineFailureReceipt(AssistantRun $run): array
    {
        $operation = json_decode((string) $run->input, true);
        $lane = VoiceTurnLane::tryFrom((string) $run->lane) ?? VoiceTurnLane::Semantic;

        return $this->privacy->sanitizeDiagnosticPayload([
            'operation_id' => is_array($operation) ? (string) ($operation['id'] ?? 'unknown') : 'unknown',
            'tool' => is_array($operation) ? (string) ($operation['tool'] ?? 'system.clock.read') : 'system.clock.read',
            'status' => 'failed',
            'data' => [
                'category' => $lane->value.'_hard_deadline_timeout',
                'internal_detail' => 'The typed operation did not finish before its hard deadline.',
                'failure_scope' => 'system',
            ],
            'side_effect_committed' => false,
            'completed_at' => now()->toIso8601String(),
        ]);
    }

    private function sideEffectRank(VoiceTurnSideEffectStatus $status): int
    {
        return match ($status) {
            VoiceTurnSideEffectStatus::None => 0,
            VoiceTurnSideEffectStatus::NotCommitted => 1,
            VoiceTurnSideEffectStatus::Committed => 2,
            VoiceTurnSideEffectStatus::Uncertain => 3,
        };
    }

    /** @param Collection<int, AssistantRun> $failedRuns */
    private function combinedJobFailureDetail(Collection $failedRuns): string
    {
        return $failedRuns->map(function (AssistantRun $run): string {
            $label = trim((string) $run->label) ?: "Run {$run->id}";
            $detail = trim((string) $run->error) ?: 'The required job failed without an internal detail.';

            return "{$label}: {$detail}";
        })->implode(' | ');
    }

    private function recordTimestampOnce(
        VoiceTurn $turn,
        string $attribute,
        string $eventType,
        array $payload,
        string $source,
        ?\Closure $assertEligible = null,
    ): VoiceTurn {
        return DB::transaction(function () use ($turn, $attribute, $eventType, $payload, $source, $assertEligible): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->getAttribute($attribute) !== null) {
                return $locked;
            }
            $assertEligible?->__invoke($locked);

            $locked->update([
                $attribute => now(),
                'version' => $locked->version + 1,
            ]);
            $this->recordEventLocked($locked, $eventType, $locked->state, $locked->state, $payload, $source);

            return $locked->refresh();
        }, 3);
    }

    private function assertDurableFinalReady(VoiceTurn $turn, string $medium): void
    {
        if (! $turn->state->isTerminal() || $turn->final_assistant_message_id === null) {
            throw new VoiceTurnConflictException("The final {$medium} is not ready for delivery.");
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertBrowserPlaybackEventEligible(
        VoiceTurn $turn,
        string $eventType,
        array $payload,
    ): void {
        $purpose = trim((string) data_get($payload, 'purpose', ''));
        if ($eventType === 'playback_started') {
            if ($purpose === 'clarification') {
                $this->assertActiveClarificationPlayback($turn);
            }

            return;
        }
        if (! in_array($eventType, ['playback_finished', 'playback_stopped'], true)) {
            return;
        }
        if (! in_array($purpose, ['final', 'acknowledgement', 'clarification'], true)) {
            throw new VoiceTurnConflictException('A terminal playback event requires an eligible speech purpose.');
        }

        match ($purpose) {
            'final' => $this->assertDurableFinalReady($turn, 'audio'),
            'acknowledgement' => $this->assertAcknowledgementPlaybackStarted($turn),
            'clarification' => $this->assertActiveClarificationPlayback($turn),
        };
        $this->assertMatchingPlaybackStarted($turn, $purpose, $payload);
    }

    private function assertAcknowledgementPlaybackStarted(VoiceTurn $turn): void
    {
        if (! $turn->acknowledgement_required
            || blank($turn->acknowledgement_text)
            || $turn->acknowledged_at === null) {
            throw new VoiceTurnConflictException('The acknowledgement has not started playback.');
        }
    }

    private function assertActiveClarificationPlayback(VoiceTurn $turn): void
    {
        if ($turn->state !== VoiceTurnState::AwaitingClarification
            || blank(data_get($turn->metadata, 'clarification_question'))) {
            throw new VoiceTurnConflictException('That clarification is not active for playback.');
        }
    }

    /** @param array<string,mixed> $payload */
    private function assertMatchingPlaybackStarted(VoiceTurn $turn, string $purpose, array $payload): void
    {
        $speechItemId = trim((string) data_get($payload, 'speech_item_id', ''));
        if ($speechItemId === '') {
            throw new VoiceTurnConflictException('A terminal playback event requires its stable speech item id.');
        }

        $started = VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('source', 'browser')
            ->whereIn('event_type', ['acknowledgement_started', 'final_audio_started', 'playback_started'])
            ->get(['event_type', 'payload'])
            ->contains(function (VoiceTurnEvent $event) use ($purpose, $speechItemId): bool {
                if (trim((string) data_get($event->payload, 'speech_item_id', '')) !== $speechItemId) {
                    return false;
                }

                return match ($purpose) {
                    'acknowledgement' => $event->event_type === 'acknowledgement_started',
                    'final' => $event->event_type === 'final_audio_started'
                        || ($event->event_type === 'playback_started'
                            && data_get($event->payload, 'purpose') === 'final'),
                    'clarification' => $event->event_type === 'playback_started'
                        && data_get($event->payload, 'purpose') === 'clarification',
                };
            });
        if (! $started) {
            throw new VoiceTurnConflictException('That speech item has not started playback.');
        }
    }

    private function recordJobWait(AssistantRun $run, string $reason): void
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $key = match ($reason) {
            'resource' => 'resource_wait_started_at',
            'priority' => 'priority_wait_started_at',
            'dependency' => 'dependency_wait_started_at',
            default => 'capacity_wait_started_at',
        };
        if (! isset($metadata[$key])) {
            $run->update(['metadata' => [...$metadata, $key => now()->toIso8601String()]]);
        }
    }

    private function hasRunnableHigherPriorityJob(AssistantRun $run): bool
    {
        $higherPriorityJobs = AssistantRun::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->whereNotNull('voice_turn_id')
            ->where('id', '!=', $run->id)
            ->where('lane', VoiceTurnLane::AppWrite->value)
            ->where('status', 'queued')
            ->where('priority', '>', $run->priority)
            ->whereNotNull('dispatch_requested_at')
            ->where(function ($query): void {
                $query->whereNull('hard_deadline_at')->orWhere('hard_deadline_at', '>', now());
            })
            ->orderByDesc('priority')
            ->orderBy('id')
            ->get(['id', 'workspace_id', 'resource_lock_key']);

        return $higherPriorityJobs->contains(function (AssistantRun $candidate): bool {
            if ($candidate->resource_lock_key === null) {
                return true;
            }

            return ! AssistantRun::query()
                ->where('workspace_id', $candidate->workspace_id)
                ->whereNotNull('voice_turn_id')
                ->where('id', '!=', $candidate->id)
                ->where('resource_lock_key', $candidate->resource_lock_key)
                ->whereIn('status', ['running', 'finalizing'])
                ->exists();
        });
    }

    private function recordEventLocked(
        VoiceTurn $turn,
        string $eventType,
        VoiceTurnState|string|null $from,
        VoiceTurnState|string|null $to,
        array $payload,
        string $source,
    ): VoiceTurnEvent {
        $sequence = ((int) VoiceTurnEvent::query()->where('voice_turn_id', $turn->id)->max('sequence')) + 1;

        return VoiceTurnEvent::create([
            'voice_turn_id' => $turn->id,
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'conversation_session_id' => $turn->conversation_session_id,
            'sequence' => $sequence,
            'event_type' => $eventType,
            'from_state' => $from instanceof VoiceTurnState ? $from->value : $from,
            'to_state' => $to instanceof VoiceTurnState ? $to->value : $to,
            'version' => $turn->version,
            'source' => $source,
            'payload' => $this->privacy->sanitizeDiagnosticPayload($payload),
        ]);
    }

    private function scheduleDeadlines(VoiceTurn $turn): void
    {
        foreach ([$turn->no_progress_deadline_at, $turn->hard_deadline_at] as $deadline) {
            if ($deadline !== null) {
                $this->dispatchDeadlineEnforcement($turn->id, $deadline, afterCommit: true);
            }
        }
    }

    private function dispatchDeadlineEnforcement(
        int $voiceTurnId,
        Carbon $deadline,
        bool $afterCommit = false,
    ): void {
        $queueAt = $deadline->copy();
        if ((int) $queueAt->format('u') > 0) {
            // Queue timestamps are commonly second-granular. Round toward the
            // future so the one-shot enforcer never runs before the durable
            // microsecond cutoff and exits without terminalizing anything.
            $queueAt = $queueAt->addSecond()->startOfSecond();
        }
        $dispatch = EnforceBrowserVoiceTurnDeadline::dispatch(
            $voiceTurnId,
            $deadline->format('Y-m-d\TH:i:s.uP'),
        )->delay($queueAt);
        if ($afterCommit) {
            $dispatch->afterCommit();
        }
    }
}
