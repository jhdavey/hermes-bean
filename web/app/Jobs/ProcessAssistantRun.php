<?php

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Services\HermesRuntimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAssistantRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

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

    public function handle(HermesRuntimeService $runtime): void
    {
        $run = AssistantRun::with('session', 'userMessage')->find($this->assistantRunId);
        if (! $run || ! $run->session || ! $run->userMessage) {
            return;
        }

        if ($run->status === 'cancelled' || $run->session->status === 'cancelling') {
            $this->markCancelled($run);

            return;
        }

        DB::transaction(function () use ($run): void {
            $run->refresh();
            if (! in_array($run->status, ['queued', 'running'], true)) {
                return;
            }

            $run->update([
                'status' => 'running',
                'started_at' => $run->started_at ?? now(),
            ]);
            $run->session->update([
                'status' => 'running',
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($run, 'runtime.run_started', [
                'run_id' => $run->id,
                'source' => $run->source,
                'message_id' => $run->user_message_id,
                'queue_wait_ms' => $this->elapsedMilliseconds($run->created_at),
            ], 'hermes.runs', 'started');
        });

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
                ],
                'completed_at' => now(),
            ]);

            $this->recordEvent($run, 'runtime.run_completed', [
                'run_id' => $run->id,
                'status' => $run->status,
                'assistant_message_id' => $run->assistant_message_id,
                'run_duration_ms' => $this->elapsedMilliseconds($run->started_at),
            ], 'hermes.runs', $run->status === 'completed' ? 'succeeded' : 'cancelled');
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
        $run->update([
            'status' => 'cancelled',
            'cancelled_at' => $run->cancelled_at ?? now(),
            'completed_at' => $run->completed_at ?? now(),
        ]);
        $run->session?->update(['status' => 'active', 'last_activity_at' => now()]);
        $this->recordEvent($run, 'runtime.run_cancelled', ['run_id' => $run->id], 'hermes.runs', 'cancelled');
    }

    private function markFailed(AssistantRun $run, string $reason): void
    {
        $run->refresh();
        if (in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return;
        }

        Log::error('Assistant run failed.', [
            'run_id' => $run->id,
            'session_id' => $run->conversation_session_id,
            'exception' => $reason,
        ]);

        $run->update([
            'status' => 'failed',
            'error' => $reason,
            'completed_at' => now(),
        ]);
        $run->session?->update(['status' => 'active', 'last_activity_at' => now()]);

        $this->recordEvent($run, 'runtime.run_failed', [
            'run_id' => $run->id,
            'reason' => $reason,
        ], 'hermes.runs', 'failed');
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
