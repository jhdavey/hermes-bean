<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HermesDemoLoopTest extends TestCase
{
    use RefreshDatabase;

    public function test_chat_demo_loop_creates_and_updates_domain_records_with_visible_grounding(): void
    {
        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'HB-6 local demo',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Demo: add task Replace air filter; remind me tomorrow to take out bins; schedule dentist tomorrow at 3pm.',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created'])
            ->assertSee('I checked this session and changed tasks, reminders, and calendar events', false);

        $this->assertDatabaseHas('tasks', [
            'conversation_session_id' => $sessionId,
            'title' => 'Replace air filter',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('reminders', [
            'conversation_session_id' => $sessionId,
            'title' => 'take out bins',
            'status' => 'scheduled',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'conversation_session_id' => $sessionId,
            'title' => 'dentist',
            'status' => 'scheduled',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Move that to tomorrow at 4pm.',
        ])->assertCreated()
            ->assertSee('I checked the latest calendar event and changed its start time', false);

        $this->assertDatabaseHas('calendar_events', [
            'conversation_session_id' => $sessionId,
            'title' => 'dentist',
        ]);
        $this->assertTrue(CalendarEvent::firstWhere('title', 'dentist')->starts_at->format('H:i') === '16:00');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What did you just schedule?',
        ])->assertCreated()
            ->assertSee('dentist', false)
            ->assertSee('16:00', false)
            ->assertSee('I checked the latest calendar event', false);

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.updated']);
    }

    public function test_chat_understands_create_task_phrasing_and_persists_visible_task(): void
    {
        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Simulator task creation',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a task to call the plumber tomorrow.',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.assistant_message.content', 'Created task: call the plumber tomorrow.')
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertSee('call the plumber tomorrow', false);

        $this->assertDatabaseHas('tasks', [
            'conversation_session_id' => $sessionId,
            'title' => 'call the plumber tomorrow',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonFragment(['title' => 'call the plumber tomorrow']);
    }

    public function test_demo_artisan_command_exercises_domain_activity_and_blocker_flow(): void
    {
        $this->artisan('hermes-bean:demo --reset')
            ->assertExitCode(0)
            ->expectsOutputToContain('HB-6 demo complete')
            ->expectsOutputToContain('Created task')
            ->expectsOutputToContain('Created reminder')
            ->expectsOutputToContain('Created calendar event')
            ->expectsOutputToContain('Opened blocker')
            ->expectsOutputToContain('Approved blocker');

        $this->assertSame(1, Task::count());
        $this->assertSame(1, Reminder::count());
        $this->assertSame(1, CalendarEvent::count());
        $this->assertSame(1, Approval::where('status', 'approved')->count());
        $this->assertSame(1, Blocker::where('status', 'resolved')->count());
        $this->assertGreaterThanOrEqual(5, ActivityEvent::count());
    }
}
