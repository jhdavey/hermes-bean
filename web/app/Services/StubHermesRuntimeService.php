<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\Blocker;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use Illuminate\Support\Facades\DB;

class StubHermesRuntimeService implements HermesRuntimeService
{
    public function startSession(array $attributes = []): ConversationSession
    {
        return DB::transaction(function () use ($attributes): ConversationSession {
            $session = ConversationSession::create([
                'title' => $attributes['title'] ?? null,
                'status' => 'active',
                'runtime_mode' => $attributes['runtime_mode'] ?? 'stub',
                'metadata' => $attributes['metadata'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($session, 'runtime.session_started', [
                'runtime_mode' => $session->runtime_mode,
            ]);

            return $session->refresh();
        });
    }

    public function resumeSession(ConversationSession $session): ConversationSession
    {
        $session->update(['last_activity_at' => now()]);

        $this->recordEvent($session, 'runtime.session_resumed');

        return $session->refresh();
    }

    public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array
    {
        return DB::transaction(function () use ($session, $content, $metadata): array {
            $userMessage = ConversationMessage::create([
                'conversation_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);

            $received = $this->recordEvent($session, 'runtime.message_received', [
                'message_id' => $userMessage->id,
            ]);

            if ($session->runtime_mode !== 'stub') {
                $blocker = Blocker::create([
                    'conversation_session_id' => $session->id,
                    'reason' => 'External Hermes invocation is not configured. User approval or operator setup is required before continuing.',
                    'status' => 'open',
                    'context' => [
                        'runtime_mode' => $session->runtime_mode,
                        'message_id' => $userMessage->id,
                    ],
                ]);

                $session->update(['status' => 'blocked', 'last_activity_at' => now()]);
                $blocked = $this->recordEvent($session, 'runtime.blocked', [
                    'blocker_id' => $blocker->id,
                    'reason' => $blocker->reason,
                ]);

                return [
                    'status' => 'blocked',
                    'session' => $session->refresh(),
                    'user_message' => $userMessage,
                    'assistant_message' => null,
                    'events' => collect([$received, $blocked]),
                    'blocker' => $blocker,
                ];
            }

            $toolEvent = $this->recordEvent($session, 'tool.executed', [
                'input' => ['content' => $content],
                'output' => ['accepted' => true],
            ], 'local_stub_runtime', 'succeeded');

            $assistantMessage = ConversationMessage::create([
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => 'Stub Hermes runtime received: '.$content,
                'metadata' => ['runtime' => 'stub'],
            ]);

            $completed = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => collect([$received, $toolEvent, $completed]),
                'blocker' => null,
            ];
        });
    }

    private function recordEvent(ConversationSession $session, string $type, array $payload = [], ?string $toolName = null, string $status = 'recorded'): ActivityEvent
    {
        return ActivityEvent::create([
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }
}
