<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DailyAssistantFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_signed_in_user_can_plan_today_and_get_user_scoped_today_summary(): void
    {
        Carbon::setTestNow('2026-05-12T12:00:00Z');

        $aliceToken = $this->apiToken('alice@example.com');
        $bobToken = $this->apiToken('bob@example.com');

        $sessionId = $this->withToken($aliceToken)->postJson('/api/assistant/sessions', [
            'title' => 'Today',
            'metadata' => ['intent' => 'daily_planning'],
        ])->assertCreated()->json('data.id');

        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        Http::fakeSequence()
            ->push($this->toolCallResponse([
                $this->toolCall('call_task', 'create_task', ['title' => 'Review launch notes', 'type' => 'todo']),
                $this->toolCall('call_reminder', 'create_reminder', ['title' => 'pack laptop', 'remind_at' => '2026-05-13T09:00:00Z']),
                $this->toolCall('call_event', 'create_calendar_event', ['title' => 'Focus block', 'starts_at' => '2026-05-13T09:00:00Z', 'ends_at' => '2026-05-13T10:00:00Z']),
            ]), 200)
            ->push($this->assistantResponse('Planned your day.'), 200);

        $this->withToken($aliceToken)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan my day: add task Review launch notes; remind me tomorrow to pack laptop; schedule Focus block tomorrow at 9am.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $summary = $this->withToken($aliceToken)->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonPath('data.counts.tasks', 1)
            ->assertJsonPath('data.counts.reminders', 1)
            ->assertJsonPath('data.counts.calendar_events', 1)
            ->assertJsonPath('data.counts.activity_events', 8)
            ->assertJsonFragment(['title' => 'Review launch notes'])
            ->assertJsonFragment(['title' => 'pack laptop'])
            ->assertJsonFragment(['title' => 'Focus block'])
            ->json('data');

        $this->assertSame($sessionId, $summary['session']['id']);

        $this->withToken($bobToken)->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'bob@example.com')
            ->assertJsonPath('data.counts.tasks', 0)
            ->assertJsonMissing(['title' => 'Review launch notes']);
    }

    public function test_live_resource_list_endpoints_are_user_scoped(): void
    {
        $aliceToken = $this->apiToken('alice@example.com');
        $bobToken = $this->apiToken('bob@example.com');
        $bobId = User::where('email', 'bob@example.com')->value('id');

        $this->withToken($aliceToken)->postJson('/api/tasks', [
            'title' => 'Alice private task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($bobToken)->postJson('/api/tasks', [
            'title' => 'Bob private task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($bobToken)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $bobId)
            ->assertJsonPath('data.0.title', 'Bob private task')
            ->assertJsonMissing(['title' => 'Alice private task']);
    }

    private function assistantResponse(string $content): array
    {
        return [
            'id' => 'chatcmpl-test',
            'model' => 'gpt-test-tools',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => $content],
            ]],
        ];
    }

    private function toolCallResponse(array $toolCalls): array
    {
        return [
            'id' => 'chatcmpl-tool-call',
            'model' => 'gpt-test-tools',
            'choices' => [[
                'finish_reason' => 'tool_calls',
                'message' => ['role' => 'assistant', 'content' => null, 'tool_calls' => $toolCalls],
            ]],
        ];
    }

    private function toolCall(string $id, string $name, array $arguments): array
    {
        return [
            'id' => $id,
            'type' => 'function',
            'function' => [
                'name' => $name,
                'arguments' => json_encode($arguments, JSON_THROW_ON_ERROR),
            ],
        ];
    }
}
