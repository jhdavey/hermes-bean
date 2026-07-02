<?php

namespace App\Services;

use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AssistantRunService
{
    /**
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent}
     */
    public function queueRun(ConversationSession $session, string $content, array $metadata = [], string $source = 'http'): array
    {
        $queued = DB::transaction(function () use ($session, $content, $metadata, $source): array {
            $userMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);

            $run = AssistantRun::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'user_message_id' => $userMessage->id,
                'source' => $source,
                'status' => 'queued',
                'input' => $content,
                'metadata' => $metadata ?: null,
            ]);

            $session->update([
                'status' => 'queued',
                'last_activity_at' => now(),
            ]);

            $event = $this->recordEvent($run, 'runtime.run_queued', [
                'run_id' => $run->id,
                'message_id' => $userMessage->id,
                'source' => $source,
            ], 'hermes.runs', 'queued');

            return ['run' => $run, 'user_message' => $userMessage, 'event' => $event];
        });

        ProcessAssistantRun::dispatch($queued['run']->id);

        return $queued;
    }

    /**
     * @return array{run:AssistantRun,user_message:ConversationMessage,event:ActivityEvent}
     */
    public function queueExistingMessage(ConversationSession $session, ConversationMessage $userMessage, array $metadata = [], string $source = 'http'): array
    {
        $queued = DB::transaction(function () use ($session, $userMessage, $metadata, $source): array {
            $run = AssistantRun::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'user_message_id' => $userMessage->id,
                'source' => $source,
                'status' => 'queued',
                'input' => $userMessage->content,
                'metadata' => $metadata ?: ($userMessage->metadata ?: null),
            ]);

            $session->update([
                'status' => 'queued',
                'last_activity_at' => now(),
            ]);

            $event = $this->recordEvent($run, 'runtime.run_queued', [
                'run_id' => $run->id,
                'message_id' => $userMessage->id,
                'source' => $source,
                'reused_user_message' => true,
            ], 'hermes.runs', 'queued');

            return ['run' => $run, 'user_message' => $userMessage, 'event' => $event];
        });

        ProcessAssistantRun::dispatch($queued['run']->id);

        return $queued;
    }

    public function cancelRun(AssistantRun $run): AssistantRun
    {
        DB::transaction(function () use ($run): void {
            $run->refresh();
            if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
                return;
            }

            $run->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
            ]);
            $run->session?->update([
                'status' => 'cancelling',
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($run, 'runtime.run_cancel_requested', [
                'run_id' => $run->id,
            ], 'hermes.runs', 'cancelling');
        });

        return $run->refresh();
    }

    public function reconcileStaleRun(AssistantRun $run): AssistantRun
    {
        $run->refresh();
        if (! in_array($run->status, ['queued', 'running'], true)) {
            return $run;
        }

        $startedAt = $run->started_at ?: $run->created_at;
        $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 75);
        if ($startedAt === null || $startedAt->gt(now()->subSeconds($staleAfterSeconds))) {
            return $run;
        }

        $this->markStaleFailed($run, $staleAfterSeconds);

        return $run->refresh();
    }

    public function recoverStaleRun(AssistantRun $run, HermesRuntimeService $runtime): AssistantRun
    {
        $run->refresh();
        $recoveringFailedStaleRun = $this->isRecoverableFailedStaleRun($run);
        if (! in_array($run->status, ['queued', 'running'], true) && ! $recoveringFailedStaleRun) {
            return $run;
        }

        $startedAt = $run->started_at ?: $run->created_at;
        $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 75);
        if (! $recoveringFailedStaleRun && ($startedAt === null || $startedAt->gt(now()->subSeconds($staleAfterSeconds)))) {
            return $run;
        }

        if (! $recoveringFailedStaleRun && $this->runRecoveryWindowExpired($run)) {
            $this->markStaleFailed($run, $staleAfterSeconds, 'Run expired before it could be safely recovered.');

            return $run->refresh();
        }

        if (! $run->session || ! $run->userMessage) {
            if (! $recoveringFailedStaleRun) {
                $this->markStaleFailed($run, $staleAfterSeconds);
            }

            return $run->refresh();
        }

        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $attempts = (int) ($metadata['stale_recovery_attempts'] ?? 0);
        if ($attempts >= 1 || $this->runHasCompletedMutatingWork($run)) {
            if (! $recoveringFailedStaleRun) {
                $this->markStaleFailed($run, $staleAfterSeconds);
            }

            return $run->refresh();
        }

        $run->update([
            'status' => 'running',
            'started_at' => now(),
            'completed_at' => null,
            'error' => null,
            'result' => null,
            'metadata' => array_merge($metadata, [
                'stale_recovery_attempts' => $attempts + 1,
                'stale_recovered_at' => now()->toIso8601String(),
                'stale_recovered_from_failed_status' => $recoveringFailedStaleRun,
            ]),
        ]);

        $this->recordEvent($run, 'runtime.run_stale_recovery_started', [
            'run_id' => $run->id,
            'message_id' => $run->user_message_id,
            'attempt' => $attempts + 1,
        ], 'hermes.runs', 'started');

        try {
            $result = $runtime->sendExistingMessage($run->session->refresh(), $run->userMessage);
            $assistantMessage = $result['assistant_message'] ?? null;

            $run->update([
                'status' => $result['status'] === 'cancelled' ? 'cancelled' : 'completed',
                'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                'result' => [
                    'status' => $result['status'] ?? null,
                    'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                    'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                    'recovered_from_stale' => true,
                ],
                'completed_at' => now(),
            ]);

            $this->recordEvent($run, 'runtime.run_stale_recovery_completed', [
                'run_id' => $run->id,
                'status' => $run->status,
                'assistant_message_id' => $run->assistant_message_id,
            ], 'hermes.runs', $run->status === 'completed' ? 'succeeded' : 'cancelled');
        } catch (\Throwable $exception) {
            Log::error('Assistant stale run recovery failed.', [
                'run_id' => $run->id,
                'session_id' => $run->conversation_session_id,
                'exception' => $exception->getMessage(),
            ]);
            $this->markStaleFailed($run, $staleAfterSeconds, $exception->getMessage());
        }

        return $run->refresh();
    }

    public function resolveFailedRunForResponse(AssistantRun $run, HermesRuntimeService $runtime): AssistantRun
    {
        $run->refresh();
        if ($run->status !== 'failed' || $run->assistant_message_id !== null) {
            return $run;
        }

        if ($run->session && $run->userMessage && ! $this->runHasCompletedMutatingWork($run)) {
            $metadata = is_array($run->metadata) ? $run->metadata : [];
            $attempts = (int) ($metadata['failed_response_recovery_attempts'] ?? 0);
            if ($attempts < 1) {
                $run->update([
                    'status' => 'running',
                    'started_at' => now(),
                    'completed_at' => null,
                    'error' => null,
                    'result' => null,
                    'metadata' => array_merge($metadata, [
                        'failed_response_recovery_attempts' => $attempts + 1,
                        'failed_response_recovered_at' => now()->toIso8601String(),
                        'failed_response_original_error' => $run->error,
                    ]),
                ]);

                $this->recordEvent($run, 'runtime.run_failed_response_recovery_started', [
                    'run_id' => $run->id,
                    'message_id' => $run->user_message_id,
                    'attempt' => $attempts + 1,
                ], 'hermes.runs', 'started');

                try {
                    $result = $runtime->sendExistingMessage($run->session->refresh(), $run->userMessage);
                    $assistantMessage = $result['assistant_message'] ?? null;

                    $run->update([
                        'status' => $result['status'] === 'cancelled' ? 'cancelled' : 'completed',
                        'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                        'result' => [
                            'status' => $result['status'] ?? null,
                            'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                            'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                            'recovered_from_failed_response' => true,
                        ],
                        'completed_at' => now(),
                    ]);

                    $this->recordEvent($run, 'runtime.run_failed_response_recovery_completed', [
                        'run_id' => $run->id,
                        'status' => $run->status,
                        'assistant_message_id' => $run->assistant_message_id,
                    ], 'hermes.runs', $run->status === 'completed' ? 'succeeded' : 'cancelled');

                    return $run->refresh();
                } catch (\Throwable $exception) {
                    Log::error('Assistant failed run response recovery failed.', [
                        'run_id' => $run->id,
                        'session_id' => $run->conversation_session_id,
                        'exception' => $exception->getMessage(),
                    ]);

                    $run->refresh();
                    $run->update([
                        'status' => 'failed',
                        'error' => $exception->getMessage(),
                        'completed_at' => now(),
                    ]);
                }
            }
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
            && ! str_contains($error, 'assistant run did not complete within')) {
            return false;
        }

        return ! $this->runHasCompletedMutatingWork($run);
    }

    private function completeFailedRunWithBridgeMessage(AssistantRun $run): AssistantRun
    {
        return DB::transaction(function () use ($run): AssistantRun {
            $run->refresh();
            if ($run->status !== 'failed' || $run->assistant_message_id !== null) {
                return $run->refresh();
            }

            $metadata = is_array($run->metadata) ? $run->metadata : [];
            $hadCompletedWork = $this->runHasCompletedMutatingWork($run);
            $content = $hadCompletedWork
                ? 'I finished the app updates and refreshed the latest details. Tell me what you want to do next.'
                : 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.';

            $assistantMessage = ConversationMessage::create([
                'user_id' => $run->user_id,
                'conversation_session_id' => $run->conversation_session_id,
                'role' => 'assistant',
                'content' => $content,
                'metadata' => [
                    'runtime' => 'failed_run_bridge',
                    'original_error' => str((string) $run->error)->limit(1000, '')->toString(),
                    'had_completed_work' => $hadCompletedWork,
                ],
            ]);

            $run->update([
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
                    'failed_response_original_error' => $run->error,
                    'failed_response_had_completed_work' => $hadCompletedWork,
                ]),
                'completed_at' => now(),
            ]);
            $run->session?->update([
                'status' => 'active',
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($run->refresh(), 'runtime.run_failed_response_resolved', [
                'run_id' => $run->id,
                'assistant_message_id' => $assistantMessage->id,
                'had_completed_work' => $hadCompletedWork,
            ], 'hermes.runs', 'succeeded');

            return $run->refresh();
        });
    }

    public function closeExpiredStaleRunsForSession(ConversationSession $session): void
    {
        $staleAfterSeconds = (int) config('services.hermes_runtime.assistant_run_stale_seconds', 75);

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
            $run->refresh();
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }

            $reason = $detail ?: "Assistant run did not complete within {$staleAfterSeconds} seconds.";
            $run->update([
                'status' => 'failed',
                'error' => $reason,
                'completed_at' => now(),
            ]);
            $run->session?->update([
                'status' => 'active',
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($run, 'runtime.run_stale_failed', [
                'run_id' => $run->id,
                'reason' => $reason,
            ], 'hermes.runs', 'failed');
        });
    }

    private function runHasCompletedMutatingWork(AssistantRun $run): bool
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
                    ->orWhere('payload->source_message_id', $messageId);
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
            ])
            ->exists();
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
}
