<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ProductivityDomainApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/productivity-domain-api-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->tempDir, 0755, true);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_productivity_resources_can_be_created_via_api(): void
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
            'all_day' => false,
            'starts_at' => '2026-05-14T15:00:00Z',
            'ends_at' => '2026-05-14T16:00:00Z',
            'location' => 'Main Street',
        ])->assertCreated()
            ->assertJsonPath('data.location', 'Main Street');

        $this->assertDatabaseHas('tasks', ['title' => 'Replace air filter', 'type' => 'maintenance', 'category' => 'Home', 'color' => '#FF9500']);
        $this->assertDatabaseHas('reminders', ['title' => 'Take out bins', 'category' => 'Home', 'color' => '#FF9500']);
        $this->assertDatabaseHas('calendar_events', ['title' => 'Dentist']);
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
            'all_day' => false,
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
            'all_day' => false,
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
            'all_day' => false,
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
            'all_day' => false,
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
            ],
        ])->assertCreated()
            ->assertJsonPath('data.calendar_event_id', $eventId)
            ->assertJsonPath('data.category', 'Kids')
            ->assertJsonPath('data.color', '#007AFF')
            ->assertJsonPath('data.metadata.recurrence', 'specific_days')
            ->assertJsonPath('data.metadata.days', ['mon', 'wed', 'fri']);

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

        $user = User::where('email', 'task-list-recurring@example.com')->firstOrFail();

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Semantic incomplete should not list',
            'type' => 'todo',
            'status' => 'incomplete',
            'due_at' => now()->subDay(),
        ]);

        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Completed should not list',
            'type' => 'todo',
            'status' => 'completed',
            'due_at' => now()->subDay(),
            'completed_at' => now(),
        ]);

        $this->withToken($token)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Yesterday one-off'])
            ->assertJsonFragment(['title' => 'Recurring vitamins'])
            ->assertJsonFragment(['title' => 'Today task'])
            ->assertJsonMissing(['title' => 'Semantic incomplete should not list'])
            ->assertJsonMissing(['title' => 'Completed should not list']);

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
            'metadata' => ['recurrence' => 'interval', 'interval' => 2, 'unit' => 'months'],
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

}
