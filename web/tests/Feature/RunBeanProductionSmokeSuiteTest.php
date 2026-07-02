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

    public function test_smoke_quality_checks_flag_weak_responses(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'assistantQualityFailures');
        $method->setAccessible(true);

        $this->assertContains('missing_write_confirmation', $method->invoke(
            $command,
            'REQ-001: Create a task to review insurance paperwork tomorrow morning.',
            'I can help with that.',
        ));
        $this->assertContains('missing_weather_details', $method->invoke(
            $command,
            'REQ-061: Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
            'Tomorrow should be fine.',
        ));
        $this->assertContains('missing_place_details', $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'I found one nearby.',
        ));
        $this->assertContains('missing_memory_confirmation', $method->invoke(
            $command,
            'REQ-081: Remember that I prefer short practical answers unless I ask for detail, then tell me what you saved.',
            'That makes sense.',
        ));
        $this->assertContains('missing_day_context', $method->invoke(
            $command,
            'REQ-091: What do I have coming up today, and if there is empty time after 5pm, suggest a simple plan.',
            'Sounds good.',
        ));
    }

    public function test_smoke_quality_checks_accept_useful_responses(): void
    {
        $command = new RunBeanProductionSmokeSuite;
        $method = new ReflectionMethod($command, 'assistantQualityFailures');
        $method->setAccessible(true);

        $this->assertSame([], $method->invoke(
            $command,
            'REQ-011: Add three calendar events: 7/9 Dr Chen Cardio at 100 N Dean Rd at 3pm, 7/15 Ventura at 6pm, and 7/19 Azalea Lane at 2pm.',
            'Done - I added Dr Chen Cardio to your calendar for Jul 9, 3:00 PM, I added Ventura to your calendar for Jul 15, 6:00 PM, and I added Azalea Lane to your calendar for Jul 19, 2:00 PM.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-061: Find the weather for tomorrow in Orlando, then suggest whether my evening run should be indoors or outdoors.',
            'Tomorrow in Orlando should be stormy. High 94°F, low 76°F, with precipitation possible.',
        ));
        $this->assertSame([], $method->invoke(
            $command,
            'REQ-071: Find the nearest Wawa to 32820 and tell me the address quickly.',
            'The nearest Wawa I found near 32820 is Wawa at 16959 E Colonial Dr, Orlando, FL 32820, USA about 1.4 miles away.',
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
