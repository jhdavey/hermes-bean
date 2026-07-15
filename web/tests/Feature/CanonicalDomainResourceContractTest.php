<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CanonicalDomainResourceContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_endpoints_accept_only_complete_absolute_intervals(): void
    {
        $token = $this->premiumApiToken('canonical-history-interval@example.com');

        foreach (['request-history', 'activity-timeline'] as $endpoint) {
            $base = "/api/memory/{$endpoint}";

            $this->withToken($token)->getJson($base.'?date=2026-07-14')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('date');
            $this->withToken($token)->getJson($base.'?from_date=2026-07-14&to_date=2026-07-15')
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['from_date', 'to_date']);
            $this->withToken($token)->getJson($base.'?from=2026-07-14T00:00:00-04:00')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('to');
            $this->withToken($token)->getJson($base.'?from=tomorrow&to=2026-07-15T00:00:00-04:00')
                ->assertUnprocessable()
                ->assertJsonValidationErrors('from');

            $this->withToken($token)->getJson(
                $base.'?from=2026-07-14T00:00:00-04:00&to=2026-07-14T23:59:59-04:00',
            )->assertOk()->assertJsonPath('data', []);
        }
    }

    public function test_task_status_accepts_only_open_and_completed(): void
    {
        $token = $this->premiumApiToken('canonical-task-status@example.com');

        foreach (['complete', 'done', 'pending', 'COMPLETED'] as $status) {
            $this->withToken($token)->postJson('/api/tasks', [
                'title' => 'Canonical task',
                'type' => 'todo',
                'status' => $status,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');
        }

        $taskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Canonical task',
            'type' => 'todo',
            'status' => 'open',
        ])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

        $this->withToken($token)->patchJson("/api/tasks/{$taskId}", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertSame(1, Task::query()->count());
    }

    public function test_reminder_status_accepts_only_scheduled_and_completed_and_defaults_to_scheduled(): void
    {
        $token = $this->premiumApiToken('canonical-reminder-status@example.com');
        $remindAt = now()->addHour()->toIso8601String();

        $defaultReminderId = $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'Default canonical reminder',
            'remind_at' => $remindAt,
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');

        foreach (['pending', 'complete', 'done', 'Scheduled', 'COMPLETED'] as $status) {
            $this->withToken($token)->postJson('/api/reminders', [
                'title' => "Invalid {$status} reminder",
                'remind_at' => $remindAt,
                'status' => $status,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');

            $this->withToken($token)->patchJson("/api/reminders/{$defaultReminderId}", [
                'status' => $status,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');
        }

        $this->withToken($token)->patchJson("/api/reminders/{$defaultReminderId}", [
            'status' => 'completed',
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->withToken($token)->patchJson("/api/reminders/{$defaultReminderId}", [
            'status' => 'scheduled',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->assertSame('scheduled', Reminder::findOrFail($defaultReminderId)->status);
        $this->assertSame(1, Reminder::query()->count());
    }

    public function test_calendar_status_accepts_only_scheduled_and_cancelled_and_defaults_to_scheduled(): void
    {
        $token = $this->premiumApiToken('canonical-calendar-status@example.com');
        $startsAt = now()->addDay()->toIso8601String();

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Default canonical event',
            'starts_at' => $startsAt,
            'all_day' => false,
        ])->assertCreated()->assertJsonPath('data.status', 'scheduled')->json('data.id');

        foreach (['confirmed', 'tentative', 'canceled', 'Scheduled', 'CANCELLED'] as $status) {
            $this->withToken($token)->postJson('/api/calendar-events', [
                'title' => "Invalid {$status} event",
                'starts_at' => $startsAt,
                'all_day' => false,
                'status' => $status,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');

            $this->withToken($token)->patchJson("/api/calendar-events/{$eventId}", [
                'status' => $status,
            ])->assertUnprocessable()->assertJsonValidationErrors('status');
        }

        $this->withToken($token)->patchJson("/api/calendar-events/{$eventId}", [
            'status' => 'cancelled',
        ])->assertOk()->assertJsonPath('data.status', 'cancelled');

        $this->withToken($token)->patchJson("/api/calendar-events/{$eventId}", [
            'status' => 'scheduled',
        ])->assertOk()->assertJsonPath('data.status', 'scheduled');

        $this->assertSame('scheduled', CalendarEvent::findOrFail($eventId)->status);
        $this->assertSame(1, CalendarEvent::query()->count());
    }

    public function test_recurrence_requires_one_exact_canonical_shape(): void
    {
        $token = $this->premiumApiToken('canonical-recurrence@example.com');
        $task = [
            'title' => 'Canonical recurring task',
            'type' => 'maintenance',
            'status' => 'open',
        ];

        foreach ([
            ['metadata' => ['rrule' => 'FREQ=WEEKLY'], 'error' => 'metadata.rrule'],
            ['metadata' => ['recurring' => 'weekly'], 'error' => 'metadata.recurring'],
            ['metadata' => ['recurrence' => ['value' => 'weekly']], 'error' => 'metadata.recurrence'],
            ['metadata' => ['recurrence' => 'FREQ=WEEKLY'], 'error' => 'metadata.recurrence'],
            ['metadata' => ['recurrence' => 'interval', 'interval' => 2, 'unit' => 'week'], 'error' => 'metadata.unit'],
            ['metadata' => ['recurrence' => 'interval', 'interval' => 2, 'unit' => 'fortnights'], 'error' => 'metadata.unit'],
            ['metadata' => ['recurrence' => 'specific_days', 'specificDays' => ['mon']], 'error' => 'metadata.specificDays'],
        ] as $case) {
            $this->withToken($token)->postJson('/api/tasks', [
                ...$task,
                'metadata' => $case['metadata'],
            ])->assertUnprocessable()->assertJsonValidationErrors($case['error']);
        }

        $taskId = $this->withToken($token)->postJson('/api/tasks', [
            ...$task,
            'metadata' => [
                'recurrence' => 'interval',
                'interval' => 2,
                'unit' => 'weeks',
            ],
        ])->assertCreated()->json('data.id');

        $reminderId = $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'Canonical recurring reminder',
            'remind_at' => now()->addDay()->toIso8601String(),
            'metadata' => [
                'recurrence' => 'specific_days',
                'days' => ['mon', 'wed'],
            ],
        ])->assertCreated()->json('data.id');

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Canonical recurring event',
            'starts_at' => now()->addDay()->toIso8601String(),
            'all_day' => false,
            'recurrence' => 'weekly',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Duplicated recurrence',
            'starts_at' => now()->addDay()->toIso8601String(),
            'all_day' => false,
            'recurrence' => 'weekly',
            'metadata' => ['recurrence' => 'weekly'],
        ])->assertUnprocessable()->assertJsonValidationErrors('metadata.recurrence');

        $this->assertSame([
            'recurrence' => 'interval',
            'interval' => 2,
            'unit' => 'weeks',
        ], Task::findOrFail($taskId)->metadata);
        $this->assertSame([
            'recurrence' => 'specific_days',
            'days' => ['mon', 'wed'],
        ], Reminder::findOrFail($reminderId)->metadata);
        $this->assertSame('weekly', CalendarEvent::findOrFail($eventId)->recurrence);
        $this->assertArrayNotHasKey('recurrence', CalendarEvent::findOrFail($eventId)->metadata ?? []);
    }
}
