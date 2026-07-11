<?php

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Services\AssistantRunService;
use App\Services\BeanMemoryService;
use App\Services\HermesRuntimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAssistantRun implements ShouldQueue
{
    use Queueable;

    // WithoutOverlapping releases a later run while an earlier run in the same
    // conversation owns the lock. Those releases consume attempts, so this must
    // cover the full lock lifetime rather than treating a normal queue wait as a
    // terminal one-attempt failure.
    public int $tries = 120;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $assistantRunId) {}

    public function middleware(): array
    {
        $run = AssistantRun::query()
            ->select(['id', 'conversation_session_id'])
            ->find($this->assistantRunId);
        $lockKey = $run?->conversation_session_id
            ? "assistant-session-{$run->conversation_session_id}"
            : "assistant-run-{$this->assistantRunId}";

        return [
            (new WithoutOverlapping($lockKey))
                ->releaseAfter(2)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(HermesRuntimeService $runtime, AssistantRunService $runs): void
    {
        $run = AssistantRun::with('session', 'userMessage')->find($this->assistantRunId);
        if (! $run || ! $run->session || ! $run->userMessage) {
            return;
        }

        $supersedesRunId = (int) data_get($run->metadata, 'supersedes_run_id', 0);
        if ($supersedesRunId > 0) {
            $predecessor = AssistantRun::query()
                ->whereKey($supersedesRunId)
                ->where('user_id', $run->user_id)
                ->where('conversation_session_id', $run->conversation_session_id)
                ->first();
            if ($predecessor instanceof AssistantRun && $runs->runHasCompletedMutatingWork($predecessor)) {
                $this->markSupersessionConflict($run, $predecessor);

                return;
            }
        }

        if ($run->status === 'cancelled') {
            $this->markCancelled($run);

            return;
        }

        $started = DB::transaction(function () use ($run): bool {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun || ! in_array($lockedRun->status, ['queued', 'running'], true)) {
                return false;
            }

            $lockedRun->update([
                'status' => 'running',
                'started_at' => $lockedRun->started_at ?? now(),
            ]);
            $session->update([
                'status' => 'running',
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_started', [
                'run_id' => $lockedRun->id,
                'source' => $lockedRun->source,
                'message_id' => $lockedRun->user_message_id,
                'queue_wait_ms' => $this->elapsedMilliseconds($lockedRun->created_at),
            ], 'hermes.runs', 'started');

            return true;
        }, 3);

        if (! $started) {
            return;
        }

        $run->refresh()->load(['session', 'userMessage']);

        $userMessageMetadata = is_array($run->userMessage->metadata) ? $run->userMessage->metadata : [];
        $run->userMessage->setAttribute('metadata', array_merge($userMessageMetadata, [
            'assistant_run_id' => $run->id,
            'defer_memory_candidate' => true,
        ]));

        try {
            $result = $runtime->sendExistingMessage($run->session->refresh(), $run->userMessage);
            $assistantMessage = $result['assistant_message'] ?? null;
            $completionWon = DB::transaction(function () use ($run, $result, $assistantMessage): bool {
                $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
                $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
                if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun || $lockedRun->status !== 'running') {
                    $alreadyReconciled = $assistantMessage instanceof ConversationMessage
                        && $lockedRun instanceof AssistantRun
                        && $lockedRun->status === 'completed'
                        && (int) $lockedRun->assistant_message_id === (int) $assistantMessage->id;
                    if ($assistantMessage instanceof ConversationMessage && ! $alreadyReconciled) {
                        $assistantMessage->delete();
                    }
                    if ($session instanceof ConversationSession && $lockedRun instanceof AssistantRun) {
                        $session->update([
                            'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                            'last_activity_at' => now(),
                        ]);
                    }

                    return false;
                }

                $status = ($result['status'] ?? null) === 'cancelled' ? 'cancelled' : 'completed';
                $lockedRun->update([
                    'status' => $status,
                    'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                    'result' => [
                        'status' => $result['status'] ?? null,
                        'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                        'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                    ],
                    'cancelled_at' => $status === 'cancelled' ? now() : null,
                    'completed_at' => now(),
                ]);
                $session->update([
                    'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
                $this->recordEvent($lockedRun, 'runtime.run_completed', [
                    'run_id' => $lockedRun->id,
                    'status' => $status,
                    'assistant_message_id' => $lockedRun->assistant_message_id,
                    'run_duration_ms' => $this->elapsedMilliseconds($lockedRun->started_at),
                ], 'hermes.runs', $status === 'completed' ? 'succeeded' : 'cancelled');

                return $status === 'completed';
            }, 3);

            if ($completionWon && $assistantMessage instanceof ConversationMessage) {
                $persistedUserMessage = ConversationMessage::find($run->user_message_id);
                if ($persistedUserMessage instanceof ConversationMessage) {
                    app(BeanMemoryService::class)->recordTurnCandidate($run->session->refresh(), $persistedUserMessage, $assistantMessage);
                }
            }
        } catch (\Throwable $exception) {
            $this->markFailed($run, $exception->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $run = AssistantRun::with('session')->find($this->assistantRunId);
        if (! $run) {
            return;
        }

        $this->markFailed($run, $exception->getMessage());
    }

    private function markCancelled(AssistantRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $lockedRun instanceof AssistantRun || in_array($lockedRun->status, ['completed', 'failed'], true)) {
                return;
            }

            $transitioned = $lockedRun->status !== 'cancelled';
            $lockedRun->update([
                'status' => 'cancelled',
                'cancelled_at' => $lockedRun->cancelled_at ?? now(),
                'completed_at' => $lockedRun->completed_at ?? now(),
            ]);
            $this->deleteOrphanAssistants($lockedRun);
            if ($session instanceof ConversationSession) {
                $session->update([
                    'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
            }
            if ($transitioned) {
                $this->recordEvent($lockedRun, 'runtime.run_cancelled', ['run_id' => $lockedRun->id], 'hermes.runs', 'cancelled');
            }
        }, 3);
    }

    private function markSupersessionConflict(AssistantRun $run, AssistantRun $predecessor): void
    {
        DB::transaction(function () use ($run, $predecessor): void {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun || ! in_array($lockedRun->status, ['queued', 'running'], true)) {
                return;
            }

            $assistantMessage = ConversationMessage::create([
                'user_id' => $lockedRun->user_id,
                'conversation_session_id' => $lockedRun->conversation_session_id,
                'role' => 'assistant',
                'content' => 'That first change completed before I could safely replace it, so I did not make a second change. Tell me whether you want me to update or undo the first one.',
                'metadata' => [
                    'runtime' => 'supersession_conflict',
                    'supersedes_run_id' => $predecessor->id,
                ],
            ]);
            $metadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $lockedRun->update([
                'status' => 'failed',
                'assistant_message_id' => $assistantMessage->id,
                'error' => 'The superseded run committed mutating work before cancellation.',
                'result' => [
                    'status' => 'supersession_conflict',
                    'assistant_message_id' => $assistantMessage->id,
                    'supersedes_run_id' => $predecessor->id,
                ],
                'metadata' => array_merge($metadata, [
                    'supersession_conflict' => true,
                    'supersession_conflict_detected_at' => now()->toIso8601String(),
                ]),
                'completed_at' => now(),
            ]);
            $session->update([
                'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_supersession_conflict', [
                'run_id' => $lockedRun->id,
                'supersedes_run_id' => $predecessor->id,
                'assistant_message_id' => $assistantMessage->id,
            ], 'hermes.runs', 'failed');
        }, 3);
    }

    private function markFailed(AssistantRun $run, string $reason): void
    {
        $failed = DB::transaction(function () use ($run, $reason): bool {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun) {
                return false;
            }

            if (! in_array($lockedRun->status, ['queued', 'running'], true)) {
                // A cancel/reconcile CAS may have won after the runtime committed its
                // assistant but before the exception reached this worker. Under the same
                // session/run locks, remove only output that no terminal run owns.
                // Failed + unlinked remains an intentionally recoverable ambiguity until
                // polling reconciles it or Stop transitions it to cancelled.
                if ($lockedRun->status !== 'failed' || $lockedRun->assistant_message_id !== null) {
                    $this->deleteOrphanAssistants($lockedRun, preserveLinkedTerminalAssistant: true);
                }

                return false;
            }

            $lockedRun->update([
                'status' => 'failed',
                'error' => $reason,
                'completed_at' => now(),
            ]);
            $session->update([
                'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_failed', [
                'run_id' => $lockedRun->id,
                'reason' => $reason,
            ], 'hermes.runs', 'failed');

            return true;
        }, 3);

        if ($failed) {
            Log::error('Assistant run failed.', [
                'run_id' => $run->id,
                'session_id' => $run->conversation_session_id,
                'exception' => $reason,
            ]);
        }
    }

    private function sessionStatusForActiveRuns(int $sessionId, int $excludingRunId): string
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

    private function deleteOrphanAssistants(AssistantRun $run, bool $preserveLinkedTerminalAssistant = false): void
    {
        $assistants = ConversationMessage::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where('role', 'assistant')
            ->where('metadata->assistant_run_id', $run->id);
        if ($preserveLinkedTerminalAssistant
            && in_array($run->status, ['completed', 'failed'], true)
            && $run->assistant_message_id !== null) {
            $assistants->where('id', '!=', $run->assistant_message_id);
        }
        $assistantIds = $assistants->pluck('id');
        if ($assistantIds->isEmpty()) {
            return;
        }

        MemoryEvent::query()->whereIn('assistant_message_id', $assistantIds)->delete();
        ConversationMessage::query()->whereIn('id', $assistantIds)->delete();
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
}
