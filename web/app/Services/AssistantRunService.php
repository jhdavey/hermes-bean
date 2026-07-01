<?php

namespace App\Services;

use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use Illuminate\Support\Facades\DB;

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
