<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\User;
use App\Services\WorkspaceService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RecurringCalendarEventExpansionTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_recurring_calendar_event_materializes_event_blocks_for_one_year(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00', 'UTC'));
        $token = $this->apiToken('recurring-create@example.com');
        $user = User::where('email', 'recurring-create@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $response = $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspaceId,
            'title' => 'Daily standup',
            'starts_at' => '2026-05-26T13:00:00Z',
            'ends_at' => '2026-05-26T13:30:00Z',
            'recurrence' => 'daily',
            'metadata' => ['recurrence' => 'daily'],
        ])->assertCreated();

        $sourceId = $response->json('data.id');
        $listed = $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$workspaceId.'&skip_google_sync=1')
            ->assertOk()
            ->json('data');

        $this->assertCount(366, $listed);
        $this->assertDatabaseHas('calendar_events', [
            'id' => $sourceId,
            'title' => 'Daily standup',
            'recurrence' => 'daily',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'workspace_id' => $workspaceId,
            'title' => 'Daily standup',
            'starts_at' => '2026-05-27 13:00:00',
            'recurrence' => null,
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'workspace_id' => $workspaceId,
            'title' => 'Daily standup',
            'starts_at' => '2027-05-26 13:00:00',
            'recurrence' => null,
        ]);
        $this->assertDatabaseMissing('calendar_events', [
            'workspace_id' => $workspaceId,
            'title' => 'Daily standup',
            'starts_at' => '2027-05-27 13:00:00',
        ]);

        CarbonImmutable::setTestNow();
    }

    public function test_daily_command_extends_recurring_calendar_events_one_year_forward_without_duplicates(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-01 08:00:00', 'UTC'));
        $user = User::factory()->create(['email' => 'recurring-command@example.com']);
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $source = CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Daily planning',
            'starts_at' => '2026-06-01 14:00:00',
            'ends_at' => '2026-06-01 15:00:00',
            'recurrence' => 'daily',
            'metadata' => [
                'recurrence' => 'daily',
                'recurrence_materialized_until' => '2026-06-02',
            ],
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
            'title' => 'Daily planning',
            'starts_at' => '2026-06-02 14:00:00',
            'ends_at' => '2026-06-02 15:00:00',
            'metadata' => [
                'recurrence_generated' => true,
                'recurrence_parent_event_id' => $source->id,
                'recurrence_occurrence_date' => '2026-06-02',
            ],
        ]);

        $this->artisan('calendar-events:materialize-recurring')->assertSuccessful();

        $this->assertSame(366, CalendarEvent::where('workspace_id', $workspaceId)->where('title', 'Daily planning')->count());
        $this->assertSame(1, CalendarEvent::where('workspace_id', $workspaceId)->where('title', 'Daily planning')->where('starts_at', '2026-06-02 14:00:00')->count());
        $this->assertDatabaseHas('calendar_events', [
            'workspace_id' => $workspaceId,
            'title' => 'Daily planning',
            'starts_at' => '2027-06-01 14:00:00',
            'recurrence' => null,
        ]);

        CarbonImmutable::setTestNow();
    }
}
