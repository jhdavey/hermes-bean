<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\CalendarEvent;
use App\Models\GoogleCalendarConnection;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AssistantDomainApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/assistant-domain-api-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_personal_assistant_domain_resources_can_be_created_via_api(): void
    {
        $token = $this->apiToken();

        $taskResponse = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Replace air filter',
            'type' => 'maintenance',
            'status' => 'open',
            'notes' => 'Use the garage filter',
            'category' => 'Home',
            'color' => '#FF9500',
            'due_at' => '2026-05-12T09:00:00Z',
        ])->assertCreated();

        $taskResponse->assertJsonPath('data.type', 'maintenance')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.category', 'Home')
            ->assertJsonPath('data.color', '#FF9500');

        $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Invalid task type',
            'type' => 'errand',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'Take out bins',
            'remind_at' => '2026-05-11T18:30:00Z',
            'category' => 'Home',
            'color' => '#FF9500',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Take out bins')
            ->assertJsonPath('data.category', 'Home')
            ->assertJsonPath('data.color', '#FF9500');

        $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Dentist',
            'starts_at' => '2026-05-14T15:00:00Z',
            'ends_at' => '2026-05-14T16:00:00Z',
            'location' => 'Main Street',
        ])->assertCreated()
            ->assertJsonPath('data.location', 'Main Street');

        $this->withToken($token)->postJson('/api/approvals', [
            'title' => 'Confirm booking',
            'status' => 'pending',
            'payload' => ['provider' => 'server_hermes'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->withToken($token)->postJson('/api/blockers', [
            'reason' => 'Needs user credentials',
            'status' => 'open',
            'context' => ['service' => 'calendar'],
        ])->assertCreated()
            ->assertJsonPath('data.reason', 'Needs user credentials');

        $this->assertDatabaseHas('tasks', ['title' => 'Replace air filter', 'type' => 'maintenance', 'category' => 'Home', 'color' => '#FF9500']);
        $this->assertDatabaseHas('reminders', ['title' => 'Take out bins', 'category' => 'Home', 'color' => '#FF9500']);
        $this->assertDatabaseHas('calendar_events', ['title' => 'Dentist']);
        $this->assertDatabaseHas('approvals', ['title' => 'Confirm booking']);
        $this->assertDatabaseHas('blockers', ['reason' => 'Needs user credentials']);
    }

    public function test_direct_domain_api_normalizes_explicit_timezone_offsets_to_utc(): void
    {
        $token = $this->apiToken();

        $taskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Offset task',
            'type' => 'todo',
            'due_at' => '2026-05-18T14:15:00-04:00',
        ])->assertCreated()->json('data.id');

        $reminderId = $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'Offset reminder',
            'remind_at' => '2026-05-18T14:30:00+01:00',
        ])->assertCreated()->json('data.id');

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Offset event',
            'starts_at' => '2026-05-18T13:45:00-07:00',
            'ends_at' => '2026-05-18T14:00:00-07:00',
        ])->assertCreated()->json('data.id');

        $this->assertSame('2026-05-18T18:15:00+00:00', Task::findOrFail($taskId)->due_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T13:30:00+00:00', Reminder::findOrFail($reminderId)->remind_at->utc()->toIso8601String());
        $event = CalendarEvent::findOrFail($eventId);
        $this->assertSame('2026-05-18T20:45:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T21:00:00+00:00', $event->ends_at->utc()->toIso8601String());
    }

    public function test_uncategorized_resources_default_to_bean_green(): void
    {
        $token = $this->apiToken();

        $taskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'No category task',
            'type' => 'todo',
        ])->assertCreated()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.color', '#34C759')
            ->json('data.id');

        $reminderId = $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'No category reminder',
            'remind_at' => '2026-05-18T14:30:00Z',
        ])->assertCreated()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.color', '#34C759')
            ->json('data.id');

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'No category event',
            'starts_at' => '2026-05-18T13:45:00Z',
            'ends_at' => '2026-05-18T14:45:00Z',
        ])->assertCreated()
            ->assertJsonPath('data.category', null)
            ->assertJsonPath('data.color', '#34C759')
            ->json('data.id');

        $this->withToken($token)->patchJson("/api/tasks/{$taskId}", [
            'category' => null,
        ])->assertOk()
            ->assertJsonPath('data.color', '#34C759');
        $this->withToken($token)->patchJson("/api/reminders/{$reminderId}", [
            'category' => null,
        ])->assertOk()
            ->assertJsonPath('data.color', '#34C759');
        $this->withToken($token)->patchJson("/api/calendar-events/{$eventId}", [
            'category' => null,
        ])->assertOk()
            ->assertJsonPath('data.color', '#34C759');
    }

    public function test_created_tasks_are_immediately_visible_from_database_task_list(): void
    {
        $token = $this->apiToken();

        $created = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Database source task',
            'type' => 'todo',
            'status' => 'open',
        ])->assertCreated()
            ->json('data.id');

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonFragment([
                'id' => $created,
                'title' => 'Database source task',
            ]);
    }

    public function test_calendar_events_support_editable_details_categories_recurrence_and_reminders(): void
    {
        $token = $this->premiumApiToken('calendar-details@example.com');

        $categoryId = $this->withToken($token)->postJson('/api/event-categories', [
            'name' => 'Family',
            'color' => '#AF52DE',
        ])->assertCreated()
            ->assertJsonPath('data.name', 'Family')
            ->assertJsonPath('data.color', '#AF52DE')
            ->json('data.id');

        $this->withToken($token)->patchJson("/api/event-categories/{$categoryId}", [
            'name' => 'Kids',
            'color' => '#007AFF',
        ])->assertOk()
            ->assertJsonPath('data.name', 'Kids')
            ->assertJsonPath('data.color', '#007AFF');

        $this->withToken($token)->getJson('/api/event-categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Kids', 'color' => '#007AFF']);

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Soccer practice',
            'starts_at' => '2026-05-14T15:00:00Z',
            'ends_at' => '2026-05-14T16:00:00Z',
            'category' => 'Family',
            'color' => '#34C759',
            'recurrence' => 'weekly',
        ])->assertCreated()
            ->assertJsonPath('data.category', 'Family')
            ->assertJsonPath('data.color', '#34C759')
            ->assertJsonPath('data.recurrence', 'weekly')
            ->json('data.id');

        $this->withToken($token)->patchJson("/api/calendar-events/{$eventId}", [
            'title' => 'Soccer match',
            'starts_at' => '2026-05-14T17:30:00Z',
            'ends_at' => '2026-05-14T18:45:00Z',
            'category' => 'Kids',
            'color' => '#007AFF',
            'recurrence' => 'none',
        ])->assertOk()
            ->assertJsonPath('data.title', 'Soccer match')
            ->assertJsonPath('data.category', 'Kids')
            ->assertJsonPath('data.color', '#007AFF')
            ->assertJsonPath('data.recurrence', 'none');

        $recurringEventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Piano lesson',
            'starts_at' => '2026-05-15T15:00:00Z',
            'ends_at' => '2026-05-15T16:00:00Z',
            'recurrence' => 'weekly',
        ])->assertCreated()
            ->json('data.id');

        $this->withToken($token)->deleteJson("/api/calendar-events/{$recurringEventId}", [
            'recurring_delete_mode' => 'single',
            'recurring_occurrence_date' => '2026-05-22',
        ])->assertNoContent();

        $this->assertDatabaseHas('calendar_events', ['id' => $recurringEventId]);
        $this->assertSame(
            ['2026-05-22'],
            CalendarEvent::findOrFail($recurringEventId)->metadata['recurring_exception_dates']
        );

        $this->withToken($token)->deleteJson("/api/calendar-events/{$recurringEventId}", [
            'recurring_delete_mode' => 'future',
            'recurring_occurrence_date' => '2026-06-05',
        ])->assertNoContent();

        $this->assertSame(
            '2026-06-05',
            CalendarEvent::findOrFail($recurringEventId)->metadata['recurrence_until']
        );

        $this->withToken($token)->postJson('/api/reminders', [
            'calendar_event_id' => $eventId,
            'title' => 'Leave for soccer match',
            'remind_at' => '2026-05-14T17:00:00Z',
            'category' => 'Kids',
            'color' => '#007AFF',
            'metadata' => [
                'recurrence' => 'specific_days',
                'days' => ['mon', 'wed', 'fri'],
                'interval' => 2,
                'unit' => 'weeks',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.calendar_event_id', $eventId)
            ->assertJsonPath('data.category', 'Kids')
            ->assertJsonPath('data.color', '#007AFF')
            ->assertJsonPath('data.metadata.recurrence', 'specific_days')
            ->assertJsonPath('data.metadata.unit', 'weeks');

        $taskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Wash soccer kit',
            'type' => 'todo',
            'category' => 'Kids',
            'color' => '#007AFF',
            'due_at' => '2026-05-14T12:00:00Z',
        ])->assertCreated()
            ->assertJsonPath('data.category', 'Kids')
            ->assertJsonPath('data.color', '#007AFF')
            ->json('data.id');

        $this->assertDatabaseHas('calendar_events', [
            'id' => $eventId,
            'title' => 'Soccer match',
            'category' => 'Kids',
            'color' => '#007AFF',
            'recurrence' => 'none',
        ]);
        $this->assertDatabaseHas('reminders', [
            'calendar_event_id' => $eventId,
            'title' => 'Leave for soccer match',
            'category' => 'Kids',
            'color' => '#007AFF',
        ]);
        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'category' => 'Kids',
            'color' => '#007AFF',
        ]);

        $this->withToken($token)->deleteJson("/api/event-categories/{$categoryId}")->assertNoContent();
        $this->assertDatabaseMissing('event_categories', ['id' => $categoryId]);
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'category' => null, 'color' => '#34C759']);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'category' => null, 'color' => '#34C759']);
        $this->assertDatabaseHas('reminders', ['calendar_event_id' => $eventId, 'category' => null, 'color' => '#34C759']);
    }

    public function test_agent_can_update_calendar_event_metadata_and_create_event_reminder(): void
    {
        $this->fakeAgentToolCalls([
            $this->toolCall('call_event', 'update_calendar_event', [
                'id' => 1,
                'title' => 'Updated design review',
                'starts_at' => '2026-05-14T17:00:00Z',
                'ends_at' => '2026-05-14T18:00:00Z',
                'category' => 'Work',
                'color' => '#FF9500',
                'recurrence' => 'weekly',
            ]),
            $this->toolCall('call_reminder', 'create_reminder', [
                'calendar_event_id' => 1,
                'title' => 'Prep for updated design review',
                'remind_at' => '2026-05-14T16:45:00Z',
            ]),
        ], 'Updated the event and added a reminder.');

        $token = $this->premiumApiToken('agent-calendar-recurrence@example.com');
        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Design review',
            'starts_at' => '2026-05-14T15:00:00Z',
        ])->assertCreated()->json('data.id');
        $this->assertSame(1, $eventId);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Calendar edits',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Move design review, make it weekly orange work, and remind me 15 min before',
        ])->assertCreated();

        $this->assertDatabaseHas('calendar_events', [
            'id' => $eventId,
            'title' => 'Updated design review',
            'category' => 'Work',
            'color' => '#FF9500',
            'recurrence' => 'weekly',
        ]);
        $this->assertDatabaseHas('reminders', [
            'calendar_event_id' => $eventId,
            'title' => 'Prep for updated design review',
        ]);
    }

    public function test_agent_can_move_named_calendar_event_from_relative_source_day_without_id(): void
    {
        Carbon::setTestNow('2026-05-19T19:09:00Z');
        $this->fakeAgentToolCalls([
            $this->toolCall('call_event', 'update_calendar_event', [
                'match_title' => 'lunch',
                'starts_at' => '2026-05-25T12:00:00-04:00',
            ]),
        ], 'Quick Lunch is moved to next Monday at 12:00 PM.');

        $token = $this->apiToken();
        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Quick Lunch',
            'starts_at' => '2026-05-20T12:30:00-04:00',
            'ends_at' => '2026-05-20T13:15:00-04:00',
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Quick Lunch',
            'starts_at' => '2026-05-27T12:30:00-04:00',
            'ends_at' => '2026-05-27T13:15:00-04:00',
        ])->assertCreated();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Calendar edits',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Move lunch tomorrow to next Monday at 12',
            'metadata' => [
                'source' => 'web',
                'client_context' => [
                    'current_local_time' => '2026-05-19T15:09:00-04:00',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated();

        $event = CalendarEvent::findOrFail($eventId);
        $this->assertSame('2026-05-25T16:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-25T16:45:00+00:00', $event->ends_at->utc()->toIso8601String());
        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.calendar_event.updated',
            'status' => 'succeeded',
        ]);
    }

    public function test_agent_calendar_update_does_not_export_to_google_calendar(): void
    {
        Carbon::setTestNow('2026-05-19T19:09:00Z');
        $this->fakeAgentToolCalls([
            $this->toolCall('call_event', 'update_calendar_event', [
                'match_title' => 'lunch',
                'from_date' => '2026-05-20',
                'starts_at' => '2026-05-25T12:00:00-04:00',
            ]),
        ], 'Quick Lunch is moved to next Monday at 12:00 PM.');

        $token = $this->apiToken('google-export-ignored@example.com');
        $user = User::where('email', 'google-export-ignored@example.com')->firstOrFail();
        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Quick Lunch',
            'starts_at' => '2026-05-20T12:30:00-04:00',
            'ends_at' => '2026-05-20T13:15:00-04:00',
        ])->assertCreated()->json('data.id');

        GoogleCalendarConnection::create([
            'user_id' => $user->id,
            'status' => 'connected',
            'calendar_id' => 'primary',
            'access_token_encrypted' => Crypt::encryptString('access-token'),
            'refresh_token_encrypted' => Crypt::encryptString('refresh-token'),
            'token_expires_at' => now()->addHour(),
            'metadata' => [
                'selected_calendar_ids' => ['primary'],
                'calendars' => [['id' => 'primary', 'summary' => 'Primary', 'primary' => true, 'access_role' => 'owner']],
            ],
        ]);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Calendar edits',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => "move tomorrow's lunch to next Monday at 12",
            'metadata' => [
                'source' => 'web',
                'client_context' => [
                    'current_local_time' => '2026-05-19T15:25:00-04:00',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Done - I updated lunch for May 25, 12:00 PM.');

        $event = CalendarEvent::findOrFail($eventId);
        $this->assertSame('2026-05-25T16:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-25T16:45:00+00:00', $event->ends_at->utc()->toIso8601String());
        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.calendar_event.updated',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseMissing('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.google_calendar.export_failed',
        ]);
    }

    public function test_task_list_shows_overdue_open_tasks_while_today_stays_date_scoped(): void
    {
        $token = $this->premiumApiToken('task-list-recurring@example.com');

        $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Yesterday one-off',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDay()->toIso8601String(),
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Recurring vitamins',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->subDay()->toIso8601String(),
            'metadata' => ['recurrence' => 'daily'],
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Today task',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->toIso8601String(),
        ])->assertCreated();

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Yesterday one-off'])
            ->assertJsonFragment(['title' => 'Recurring vitamins'])
            ->assertJsonFragment(['title' => 'Today task']);

        $this->withToken($token)->getJson('/api/today')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Yesterday one-off'])
            ->assertJsonFragment(['title' => 'Recurring vitamins'])
            ->assertJsonFragment(['title' => 'Today task']);
    }

    public function test_past_tasks_lists_completed_tasks_that_dropped_from_active_views(): void
    {
        $token = $this->apiToken();

        $pastTaskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Archived oil change',
            'type' => 'maintenance',
            'status' => 'open',
            'due_at' => now()->subDays(2)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/tasks/{$pastTaskId}", [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.completed_at', fn ($value) => is_string($value));

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Archived oil change']);

        $this->withToken($token)->getJson('/api/tasks/past')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Archived oil change'])
            ->assertJsonPath('data.0.status', 'completed')
            ->assertJsonPath('data.0.completed_at', fn ($value) => is_string($value));

        $this->withToken($token)->patchJson("/api/tasks/{$pastTaskId}", [
            'status' => 'open',
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.completed_at', null);

        $this->assertDatabaseHas('tasks', ['id' => $pastTaskId, 'completed_at' => null, 'due_at' => null]);

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Archived oil change']);
    }

    public function test_completing_recurring_tasks_advances_due_date_instead_of_archiving(): void
    {
        Carbon::setTestNow('2026-06-01T16:00:00Z');
        $token = $this->premiumApiToken('recurring-task-complete@example.com');

        $monthlyTaskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Replace air filter',
            'type' => 'maintenance',
            'status' => 'open',
            'due_at' => '2026-06-01T09:00:00-04:00',
            'metadata' => ['recurrence' => 'monthly'],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/tasks/{$monthlyTaskId}", [
            'status' => 'completed',
            'completed_at' => now()->toIso8601String(),
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.metadata.recurrence', 'monthly')
            ->assertJsonPath('data.metadata.completion_count', 1)
            ->assertJsonPath('data.due_at', fn ($value) => Carbon::parse($value)->utc()->toIso8601String() === '2026-07-01T13:00:00+00:00');

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Replace air filter']);

        $this->withToken($token)->getJson('/api/tasks/past')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Replace air filter']);
    }

    public function test_recurring_tasks_support_multi_month_and_yearly_advancement(): void
    {
        Carbon::setTestNow('2026-06-01T16:00:00Z');
        $token = $this->premiumApiToken('recurring-task-intervals@example.com');

        $biMonthlyTaskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Service water filter',
            'type' => 'maintenance',
            'status' => 'open',
            'due_at' => '2026-05-15T08:00:00-04:00',
            'metadata' => ['recurrence' => 'interval', 'interval' => 2, 'interval_unit' => 'months'],
        ])->assertCreated()->json('data.id');

        $yearlyTaskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Renew registration',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => '2026-06-01T09:00:00-04:00',
            'metadata' => ['recurrence' => 'yearly'],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/tasks/{$biMonthlyTaskId}", [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.due_at', fn ($value) => Carbon::parse($value)->utc()->toIso8601String() === '2026-07-15T12:00:00+00:00');

        $this->withToken($token)->patchJson("/api/tasks/{$yearlyTaskId}", [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.completed_at', null)
            ->assertJsonPath('data.due_at', fn ($value) => Carbon::parse($value)->utc()->toIso8601String() === '2027-06-01T13:00:00+00:00');
    }

    public function test_past_tasks_lists_recent_completed_tasks_that_were_due_today(): void
    {
        $token = $this->apiToken();

        $todayTaskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Finish today checklist',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson("/api/tasks/{$todayTaskId}", [
            'status' => 'completed',
        ])->assertOk()
            ->assertJsonPath('data.completed_at', fn ($value) => is_string($value));

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Finish today checklist']);

        $this->withToken($token)->getJson('/api/tasks/past')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Finish today checklist']);
    }

    public function test_completed_tasks_are_purged_after_plan_history_window(): void
    {
        $token = $this->apiToken();

        $oldCompletedId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Old completed task',
            'type' => 'todo',
            'status' => 'completed',
            'due_at' => now()->subDays(18)->toIso8601String(),
            'completed_at' => now()->subDays(16)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $recentCompletedId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Recent completed task',
            'type' => 'todo',
            'status' => 'completed',
            'due_at' => now()->subDays(4)->toIso8601String(),
            'completed_at' => now()->subDays(9)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $this->artisan('tasks:purge-completed')->assertSuccessful();

        $this->assertDatabaseMissing('tasks', ['id' => $oldCompletedId]);
        $this->assertDatabaseHas('tasks', ['id' => $recentCompletedId]);
    }

    public function test_agent_runtime_handles_100_complex_requests_through_structured_actions(): void
    {
        Carbon::setTestNow('2026-05-19T19:25:00Z');
        $this->fakeComplexSweepAgent();

        config()->set('security.rate_limits.api_per_minute', 500);
        config()->set('services.ai_usage.limits.base_cost_limit', 100);
        config()->set('services.ai_usage.limits.base_external_cost_limit', 100);

        $token = $this->apiToken('complex-sweep@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => '100 complex requests',
        ])->assertCreated()->json('data.id');

        foreach ($this->complexRequestSweepPrompts() as $prompt) {
            $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
                'content' => $prompt,
                'metadata' => [
                    'source' => 'web',
                    'client_context' => [
                        'current_local_time' => '2026-05-19T15:25:00-04:00',
                        'timezone_offset' => '-04:00',
                        'timezone_offset_minutes' => -240,
                    ],
                ],
            ]);
            $response->assertCreated()
                ->assertJsonPath('data.status', 'completed');
        }

        $this->assertSame(100, Task::where('category', 'Complex Sweep')->count());
        $this->assertSame(100, Reminder::where('category', 'Complex Sweep')->count());
        $this->assertSame(100, CalendarEvent::where('category', 'Complex Sweep')->count());
        $this->assertDatabaseCount('conversation_messages', 200);
        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'runtime.tool_model_started',
            'status' => 'started',
        ]);
        $this->assertSame(100, ActivityEvent::where('conversation_session_id', $sessionId)->where('event_type', 'runtime.tool_model_started')->count());
    }

    /**
     * @return list<string>
     */
    private function complexRequestSweepPrompts(): array
    {
        $templates = [
            'Plan the school morning handoff, add prep tasks, remind me early, and block the calendar.',
            'Coordinate a work deadline with a prep task, critical reminder, and focus block.',
            'Schedule a family logistics block, make a checklist, and remind me before it starts.',
            'Create a project follow-up plan with calendar time, task notes, and a morning nudge.',
            'Organize an appointment sequence with travel buffer, prep task, and reminder.',
            'Prepare a household maintenance plan with a calendar hold and materials checklist.',
            'Set up a birthday planning workflow with a task, reminder, and calendar planning block.',
            'Plan dinner prep around the afternoon schedule and remind me before groceries.',
            'Create a health-admin workflow with paperwork task, calendar hold, and reminder.',
            'Set up a weekly review prep plan with task notes, reminder, and focus block.',
        ];

        $prompts = [];
        for ($i = 1; $i <= 100; $i++) {
            $prompts[] = sprintf('REQ-%03d: %s Include category, color, critical flags when useful, and keep everything in the current workspace.', $i, $templates[($i - 1) % count($templates)]);
        }

        return $prompts;
    }

    public function test_activity_events_can_be_polled_for_a_session(): void
    {
        $this->fakeAgentResponse('Planning complete.');

        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Morning planning',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan out my day with tasks, reminders, and calendar priorities.',
        ])->assertCreated();

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'runtime.session_started')
            ->assertJsonFragment(['event_type' => 'runtime.message_received'])
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_started'])
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);
    }

    private function fakeAgentResponse(string $content): void
    {
        $this->configureAgentHttp();
        Http::fake(fn ($request) => str_contains($request->url(), 'googleapis.com')
            ? Http::response(['error' => ['message' => 'Forbidden']], 403)
            : Http::response($this->assistantResponse($content), 200));
    }

    private function fakeAgentToolCalls(array $toolCalls, string $finalContent): void
    {
        $this->configureAgentHttp();
        Http::fake(function ($request) use ($toolCalls, $finalContent) {
            if (str_contains($request->url(), 'googleapis.com')) {
                return Http::response(['error' => ['message' => 'Forbidden']], 403);
            }

            $hasToolResult = collect($request->data()['messages'] ?? [])->contains(fn (array $message): bool => ($message['role'] ?? null) === 'tool');

            return Http::response($hasToolResult
                ? $this->assistantResponse($finalContent)
                : $this->toolCallResponse($toolCalls), 200);
        });
    }

    private function fakeComplexSweepAgent(): void
    {
        $this->configureAgentHttp();
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'googleapis.com')) {
                return Http::response(['error' => ['message' => 'Forbidden']], 403);
            }

            $messages = $request->data()['messages'] ?? [];
            $hasToolResult = collect($messages)->contains(fn (array $message): bool => ($message['role'] ?? null) === 'tool');
            $content = (string) collect($messages)->firstWhere('role', 'user')['content'];
            preg_match('/REQ-(\d{3})/', $content, $matches);
            $index = (int) ($matches[1] ?? 1);

            if ($hasToolResult) {
                return Http::response($this->assistantResponse(sprintf('Handled REQ-%03d with a plan, task, reminder, and calendar block.', $index)), 200);
            }

            $day = 20 + (($index - 1) % 8);
            $hour = 8 + (($index - 1) % 9);
            $minute = (($index * 5) % 60);
            $toolCalls = [
                $this->toolCall('call_category', 'create_event_category', ['name' => 'Complex Sweep', 'color' => '#34C759', 'metadata' => ['request_index' => $index]]),
                $this->toolCall('call_event', 'create_calendar_event', [
                    'title' => sprintf('REQ-%03d planning block', $index),
                    'description' => $content,
                    'category' => 'Complex Sweep',
                    'color' => '#34C759',
                    'is_critical' => $index % 10 === 0,
                    'starts_at' => sprintf('2026-05-%02dT%02d:%02d:00-04:00', $day, $hour, $minute),
                    'ends_at' => sprintf('2026-05-%02dT%02d:%02d:00-04:00', $day, $hour + 1, $minute),
                    'metadata' => ['request_index' => $index, 'source' => 'complex_sweep'],
                ]),
                $this->toolCall('call_task', 'create_task', [
                    'title' => sprintf('REQ-%03d follow-up task', $index),
                    'type' => $index % 3 === 0 ? 'maintenance' : 'todo',
                    'status' => 'open',
                    'notes' => 'Generated by complex request sweep from the agent response.',
                    'category' => 'Complex Sweep',
                    'color' => '#34C759',
                    'is_critical' => $index % 9 === 0,
                    'due_at' => sprintf('2026-05-%02dT17:00:00-04:00', $day),
                    'metadata' => ['request_index' => $index, 'source' => 'complex_sweep'],
                ]),
                $this->toolCall('call_reminder', 'create_reminder', [
                    'title' => sprintf('REQ-%03d reminder', $index),
                    'notes' => 'Generated by complex request sweep from the agent response.',
                    'category' => 'Complex Sweep',
                    'color' => '#34C759',
                    'remind_at' => sprintf('2026-05-%02dT07:30:00-04:00', $day),
                    'metadata' => ['request_index' => $index, 'source' => 'complex_sweep'],
                ]),
            ];

            return Http::response($this->toolCallResponse($toolCalls), 200);
        });
    }

    private function configureAgentHttp(): void
    {
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
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
