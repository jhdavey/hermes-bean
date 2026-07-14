<?php

namespace App\Services;

use App\Data\VoiceTurnRoute;
use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\VoiceTurnConflictException;
use App\Jobs\EnforceBrowserVoiceTurnDeadline;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class VoiceTurnLifecycleService
{
    public function __construct(
        private readonly BrowserVoiceAdmissionRouter $router,
        private readonly BrowserVoiceRequestCompletenessService $completeness,
        private readonly VoiceTurnPrivacyService $privacy,
        private readonly FastDomainWriteService $domainWrites,
        private readonly BrowserVoiceContextReferenceResolver $contextReferences,
    ) {}

    /**
     * @param  array<string, mixed>  $input
     */
    public function admit(User $user, ConversationSession $session, array $input): VoiceTurn
    {
        $transcript = trim((string) $input['transcript']);
        $sanitizedTranscript = $this->privacy->sanitizeTranscript($transcript);
        $turnId = trim((string) $input['turn_id']);
        $conversationContext = [
            'mode' => data_get($input, 'conversation_context.mode') === 'contextual_follow_up'
                ? 'contextual_follow_up'
                : 'new_conversation',
            'epoch' => max(0, (int) data_get($input, 'conversation_context.epoch', 0)),
        ];
        $inputContext = [
            'timezone' => $input['timezone'] ?? null,
            'location_context' => $input['location_context'] ?? null,
            'transcript_timing' => $input['transcript_timing'] ?? null,
            'controller_generation' => $input['controller_generation'] ?? null,
            'provider_connection_generation' => $input['provider_connection_generation'] ?? null,
            'conversation_context' => $conversationContext,
        ];
        $inputContext = array_filter($inputContext, static fn (mixed $value): bool => $value !== null);
        // Only client-supplied, immutable admission facts participate in the
        // stable-turn fingerprint. Server-observed authorization may change as
        // earlier concurrent admissions settle, but an idempotent retry must
        // still resolve to the originally admitted turn.
        $fingerprintContext = $inputContext;
        $priorTurn = $this->contextReferences->priorTurn($user, $session, $input);
        $inputContext['prior_context_authorized'] = $priorTurn instanceof VoiceTurn;
        $contextualReference = $priorTurn instanceof VoiceTurn
            ? $this->contextReferences->resolve($user, $session, $priorTurn, $transcript)
            : null;
        $activeBackgroundJobCount = AssistantRun::query()
            ->where('user_id', $user->id)
            ->where('conversation_session_id', $session->id)
            ->whereNotNull('voice_turn_id')
            ->whereIn('status', ['queued', 'running', 'finalizing'])
            ->count();
        $context = [
            ...$inputContext,
            'active_background_job_count' => $activeBackgroundJobCount,
            ...($priorTurn instanceof VoiceTurn ? [
                'prior_turn_id' => $priorTurn->turn_id,
                'prior_handler' => $priorTurn->handler,
                'prior_transcript' => $priorTurn->transcript,
            ] : []),
            ...($contextualReference !== null ? ['contextual_reference' => $contextualReference] : []),
        ];
        $declaredLocalHandler = trim((string) ($input['declared_local_handler'] ?? '')) ?: null;
        $clarificationQuestion = trim((string) ($input['_clarification_question'] ?? '')) ?: null;
        $route = $clarificationQuestion === null
            ? $this->router->route($transcript, $context, $declaredLocalHandler)
            : new VoiceTurnRoute(
                VoiceTurnLane::ComplexAgent,
                'clarification.pending',
                false,
                null,
                30,
            );
        $fingerprint = hash('sha256', json_encode([
            'session_id' => (int) $session->id,
            'transcript' => $transcript,
            'context' => $fingerprintContext,
            'declared_local_handler' => $declaredLocalHandler,
        ], JSON_THROW_ON_ERROR));
        $now = now();

        $result = DB::transaction(function () use ($user, $session, $turnId, $transcript, $sanitizedTranscript, $context, $route, $fingerprint, $now, $clarificationQuestion): array {
            ConversationSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $existing = VoiceTurn::query()->where('turn_id', $turnId)->lockForUpdate()->first();

            if ($existing instanceof VoiceTurn) {
                $sameOwner = (int) $existing->user_id === (int) $user->id
                    && (int) $existing->conversation_session_id === (int) $session->id;
                $sameAdmission = hash_equals((string) data_get($existing->metadata, 'admission_fingerprint', ''), $fingerprint);

                if (! $sameOwner || ! $sameAdmission) {
                    return [
                        'turn' => $existing,
                        'conflict' => 'That stable turn ID has already been admitted with different request data.',
                    ];
                }

                $this->recordEventLocked($existing, 'admission_deduplicated', $existing->state, $existing->state, [
                    'idempotency_key' => $existing->idempotency_key,
                ], 'admission');

                return ['turn' => $existing->refresh(), 'conflict' => null, 'created' => false];
            }

            $legacyStableTurnExists = ConversationMessage::query()
                ->where('conversation_session_id', $session->id)
                ->where('client_turn_id', $turnId)
                ->exists();
            if ($legacyStableTurnExists) {
                return [
                    'turn' => new VoiceTurn,
                    'conflict' => 'That stable turn ID was already admitted by the legacy voice path.',
                    'created' => false,
                ];
            }

            $turn = VoiceTurn::create([
                'turn_id' => $turnId,
                'user_id' => $user->id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'source' => 'browser_voice_v2',
                'client_kind' => 'browser_voice',
                'transcript' => $transcript,
                'sanitized_transcript' => $sanitizedTranscript,
                'lane' => $route->lane,
                'handler' => $route->handler,
                'state' => $clarificationQuestion === null
                    ? VoiceTurnState::Accepted
                    : VoiceTurnState::AwaitingClarification,
                'version' => 1,
                'idempotency_key' => $turnId,
                'acknowledgement_required' => $route->acknowledgementRequired,
                'acknowledgement_text' => $route->acknowledgementText,
                'accepted_at' => $clarificationQuestion === null ? $now : null,
                'hard_deadline_at' => $clarificationQuestion === null
                    ? $now->copy()->addSeconds($route->hardDeadlineSeconds)
                    : null,
                'no_progress_deadline_at' => $clarificationQuestion !== null || $route->noProgressDeadlineSeconds === null
                    ? null
                    : $now->copy()->addSeconds($route->noProgressDeadlineSeconds),
                'side_effect_status' => VoiceTurnSideEffectStatus::None,
                'metadata' => [
                    ...$context,
                    'admission_fingerprint' => $fingerprint,
                    'raw_audio_retained' => false,
                    'no_progress_interval_seconds' => $route->noProgressDeadlineSeconds,
                    ...($clarificationQuestion === null ? [] : [
                        'clarification_question' => $clarificationQuestion,
                        'clarification_sequence' => 1,
                        'clarification_resolutions' => [],
                    ]),
                ],
            ]);

            $userMessage = ConversationMessage::firstOrCreate([
                'conversation_session_id' => $session->id,
                'client_turn_id' => $turnId,
                'role' => 'user',
            ], [
                'user_id' => $user->id,
                'content' => $transcript,
                'metadata' => [
                    'source' => 'browser_voice_v2',
                    'voice_turn_id' => $turn->id,
                    'stable_turn_id' => $turnId,
                ],
            ]);

            $turn->update(['user_message_id' => $userMessage->id]);
            $this->recordEventLocked($turn, 'turn_admitted', null, $turn->state, [
                'lane' => $route->lane->value,
                'handler' => $route->handler,
                'acknowledgement_required' => $route->acknowledgementRequired,
                'hard_deadline_at' => $turn->hard_deadline_at?->toIso8601String(),
                'no_progress_deadline_at' => $turn->no_progress_deadline_at?->toIso8601String(),
                'clarification_question' => $clarificationQuestion,
            ], 'admission');
            if ($clarificationQuestion !== null) {
                $this->recordEventLocked($turn, 'clarification_requested', $turn->state, $turn->state, [
                    'question' => $clarificationQuestion,
                    'sequence' => 1,
                ], 'admission');
            }
            $session->update(['last_activity_at' => $now]);

            return ['turn' => $turn->refresh(), 'conflict' => null, 'created' => true];
        }, 3);

        if (is_string($result['conflict'])) {
            throw new VoiceTurnConflictException($result['conflict']);
        }

        /** @var VoiceTurn $turn */
        $turn = $result['turn'];
        if ($result['created']) {
            $this->scheduleDeadlines($turn);
        }

        return $turn->load(['userMessage', 'finalAssistantMessage', 'runs']);
    }

    public function resolveClarification(VoiceTurn $turn, string $answer, string $clarificationId): VoiceTurn
    {
        $answer = trim($answer);
        $clarificationId = trim($clarificationId);
        if ($answer === '' || $clarificationId === '') {
            throw new VoiceTurnConflictException('A clarification answer and id are required.');
        }

        $resolved = DB::transaction(function () use ($turn, $answer, $clarificationId): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
            $resolutions = is_array($metadata['clarification_resolutions'] ?? null)
                ? $metadata['clarification_resolutions']
                : [];
            $answerHash = hash('sha256', $answer);

            if (isset($resolutions[$clarificationId])) {
                if (! hash_equals((string) $resolutions[$clarificationId], $answerHash)) {
                    throw new VoiceTurnConflictException('That clarification id was already used for a different answer.');
                }

                return $locked->refresh();
            }
            if ($locked->state !== VoiceTurnState::AwaitingClarification) {
                throw new VoiceTurnConflictException('That voice request is not waiting for clarification.');
            }

            $combinedTranscript = $this->combineClarification(
                $locked->transcript,
                $answer,
                (string) data_get($metadata, 'clarification_question', ''),
            );
            $hasDefaultLocation = filled(data_get($metadata, 'location_context.label'))
                || (data_get($metadata, 'location_context.latitude') !== null
                    && data_get($metadata, 'location_context.longitude') !== null);
            $question = $this->completeness->clarificationQuestion(
                $combinedTranscript,
                $hasDefaultLocation,
                data_get($metadata, 'timezone'),
                true,
                (bool) data_get($metadata, 'prior_context_authorized', false),
                is_array(data_get($metadata, 'contextual_reference')),
            );
            $resolutions[$clarificationId] = $answerHash;
            $sequence = max(1, (int) ($metadata['clarification_sequence'] ?? 1)) + 1;
            $metadata = [
                ...$metadata,
                'clarification_resolutions' => $resolutions,
                'clarification_sequence' => $sequence,
                'clarification_question' => $question,
                'clarification_deadline_started_at' => null,
            ];
            $from = $locked->state;

            if ($question === null) {
                $route = $this->router->route($combinedTranscript, $metadata);
                $now = now();
                // Admission route fields are immutable after acceptance. This
                // is the single lifecycle-owned seal from clarification draft
                // to executable request, so bypass the generic model guard.
                $locked->forceFill([
                    'transcript' => $combinedTranscript,
                    'sanitized_transcript' => $this->privacy->sanitizeTranscript($combinedTranscript),
                    'lane' => $route->lane,
                    'handler' => $route->handler,
                    'state' => VoiceTurnState::Accepted,
                    'version' => $locked->version + 1,
                    'acknowledgement_required' => $route->acknowledgementRequired,
                    'acknowledgement_text' => $route->acknowledgementText,
                    'accepted_at' => $now,
                    'hard_deadline_at' => $now->copy()->addSeconds($route->hardDeadlineSeconds),
                    'no_progress_deadline_at' => $route->noProgressDeadlineSeconds === null
                        ? null
                        : $now->copy()->addSeconds($route->noProgressDeadlineSeconds),
                    'metadata' => [
                        ...$metadata,
                        'no_progress_interval_seconds' => $route->noProgressDeadlineSeconds,
                    ],
                ])->saveQuietly();
                $locked->userMessage?->update(['content' => $combinedTranscript]);
                $this->recordEventLocked($locked, 'clarification_resolved', $from, VoiceTurnState::Accepted, [
                    'clarification_id' => $clarificationId,
                    'sequence' => $sequence,
                    'lane' => $route->lane->value,
                    'handler' => $route->handler,
                ], 'clarification');
            } else {
                $locked->forceFill([
                    'transcript' => $combinedTranscript,
                    'sanitized_transcript' => $this->privacy->sanitizeTranscript($combinedTranscript),
                    'version' => $locked->version + 1,
                    'hard_deadline_at' => null,
                    'no_progress_deadline_at' => null,
                    'metadata' => $metadata,
                ])->saveQuietly();
                $locked->userMessage?->update(['content' => $combinedTranscript]);
                $this->recordEventLocked($locked, 'clarification_requested', $from, $from, [
                    'clarification_id' => $clarificationId,
                    'question' => $question,
                    'sequence' => $sequence,
                ], 'clarification');
            }

            return $locked->refresh();
        }, 3);

        if ($resolved->state === VoiceTurnState::Accepted) {
            $this->scheduleDeadlines($resolved);
        }

        return $resolved->load(['userMessage', 'finalAssistantMessage', 'runs']);
    }

    public function startClarificationDeadline(VoiceTurn $turn, int $seconds = 30): VoiceTurn
    {
        $updated = DB::transaction(function () use ($turn, $seconds): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->state !== VoiceTurnState::AwaitingClarification) {
                return $locked;
            }
            $metadata = is_array($locked->metadata) ? $locked->metadata : [];
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
            EnforceBrowserVoiceTurnDeadline::dispatch($updated->id, $updated->hard_deadline_at->toIso8601String())
                ->delay($updated->hard_deadline_at);
        }

        return $updated;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transition(
        VoiceTurn $turn,
        VoiceTurnState $to,
        ?int $expectedVersion = null,
        array $payload = [],
        string $source = 'server',
    ): VoiceTurn {
        if ($to->isTerminal()) {
            throw new VoiceTurnConflictException('Terminal voice turns must use the single finalization or cancellation path.');
        }

        $result = DB::transaction(function () use ($turn, $to, $expectedVersion, $payload, $source): array {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $from = $locked->state;

            if ($expectedVersion !== null && $locked->version !== $expectedVersion) {
                $this->recordEventLocked($locked, 'transition_rejected', $from, $to, [
                    ...$payload,
                    'reason' => 'version_conflict',
                    'expected_version' => $expectedVersion,
                    'actual_version' => $locked->version,
                ], $source);

                return ['turn' => $locked->refresh(), 'conflict' => 'Voice turn version conflict.'];
            }

            if ($from === $to) {
                $this->recordEventLocked($locked, 'transition_deduplicated', $from, $to, $payload, $source);

                return ['turn' => $locked->refresh(), 'conflict' => null];
            }

            if (! $this->mayTransition($from, $to)) {
                $this->recordEventLocked($locked, 'transition_rejected', $from, $to, [
                    ...$payload,
                    'reason' => 'non_monotonic_transition',
                ], $source);

                return ['turn' => $locked->refresh(), 'conflict' => "Voice turn cannot move from {$from->value} to {$to->value}."];
            }

            $locked->update([
                'state' => $to,
                'version' => $locked->version + 1,
                'started_at' => $to === VoiceTurnState::Running ? ($locked->started_at ?? now()) : $locked->started_at,
            ]);
            $this->recordEventLocked($locked, 'state_transitioned', $from, $to, $payload, $source);

            return ['turn' => $locked->refresh(), 'conflict' => null];
        }, 3);

        if (is_string($result['conflict'])) {
            throw new VoiceTurnConflictException($result['conflict']);
        }

        return $result['turn'];
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
            EnforceBrowserVoiceTurnDeadline::dispatch(
                $progressed->id,
                $progressed->no_progress_deadline_at->toIso8601String(),
            )->delay($progressed->no_progress_deadline_at);
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
        return $this->recordTimestampOnce($turn, 'acknowledged_at', 'acknowledgement_started', $payload, $source);
    }

    /** @param array<string, mixed> $payload */
    public function markFinalDelivered(VoiceTurn $turn, array $payload = [], string $source = 'browser'): VoiceTurn
    {
        return $this->recordTimestampOnce($turn, 'final_delivered_at', 'final_text_delivered', $payload, $source);
    }

    /** @param array<string, mixed> $payload */
    public function recordBrowserEvent(VoiceTurn $turn, string $eventType, array $payload = []): VoiceTurn
    {
        return DB::transaction(function () use ($turn, $eventType, $payload): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            $this->recordEventLocked($locked, $eventType, $locked->state, $locked->state, $payload, 'browser');

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
                throw new VoiceTurnConflictException('Only Browser Voice v2 jobs can be canceled through this lifecycle.');
            }

            $lockedTurn = VoiceTurn::query()->whereKey($voiceTurnId)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()
                ->whereKey($run->id)
                ->where('voice_turn_id', $lockedTurn->id)
                ->lockForUpdate()
                ->firstOrFail();

            // A typed write and cancellation both lock the turn before the run.
            // Re-read the run receipt under those same locks so cancellation can
            // never win after a side effect committed but before the worker
            // reached its finalizer.
            $committedText = $this->isReceiptBackedWriteRun($lockedRun, $lockedTurn)
                ? $this->domainWrites->reconcile($lockedTurn, $lockedRun)
                : null;
            if ($committedText !== null) {
                return $this->finishJob(
                    $lockedRun,
                    'completed',
                    finalText: $committedText,
                    sideEffectStatus: VoiceTurnSideEffectStatus::Committed,
                    metadata: [
                        ...$metadata,
                        'reason' => $reason,
                        'cancellation_requested_after_commit' => true,
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
                throw new VoiceTurnConflictException('Only Browser Voice v2 runs participate in the turn finalizer barrier.');
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

            if ($lockedTurn->state->isTerminal()) {
                $this->recordEventLocked($lockedTurn, 'job_barrier_deduplicated', $lockedTurn->state, $lockedTurn->state, [
                    'run_id' => $lockedRun->id,
                    'run_status' => $lockedRun->status,
                    'reason' => 'turn_already_terminal',
                ], 'finalizer');

                return $lockedTurn->refresh()->load(['userMessage', 'finalAssistantMessage', 'runs']);
            }

            if (! in_array($lockedRun->status, ['completed', 'failed', 'cancelled'], true)) {
                $safeFinalText = $finalText === null ? null : $this->privacy->sanitizeTranscript($finalText);
                $safeInternalDetail = $internalDetail === null ? null : $this->privacy->sanitizeTranscript($internalDetail);
                $safeUserFacingFailure = $userFacingFailure === null ? null : $this->privacy->sanitizeTranscript($userFacingFailure);
                $lockedRun->update([
                    'status' => $status,
                    'result' => [
                        'status' => $status,
                        'voice_turn_id' => $lockedTurn->id,
                        'final_text' => $safeFinalText,
                        'failure_category' => $failureCategory,
                        'user_facing_failure' => $safeUserFacingFailure,
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

            if ($failedRuns->isNotEmpty()) {
                return $this->fail(
                    $lockedTurn,
                    $failedRuns->count() === 1
                        ? ((string) data_get($failedRuns->first()?->result, 'failure_category', '') ?: 'required_job_failed')
                        : 'required_jobs_failed',
                    $this->combinedJobFailureDetail($failedRuns),
                    $this->combinedJobFailureText($requiredRuns, $failedRuns),
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

            return $this->complete(
                $lockedTurn,
                $this->combinedJobSuccessText($requiredRuns),
                $aggregateSideEffect,
                $barrierMetadata,
            );
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    /** @return array{run: AssistantRun, created: bool} */
    public function createJob(
        VoiceTurn $turn,
        string $label,
        string $jobKey,
        ?string $resourceLockKey = null,
        int $priority = 0,
        array $metadata = [],
        ?VoiceTurnLane $lane = null,
        ?string $handler = null,
        ?string $input = null,
        ?int $hardDeadlineSeconds = null,
    ): array {
        return DB::transaction(function () use ($turn, $label, $jobKey, $resourceLockKey, $priority, $metadata, $lane, $handler, $input, $hardDeadlineSeconds): array {
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
                'source' => 'browser_voice_v2',
                'lane' => ($lane ?? $locked->lane)->value,
                'handler' => $handler ?? $locked->handler,
                'label' => mb_substr(trim($label), 0, 180),
                'priority' => $priority,
                'resource_lock_key' => $resourceLockKey,
                'hard_deadline_at' => $hardDeadlineSeconds === null
                    ? $locked->hard_deadline_at
                    : ($locked->accepted_at ?? now())->copy()->addSeconds(max(1, $hardDeadlineSeconds)),
                'last_progress_at' => null,
                'status' => 'queued',
                'input' => trim((string) ($input ?? $locked->transcript)),
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

    public function claimJobExecution(AssistantRun $run): bool
    {
        return DB::transaction(function () use ($run): bool {
            ConversationSession::query()->whereKey($run->conversation_session_id)->lockForUpdate()->firstOrFail();
            $lockedTurn = VoiceTurn::query()->whereKey($run->voice_turn_id)->lockForUpdate()->firstOrFail();
            $lockedRun = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->firstOrFail();
            if ($lockedRun->status !== 'queued' || $lockedTurn->state->isTerminal()) {
                return false;
            }

            $predecessorRun = $this->contextualCreatePredecessorRun($lockedRun, $lockedTurn);
            if ($predecessorRun instanceof AssistantRun
                && in_array($predecessorRun->status, ['queued', 'running', 'finalizing'], true)) {
                $this->recordJobWait($lockedRun, 'dependency');

                return false;
            }

            $capacityLane = in_array($lockedRun->lane, [
                VoiceTurnLane::AppWrite->value,
                VoiceTurnLane::ComplexAgent->value,
            ], true);
            if ($capacityLane) {
                $runningBackgroundJobs = AssistantRun::query()
                    ->where('conversation_session_id', $lockedRun->conversation_session_id)
                    ->whereNotNull('voice_turn_id')
                    ->where('id', '!=', $lockedRun->id)
                    ->whereIn('lane', [VoiceTurnLane::AppWrite->value, VoiceTurnLane::ComplexAgent->value])
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
                'metadata' => array_diff_key($metadata, array_flip(['capacity_wait_started_at', 'resource_wait_started_at'])),
            ]);

            return true;
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
            if ($noProgressExpired && $fresh->lane === VoiceTurnLane::ComplexAgent) {
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

            $writeMayHaveCommitted = ($fresh->lane === VoiceTurnLane::AppWrite
                || $fresh->runs()->where('handler', 'agent.generate_note')->exists())
                && $fresh->state === VoiceTurnState::Running;
            $category = $noProgressExpired ? 'no_progress_timeout' : 'hard_deadline_timeout';
            $userFacing = $this->deadlineFailureText($fresh->lane);

            try {
                if (($receipt = $this->firstCommittedRunReceipt($fresh)) !== null) {
                    $this->finishJob(
                        $receipt['run'],
                        'completed',
                        finalText: $receipt['text'],
                        sideEffectStatus: VoiceTurnSideEffectStatus::Committed,
                        metadata: ['reconciled_at_deadline' => true],
                    );
                    $failed++;

                    continue;
                }
                if ($fresh->lane === VoiceTurnLane::AppWrite
                    && ($reconciled = $this->domainWrites->reconcile($fresh)) !== null) {
                    $this->complete(
                        $fresh,
                        $reconciled,
                        VoiceTurnSideEffectStatus::Committed,
                        ['reconciled_at_deadline' => true],
                    );
                    $failed++;

                    continue;
                }
                $this->fail(
                    $fresh,
                    $category,
                    $noProgressExpired ? 'No meaningful progress was recorded before the deadline.' : 'The hard deadline elapsed.',
                    $userFacing,
                    $writeMayHaveCommitted ? VoiceTurnSideEffectStatus::Uncertain : VoiceTurnSideEffectStatus::NotCommitted,
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
        $expiredRuns = AssistantRun::query()
            ->where('voice_turn_id', $turn->id)
            ->whereIn('status', ['queued', 'running', 'finalizing'])
            ->whereNotNull('hard_deadline_at')
            ->where('hard_deadline_at', '<=', now())
            ->orderBy('id')
            ->get();
        $terminalized = 0;

        foreach ($expiredRuns as $run) {
            $lane = VoiceTurnLane::tryFrom((string) $run->lane) ?? $turn->lane;
            try {
                if ($this->isReceiptBackedWriteRun($run, $turn)
                    && ($reconciled = $this->domainWrites->reconcile($turn->fresh(), $run->fresh())) !== null) {
                    $this->finishJob(
                        $run,
                        'completed',
                        finalText: $reconciled,
                        sideEffectStatus: VoiceTurnSideEffectStatus::Committed,
                        metadata: ['reconciled_at_job_deadline' => true],
                    );
                } else {
                    $this->finishJob(
                        $run,
                        'failed',
                        failureCategory: $turn->lane === VoiceTurnLane::ComplexAgent
                            ? 'job_hard_deadline_timeout'
                            : 'hard_deadline_timeout',
                        internalDetail: 'The required job hard deadline elapsed.',
                        userFacingFailure: $this->deadlineFailureText($lane),
                        sideEffectStatus: $this->isReceiptBackedWriteRun($run, $turn) && $run->status !== 'queued'
                            ? VoiceTurnSideEffectStatus::Uncertain
                            : VoiceTurnSideEffectStatus::NotCommitted,
                        metadata: ['deadline_at' => $run->hard_deadline_at?->toIso8601String()],
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

            if ($terminalState === VoiceTurnState::Canceled
                && ($reconciled = $this->domainWrites->reconcile($locked)) !== null) {
                $terminalState = VoiceTurnState::Completed;
                $finalText = $reconciled;
                $sideEffectStatus = VoiceTurnSideEffectStatus::Committed;
                $metadata = [
                    ...$metadata,
                    'cancellation_requested_after_commit' => true,
                ];
            }

            $requiredRuns = AssistantRun::query()
                ->where('voice_turn_id', $locked->id)
                ->orderBy('id')
                ->get()
                ->filter(fn (AssistantRun $run): bool => data_get($run->metadata, 'required', true) !== false)
                ->values();
            $this->deleteProvisionalAssistantMessages($locked, $requiredRuns);
            if ($terminalState === VoiceTurnState::Canceled) {
                $committedRunTexts = $requiredRuns->mapWithKeys(function (AssistantRun $run) use ($locked): array {
                    if (! $this->isReceiptBackedWriteRun($run, $locked)) {
                        return [];
                    }
                    $receiptText = $this->domainWrites->reconcile($locked, $run);

                    return $receiptText === null ? [] : [(string) $run->id => $receiptText];
                });
                foreach ($requiredRuns->whereIn('id', $committedRunTexts->keys()) as $committedRun) {
                    $receiptText = (string) $committedRunTexts->get((string) $committedRun->id);
                    $committedRun->update([
                        'status' => 'completed',
                        'result' => [
                            'status' => 'completed',
                            'voice_turn_id' => $locked->id,
                            'final_text' => $receiptText,
                            'side_effect_status' => VoiceTurnSideEffectStatus::Committed->value,
                            'reconciled_during_cancellation' => true,
                        ],
                        'completed_at' => now(),
                        'last_progress_at' => now(),
                    ]);
                }
                $requiredRuns = AssistantRun::query()
                    ->whereIn('id', $requiredRuns->pluck('id'))
                    ->orderBy('id')
                    ->get();
                $allRequiredWorkCompleted = $requiredRuns->isNotEmpty()
                    && $requiredRuns->every(fn (AssistantRun $run): bool => $run->status === 'completed');
                if ($allRequiredWorkCompleted) {
                    $terminalState = VoiceTurnState::Completed;
                    $finalText = $this->combinedJobSuccessText($requiredRuns);
                    $sideEffectStatus = $this->aggregateJobSideEffectStatus($requiredRuns);
                    $metadata = [
                        ...$metadata,
                        'cancellation_requested_after_all_work_completed' => true,
                        'committed_run_ids' => $committedRunTexts->keys()->map(fn (string $id): int => (int) $id)->all(),
                        'barrier_run_ids' => $requiredRuns->pluck('id')->all(),
                        'barrier_run_statuses' => $requiredRuns->mapWithKeys(
                            fn (AssistantRun $run): array => [(string) $run->id => $run->status],
                        )->all(),
                    ];
                } elseif ($committedRunTexts->isNotEmpty()) {
                    $sideEffectStatus = VoiceTurnSideEffectStatus::Committed;
                    $metadata = [
                        ...$metadata,
                        'cancellation_after_partial_commit' => true,
                        'committed_run_ids' => $committedRunTexts->keys()->map(fn (string $id): int => (int) $id)->all(),
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
                $safeFinalText = $this->privacy->sanitizeTranscript((string) $finalText);
                $assistantMessage = ConversationMessage::firstOrCreate([
                    'conversation_session_id' => $locked->conversation_session_id,
                    'client_turn_id' => $locked->turn_id,
                    'role' => 'assistant',
                ], [
                    'user_id' => $locked->user_id,
                    'content' => $safeFinalText,
                    'metadata' => [
                        'source' => 'browser_voice_v2',
                        'voice_turn_id' => $locked->id,
                        'stable_turn_id' => $locked->turn_id,
                        'final_response' => true,
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
                    ? $this->privacy->sanitizeTranscript((string) $finalText)
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

    /** @return array{run: AssistantRun, text: string}|null */
    private function firstCommittedRunReceipt(VoiceTurn $turn): ?array
    {
        foreach ($turn->runs()->orderBy('id')->get() as $run) {
            if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)
                || ! $this->isReceiptBackedWriteRun($run, $turn)) {
                continue;
            }
            $text = $this->domainWrites->reconcile($turn, $run);
            if ($text !== null) {
                return ['run' => $run, 'text' => $text];
            }
        }

        return null;
    }

    private function isReceiptBackedWriteRun(AssistantRun $run, VoiceTurn $turn): bool
    {
        return (VoiceTurnLane::tryFrom((string) $run->lane) ?? $turn->lane) === VoiceTurnLane::AppWrite
            || ($run->handler ?: $turn->handler) === 'agent.generate_note';
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

    /** @param Collection<int, AssistantRun> $runs */
    private function deleteProvisionalAssistantMessages(VoiceTurn $turn, Collection $runs): void
    {
        $runIds = $runs->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        if ($runIds === []) {
            return;
        }

        ConversationMessage::query()
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('role', 'assistant')
            ->where('id', '!=', $turn->final_assistant_message_id)
            ->get()
            ->filter(fn (ConversationMessage $message): bool => in_array(
                (int) data_get($message->metadata, 'assistant_run_id', 0),
                $runIds,
                true,
            ))
            ->each->delete();
    }

    /** @param Collection<int, AssistantRun> $runs */
    private function combinedJobSuccessText(Collection $runs): string
    {
        if ($runs->count() === 1) {
            $text = trim((string) data_get($runs->first()?->result, 'final_text'));

            return $text !== '' ? $text : 'Done—I finished that request.';
        }

        $parts = $runs->map(function (AssistantRun $run): string {
            $label = trim((string) $run->label) ?: 'Work item';
            $text = trim((string) data_get($run->result, 'final_text')) ?: 'Completed.';

            return "{$label}: {$text}";
        })->all();

        return 'I finished all the requested work. '.implode(' ', $parts);
    }

    /**
     * @param  Collection<int, AssistantRun>  $allRuns
     * @param  Collection<int, AssistantRun>  $failedRuns
     */
    private function combinedJobFailureText(Collection $allRuns, Collection $failedRuns): string
    {
        if ($allRuns->count() === 1) {
            $text = trim((string) data_get($failedRuns->first()?->result, 'user_facing_failure'));

            return $text !== '' ? $text : 'I couldn’t finish that request. Would you like me to try again?';
        }

        $completedLabels = $allRuns
            ->where('status', 'completed')
            ->map(fn (AssistantRun $run): string => trim((string) $run->label) ?: 'one work item')
            ->values()
            ->all();
        $failedLabels = $failedRuns
            ->map(fn (AssistantRun $run): string => trim((string) $run->label) ?: 'one work item')
            ->values()
            ->all();
        $prefix = $completedLabels === []
            ? 'I couldn’t finish all the requested work.'
            : 'I finished '.implode(', ', $completedLabels).', but some requested work did not finish.';

        return $prefix.' Failed: '.implode(', ', $failedLabels).'. Would you like me to try the failed work again?';
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

    private function mayTransition(VoiceTurnState $from, VoiceTurnState $to): bool
    {
        return match ($from) {
            VoiceTurnState::Capturing => in_array($to, [VoiceTurnState::AwaitingClarification, VoiceTurnState::Accepted], true),
            VoiceTurnState::AwaitingClarification => $to === VoiceTurnState::Accepted,
            VoiceTurnState::Accepted => $to === VoiceTurnState::Running,
            default => false,
        };
    }

    private function recordTimestampOnce(
        VoiceTurn $turn,
        string $attribute,
        string $eventType,
        array $payload,
        string $source,
    ): VoiceTurn {
        return DB::transaction(function () use ($turn, $attribute, $eventType, $payload, $source): VoiceTurn {
            $locked = VoiceTurn::query()->whereKey($turn->id)->lockForUpdate()->firstOrFail();
            if ($locked->getAttribute($attribute) !== null) {
                return $locked;
            }

            $locked->update([
                $attribute => now(),
                'version' => $locked->version + 1,
            ]);
            $this->recordEventLocked($locked, $eventType, $locked->state, $locked->state, $payload, $source);

            return $locked->refresh();
        }, 3);
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

    private function contextualCreatePredecessorRun(AssistantRun $run, VoiceTurn $turn): ?AssistantRun
    {
        $resourceLockKey = trim((string) ($run->resource_lock_key ?? ''));
        $sameTurnDependency = data_get($run->metadata, 'contextual_create_dependency');
        if (is_array($sameTurnDependency)) {
            if (data_get($sameTurnDependency, 'scope') !== 'same_turn'
                || data_get($sameTurnDependency, 'resource_lock_key') !== $resourceLockKey) {
                return null;
            }

            return AssistantRun::query()
                ->where('voice_turn_id', $turn->id)
                ->where('idempotency_key', (string) data_get($sameTurnDependency, 'predecessor_idempotency_key', ''))
                ->where('handler', (string) data_get($sameTurnDependency, 'predecessor_handler', ''))
                ->where('resource_lock_key', $resourceLockKey)
                ->first();
        }

        $dependencies = is_array(data_get($turn->metadata, 'contextual_create_dependencies'))
            ? data_get($turn->metadata, 'contextual_create_dependencies')
            : [];
        $dependency = $resourceLockKey !== '' ? ($dependencies[$resourceLockKey] ?? null) : null;
        if (! is_array($dependency)
            || data_get($dependency, 'resource_lock_key') !== $resourceLockKey) {
            return null;
        }

        return AssistantRun::query()
            ->where('voice_turn_id', (int) data_get($dependency, 'voice_turn_id', 0))
            ->where('handler', 'app.'.data_get($dependency, 'domain').'.create')
            ->where('resource_lock_key', $resourceLockKey)
            ->latest('id')
            ->first();
    }

    private function hasRunnableHigherPriorityJob(AssistantRun $run): bool
    {
        $higherPriorityJobs = AssistantRun::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->whereNotNull('voice_turn_id')
            ->where('id', '!=', $run->id)
            ->whereIn('lane', [VoiceTurnLane::AppWrite->value, VoiceTurnLane::ComplexAgent->value])
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
                EnforceBrowserVoiceTurnDeadline::dispatch($turn->id, $deadline->toIso8601String())
                    ->delay($deadline)
                    ->afterCommit();
            }
        }
    }

    private function deadlineFailureText(VoiceTurnLane $lane): string
    {
        return match ($lane) {
            VoiceTurnLane::Instant => 'I couldn’t answer that quickly enough. Would you like me to try again?',
            VoiceTurnLane::AppRead => 'I couldn’t retrieve that from the app in time. Would you like me to try again?',
            VoiceTurnLane::AppWrite => 'I couldn’t confirm that change in time. Would you like me to check what happened and try again?',
            VoiceTurnLane::External => 'I couldn’t reach that service in time. Would you like me to try again?',
            VoiceTurnLane::ComplexAgent => 'I wasn’t able to finish that work. Would you like me to try again?',
        };
    }

    private function combineClarification(string $transcript, string $answer, string $question): string
    {
        $base = rtrim(trim($transcript), " \t\n\r\0\x0B.!?");
        $detail = trim($answer);
        $normalizedQuestion = mb_strtolower(trim($question));

        $joiner = match (true) {
            str_contains($normalizedQuestion, 'which location') => ' in ',
            str_contains($normalizedQuestion, 'what should i remind you about') => ' titled ',
            str_contains($normalizedQuestion, 'what should the note include') => ' with content ',
            str_contains($normalizedQuestion, 'what task should i create'),
            str_contains($normalizedQuestion, 'what should i schedule'),
            str_contains($normalizedQuestion, 'which reminder should i change'),
            str_contains($normalizedQuestion, 'which task should i change'),
            str_contains($normalizedQuestion, 'which note should i change'),
            str_contains($normalizedQuestion, 'which calendar event should i change') => ' titled ',
            default => ' ',
        };

        return trim($base.$joiner.$detail);
    }
}
