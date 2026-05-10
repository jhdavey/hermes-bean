<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HermesRuntimeApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_runtime_can_start_resume_and_send_messages(): void
    {
        $sessionId = $this->postJson('/api/assistant/sessions', [
            'title' => 'Kitchen remodel',
            'metadata' => ['source' => 'feature-test'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.title', 'Kitchen remodel')
            ->json('data.id');

        $this->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.status', 'active');

        $this->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What should I do first?',
        ])->assertCreated()
            ->assertJsonPath('data.session.id', $sessionId)
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.assistant_message.content', 'Stub Hermes runtime received: What should I do first?')
            ->assertJsonFragment(['event_type' => 'tool.executed']);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'What should I do first?',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'tool.executed',
        ]);
    }

    public function test_runtime_fails_safe_to_blocker_for_unsupported_real_invocation(): void
    {
        $sessionId = $this->postJson('/api/assistant/sessions', [
            'title' => 'Real Hermes request',
            'runtime_mode' => 'external',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Use real Hermes to book an appointment',
        ])->assertAccepted()
            ->assertJsonPath('data.session.status', 'blocked')
            ->assertJsonPath('data.blocker.status', 'open')
            ->assertJsonPath('data.assistant_message', null);

        $this->assertDatabaseHas('conversation_sessions', [
            'id' => $sessionId,
            'status' => 'blocked',
        ]);

        $this->assertDatabaseHas('blockers', [
            'conversation_session_id' => $sessionId,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'runtime.blocked',
        ]);
    }
}
