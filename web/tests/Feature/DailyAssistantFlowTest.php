<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyAssistantFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_user_can_plan_today_and_get_user_scoped_today_summary(): void
    {
        $aliceToken = $this->apiToken('alice@example.com');
        $bobToken = $this->apiToken('bob@example.com');

        $sessionId = $this->withToken($aliceToken)->postJson('/api/assistant/sessions', [
            'title' => 'Today',
            'metadata' => ['intent' => 'daily_planning'],
        ])->assertCreated()->json('data.id');

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
            ->assertJsonPath('data.counts.activity_events', 7)
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
}
