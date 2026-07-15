<?php

namespace App\Services;

use App\Data\AssistantRunExecutionClaim;
use App\Exceptions\AssistantRunClaimLostException;
use App\Exceptions\AssistantRunConflictException;
use App\Exceptions\VoiceTurnConflictException;
use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\VoiceTurn;
use Closure;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantRunService
{
    public const SYSTEM_FAILURE_FINAL = 'I couldn’t complete that request because Bean is temporarily unavailable. Please try again.';

    /**
     * Metadata that describes server-observed run state or server-created relationships.
     * None of these values may be supplied by an API client: several are consumed by the
     * cancellation/recovery paths and therefore cannot safely be treated as annotations.
     *
     * @var array<int, string>
     */
    private const SERVER_OWNED_METADATA_KEYS = [
        'assistant_run_id',
        'status',
        'run_status',
        'response_status',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'cancelled_before_queue',
        'cancellation_requested_at',
        'cancellation_source',
        'background_stale_retry_attempts',
        'background_stale_retried_at',
        'execution_generation',
        'queued_at',
        'edited_from_message_id',
        'edited_message_id',
        'request_fingerprint',
        'source',
    ];

    public function __construct(private readonly Dispatcher $dispatcher) {}

    /**
     * Keep caller context while removing state that only the run processor may assert.
     */
    public function sanitizeClientMetadata(array $metadata): array
    {
        foreach (self::SERVER_OWNED_METADATA_KEYS as $key) {
            unset($metadata[$key]);
        }

        return $metadata;
    }

    /**
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>,existing:bool}
     */
    public function queueRun(ConversationSession $session, string $content, array $metadata = [], string $source = 'http'): array
    {
        return $this->queueRunRequest($session, $content, $metadata, $source);
    }

    /**
     * Admit an immutable branch from one durable user message. The branch
     * relationship and the new run are sealed by the same lifecycle owner;
     * prior messages and their run/turn pointers are never rewritten.
     *
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>,existing:bool}
     */
    public function queueBranchRun(
        ConversationSession $session,
        int $editedFromMessageId,
        string $content,
        array $metadata = [],
        string $source = 'conversation_branch',
    ): array {
        $metadata = $this->sanitizeClientMetadata($metadata);
        $metadata['edited_from_message_id'] = $editedFromMessageId;

        return $this->queueRunRequest(
            $session,
            $content,
            $metadata,
            $source,
            $editedFromMessageId,
        );
    }

    /**
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>,existing:bool}
     */
    private function queueRunRequest(
        ConversationSession $session,
        string $content,
        array $metadata,
        string $source,
        ?int $editedFromMessageId = null,
    ): array {
        $metadata = $this->sanitizeClientMetadata($metadata);
        if ($editedFromMessageId !== null) {
            $metadata['edited_from_message_id'] = $editedFromMessageId;
        }
        $source = trim($source) ?: 'http';
        if ($source === 'browser_voice_realtime') {
            throw new \InvalidArgumentException('The Browser Voice source is reserved for lifecycle-owned voice runs.');
        }
        $clientRequestId = trim((string) data_get($metadata, 'client_request_id'));
        if ($clientRequestId === '') {
            throw new \InvalidArgumentException('A stable client_request_id is required for every assistant run.');
        }
        $metadata['client_request_id'] = $clientRequestId;
        $requestFingerprint = $this->requestFingerprint($content, $metadata);

        $queued = DB::transaction(function () use ($session, $content, $metadata, $source, $clientRequestId, $requestFingerprint, $editedFromMessageId): array {
            $lockedSession = ConversationSession::query()
                ->whereKey($session->id)
                ->where('user_id', $session->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            if (VoiceTurn::query()
                ->where('conversation_session_id', $lockedSession->id)
                ->where('turn_id', $metadata['client_request_id'])
                ->exists()) {
                throw new AssistantRunConflictException(
                    'That client_request_id is already owned by a voice turn.',
                );
            }
            $existing = $this->existingRunForClientRequest($lockedSession, $metadata);
            if ($existing instanceof AssistantRun) {
                $this->assertMatchingRequestFingerprint($existing, $requestFingerprint);

                return $this->replayedQueueResult($existing);
            }
            if ($editedFromMessageId !== null) {
                ConversationMessage::query()
                    ->whereKey($editedFromMessageId)
                    ->where('user_id', $lockedSession->user_id)
                    ->where('conversation_session_id', $lockedSession->id)
                    ->where('role', 'user')
                    ->lockForUpdate()
                    ->firstOrFail();
            }
            $cancelledBeforeQueue = $this->clientRequestWasCancelled($lockedSession, $metadata);
            $runMetadata = $cancelledBeforeQueue
                ? array_merge($metadata, [
                    'cancelled_before_queue' => true,
                    'cancelled_at' => now()->toIso8601String(),
                ])
                : $metadata;
            $userMessage = ConversationMessage::create([
                'user_id' => $lockedSession->user_id,
                'conversation_session_id' => $lockedSession->id,
                'client_turn_id' => trim((string) (
                    data_get($runMetadata, 'client_turn_id')
                    ?: data_get($runMetadata, 'client_request_id')
                )) ?: null,
                'role' => 'user',
                'content' => $content,
                'metadata' => $runMetadata ?: null,
            ]);

            $run = AssistantRun::create([
                'user_id' => $lockedSession->user_id,
                'workspace_id' => $lockedSession->workspace_id,
                'conversation_session_id' => $lockedSession->id,
                'user_message_id' => $userMessage->id,
                'client_request_id' => $clientRequestId,
                'request_fingerprint' => $requestFingerprint,
                'source' => $source,
                'status' => $cancelledBeforeQueue ? 'cancelled' : 'queued',
                'input' => $content,
                'metadata' => $runMetadata ?: null,
                'queued_at' => $cancelledBeforeQueue ? null : now(),
                'cancelled_at' => $cancelledBeforeQueue ? now() : null,
                'completed_at' => $cancelledBeforeQueue ? now() : null,
            ]);

            $lockedSession->update([
                'status' => $cancelledBeforeQueue ? 'active' : 'queued',
                'last_activity_at' => now(),
            ]);

            if ($cancelledBeforeQueue) {
                $event = $this->recordEvent($run, 'runtime.run_cancelled_before_queue', [
                    'run_id' => $run->id,
                    'message_id' => $userMessage->id,
                    'source' => $source,
                    'client_request_id' => data_get($metadata, 'client_request_id'),
                ], 'hermes.runs', 'cancelled');

                return [
                    'run' => $run,
                    'user_message' => $userMessage,
                    'event' => $event,
                    'events' => [$event],
                    'existing' => false,
                ];
            }

            $event = $this->recordEvent($run, 'runtime.run_queued', [
                'run_id' => $run->id,
                'message_id' => $userMessage->id,
                'source' => $source,
                'next_execution_generation' => 1,
            ], 'hermes.runs', 'queued');

            return [
                'run' => $run,
                'user_message' => $userMessage,
                'event' => $event,
                'events' => [$event],
                'existing' => false,
            ];
        }, 3);

        if ($queued['run']->status === 'queued'
            && ! ($queued['existing'] ?? false)
            && ! $this->dispatchRunAfterResponse($queued['run']->id)) {
            $queued['run'] = $this->prepareRunForBackgroundResponse($queued['run']->refresh());
        }

        return $queued;
    }

    /**
     * Atomically claims a queued generic run. AssistantRunService is the only
     * owner of generic run/session lifecycle state and lifecycle events. The
     * queued job carries the exact next generation, so a prior delivery cannot
     * reclaim a run after recovery has invalidated its attempt.
     */
    public function claimRunExecution(AssistantRun $run, int $expectedGeneration): ?AssistantRunExecutionClaim
    {
        $this->assertGenericRun($run);

        return DB::transaction(function () use ($run, $expectedGeneration): ?AssistantRunExecutionClaim {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession
                || ! $lockedRun instanceof AssistantRun
                || $lockedRun->status !== 'queued'
                || $expectedGeneration <= 0
                || (int) $lockedRun->execution_generation + 1 !== $expectedGeneration) {
                return null;
            }

            $lockedRun->update([
                'status' => 'running',
                'execution_generation' => $expectedGeneration,
                'started_at' => now(),
            ]);
            $session->update([
                'status' => 'running',
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_started', [
                'run_id' => $lockedRun->id,
                'source' => $lockedRun->source,
                'message_id' => $lockedRun->user_message_id,
                'execution_generation' => $expectedGeneration,
                'queue_wait_ms' => $this->elapsedMilliseconds($lockedRun->created_at),
            ], 'hermes.runs', 'started');

            return new AssistantRunExecutionClaim(
                runId: $lockedRun->id,
                sessionId: $lockedRun->conversation_session_id,
                userMessageId: (int) $lockedRun->user_message_id,
                generation: $expectedGeneration,
            );
        }, 3);
    }

    public function executionClaimIsCurrent(AssistantRunExecutionClaim $claim): bool
    {
        return AssistantRun::query()
            ->whereKey($claim->runId)
            ->whereNull('voice_turn_id')
            ->where('conversation_session_id', $claim->sessionId)
            ->where('user_message_id', $claim->userMessageId)
            ->where('status', 'running')
            ->where('execution_generation', $claim->generation)
            ->exists();
    }

    public function withExecutionClaim(AssistantRunExecutionClaim $claim, Closure $callback): mixed
    {
        // Every generic execution write crosses this lock-protected lease
        // boundary. The callback and its writes commit in the same transaction.
        return DB::transaction(function () use ($claim, $callback): mixed {
            $session = ConversationSession::query()->lockForUpdate()->find($claim->sessionId);
            $run = AssistantRun::query()->lockForUpdate()->find($claim->runId);
            if (! $session instanceof ConversationSession
                || ! $run instanceof AssistantRun
                || $run->voice_turn_id !== null
                || $run->conversation_session_id !== $claim->sessionId
                || (int) $run->user_message_id !== $claim->userMessageId
                || $run->status !== 'running'
                || (int) $run->execution_generation !== $claim->generation) {
                throw new AssistantRunClaimLostException;
            }

            return $callback($session, $run);
        }, 3);
    }

    public function cancelRun(AssistantRun $run): AssistantRun
    {
        $this->assertGenericRun($run);

        DB::transaction(function () use ($run): void {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $lockedRun instanceof AssistantRun
                || in_array($lockedRun->status, ['completed', 'blocked', 'cancelled'], true)
                || ($lockedRun->status === 'failed' && $lockedRun->assistant_message_id !== null)) {
                return;
            }

            $lockedRun->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'completed_at' => now(),
            ]);
            if ($session instanceof ConversationSession) {
                $session->update([
                    'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
            }
            $this->recordEvent($lockedRun, 'runtime.run_cancel_requested', [
                'run_id' => $lockedRun->id,
            ], 'hermes.runs', 'cancelled');
        }, 3);

        return $run->refresh();
    }

    public function cancelSession(ConversationSession $session, ?string $clientRequestId = null): ConversationSession
    {
        $clientRequestId = trim((string) $clientRequestId);

        return DB::transaction(function () use ($session, $clientRequestId): ConversationSession {
            $lockedSession = ConversationSession::query()
                ->whereKey($session->id)
                ->where('user_id', $session->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $query = AssistantRun::query()
                ->where('conversation_session_id', $lockedSession->id)
                ->whereNull('voice_turn_id')
                ->whereIn('status', ['queued', 'running', 'failed']);
            if ($clientRequestId !== '') {
                $query->where('client_request_id', $clientRequestId);
            }

            /** @var EloquentCollection<int, AssistantRun> $candidates */
            $candidates = $query->orderBy('id')->lockForUpdate()->get();
            $runs = $candidates->filter(
                fn (AssistantRun $run): bool => in_array($run->status, ['queued', 'running'], true)
                    || ($run->status === 'failed' && $run->assistant_message_id === null),
            );
            foreach ($runs as $run) {
                $metadata = is_array($run->metadata) ? $run->metadata : [];
                $run->update([
                    'status' => 'cancelled',
                    'cancelled_at' => $run->cancelled_at ?? now(),
                    'completed_at' => $run->completed_at ?? now(),
                    'metadata' => array_merge($metadata, [
                        'cancellation_requested_at' => now()->toIso8601String(),
                        'cancellation_source' => 'session',
                    ]),
                ]);
                $this->recordEvent($run, 'runtime.run_cancel_requested', [
                    'run_id' => $run->id,
                    'source' => 'session',
                ], 'hermes.runs', 'cancelled');
            }

            $sessionMetadata = is_array($lockedSession->metadata) ? $lockedSession->metadata : [];
            if ($clientRequestId !== '') {
                $sessionMetadata['cancelled_client_request_ids'] = collect((array) ($sessionMetadata['cancelled_client_request_ids'] ?? []))
                    ->map(fn (mixed $id): string => trim((string) $id))
                    ->filter()
                    ->push($clientRequestId)
                    ->unique()
                    ->take(-50)
                    ->values()
                    ->all();
            }
            $lockedSession->update([
                'status' => $this->activeRunSessionStatus($lockedSession->id, 0),
                'metadata' => $sessionMetadata ?: null,
                'last_activity_at' => now(),
            ]);
            ActivityEvent::create([
                'user_id' => $lockedSession->user_id,
                'workspace_id' => $lockedSession->workspace_id,
                'conversation_session_id' => $lockedSession->id,
                'event_type' => 'runtime.cancel_requested',
                'tool_name' => 'hermes.runs',
                'status' => 'cancelled',
                'payload' => [
                    'run_ids' => $runs->pluck('id')->all(),
                    'client_request_id' => $clientRequestId !== '' ? $clientRequestId : null,
                ],
            ]);

            return $lockedSession->refresh();
        }, 3);
    }

    public function prepareRunForBackgroundResponse(AssistantRun $run): AssistantRun
    {
        $this->assertGenericRun($run);

        $run->refresh();
        if ($this->runRecoveryProhibited($run)) {
            return $run;
        }
        if (in_array($run->status, ['queued', 'running'], true)) {
            $startedAt = $run->status === 'running'
                ? $run->started_at
                : $run->queued_at;
            $startedAt ??= $run->created_at;
            $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 210);
            if ($startedAt !== null && $startedAt->lte(now()->subSeconds($staleAfterSeconds))) {
                if ($this->runRecoveryWindowExpired($run)) {
                    $this->markStaleFailed($run, $staleAfterSeconds, 'Run expired before it could be safely recovered.');

                    return $this->prepareRunForBackgroundResponse($run->refresh());
                }

                $metadata = is_array($run->metadata) ? $run->metadata : [];
                $attempts = (int) ($metadata['background_stale_retry_attempts'] ?? 0);
                $maxAttempts = (int) config('services.hermes_runtime.assistant_run_stale_recovery_attempts', 1);
                if ($maxAttempts > 0 && $attempts < $maxAttempts && ! $this->runHasCompletedMutatingWork($run)) {
                    $requeued = $this->requeueRunWithLock(
                        $run,
                        ['queued', 'running'],
                        'background_stale_retry_attempts',
                        'background_stale_retried_at',
                        'runtime.run_stale_retry_queued',
                        $maxAttempts,
                    );
                    if ($requeued) {
                        if (! $this->dispatchRunAfterResponse($run->id)) {
                            return $this->prepareRunForBackgroundResponse($run->refresh());
                        }
                    }

                    return $run->refresh();
                }

                $this->markStaleFailed($run, $staleAfterSeconds);

                return $this->prepareRunForBackgroundResponse($run->refresh());
            }

            return $run;
        }

        if ($run->status !== 'failed' || $run->assistant_message_id !== null) {
            return $run;
        }

        return $this->finalizeFailedRun($run);
    }

    /**
     * Atomically owns generic run terminal state and its single durable final.
     * Hermes returns meaning and tool outcomes; it never persists chat output.
     *
     * @param  array<string, mixed>  $runtimeResult
     */
    public function finishRuntimeResult(AssistantRunExecutionClaim $claim, array $runtimeResult): AssistantRun
    {
        $run = AssistantRun::query()->findOrFail($claim->runId);
        $this->assertGenericRun($run);

        $outcome = strtolower(trim((string) ($runtimeResult['status'] ?? 'failed')));
        if ($outcome === 'cancelled') {
            return $this->finishCancelledRun($claim);
        }
        if ($outcome === 'failed') {
            $reason = trim((string) (
                $runtimeResult['error']
                ?? data_get($runtimeResult, 'failure_context.exception')
                ?? data_get($runtimeResult, 'failure_context.reason')
                ?? 'Hermes runtime failed before producing a terminal response.'
            ));
            if (! $this->markRuntimeFailed($claim, $reason, $runtimeResult)) {
                return $run->refresh();
            }

            return $this->prepareRunForBackgroundResponse($run->refresh());
        }
        if (! in_array($outcome, ['completed', 'blocked'], true)) {
            if (! $this->markRuntimeFailed($claim, "Hermes returned unsupported terminal status [{$outcome}].", $runtimeResult)) {
                return $run->refresh();
            }

            return $this->prepareRunForBackgroundResponse($run->refresh());
        }

        $content = (string) ($runtimeResult['assistant_content'] ?? '');
        if (trim($content) === '') {
            if (! $this->markRuntimeFailed($claim, 'Hermes returned an empty terminal response.', $runtimeResult)) {
                return $run->refresh();
            }

            return $this->prepareRunForBackgroundResponse($run->refresh());
        }

        $assistantMessage = null;
        try {
            $completedRun = $this->withExecutionClaim($claim, function (ConversationSession $session, AssistantRun $lockedRun) use ($claim, $runtimeResult, $outcome, $content, &$assistantMessage): AssistantRun {
                $assistantMessage = ConversationMessage::create([
                    'user_id' => $lockedRun->user_id,
                    'conversation_session_id' => $lockedRun->conversation_session_id,
                    'role' => 'assistant',
                    'content' => $content,
                    'metadata' => [
                        'assistant_run_id' => $lockedRun->id,
                        'final_status' => $outcome,
                    ],
                ]);
                $eventIds = collect($runtimeResult['events'] ?? [])
                    ->pluck('id')
                    ->filter()
                    ->values()
                    ->all();
                $lockedRun->update([
                    'status' => $outcome,
                    'assistant_message_id' => $assistantMessage->id,
                    'error' => null,
                    'result' => [
                        'status' => $outcome,
                        'assistant_message_id' => $assistantMessage->id,
                        'event_ids' => $eventIds,
                    ],
                    'completed_at' => now(),
                ]);
                $session->update([
                    'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
                $this->recordEvent($lockedRun, 'runtime.run_completed', [
                    'run_id' => $lockedRun->id,
                    'execution_generation' => $claim->generation,
                    'status' => $outcome,
                    'assistant_message_id' => $assistantMessage->id,
                ], 'hermes.runs', $outcome === 'blocked' ? 'blocked' : 'succeeded');

                return $lockedRun->refresh();
            });
        } catch (AssistantRunClaimLostException) {
            return $run->refresh();
        }

        if ($assistantMessage instanceof ConversationMessage
            && $completedRun->assistant_message_id === $assistantMessage->id) {
            $userMessage = ConversationMessage::find($completedRun->user_message_id);
            $session = ConversationSession::find($completedRun->conversation_session_id);
            if ($userMessage instanceof ConversationMessage && $session instanceof ConversationSession) {
                app(BeanMemoryService::class)->recordTurnActivity($session, $userMessage, $assistantMessage);
            }
        }

        return $completedRun;
    }

    /** @param array<string, mixed> $runtimeResult */
    private function markRuntimeFailed(AssistantRunExecutionClaim $claim, string $reason, array $runtimeResult): bool
    {
        try {
            $failed = $this->withExecutionClaim($claim, function (ConversationSession $session, AssistantRun $lockedRun) use ($claim, $reason, $runtimeResult): AssistantRun {
                $eventIds = collect($runtimeResult['events'] ?? [])
                    ->pluck('id')
                    ->filter()
                    ->values()
                    ->all();
                $lockedRun->update([
                    'status' => 'failed',
                    'error' => mb_substr($reason, 0, 4000),
                    'result' => [
                        'status' => 'failed',
                        'assistant_message_id' => null,
                        'event_ids' => $eventIds,
                    ],
                    'completed_at' => now(),
                ]);
                $session->update([
                    'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
                $this->recordEvent($lockedRun, 'runtime.run_failed', [
                    'run_id' => $lockedRun->id,
                    'execution_generation' => $claim->generation,
                    'reason' => mb_substr($reason, 0, 1000),
                ], 'hermes.runs', 'failed');

                return $lockedRun->refresh();
            });
        } catch (AssistantRunClaimLostException) {
            return false;
        }

        if ($failed instanceof AssistantRun) {
            Log::error('Assistant run failed.', [
                'run_id' => $failed->id,
                'session_id' => $failed->conversation_session_id,
                'exception' => $reason,
            ]);
        }

        return true;
    }

    private function finishCancelledRun(AssistantRunExecutionClaim $claim): AssistantRun
    {
        try {
            return $this->withExecutionClaim($claim, function (ConversationSession $session, AssistantRun $lockedRun) use ($claim): AssistantRun {
                $lockedRun->update([
                    'status' => 'cancelled',
                    'cancelled_at' => now(),
                    'completed_at' => now(),
                ]);
                $session->update([
                    'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
                $this->recordEvent($lockedRun, 'runtime.run_cancelled', [
                    'run_id' => $lockedRun->id,
                    'execution_generation' => $claim->generation,
                ], 'hermes.runs', 'cancelled');

                return $lockedRun->refresh();
            });
        } catch (AssistantRunClaimLostException) {
            return AssistantRun::query()->findOrFail($claim->runId);
        }
    }

    private function finalizeFailedRun(AssistantRun $run): AssistantRun
    {
        return DB::transaction(function () use ($run): AssistantRun {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession
                || ! $lockedRun instanceof AssistantRun
                || $lockedRun->status !== 'failed'
                || $lockedRun->assistant_message_id !== null
                || $this->runRecoveryProhibited($lockedRun)) {
                return $lockedRun?->refresh() ?? $run->refresh();
            }

            $hadCommittedWrites = $this->runHasCompletedMutatingWork($lockedRun);
            $content = self::SYSTEM_FAILURE_FINAL;

            $assistantMessage = ConversationMessage::create([
                'user_id' => $lockedRun->user_id,
                'conversation_session_id' => $lockedRun->conversation_session_id,
                'role' => 'assistant',
                'content' => $content,
                'metadata' => [
                    'assistant_run_id' => $lockedRun->id,
                    'final_status' => 'failed',
                    'had_committed_writes' => $hadCommittedWrites,
                ],
            ]);

            $lockedRun->update([
                'assistant_message_id' => $assistantMessage->id,
                'result' => array_merge(is_array($lockedRun->result) ? $lockedRun->result : [], [
                    'status' => 'failed',
                    'assistant_message_id' => $assistantMessage->id,
                    'fault_final' => true,
                    'had_committed_writes' => $hadCommittedWrites,
                ]),
                'completed_at' => $lockedRun->completed_at ?? now(),
            ]);
            $session->update([
                'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($lockedRun->refresh(), 'runtime.run_fault_final_created', [
                'run_id' => $lockedRun->id,
                'assistant_message_id' => $assistantMessage->id,
                'had_committed_writes' => $hadCommittedWrites,
            ], 'hermes.runs', 'failed');

            return $lockedRun->refresh();
        }, 3);
    }

    public function closeExpiredStaleRunsForSession(ConversationSession $session): void
    {
        $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 210);

        /** @var EloquentCollection<int, AssistantRun> $runs */
        $runs = AssistantRun::query()
            ->where('conversation_session_id', $session->id)
            ->whereNull('voice_turn_id')
            ->whereIn('status', ['queued', 'running'])
            ->where(function ($query) use ($staleAfterSeconds): void {
                $threshold = now()->subSeconds($staleAfterSeconds);
                $query
                    ->where('started_at', '<=', $threshold)
                    ->orWhere(function ($query) use ($threshold): void {
                        $query->whereNull('started_at')->where('created_at', '<=', $threshold);
                    });
            })
            ->get();

        foreach ($runs as $run) {
            if ($this->runRecoveryWindowExpired($run)) {
                $this->markStaleFailed($run, $staleAfterSeconds, 'Run expired before it could be safely recovered.');
            }
        }
    }

    public function prepareSessionRunsForResponse(ConversationSession $session): void
    {
        $this->closeExpiredStaleRunsForSession($session);

        /** @var EloquentCollection<int, AssistantRun> $runs */
        $runs = AssistantRun::query()
            ->where('conversation_session_id', $session->id)
            ->whereNull('voice_turn_id')
            ->whereIn('status', ['queued', 'running', 'failed'])
            ->orderBy('id')
            ->get();

        foreach ($runs as $run) {
            $this->prepareRunForBackgroundResponse($run);
        }
    }

    private function runRecoveryWindowExpired(AssistantRun $run): bool
    {
        $startedAt = $run->started_at ?: $run->created_at;
        if ($startedAt === null) {
            return false;
        }

        $windowSeconds = (int) config('services.hermes_runtime.assistant_run_recovery_window_seconds', 900);
        if ($windowSeconds <= 0) {
            return false;
        }

        return $startedAt->lte(now()->subSeconds($windowSeconds));
    }

    private function markStaleFailed(AssistantRun $run, int $staleAfterSeconds, ?string $detail = null): void
    {
        DB::transaction(function () use ($run, $staleAfterSeconds, $detail): void {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession
                || ! $lockedRun instanceof AssistantRun
                || ! in_array($lockedRun->status, ['queued', 'running'], true)
                || $this->runRecoveryProhibited($lockedRun)) {
                return;
            }

            $reason = $detail ?: "Assistant run did not complete within {$staleAfterSeconds} seconds.";
            $lockedRun->update([
                'status' => 'failed',
                'error' => $reason,
                'completed_at' => now(),
            ]);
            $session->update([
                'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_stale_failed', [
                'run_id' => $lockedRun->id,
                'reason' => $reason,
            ], 'hermes.runs', 'failed');
        }, 3);
    }

    public function runHasCompletedMutatingWork(AssistantRun $run): bool
    {
        return ActivityEvent::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where('event_type', 'assistant.semantic_operation.receipt')
            ->where('payload->assistant_run_id', $run->id)
            ->where('payload->receipt->side_effect_committed', true)
            ->exists();
    }

    private function clientRequestWasCancelled(ConversationSession $session, array $metadata): bool
    {
        $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
        if ($clientRequestId === '') {
            return false;
        }

        $cancelledRequestIds = collect((array) data_get($session->metadata, 'cancelled_client_request_ids', []))
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->all();

        return in_array($clientRequestId, $cancelledRequestIds, true);
    }

    private function existingRunForClientRequest(ConversationSession $session, array $metadata): ?AssistantRun
    {
        $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
        if ($clientRequestId === '') {
            return null;
        }

        return $this->findRunForClientRequest($session, $clientRequestId);
    }

    public function findRunForClientRequest(ConversationSession $session, string $clientRequestId): ?AssistantRun
    {
        return AssistantRun::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->whereNull('voice_turn_id')
            ->where('client_request_id', trim($clientRequestId))
            ->with(['session', 'userMessage', 'assistantMessage'])
            ->latest('id')
            ->first();
    }

    /**
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>,existing:bool}
     */
    private function replayedQueueResult(AssistantRun $run): array
    {
        $event = $this->recordEvent($run, 'runtime.run_queue_replayed', [
            'run_id' => $run->id,
            'client_request_id' => $run->client_request_id,
        ], 'hermes.runs', 'recorded');

        return [
            'run' => $run,
            'user_message' => $run->userMessage,
            'event' => $event,
            'events' => [$event],
            'existing' => true,
        ];
    }

    private function assertMatchingRequestFingerprint(AssistantRun $run, string $fingerprint): void
    {
        $stored = trim((string) $run->request_fingerprint);
        if ($stored !== '' && hash_equals($stored, $fingerprint)) {
            return;
        }

        throw new AssistantRunConflictException(
            'That client_request_id is already bound to a different assistant request.',
        );
    }

    /** @param array<string, mixed> $metadata */
    private function requestFingerprint(string $content, array $metadata): string
    {
        unset($metadata['request_fingerprint']);
        $payload = [
            'content' => $content,
            'metadata' => $this->canonicalizeFingerprintValue($metadata),
        ];

        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    private function canonicalizeFingerprintValue(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalizeFingerprintValue($item), $value);
        }

        ksort($value);

        return array_map(fn (mixed $item): mixed => $this->canonicalizeFingerprintValue($item), $value);
    }

    private function activeRunSessionStatus(int $sessionId, int $excludingRunId): string
    {
        $statuses = AssistantRun::query()
            ->where('conversation_session_id', $sessionId)
            ->where('id', '!=', $excludingRunId)
            ->whereIn('status', ['queued', 'running'])
            ->pluck('status');

        if ($statuses->contains('running')) {
            return 'running';
        }

        return $statuses->contains('queued') ? 'queued' : 'active';
    }

    private function runRecoveryProhibited(AssistantRun $run): bool
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];

        return in_array($run->status, ['completed', 'blocked', 'cancelled'], true)
            || $run->cancelled_at !== null
            || ($metadata['cancelled_before_queue'] ?? false) === true;
    }

    private function assertGenericRun(AssistantRun $run): void
    {
        if ($run->voice_turn_id !== null) {
            throw new VoiceTurnConflictException(
                'Browser Voice runs are owned by VoiceTurnLifecycleService.',
            );
        }
    }

    private function requeueRunWithLock(
        AssistantRun $run,
        array $allowedStatuses,
        string $attemptKey,
        string $retriedAtKey,
        string $eventType,
        int $maxAttempts,
        array $extraMetadata = [],
    ): bool {
        return DB::transaction(function () use ($run, $allowedStatuses, $attemptKey, $retriedAtKey, $eventType, $maxAttempts, $extraMetadata): bool {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession
                || ! $lockedRun instanceof AssistantRun
                || ! in_array($lockedRun->status, $allowedStatuses, true)
                || $this->runRecoveryProhibited($lockedRun)
                || $this->runHasCompletedMutatingWork($lockedRun)) {
                return false;
            }

            $metadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $attempts = (int) ($metadata[$attemptKey] ?? 0);
            if ($attempts >= $maxAttempts) {
                return false;
            }

            $lockedRun->update([
                'status' => 'queued',
                'queued_at' => now(),
                // Retain the last claimed generation. The replacement job is
                // dispatched with exactly generation + 1.
                'started_at' => null,
                'completed_at' => null,
                'error' => null,
                'result' => null,
                'metadata' => array_merge($metadata, $extraMetadata, [
                    $attemptKey => $attempts + 1,
                    $retriedAtKey => now()->toIso8601String(),
                ]),
            ]);
            $session->update(['status' => 'queued', 'last_activity_at' => now()]);
            $this->recordEvent($lockedRun, $eventType, [
                'run_id' => $lockedRun->id,
                'message_id' => $lockedRun->user_message_id,
                'attempt' => $attempts + 1,
                'invalidated_execution_generation' => (int) $lockedRun->execution_generation,
                'next_execution_generation' => (int) $lockedRun->execution_generation + 1,
            ], 'hermes.runs', 'queued');

            return true;
        }, 3);
    }

    private function recordEvent(AssistantRun $run, string $eventType, array $payload = [], ?string $toolName = null, string $status = 'recorded'): ActivityEvent
    {
        return ActivityEvent::create([
            'user_id' => $run->user_id,
            'workspace_id' => $run->workspace_id,
            'conversation_session_id' => $run->conversation_session_id,
            'event_type' => $eventType,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }

    private function elapsedMilliseconds(mixed $startedAt): ?int
    {
        if (! $startedAt) {
            return null;
        }

        return max(0, (int) $startedAt->diffInMilliseconds(now(), true));
    }

    private function dispatchRunAfterResponse(int $runId): bool
    {
        try {
            $run = AssistantRun::query()->find($runId);
            if (! $run instanceof AssistantRun
                || $run->voice_turn_id !== null
                || $run->status !== 'queued') {
                return false;
            }
            $job = new ProcessAssistantRun($runId, (int) $run->execution_generation + 1);
            if (app()->runningInConsole()) {
                $this->dispatcher->dispatch($job);
            } else {
                $this->dispatcher->dispatchAfterResponse($job);
            }

            return true;
        } catch (Throwable $exception) {
            $this->markDispatchFailed($runId, $exception);

            return false;
        }
    }

    private function markDispatchFailed(int $runId, Throwable $exception): void
    {
        $failed = DB::transaction(function () use ($runId, $exception): ?AssistantRun {
            $run = AssistantRun::query()->find($runId);
            if (! $run instanceof AssistantRun || $run->voice_turn_id !== null) {
                return null;
            }
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($runId);
            if (! $lockedRun instanceof AssistantRun
                || $lockedRun->voice_turn_id !== null
                || $lockedRun->status !== 'queued') {
                return null;
            }

            $reason = 'Assistant run dispatch failed: '.str($exception->getMessage())->squish()->limit(1000, '')->toString();
            $lockedRun->update([
                'status' => 'failed',
                'error' => $reason,
                'completed_at' => now(),
            ]);
            if ($session instanceof ConversationSession) {
                $session->update([
                    'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
            }
            $this->recordEvent($lockedRun, 'runtime.run_dispatch_failed', [
                'run_id' => $lockedRun->id,
                'reason' => $reason,
            ], 'hermes.runs', 'failed');

            return $lockedRun->refresh();
        }, 3);

        if ($failed instanceof AssistantRun) {
            Log::error('Assistant run dispatch failed.', [
                'run_id' => $failed->id,
                'session_id' => $failed->conversation_session_id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }
}
