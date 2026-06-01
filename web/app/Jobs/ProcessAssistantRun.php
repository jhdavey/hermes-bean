<?php

namespace App\Jobs;

use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Services\HermesRuntimeService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAssistantRun implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public readonly int $assistantRunId) {}

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
            ], 'hermes.runs', $run->status === 'completed' ? 'succeeded' : 'cancelled');
        } catch (\Throwable $exception) {
            Log::error('Assistant run failed.', [
                'run_id' => $run->id,
                'session_id' => $run->conversation_session_id,
                'exception' => $exception->getMessage(),
            ]);

            $run->update([
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'completed_at' => now(),
            ]);
            $run->session->update(['status' => 'active', 'last_activity_at' => now()]);

            $this->recordEvent($run, 'runtime.run_failed', [
                'run_id' => $run->id,
                'reason' => $exception->getMessage(),
            ], 'hermes.runs', 'failed');
        }
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
