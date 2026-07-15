<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DirectCalendarCanonicalApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_api_requires_exact_all_day_boolean_and_rejects_metadata_aliases(): void
    {
        $token = $this->apiToken('canonical-calendar-validation@example.com');
        $base = [
            'title' => 'Planning day',
            'starts_at' => '2026-07-20T09:00:00Z',
            'ends_at' => '2026-07-20T10:00:00Z',
        ];

        $this->withToken($token)->postJson('/api/calendar-events', $base)
            ->assertUnprocessable()
            ->assertJsonValidationErrors('all_day');

        $this->withToken($token)->postJson('/api/calendar-events', [
            ...$base,
            'allDay' => true,
        ])->assertUnprocessable()->assertJsonValidationErrors('all_day');

        foreach (['yes', 'true', 1] as $alias) {
            $this->withToken($token)->postJson('/api/calendar-events', [
                ...$base,
                'all_day' => $alias,
            ])->assertUnprocessable()->assertJsonValidationErrors('all_day');
        }

        $this->withToken($token)->postJson('/api/calendar-events', [
            ...$base,
            'all_day' => false,
            'metadata' => ['allDay' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('metadata.allDay');

        $this->withToken($token)->postJson('/api/calendar-events', [
            ...$base,
            'all_day' => false,
            'metadata' => ['all_day' => true],
        ])->assertUnprocessable()->assertJsonValidationErrors('metadata.all_day');

        $this->assertSame(0, CalendarEvent::query()->count());
    }

    public function test_calendar_api_preserves_all_day_title_and_literal_bounds(): void
    {
        $token = $this->apiToken('canonical-calendar-literals@example.com');

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'All day: Product planning',
            'starts_at' => '2026-07-20T00:00:00Z',
            'ends_at' => '2026-07-21T00:00:00Z',
            'all_day' => true,
        ])->assertCreated()
            ->assertJsonPath('data.title', 'All day: Product planning')
            ->assertJsonPath('data.metadata.all_day', true)
            ->json('data.id');

        $event = CalendarEvent::query()->findOrFail($eventId);
        $this->assertSame('All day: Product planning', $event->title);
        $this->assertSame('2026-07-20T00:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-07-21T00:00:00+00:00', $event->ends_at->utc()->toIso8601String());

        $this->withToken($token)->patchJson("/api/calendar-events/{$eventId}", [
            'title' => 'All-day: Product planning follow-up',
            'starts_at' => '2026-07-20T10:15:00Z',
            'ends_at' => '2026-07-20T11:45:00Z',
            'all_day' => false,
        ])->assertOk()
            ->assertJsonPath('data.title', 'All-day: Product planning follow-up')
            ->assertJsonPath('data.metadata.all_day', false);

        $event->refresh();
        $this->assertSame('All-day: Product planning follow-up', $event->title);
        $this->assertSame('2026-07-20T10:15:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-07-20T11:45:00+00:00', $event->ends_at->utc()->toIso8601String());
    }
}
