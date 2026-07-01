<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ActivityEventFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_activity_event_feed_supports_incremental_reads(): void
    {
        $token = $this->apiToken('activity-cursor@example.com');
        $user = User::where('email', 'activity-cursor@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);

        $first = ActivityEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => 'runtime.run_queued',
            'status' => 'queued',
        ]);
        $second = ActivityEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.task.created',
            'status' => 'succeeded',
            'payload' => ['title' => 'Buy milk'],
        ]);

        $this->withToken($token)->getJson("/api/assistant/sessions/{$session->id}/events?after={$first->id}&wait=0&limit=10")
            ->assertOk()
            ->assertJsonPath('meta.after', $first->id)
            ->assertJsonPath('meta.latest_id', $second->id)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $second->id)
            ->assertJsonPath('data.0.event_type', 'assistant.task.created');
    }
}
