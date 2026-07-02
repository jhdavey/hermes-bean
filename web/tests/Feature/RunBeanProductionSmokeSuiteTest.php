<?php

namespace Tests\Feature;

use App\Console\Commands\RunBeanProductionSmokeSuite;
use App\Models\ActivityEvent;
use App\Models\AiUsageLog;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\MemoryItem;
use App\Models\Note;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionMethod;
use Tests\TestCase;

class RunBeanProductionSmokeSuiteTest extends TestCase
{
    use RefreshDatabase;

    public function test_usage_limit_copy_counts_as_smoke_failure(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'containsFailureCopy');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's AI usage limit.",
        ));
        $this->assertTrue($method->invoke(
            $command,
            "This account has reached today's external lookup usage limit.",
        ));
        $this->assertFalse($method->invoke(
            $command,
            'Done - I added the three events to your calendar.',
        ));
    }

    public function test_smoke_account_reset_clears_ai_usage_logs(): void
    {
        $user = User::factory()->create();
        AiUsageLog::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'gpt-test-tools',
            'route_tier' => 'agent',
            'request_type' => 'text',
            'status' => 'completed',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'total_tokens' => 120,
            'estimated_cost_usd' => 0.01,
            'action_types' => ['calendar_event.create'],
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'resetSmokeUserData');
        $method->setAccessible(true);
        $method->invoke($command, $user);

        $this->assertDatabaseMissing('ai_usage_logs', [
            'user_id' => $user->id,
        ]);
    }

    public function test_suite_cleanup_removes_all_suite_artifacts(): void
    {
        $user = User::factory()->create();
        $suiteId = 'test-suite-cleanup';
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'title' => 'Smoke cleanup session',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'metadata' => ['suite_id' => $suiteId],
            'last_activity_at' => now(),
        ]);
        $note = Note::create([
            'user_id' => $user->id,
            'title' => 'Smoke note',
            'body_html' => 'Smoke note',
            'plain_text' => 'Smoke note',
            'metadata' => ['created_by' => 'structured_hermes_action'],
        ]);
        $memory = MemoryItem::create([
            'user_id' => $user->id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'content' => 'Smoke memory',
            'source_type' => 'assistant_tool',
            'source_id' => $session->id,
        ]);
        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'remember this',
        ]);
        $assistantMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Done.',
        ]);
        AssistantRun::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'user_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'source' => 'production_smoke',
            'status' => 'completed',
            'input' => 'remember this',
            'metadata' => ['suite_id' => $suiteId],
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.note.created',
            'tool_name' => 'notes.create',
            'status' => 'succeeded',
            'payload' => ['note_id' => $note->id],
        ]);
        ActivityEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.memory.created',
            'tool_name' => 'memory.create',
            'status' => 'succeeded',
            'payload' => ['memory_item_id' => $memory->id],
        ]);
        MemoryEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'conversation_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage->id,
            'event_type' => 'turn_candidate',
            'status' => 'processed',
            'content' => 'remember this',
        ]);
        AiUsageLog::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'provider' => 'openai',
            'model' => 'gpt-test-tools',
            'route_tier' => 'agent',
            'request_type' => 'text',
            'status' => 'completed',
            'input_tokens' => 100,
            'output_tokens' => 20,
            'total_tokens' => 120,
            'estimated_cost_usd' => 0.01,
            'action_types' => ['memory.create'],
        ]);

        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'cleanup');
        $method->setAccessible(true);
        $method->invoke($command, $user, $suiteId);

        $this->assertDatabaseMissing('conversation_sessions', ['id' => $session->id]);
        $this->assertDatabaseMissing('conversation_messages', ['id' => $userMessage->id]);
        $this->assertDatabaseMissing('conversation_messages', ['id' => $assistantMessage->id]);
        $this->assertDatabaseMissing('assistant_runs', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('activity_events', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('memory_events', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('ai_usage_logs', ['conversation_session_id' => $session->id]);
        $this->assertDatabaseMissing('notes', ['id' => $note->id]);
        $this->assertDatabaseMissing('memory_items', ['id' => $memory->id]);
    }
}
