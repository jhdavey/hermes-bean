<?php

namespace App\Services;

use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;

class AssistantRunService
{
    /**
     * Metadata that describes server-observed run state or server-created relationships.
     * None of these values may be supplied by an API client: several are consumed by the
     * cancellation/recovery paths and therefore cannot safely be treated as annotations.
     *
     * @var array<int, string>
     */
    private const SERVER_OWNED_METADATA_KEYS = [
        'assistant_run_id',
        'defer_memory_candidate',
        'status',
        'run_status',
        'response_status',
        'voice_turn_outcome',
        'started_at',
        'completed_at',
        'failed_at',
        'cancelled_at',
        'cancelled_before_queue',
        'cancellation_requested_at',
        'cancellation_source',
        'supersession_predecessor_missing',
        'missing_predecessor_client_request_id',
        'superseded_client_request_ids',
        'supersedes_run_id',
        'superseded_by_client_request_id',
        'superseded_at',
        'late_superseded_request_coalesced',
        'coalesced_into_run_id',
        'supersession_conflict',
        'supersession_conflict_detected_at',
        'background_stale_retry_attempts',
        'background_stale_retried_at',
        'background_response_retry_attempts',
        'background_response_retried_at',
        'background_response_original_error',
        'failed_response_resolved_at',
        'failed_response_original_error',
        'failed_response_had_completed_work',
    ];

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
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>}
     */
    public function queueRun(ConversationSession $session, string $content, array $metadata = [], string $source = 'http'): array
    {
        $metadata = $this->sanitizeClientMetadata($metadata);

        $queued = DB::transaction(function () use ($session, $content, $metadata, $source): array {
            $lockedSession = ConversationSession::query()
                ->whereKey($session->id)
                ->where('user_id', $session->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = $this->existingRunForClientRequest($lockedSession, $metadata);
            if ($existing instanceof AssistantRun) {
                return $this->replayedQueueResult($existing);
            }
            $cancelledBeforeQueue = $this->clientRequestWasCancelled($lockedSession, $metadata);
            $missingPredecessorSuccessor = $cancelledBeforeQueue
                ? $this->missingPredecessorSuccessorForClientRequest($lockedSession, $metadata)
                : null;
            if ($missingPredecessorSuccessor instanceof AssistantRun
                && $missingPredecessorSuccessor->userMessage instanceof ConversationMessage) {
                return $this->coalescedLateOriginalResult(
                    $lockedSession,
                    $missingPredecessorSuccessor,
                    $content,
                    $metadata,
                    $source,
                );
            }
            $runMetadata = $cancelledBeforeQueue
                ? array_merge($metadata, [
                    'cancelled_before_queue' => true,
                    'cancelled_at' => now()->toIso8601String(),
                ])
                : $metadata;
            $userMessage = ConversationMessage::create([
                'user_id' => $lockedSession->user_id,
                'conversation_session_id' => $lockedSession->id,
                'role' => 'user',
                'content' => $content,
                'metadata' => $runMetadata ?: null,
            ]);

            $run = AssistantRun::create([
                'user_id' => $lockedSession->user_id,
                'workspace_id' => $lockedSession->workspace_id,
                'conversation_session_id' => $lockedSession->id,
                'user_message_id' => $userMessage->id,
                'source' => $source,
                'status' => $cancelledBeforeQueue ? 'cancelled' : 'queued',
                'input' => $content,
                'metadata' => $runMetadata ?: null,
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

                return ['run' => $run, 'user_message' => $userMessage, 'event' => $event, 'events' => [$event]];
            }

            $events = [];
            $intent = is_array($metadata['bean_intent'] ?? null) ? $metadata['bean_intent'] : null;
            if ($intent !== null) {
                $events[] = $this->recordEvent($run, 'runtime.intent_routed', [
                    'run_id' => $run->id,
                    'message_id' => $userMessage->id,
                    ...$intent,
                ], 'hermes.router', 'completed');

                foreach ((array) ($intent['work_plan'] ?? []) as $index => $item) {
                    if (! is_array($item) || trim((string) ($item['label'] ?? '')) === '') {
                        continue;
                    }
                    $events[] = $this->recordEvent($run, 'assistant.work_item.planned', [
                        'run_id' => $run->id,
                        'message_id' => $userMessage->id,
                        'work_item_id' => (string) ($item['id'] ?? 'route-plan-'.$index),
                        'work_label' => (string) $item['label'],
                        'label' => (string) $item['label'],
                        'work_order' => $index,
                    ], 'hermes.router', 'started');
                }
            }

            $event = $this->recordEvent($run, 'runtime.run_queued', [
                'run_id' => $run->id,
                'message_id' => $userMessage->id,
                'source' => $source,
            ], 'hermes.runs', 'queued');
            $events[] = $event;

            return ['run' => $run, 'user_message' => $userMessage, 'event' => $event, 'events' => $events];
        }, 3);

        if ($queued['run']->status === 'queued' && ! ($queued['existing'] ?? false)) {
            $this->dispatchRunAfterResponse($queued['run']->id);
        }

        return $queued;
    }

    /**
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>}
     */
    public function queueExistingMessage(ConversationSession $session, ConversationMessage $userMessage, array $metadata = [], string $source = 'http'): array
    {
        $metadata = $this->sanitizeClientMetadata(
            $metadata ?: (is_array($userMessage->metadata) ? $userMessage->metadata : [])
        );

        $queued = DB::transaction(function () use ($session, $userMessage, $metadata, $source): array {
            $lockedSession = ConversationSession::query()
                ->whereKey($session->id)
                ->where('user_id', $session->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $existing = $this->existingRunForClientRequest($lockedSession, $metadata);
            if ($existing instanceof AssistantRun) {
                return $this->replayedQueueResult($existing);
            }
            $cancelledBeforeQueue = $this->clientRequestWasCancelled($lockedSession, $metadata);
            $runMetadata = $cancelledBeforeQueue
                ? array_merge($metadata, [
                    'cancelled_before_queue' => true,
                    'cancelled_at' => now()->toIso8601String(),
                ])
                : $metadata;
            if (($userMessage->metadata ?: []) !== $runMetadata) {
                $userMessage->update(['metadata' => $runMetadata ?: null]);
            }
            $run = AssistantRun::create([
                'user_id' => $lockedSession->user_id,
                'workspace_id' => $lockedSession->workspace_id,
                'conversation_session_id' => $lockedSession->id,
                'user_message_id' => $userMessage->id,
                'source' => $source,
                'status' => $cancelledBeforeQueue ? 'cancelled' : 'queued',
                'input' => $userMessage->content,
                'metadata' => $runMetadata ?: null,
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
                    'reused_user_message' => true,
                ], 'hermes.runs', 'cancelled');

                return ['run' => $run, 'user_message' => $userMessage->refresh(), 'event' => $event, 'events' => [$event]];
            }

            $events = [];
            $intent = is_array($metadata['bean_intent'] ?? null) ? $metadata['bean_intent'] : null;
            if ($intent !== null) {
                $events[] = $this->recordEvent($run, 'runtime.intent_routed', [
                    'run_id' => $run->id,
                    'message_id' => $userMessage->id,
                    ...$intent,
                ], 'hermes.router', 'completed');

                foreach ((array) ($intent['work_plan'] ?? []) as $index => $item) {
                    if (! is_array($item) || trim((string) ($item['label'] ?? '')) === '') {
                        continue;
                    }
                    $events[] = $this->recordEvent($run, 'assistant.work_item.planned', [
                        'run_id' => $run->id,
                        'message_id' => $userMessage->id,
                        'work_item_id' => (string) ($item['id'] ?? 'route-plan-'.$index),
                        'work_label' => (string) $item['label'],
                        'label' => (string) $item['label'],
                        'work_order' => $index,
                    ], 'hermes.router', 'started');
                }
            }

            $event = $this->recordEvent($run, 'runtime.run_queued', [
                'run_id' => $run->id,
                'message_id' => $userMessage->id,
                'source' => $source,
                'reused_user_message' => true,
            ], 'hermes.runs', 'queued');
            $events[] = $event;

            return ['run' => $run, 'user_message' => $userMessage, 'event' => $event, 'events' => $events];
        }, 3);

        if ($queued['run']->status === 'queued' && ! ($queued['existing'] ?? false)) {
            $this->dispatchRunAfterResponse($queued['run']->id);
        }

        return $queued;
    }

    /**
     * Replace an uncommitted request with a corrected request while retaining one durable
     * user message. The predecessor input remains on its cancelled run for auditability.
     *
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>,existing?:bool}
     */
    public function queueSupersedingRun(
        ConversationSession $session,
        string $supersededClientRequestId,
        string $content,
        array $metadata,
        string $source = 'http'
    ): array {
        $metadata = $this->sanitizeClientMetadata($metadata);
        $metadata['supersedes_client_request_id'] = trim($supersededClientRequestId);

        $dispatchRunId = null;
        $queued = DB::transaction(function () use ($session, $supersededClientRequestId, $content, $metadata, $source, &$dispatchRunId): array {
            $lockedSession = ConversationSession::query()
                ->whereKey($session->id)
                ->where('user_id', $session->user_id)
                ->lockForUpdate()
                ->firstOrFail();
            $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
            if ($clientRequestId === '' || $clientRequestId === $supersededClientRequestId) {
                throw new \DomainException('A correction needs a new client request id.');
            }
            $cancelledBeforeQueue = $this->clientRequestWasCancelled($lockedSession, $metadata);

            $existing = AssistantRun::query()
                ->where('user_id', $lockedSession->user_id)
                ->where('conversation_session_id', $lockedSession->id)
                ->where('metadata->client_request_id', $clientRequestId)
                ->with('userMessage')
                ->latest('id')
                ->first();
            if ($existing instanceof AssistantRun) {
                $event = $this->recordEvent($existing, 'runtime.run_supersession_replayed', [
                    'run_id' => $existing->id,
                    'client_request_id' => $clientRequestId,
                ], 'hermes.runs', 'recorded');

                return [
                    'run' => $existing,
                    'user_message' => $existing->userMessage,
                    'event' => $event,
                    'events' => [$event],
                    'existing' => true,
                ];
            }

            $predecessor = AssistantRun::query()
                ->where('user_id', $lockedSession->user_id)
                ->where('conversation_session_id', $lockedSession->id)
                ->where('metadata->client_request_id', $supersededClientRequestId)
                ->with('userMessage')
                ->lockForUpdate()
                ->latest('id')
                ->first();
            if (! $predecessor instanceof AssistantRun || ! $predecessor->userMessage instanceof ConversationMessage) {
                $currentMissingPredecessorSuccessor = $this->missingPredecessorSuccessorForId(
                    $lockedSession,
                    $supersededClientRequestId,
                    lockForUpdate: true,
                );
                if ($currentMissingPredecessorSuccessor instanceof AssistantRun) {
                    $replacement = $this->replaceMissingPredecessorSuccessor(
                        $lockedSession,
                        $currentMissingPredecessorSuccessor,
                        $supersededClientRequestId,
                        $content,
                        $metadata,
                        $source,
                        $cancelledBeforeQueue,
                    );
                    $dispatchRunId = $replacement['run']->status === 'queued'
                        ? $replacement['run']->id
                        : null;

                    return $replacement;
                }

                $orphanedMessage = ConversationMessage::query()
                    ->where('user_id', $lockedSession->user_id)
                    ->where('conversation_session_id', $lockedSession->id)
                    ->where('role', 'user')
                    ->where('metadata->client_request_id', $supersededClientRequestId)
                    ->exists();
                if ($orphanedMessage) {
                    throw new \DomainException('The request being corrected has no cancellable run.');
                }

                $this->addCancellationTombstone($lockedSession, $supersededClientRequestId);

                $successorMetadata = array_merge($metadata, [
                    'supersession_predecessor_missing' => true,
                    'missing_predecessor_client_request_id' => $supersededClientRequestId,
                    'superseded_client_request_ids' => [$supersededClientRequestId],
                ]);
                if ($cancelledBeforeQueue) {
                    $successorMetadata = array_merge($successorMetadata, [
                        'cancelled_before_queue' => true,
                        'cancelled_at' => now()->toIso8601String(),
                    ]);
                }
                $userMessage = ConversationMessage::create([
                    'user_id' => $lockedSession->user_id,
                    'conversation_session_id' => $lockedSession->id,
                    'role' => 'user',
                    'content' => $content,
                    'metadata' => $successorMetadata,
                ]);
                $run = AssistantRun::create([
                    'user_id' => $lockedSession->user_id,
                    'workspace_id' => $lockedSession->workspace_id,
                    'conversation_session_id' => $lockedSession->id,
                    'user_message_id' => $userMessage->id,
                    'source' => $source,
                    'status' => $cancelledBeforeQueue ? 'cancelled' : 'queued',
                    'input' => $content,
                    'metadata' => $successorMetadata,
                    'cancelled_at' => $cancelledBeforeQueue ? now() : null,
                    'completed_at' => $cancelledBeforeQueue ? now() : null,
                ]);
                $lockedSession->update([
                    'status' => $cancelledBeforeQueue ? 'active' : 'queued',
                    'last_activity_at' => now(),
                ]);
                $events = $cancelledBeforeQueue ? [] : $this->recordIntentEvents($run, $userMessage, $successorMetadata);
                $event = $this->recordEvent($run, $cancelledBeforeQueue ? 'runtime.run_cancelled_before_queue' : 'runtime.run_queued', [
                    'run_id' => $run->id,
                    'message_id' => $userMessage->id,
                    'source' => $source,
                    'supersession_predecessor_missing' => true,
                    'client_request_id' => $clientRequestId,
                ], 'hermes.runs', $cancelledBeforeQueue ? 'cancelled' : 'queued');
                $dispatchRunId = $cancelledBeforeQueue ? null : $run->id;
                $events[] = $event;

                return [
                    'run' => $run,
                    'user_message' => $userMessage,
                    'event' => $event,
                    'events' => $events,
                ];
            }
            if (filled(data_get($predecessor->metadata, 'superseded_by_client_request_id'))) {
                throw new \DomainException('The first request has already been superseded.');
            }
            if ($predecessor->assistant_message_id !== null || $this->runHasCompletedMutatingWork($predecessor)) {
                throw new \DomainException('The first request already committed work and cannot be safely replaced.');
            }
            if (! in_array($predecessor->status, ['queued', 'running', 'cancelled', 'failed'], true)) {
                throw new \DomainException('The first request has already finished and cannot be safely replaced.');
            }

            $predecessorMetadata = is_array($predecessor->metadata) ? $predecessor->metadata : [];
            $predecessor->update([
                'status' => 'cancelled',
                'cancelled_at' => $predecessor->cancelled_at ?? now(),
                'completed_at' => $predecessor->completed_at ?? now(),
                'metadata' => array_merge($predecessorMetadata, [
                    'superseded_by_client_request_id' => $clientRequestId,
                    'superseded_at' => now()->toIso8601String(),
                ]),
            ]);
            $this->deleteOrphanAssistantsForRun($predecessor);

            $supersededIds = collect([
                ...((array) data_get($predecessor->userMessage->metadata, 'superseded_client_request_ids', [])),
                $supersededClientRequestId,
            ])->map(fn (mixed $id): string => trim((string) $id))
                ->filter()
                ->unique()
                ->values()
                ->all();
            $successorMetadata = array_merge($metadata, [
                'supersedes_run_id' => $predecessor->id,
                'superseded_client_request_ids' => $supersededIds,
            ]);
            if ($cancelledBeforeQueue) {
                $successorMetadata = array_merge($successorMetadata, [
                    'cancelled_before_queue' => true,
                    'cancelled_at' => now()->toIso8601String(),
                ]);
            }
            $userMessage = $predecessor->userMessage;
            $userMessage->update([
                'content' => $content,
                'metadata' => $successorMetadata,
            ]);

            $run = AssistantRun::create([
                'user_id' => $lockedSession->user_id,
                'workspace_id' => $lockedSession->workspace_id,
                'conversation_session_id' => $lockedSession->id,
                'user_message_id' => $userMessage->id,
                'source' => $source,
                'status' => $cancelledBeforeQueue ? 'cancelled' : 'queued',
                'input' => $content,
                'metadata' => $successorMetadata,
                'cancelled_at' => $cancelledBeforeQueue ? now() : null,
                'completed_at' => $cancelledBeforeQueue ? now() : null,
            ]);
            $lockedSession->update([
                'status' => $cancelledBeforeQueue ? 'active' : 'queued',
                'last_activity_at' => now(),
            ]);

            $superseded = $this->recordEvent($predecessor, 'runtime.run_superseded', [
                'run_id' => $predecessor->id,
                'successor_run_id' => $run->id,
                'client_request_id' => $supersededClientRequestId,
                'superseded_by_client_request_id' => $clientRequestId,
            ], 'hermes.runs', 'cancelled');
            $events = [$superseded, ...($cancelledBeforeQueue ? [] : $this->recordIntentEvents($run, $userMessage, $successorMetadata))];
            $event = $this->recordEvent($run, $cancelledBeforeQueue ? 'runtime.run_cancelled_before_queue' : 'runtime.run_queued', [
                'run_id' => $run->id,
                'message_id' => $userMessage->id,
                'source' => $source,
                'reused_user_message' => true,
                'supersedes_run_id' => $predecessor->id,
                'client_request_id' => $clientRequestId,
            ], 'hermes.runs', $cancelledBeforeQueue ? 'cancelled' : 'queued');
            $dispatchRunId = $cancelledBeforeQueue ? null : $run->id;
            $events[] = $event;

            return [
                'run' => $run,
                'user_message' => $userMessage->refresh(),
                'event' => $event,
                'events' => $events,
            ];
        }, 3);

        if ($dispatchRunId !== null) {
            $this->dispatchRunAfterResponse($dispatchRunId);
        }

        return $queued;
    }

    public function cancelRun(AssistantRun $run): AssistantRun
    {
        DB::transaction(function () use ($run): void {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $lockedRun instanceof AssistantRun
                || in_array($lockedRun->status, ['completed', 'cancelled'], true)
                || ($lockedRun->status === 'failed' && $lockedRun->assistant_message_id !== null)) {
                return;
            }

            $lockedRun->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'completed_at' => now(),
            ]);
            $this->deleteOrphanAssistantsForRun($lockedRun);
            if ($session instanceof ConversationSession) {
                $session->update([
                    'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
            }
            $this->recordEvent($lockedRun, 'runtime.run_cancel_requested', [
                'run_id' => $lockedRun->id,
            ], 'hermes.runs', 'cancelling');
        }, 3);

        return $run->refresh();
    }

    public function reconcileStaleRun(AssistantRun $run): AssistantRun
    {
        $run->refresh();
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return $run;
        }

        $startedAt = $run->started_at ?: $run->created_at;
        $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 210);
        if ($startedAt === null || $startedAt->gt(now()->subSeconds($staleAfterSeconds))) {
            return $run;
        }

        $this->markStaleFailed($run, $staleAfterSeconds);

        return $run->refresh();
    }

    public function recoverStaleRun(AssistantRun $run, HermesRuntimeService $runtime): AssistantRun
    {
        unset($runtime);

        return $this->prepareRunForBackgroundResponse($run);
    }

    public function resolveFailedRunForResponse(AssistantRun $run, HermesRuntimeService $runtime): AssistantRun
    {
        unset($runtime);

        return $this->prepareRunForBackgroundResponse($run);
    }

    public function prepareRunForBackgroundResponse(AssistantRun $run): AssistantRun
    {
        $run->refresh();
        if ($this->runRecoveryProhibited($run)) {
            return $run;
        }
        $reconciled = $this->reconcileCommittedAssistant($run);
        if ($reconciled instanceof AssistantRun) {
            return $reconciled;
        }
        if (in_array($run->status, ['queued', 'running'], true)) {
            $startedAt = $run->started_at ?: $run->created_at;
            $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 210);
            if ($startedAt !== null && $startedAt->lte(now()->subSeconds($staleAfterSeconds))) {
                if ($this->runRecoveryWindowExpired($run)) {
                    $this->markStaleFailed($run, $staleAfterSeconds, 'Run expired before it could be safely recovered.');

                    return $this->prepareRunForBackgroundResponse($run->refresh());
                }

                $metadata = is_array($run->metadata) ? $run->metadata : [];
                $attempts = (int) ($metadata['background_stale_retry_attempts'] ?? 0);
                $maxAttempts = (int) config('services.hermes_runtime.assistant_run_background_retry_attempts', 1);
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
                        $this->dispatchRunAfterResponse($run->id);
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

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $attempts = (int) ($metadata['background_response_retry_attempts'] ?? 0);
        $maxAttempts = (int) config('services.hermes_runtime.assistant_run_background_retry_attempts', 1);
        if ($maxAttempts > 0 && $attempts < $maxAttempts && ! $this->runHasCompletedMutatingWork($run)) {
            $requeued = $this->requeueRunWithLock(
                $run,
                ['failed'],
                'background_response_retry_attempts',
                'background_response_retried_at',
                'runtime.run_retry_queued',
                $maxAttempts,
                ['background_response_original_error' => $run->error],
            );
            if ($requeued) {
                $this->dispatchRunAfterResponse($run->id);
            }

            return $run->refresh();
        }

        return $this->completeFailedRunWithBridgeMessage($run);
    }

    private function isRecoverableFailedStaleRun(AssistantRun $run): bool
    {
        if ($run->status !== 'failed' || $run->assistant_message_id !== null) {
            return false;
        }

        $error = strtolower((string) $run->error);
        if (! str_contains($error, 'run expired before it could be safely recovered')
            && ! str_contains($error, 'assistant run did not complete within')
            && ! str_contains($error, 'has timed out')
            && ! str_contains($error, 'timed out')
            && ! str_contains($error, 'timeout')) {
            return false;
        }

        return ! $this->runHasCompletedMutatingWork($run);
    }

    private function completeFailedRunWithBridgeMessage(AssistantRun $run): AssistantRun
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

            $metadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $hadCompletedWork = $this->runHasCompletedMutatingWork($lockedRun);
            $content = $hadCompletedWork
                ? 'I finished the app updates and refreshed the latest details. Tell me what you want to do next.'
                : 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.';

            $assistantMessage = ConversationMessage::create([
                'user_id' => $lockedRun->user_id,
                'conversation_session_id' => $lockedRun->conversation_session_id,
                'role' => 'assistant',
                'content' => $content,
                'metadata' => [
                    'runtime' => 'failed_run_bridge',
                    'original_error' => str((string) $lockedRun->error)->limit(1000, '')->toString(),
                    'had_completed_work' => $hadCompletedWork,
                ],
            ]);

            $lockedRun->update([
                'status' => 'completed',
                'assistant_message_id' => $assistantMessage->id,
                'error' => null,
                'result' => [
                    'status' => 'completed',
                    'assistant_message_id' => $assistantMessage->id,
                    'resolved_failed_run' => true,
                    'had_completed_work' => $hadCompletedWork,
                ],
                'metadata' => array_merge($metadata, [
                    'failed_response_resolved_at' => now()->toIso8601String(),
                    'failed_response_original_error' => $lockedRun->error,
                    'failed_response_had_completed_work' => $hadCompletedWork,
                ]),
                'completed_at' => now(),
            ]);
            $session->update([
                'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($lockedRun->refresh(), 'runtime.run_failed_response_resolved', [
                'run_id' => $lockedRun->id,
                'assistant_message_id' => $assistantMessage->id,
                'had_completed_work' => $hadCompletedWork,
            ], 'hermes.runs', 'succeeded');

            return $lockedRun->refresh();
        }, 3);
    }

    public function closeExpiredStaleRunsForSession(ConversationSession $session): void
    {
        $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 210);

        /** @var EloquentCollection<int, AssistantRun> $runs */
        $runs = AssistantRun::query()
            ->where('conversation_session_id', $session->id)
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
        $messageId = (int) $run->user_message_id;
        if ($messageId <= 0) {
            return false;
        }

        return ActivityEvent::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where('status', 'succeeded')
            ->where(function ($query) use ($messageId): void {
                $query->where('payload->work_item_id', 'like', 'crud-plan-'.$messageId.'-%')
                    ->orWhere('payload->source_message_id', $messageId)
                    ->orWhere('payload->message_id', $messageId)
                    ->orWhere('payload->user_message_id', $messageId);
            })
            ->whereIn('event_type', [
                'assistant.task.created',
                'assistant.task.updated',
                'assistant.task.deleted',
                'assistant.reminder.created',
                'assistant.reminder.updated',
                'assistant.reminder.deleted',
                'assistant.calendar_event.created',
                'assistant.calendar_event.updated',
                'assistant.calendar_event.deleted',
                'assistant.note.created',
                'assistant.note.updated',
                'assistant.note.deleted',
                'assistant.note',
                'assistant.note_folder.created',
                'assistant.note_folder.updated',
                'assistant.note_folder.deleted',
                'assistant.event_category.saved',
                'assistant.event_category.updated',
                'assistant.event_category.deleted',
                'assistant.memory.created',
                'assistant.memory.updated',
                'assistant.memory.deleted',
                'assistant.workspace_memory.noted',
                'assistant.blocker.created',
                'assistant.blocker.updated',
                'assistant.blocker.resolved',
                'assistant.blocker.deleted',
                'assistant.approval.created',
                'assistant.approval.created_directly',
                'assistant.approval.updated',
                'assistant.approval.approved',
                'assistant.approval.denied',
                'assistant.approval.denied_by_action',
                'assistant.approval.deleted',
                'assistant.agent_profile.updated',
                'assistant.conversation_session.updated',
            ])
            ->exists();
    }

    /**
     * @return array<int, ActivityEvent>
     */
    private function recordIntentEvents(AssistantRun $run, ConversationMessage $userMessage, array $metadata): array
    {
        $intent = is_array($metadata['bean_intent'] ?? null) ? $metadata['bean_intent'] : null;
        if ($intent === null) {
            return [];
        }

        $events = [$this->recordEvent($run, 'runtime.intent_routed', [
            'run_id' => $run->id,
            'message_id' => $userMessage->id,
            ...$intent,
        ], 'hermes.router', 'completed')];

        foreach ((array) ($intent['work_plan'] ?? []) as $index => $item) {
            if (! is_array($item) || trim((string) ($item['label'] ?? '')) === '') {
                continue;
            }
            $events[] = $this->recordEvent($run, 'assistant.work_item.planned', [
                'run_id' => $run->id,
                'message_id' => $userMessage->id,
                'work_item_id' => (string) ($item['id'] ?? 'route-plan-'.$index),
                'work_label' => (string) $item['label'],
                'label' => (string) $item['label'],
                'work_order' => $index,
            ], 'hermes.router', 'started');
        }

        return $events;
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

    private function addCancellationTombstone(ConversationSession $session, string $clientRequestId): void
    {
        $sessionMetadata = is_array($session->metadata) ? $session->metadata : [];
        $sessionMetadata['cancelled_client_request_ids'] = collect((array) ($sessionMetadata['cancelled_client_request_ids'] ?? []))
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->push(trim($clientRequestId))
            ->unique()
            ->take(-50)
            ->values()
            ->all();
        $session->update(['metadata' => $sessionMetadata]);
    }

    private function existingRunForClientRequest(ConversationSession $session, array $metadata): ?AssistantRun
    {
        $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
        if ($clientRequestId === '') {
            return null;
        }

        return AssistantRun::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->where('metadata->client_request_id', $clientRequestId)
            ->with('userMessage')
            ->latest('id')
            ->first();
    }

    private function missingPredecessorSuccessorForClientRequest(ConversationSession $session, array $metadata): ?AssistantRun
    {
        $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
        if ($clientRequestId === '') {
            return null;
        }

        return $this->missingPredecessorSuccessorForId($session, $clientRequestId);
    }

    private function missingPredecessorSuccessorForId(
        ConversationSession $session,
        string $clientRequestId,
        bool $lockForUpdate = false,
    ): ?AssistantRun {
        $query = AssistantRun::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->where('metadata->supersession_predecessor_missing', true)
            ->whereJsonContains('metadata->superseded_client_request_ids', $clientRequestId)
            ->with('userMessage')
            ->latest('id');
        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query->first();
    }

    /**
     * Atomically make the newest correction win when several corrections all name an
     * original request that never reached the server.
     *
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>}
     */
    private function replaceMissingPredecessorSuccessor(
        ConversationSession $session,
        AssistantRun $predecessor,
        string $missingClientRequestId,
        string $content,
        array $metadata,
        string $source,
        bool $cancelledBeforeQueue,
    ): array {
        $userMessage = $predecessor->userMessage;
        if (! $userMessage instanceof ConversationMessage) {
            throw new \DomainException('The earlier correction has no durable user message.');
        }
        if (filled(data_get($predecessor->metadata, 'superseded_by_client_request_id'))) {
            throw new \DomainException('The earlier correction has already been superseded.');
        }
        if ($predecessor->assistant_message_id !== null || $this->runHasCompletedMutatingWork($predecessor)) {
            throw new \DomainException('The earlier correction already committed work and cannot be safely replaced.');
        }
        if (! in_array($predecessor->status, ['queued', 'running', 'cancelled', 'failed'], true)) {
            throw new \DomainException('The earlier correction has already finished and cannot be safely replaced.');
        }

        $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
        $predecessorClientRequestId = trim((string) data_get($predecessor->metadata, 'client_request_id', ''));
        $predecessorMetadata = is_array($predecessor->metadata) ? $predecessor->metadata : [];
        $predecessor->update([
            'status' => 'cancelled',
            'cancelled_at' => $predecessor->cancelled_at ?? now(),
            'completed_at' => $predecessor->completed_at ?? now(),
            'metadata' => array_merge($predecessorMetadata, [
                'superseded_by_client_request_id' => $clientRequestId,
                'superseded_at' => now()->toIso8601String(),
            ]),
        ]);
        $this->deleteOrphanAssistantsForRun($predecessor);

        $supersededIds = collect([
            ...((array) data_get($userMessage->metadata, 'superseded_client_request_ids', [])),
            $missingClientRequestId,
            $predecessorClientRequestId,
        ])->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values()
            ->all();
        $successorMetadata = array_merge($metadata, [
            'supersession_predecessor_missing' => true,
            'missing_predecessor_client_request_id' => $missingClientRequestId,
            'supersedes_run_id' => $predecessor->id,
            'superseded_client_request_ids' => $supersededIds,
        ]);
        if ($cancelledBeforeQueue) {
            $successorMetadata = array_merge($successorMetadata, [
                'cancelled_before_queue' => true,
                'cancelled_at' => now()->toIso8601String(),
            ]);
        }
        $userMessage->update([
            'content' => $content,
            'metadata' => $successorMetadata,
        ]);
        $run = AssistantRun::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'source' => $source,
            'status' => $cancelledBeforeQueue ? 'cancelled' : 'queued',
            'input' => $content,
            'metadata' => $successorMetadata,
            'cancelled_at' => $cancelledBeforeQueue ? now() : null,
            'completed_at' => $cancelledBeforeQueue ? now() : null,
        ]);
        $session->update([
            'status' => $cancelledBeforeQueue ? 'active' : 'queued',
            'last_activity_at' => now(),
        ]);

        $superseded = $this->recordEvent($predecessor, 'runtime.run_superseded', [
            'run_id' => $predecessor->id,
            'successor_run_id' => $run->id,
            'client_request_id' => $predecessorClientRequestId,
            'superseded_by_client_request_id' => $clientRequestId,
            'missing_predecessor_client_request_id' => $missingClientRequestId,
        ], 'hermes.runs', 'cancelled');
        $events = [$superseded, ...($cancelledBeforeQueue ? [] : $this->recordIntentEvents($run, $userMessage, $successorMetadata))];
        $event = $this->recordEvent($run, $cancelledBeforeQueue ? 'runtime.run_cancelled_before_queue' : 'runtime.run_queued', [
            'run_id' => $run->id,
            'message_id' => $userMessage->id,
            'source' => $source,
            'reused_user_message' => true,
            'supersedes_run_id' => $predecessor->id,
            'missing_predecessor_client_request_id' => $missingClientRequestId,
            'client_request_id' => $clientRequestId,
        ], 'hermes.runs', $cancelledBeforeQueue ? 'cancelled' : 'queued');
        $events[] = $event;

        return [
            'run' => $run,
            'user_message' => $userMessage->refresh(),
            'event' => $event,
            'events' => $events,
        ];
    }

    /**
     * A correction may reach the server before its original request. Keep the original
     * run as an auditable cancelled attempt, but point it at the correction's durable
     * user message so stale text never becomes a second conversation turn.
     *
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>}
     */
    private function coalescedLateOriginalResult(
        ConversationSession $session,
        AssistantRun $successor,
        string $content,
        array $metadata,
        string $source,
    ): array {
        $userMessage = $successor->userMessage;
        if (! $userMessage instanceof ConversationMessage) {
            throw new \DomainException('The correction has no durable user message.');
        }

        $runMetadata = array_merge($metadata, [
            'cancelled_before_queue' => true,
            'cancelled_at' => now()->toIso8601String(),
            'late_superseded_request_coalesced' => true,
            'coalesced_into_run_id' => $successor->id,
            'superseded_by_client_request_id' => data_get($successor->metadata, 'client_request_id'),
        ]);
        $run = AssistantRun::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'source' => $source,
            'status' => 'cancelled',
            'input' => $content,
            'metadata' => $runMetadata,
            'cancelled_at' => now(),
            'completed_at' => now(),
        ]);
        $session->update(['last_activity_at' => now()]);
        $event = $this->recordEvent($run, 'runtime.run_late_superseded_request_coalesced', [
            'run_id' => $run->id,
            'message_id' => $userMessage->id,
            'source' => $source,
            'client_request_id' => data_get($metadata, 'client_request_id'),
            'coalesced_into_run_id' => $successor->id,
        ], 'hermes.runs', 'cancelled');

        return [
            'run' => $run,
            'user_message' => $userMessage,
            'event' => $event,
            'events' => [$event],
        ];
    }

    /**
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent,events:array<int, ActivityEvent>,existing:bool}
     */
    private function replayedQueueResult(AssistantRun $run): array
    {
        $event = $this->recordEvent($run, 'runtime.run_queue_replayed', [
            'run_id' => $run->id,
            'client_request_id' => data_get($run->metadata, 'client_request_id'),
        ], 'hermes.runs', 'recorded');

        return [
            'run' => $run,
            'user_message' => $run->userMessage,
            'event' => $event,
            'events' => [$event],
            'existing' => true,
        ];
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

        return in_array($run->status, ['completed', 'cancelled'], true)
            || $run->cancelled_at !== null
            || filled($metadata['superseded_by_client_request_id'] ?? null)
            || ($metadata['cancelled_before_queue'] ?? false) === true;
    }

    private function deleteOrphanAssistantsForRun(AssistantRun $run): void
    {
        $assistantIds = ConversationMessage::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where('role', 'assistant')
            ->where('metadata->assistant_run_id', $run->id)
            ->pluck('id');
        if ($assistantIds->isEmpty()) {
            return;
        }

        MemoryEvent::query()->whereIn('assistant_message_id', $assistantIds)->delete();
        ConversationMessage::query()->whereIn('id', $assistantIds)->delete();
    }

    private function reconcileCommittedAssistant(AssistantRun $run): ?AssistantRun
    {
        $assistantMessage = null;
        $reconciled = DB::transaction(function () use ($run, &$assistantMessage): ?AssistantRun {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession
                || ! $lockedRun instanceof AssistantRun
                || $lockedRun->assistant_message_id !== null
                || ! in_array($lockedRun->status, ['queued', 'running', 'failed'], true)
                || $this->runRecoveryProhibited($lockedRun)) {
                return null;
            }

            $assistantMessage = ConversationMessage::query()
                ->where('conversation_session_id', $lockedRun->conversation_session_id)
                ->where('role', 'assistant')
                ->where('metadata->assistant_run_id', $lockedRun->id)
                ->latest('id')
                ->first();
            if (! $assistantMessage instanceof ConversationMessage) {
                return null;
            }

            $lockedRun->update([
                'status' => 'completed',
                'assistant_message_id' => $assistantMessage->id,
                'error' => null,
                'result' => [
                    'status' => 'completed',
                    'assistant_message_id' => $assistantMessage->id,
                    'reconciled_committed_assistant' => true,
                ],
                'completed_at' => now(),
            ]);
            $session->update([
                'status' => $this->activeRunSessionStatus($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_orphan_assistant_reconciled', [
                'run_id' => $lockedRun->id,
                'assistant_message_id' => $assistantMessage->id,
            ], 'hermes.runs', 'succeeded');

            return $lockedRun->refresh();
        }, 3);

        if ($reconciled instanceof AssistantRun
            && $assistantMessage instanceof ConversationMessage
            && ! MemoryEvent::query()->where('assistant_message_id', $assistantMessage->id)->exists()) {
            $userMessage = ConversationMessage::find($reconciled->user_message_id);
            if ($userMessage instanceof ConversationMessage && $reconciled->session instanceof ConversationSession) {
                app(BeanMemoryService::class)->recordTurnCandidate($reconciled->session, $userMessage, $assistantMessage);
            }
        }

        return $reconciled;
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

    private function dispatchRunAfterResponse(int $runId): void
    {
        if (app()->runningInConsole()) {
            ProcessAssistantRun::dispatch($runId);

            return;
        }

        ProcessAssistantRun::dispatchAfterResponse($runId);
    }
}
