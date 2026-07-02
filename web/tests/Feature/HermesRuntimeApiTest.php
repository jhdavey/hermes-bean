<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class HermesRuntimeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-13 12:00:00'));
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_runtime_can_start_resume_send_messages_and_poll_progress_events(): void
    {
        Http::fakeSequence()->push($this->assistantResponse('Planning complete.'), 200);

        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Kitchen remodel',
            'metadata' => ['source' => 'feature-test'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.runtime_mode', 'tools')
            ->assertJsonPath('data.title', 'Kitchen remodel')
            ->json('data.id');

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What should I do first?',
        ])->assertCreated()
            ->assertJsonPath('data.session.id', $sessionId)
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.assistant_message.content', 'Planning complete.')
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_started'])
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);

        $events = $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->json('data');

        $this->assertSame([
            'runtime.session_started',
            'runtime.session_resumed',
            'runtime.message_received',
            'runtime.tool_model_started',
            'runtime.tool_model_completed',
            'runtime.message_completed',
        ], collect($events)->pluck('event_type')->all());
    }

    public function test_message_branch_replaces_the_selected_message_and_later_chat_history(): void
    {
        Http::fakeSequence()
            ->push($this->assistantResponse('Old answer.'), 200)
            ->push($this->assistantResponse('Updated answer.'), 200);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Branch test',
        ])->assertCreated()->json('data.id');

        $originalMessageId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan today',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Old answer.')
            ->json('data.user_message.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages/{$originalMessageId}/branch", [
            'content' => 'Plan tomorrow',
            'metadata' => ['source' => 'web'],
        ])->assertCreated()
            ->assertJsonPath('data.user_message.content', 'Plan tomorrow')
            ->assertJsonPath('data.user_message.metadata.edited_from_message_id', $originalMessageId)
            ->assertJsonPath('data.assistant_message.content', 'Updated answer.');

        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'content' => 'Plan today',
        ]);
        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'content' => 'Old answer.',
        ]);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Plan tomorrow',
        ]);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Updated answer.',
        ]);
    }

    public function test_runtime_lists_previous_sessions_and_returns_today_session(): void
    {
        $token = $this->apiToken('history@example.com');
        $user = User::where('email', 'history@example.com')->firstOrFail();
        $workspaceId = $user->default_workspace_id;

        $oldSession = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Yesterday with Bean',
            'status' => 'active',
            'runtime_mode' => 'chat',
            'last_activity_at' => now()->subDay(),
        ]);
        DB::table('conversation_sessions')->where('id', $oldSession->id)->update([
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
        ]);
        $oldSession->refresh();

        $todaySession = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Today with Bean',
            'status' => 'active',
            'runtime_mode' => 'chat',
            'last_activity_at' => now(),
        ]);

        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $todaySession->id,
            'role' => 'assistant',
            'content' => 'Latest today message.',
        ]);

        $this->withToken($token)->getJson("/api/assistant/sessions?workspace_id={$workspaceId}&date=2026-05-13&timezone=America/New_York")
            ->assertOk()
            ->assertJsonPath('data.today_session.id', $todaySession->id)
            ->assertJsonPath('data.sessions.0.id', $todaySession->id)
            ->assertJsonPath('data.sessions.0.latest_message.content', 'Latest today message.')
            ->assertJsonPath('data.sessions.0.messages_count', 1)
            ->assertJsonPath('data.sessions.1.id', $oldSession->id);
    }

    public function test_stale_assistant_failure_copy_is_sanitized_when_serialized(): void
    {
        $token = $this->apiToken('stale-history@example.com');
        $user = User::where('email', 'stale-history@example.com')->firstOrFail();
        $workspaceId = $user->default_workspace_id;

        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Old failure copy',
            'status' => 'active',
            'runtime_mode' => 'tools',
            'last_activity_at' => now(),
        ]);

        $messages = collect([
            'Bean could not finish that request.',
            'Bean hit a snag while trying to handle that request.',
            'HermesApiException(statusCode: 502)',
        ])->map(fn (string $content): ConversationMessage => ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => $content,
        ]));

        foreach ($messages as $message) {
            $this->assertSame(
                'I’m checking the latest app state now. If I need one more detail, I’ll ask.',
                $message->content,
            );
            $this->assertDatabaseHas('conversation_messages', [
                'id' => $message->id,
                'content' => $message->getRawOriginal('content'),
            ]);
        }

        $this->withToken($token)->getJson("/api/assistant/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', 'I’m checking the latest app state now. If I need one more detail, I’ll ask.');
        $this->withToken($token)->getJson("/api/assistant/sessions/{$session->id}")
            ->assertOk()
            ->assertJsonPath('data.messages.1.content', 'I’m checking the latest app state now. If I need one more detail, I’ll ask.')
            ->assertJsonPath('data.messages.2.content', 'I’m checking the latest app state now. If I need one more detail, I’ll ask.');

        $this->withToken($token)->getJson("/api/assistant/sessions?workspace_id={$workspaceId}&date=2026-05-13&timezone=America/New_York")
            ->assertOk()
            ->assertJsonPath('data.sessions.0.latest_message.content', 'I’m checking the latest app state now. If I need one more detail, I’ll ask.');
    }

    public function test_runtime_persists_tool_created_events_tasks_and_reminders_to_domain_tables(): void
    {
        Http::fakeSequence()
            ->push($this->toolCallResponse([
                $this->toolCall('call_task', 'create_task', ['title' => 'Persist DB task', 'due_at' => '2026-05-13T17:00:00Z']),
                $this->toolCall('call_reminder', 'create_reminder', ['title' => 'Persist DB reminder', 'remind_at' => '2026-05-13T18:00:00Z']),
                $this->toolCall('call_event', 'create_calendar_event', ['title' => 'Persist DB event', 'starts_at' => '2026-05-13T19:00:00Z', 'ends_at' => '2026-05-13T20:00:00Z']),
            ]), 200)
            ->push($this->assistantResponse('Saved the task, reminder, and event.'), 200);

        $token = $this->apiToken();
        $userId = User::where('email', 'test@example.com')->value('id');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Persistence contract',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Save a task, a reminder, and a calendar event.',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $this->assertDatabaseHas('tasks', ['user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Persist DB task']);
        $this->assertDatabaseHas('reminders', ['user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Persist DB reminder']);
        $this->assertDatabaseHas('calendar_events', ['user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Persist DB event']);
    }

    public function test_agent_created_task_reminder_and_event_are_visible_in_today_dashboard(): void
    {
        Http::fakeSequence()
            ->push($this->toolCallResponse([
                $this->toolCall('call_task', 'create_task', ['title' => 'Draft proposal', 'type' => 'todo', 'due_at' => '2026-05-13T17:00:00Z']),
                $this->toolCall('call_reminder', 'create_reminder', ['title' => 'Check oven', 'remind_at' => '2026-05-13T18:00:00Z']),
                $this->toolCall('call_event', 'create_calendar_event', ['title' => 'Design sync', 'starts_at' => '2026-05-13T19:00:00Z', 'ends_at' => '2026-05-13T20:00:00Z']),
            ]), 200)
            ->push($this->assistantResponse('Added those to today.'), 200);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Visible dashboard resources',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add a proposal task, oven reminder, and design sync.',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.task.created']);

        $this->withToken($token)->getJson('/api/today')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Draft proposal'])
            ->assertJsonFragment(['title' => 'Check oven'])
            ->assertJsonFragment(['title' => 'Design sync'])
            ->assertJsonPath('data.counts.tasks', 1)
            ->assertJsonPath('data.counts.reminders', 1)
            ->assertJsonPath('data.counts.calendar_events', 1);
    }

    public function test_runtime_tool_updates_preserve_local_wall_clock_times(): void
    {
        Http::fakeSequence()
            ->push($this->toolCallResponse([
                $this->toolCall('call_event', 'create_calendar_event', [
                    'title' => 'Retreat',
                    'starts_at' => '2026-05-18T13:00:00-04:00',
                    'ends_at' => '2026-05-21T20:00:00-04:00',
                ]),
            ]), 200)
            ->push($this->assistantResponse('Saved the retreat from 1:00 PM to 8:00 PM.'), 200);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Schedule retreat today at 1pm through three days later at 8pm.',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Done - I added Retreat to your calendar from May 18, 1:00 PM to May 21, 8:00 PM.');

        $event = CalendarEvent::where('title', 'Retreat')->firstOrFail();
        $this->assertSame('2026-05-18T17:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-22T00:00:00+00:00', $event->ends_at->utc()->toIso8601String());
    }

    public function test_runtime_deterministically_creates_multiple_dated_calendar_items_without_model_planner(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response($this->assistantResponse('Unexpected model call.'), 200),
        ]);

        $token = $this->apiToken('calendar-list@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Done - I added Dr Chen Cardio to your calendar for Jul 9, 3:00 PM, I added Ventura to your calendar for Jul 15, 6:00 PM, and I added Azalea Lane to your calendar for Jul 19, 2:00 PM.');

        $events = CalendarEvent::where('conversation_session_id', $sessionId)->orderBy('starts_at')->get();

        $this->assertCount(3, $events);
        $this->assertSame('Dr Chen Cardio', $events[0]->title);
        $this->assertSame('100 N Dean rd', $events[0]->location);
        $this->assertSame('2026-07-09T19:00:00+00:00', $events[0]->starts_at->utc()->toIso8601String());
        $this->assertSame('Ventura', $events[1]->title);
        $this->assertNull($events[1]->location);
        $this->assertSame('2026-07-15T22:00:00+00:00', $events[1]->starts_at->utc()->toIso8601String());
        $this->assertSame('Azalea Lane', $events[2]->title);
        $this->assertNull($events[2]->location);
        $this->assertSame('2026-07-19T18:00:00+00:00', $events[2]->starts_at->utc()->toIso8601String());

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_runtime_followup_after_workout_does_not_duplicate_existing_workout(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response($this->assistantResponse('Unexpected model call.'), 200),
        ]);

        $token = $this->apiToken('calendar-followup-workout@example.com');
        $user = User::where('email', 'calendar-followup-workout@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        CalendarEvent::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'title' => 'Workout',
            'starts_at' => Carbon::parse('2026-05-18T17:30:00-04:00')->utc(),
            'ends_at' => Carbon::parse('2026-05-18T18:30:00-04:00')->utc(),
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Add a workout today from 5:30pm to 6:30pm.',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Done - I added Workout to your calendar from May 18, 5:30 PM to May 18, 6:30 PM.',
        ]);

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Yes please. Add grocery shopping for 45 minutes after the workout, then cooking dinner for 30 minutes after grocery shopping, and create 15-minute reminders for both.',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $assistantContent = (string) $response->json('data.assistant_message.content');
        $this->assertStringContainsString('Grocery shopping', $assistantContent);
        $this->assertStringContainsString('Cook dinner', $assistantContent);
        $this->assertStringNotContainsString('added Workout', $assistantContent);

        $this->assertSame(1, CalendarEvent::where('conversation_session_id', $sessionId)->where('title', 'Workout')->count());
        $this->assertSame(1, CalendarEvent::where('conversation_session_id', $sessionId)->where('title', 'Grocery shopping')->count());
        $this->assertSame(1, CalendarEvent::where('conversation_session_id', $sessionId)->where('title', 'Cook dinner')->count());
        $this->assertSame(1, Reminder::where('conversation_session_id', $sessionId)->where('title', 'like', '%Grocery%')->count());
        $this->assertSame(1, Reminder::where('conversation_session_id', $sessionId)->where('title', 'like', '%Cook%')->count());

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_runtime_followup_moves_target_event_and_deletes_its_reminder_without_touching_anchor_event(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response($this->assistantResponse('Unexpected model call.'), 200),
        ]);

        $token = $this->apiToken('calendar-followup-move-delete@example.com');
        $user = User::where('email', 'calendar-followup-move-delete@example.com')->firstOrFail();
        $workspaceId = $user->default_workspace_id;
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $workout = CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'conversation_session_id' => $sessionId,
            'title' => 'Workout',
            'starts_at' => Carbon::parse('2026-05-18T17:00:00-04:00')->utc(),
            'ends_at' => Carbon::parse('2026-05-18T18:00:00-04:00')->utc(),
        ]);
        $grocery = CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'conversation_session_id' => $sessionId,
            'title' => 'Grocery shopping',
            'starts_at' => Carbon::parse('2026-05-18T18:00:00-04:00')->utc(),
            'ends_at' => Carbon::parse('2026-05-18T18:45:00-04:00')->utc(),
        ]);
        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'conversation_session_id' => $sessionId,
            'calendar_event_id' => $grocery->id,
            'title' => 'Grocery shopping reminder',
            'remind_at' => Carbon::parse('2026-05-18T17:45:00-04:00')->utc(),
        ]);

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Move grocery shopping to start after the workout at 6:15pm and delete the grocery shopping reminder.',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $assistantContent = (string) $response->json('data.assistant_message.content');
        $this->assertStringContainsString('Grocery shopping', $assistantContent);
        $this->assertStringNotContainsString('updated Workout', $assistantContent);

        $workout->refresh();
        $grocery->refresh();
        $this->assertSame('2026-05-18T21:00:00+00:00', $workout->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T22:15:00+00:00', $grocery->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T23:00:00+00:00', $grocery->ends_at->utc()->toIso8601String());
        $this->assertSame(0, Reminder::where('conversation_session_id', $sessionId)->where('title', 'like', '%Grocery%')->count());

        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_runtime_explains_note_plan_limits_without_generic_failure_copy(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response($this->assistantResponse(json_encode([
                'actions' => [[
                    'type' => 'note.create',
                    'risk' => 'low',
                    'parameters' => [
                        'title' => 'Note: hello',
                        'plain_text' => 'hello',
                    ],
                ]],
            ], JSON_THROW_ON_ERROR)), 200),
        ]);

        $token = $this->apiToken('base-note-limit@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you create a note that says hello',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Notes are available on Premium, Pro, and Enterprise plans. Upgrade your plan to create and manage notes.');

        $this->assertDatabaseMissing('notes', [
            'conversation_session_id' => $sessionId,
            'title' => 'Note: hello',
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function clientTemporalMetadata(): array
    {
        return [
            'source' => 'web',
            'client_context' => [
                'current_local_time' => '2026-05-18T13:14:00.000',
                'current_utc_time' => '2026-05-18T17:14:00.000Z',
                'timezone_name' => 'EDT',
                'timezone_offset' => '-04:00',
                'timezone_offset_minutes' => -240,
            ],
        ];
    }
}
