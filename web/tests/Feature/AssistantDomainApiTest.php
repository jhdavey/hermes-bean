<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\SchedulerJobRecord;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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

        $this->withToken($token)->postJson('/api/scheduler-jobs', [
            'name' => 'daily-review',
            'status' => 'queued',
            'scheduled_for' => '2026-05-11T07:00:00Z',
            'payload' => ['timezone' => 'America/Los_Angeles'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'daily-review');

        $this->assertDatabaseHas('tasks', ['title' => 'Replace air filter', 'type' => 'maintenance', 'category' => 'Home', 'color' => '#FF9500']);
        $this->assertDatabaseHas('reminders', ['title' => 'Take out bins', 'category' => 'Home', 'color' => '#FF9500']);
        $this->assertDatabaseHas('calendar_events', ['title' => 'Dentist']);
        $this->assertDatabaseHas('approvals', ['title' => 'Confirm booking']);
        $this->assertDatabaseHas('blockers', ['reason' => 'Needs user credentials']);
        $this->assertDatabaseHas('scheduler_job_records', ['name' => 'daily-review']);
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

        $jobId = $this->withToken($token)->postJson('/api/scheduler-jobs', [
            'name' => 'offset-job',
            'scheduled_for' => '2026-05-18T07:00:00-04:00',
        ])->assertCreated()->json('data.id');

        $this->assertSame('2026-05-18T18:15:00+00:00', Task::findOrFail($taskId)->due_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T13:30:00+00:00', Reminder::findOrFail($reminderId)->remind_at->utc()->toIso8601String());
        $event = CalendarEvent::findOrFail($eventId);
        $this->assertSame('2026-05-18T20:45:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T21:00:00+00:00', $event->ends_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T11:00:00+00:00', SchedulerJobRecord::findOrFail($jobId)->scheduled_for->utc()->toIso8601String());
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
        $token = $this->apiToken();

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
        $this->assertDatabaseHas('calendar_events', ['id' => $eventId, 'category' => null, 'color' => null]);
        $this->assertDatabaseHas('tasks', ['id' => $taskId, 'category' => null, 'color' => null]);
        $this->assertDatabaseHas('reminders', ['calendar_event_id' => $eventId, 'category' => null, 'color' => null]);
    }

    public function test_agent_can_update_calendar_event_metadata_and_create_event_reminder(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Updated the event and added a reminder.',
    'actions' => [
        [
            'type' => 'calendar_event.update',
            'risk' => 'low',
            'parameters' => [
                'id' => 1,
                'title' => 'Updated design review',
                'starts_at' => '2026-05-14T17:00:00Z',
                'ends_at' => '2026-05-14T18:00:00Z',
                'category' => 'Work',
                'color' => '#FF9500',
                'recurrence' => 'weekly',
            ],
        ],
        [
            'type' => 'reminder.create',
            'risk' => 'low',
            'parameters' => [
                'calendar_event_id' => 1,
                'title' => 'Prep for updated design review',
                'remind_at' => '2026-05-14T16:45:00Z',
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
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

    public function test_task_list_shows_overdue_open_tasks_while_today_stays_date_scoped(): void
    {
        $token = $this->apiToken();

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
            ->assertJsonMissing(['title' => 'Yesterday one-off'])
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

    public function test_completed_tasks_are_purged_after_ten_days(): void
    {
        $token = $this->apiToken();

        $oldCompletedId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Old completed task',
            'type' => 'todo',
            'status' => 'completed',
            'due_at' => now()->subDays(14)->toIso8601String(),
            'completed_at' => now()->subDays(11)->toIso8601String(),
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

    public function test_activity_events_can_be_polled_for_a_session(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode(['message' => 'Planning complete.'], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Morning planning',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan my day',
        ])->assertCreated();

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'runtime.session_started')
            ->assertJsonFragment(['event_type' => 'runtime.message_received'])
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_started'])
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_completed']);
    }

    private function configureFakeHermes(string $contents): void
    {
        $path = $this->tempDir.'/fake-hermes.php';
        File::put($path, $contents);
        chmod($path, 0755);

        config()->set('services.hermes_runtime.cli_path', $path);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);
    }
}
