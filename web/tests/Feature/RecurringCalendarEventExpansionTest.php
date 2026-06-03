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
        $token = $this->premiumApiToken('recurring-create@example.com');
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

    public function test_deleting_a_generated_occurrence_can_remove_only_that_event(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00', 'UTC'));
        $token = $this->premiumApiToken('recurring-delete-single@example.com');
        $user = User::where('email', 'recurring-delete-single@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $sourceId = $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspaceId,
            'title' => 'Weekly lesson',
            'starts_at' => '2026-05-26T14:00:00Z',
            'ends_at' => '2026-05-26T15:00:00Z',
            'recurrence' => 'weekly',
        ])->assertCreated()->json('data.id');

        $occurrence = CalendarEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('starts_at', '2026-06-02 14:00:00')
            ->firstOrFail();

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$occurrence->id, [
            'recurring_delete_mode' => 'single',
            'recurring_occurrence_date' => '2026-06-02',
        ])->assertNoContent();

        $this->assertDatabaseHas('calendar_events', ['id' => $sourceId]);
        $this->assertDatabaseMissing('calendar_events', ['id' => $occurrence->id]);
        $this->assertSame(['2026-06-02'], CalendarEvent::findOrFail($sourceId)->metadata['recurring_exception_dates']);

        CarbonImmutable::setTestNow();
    }

    public function test_deleting_a_linked_generated_occurrence_removes_that_date_from_selected_workspaces(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00', 'UTC'));
        $token = $this->premiumApiToken('recurring-delete-linked-single@example.com');
        $user = User::where('email', 'recurring-delete-linked-single@example.com')->firstOrFail();
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $family = app(WorkspaceService::class)->createHousehold($user, 'Family');

        $sourceId = $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $personalWorkspaceId,
            'title' => 'Weekly lesson',
            'starts_at' => '2026-05-26T14:00:00Z',
            'ends_at' => '2026-05-26T15:00:00Z',
            'recurrence' => 'weekly',
            'sync_to_workspace_ids' => [$family->id],
        ])->assertCreated()->json('data.id');

        $familySource = CalendarEvent::query()
            ->where('workspace_id', $family->id)
            ->where('title', 'Weekly lesson')
            ->where('recurrence', 'weekly')
            ->firstOrFail();
        $personalOccurrence = CalendarEvent::query()
            ->where('workspace_id', $personalWorkspaceId)
            ->where('starts_at', '2026-06-02 14:00:00')
            ->whereNull('recurrence')
            ->firstOrFail();
        $familyOccurrence = CalendarEvent::query()
            ->where('workspace_id', $family->id)
            ->where('starts_at', '2026-06-02 14:00:00')
            ->whereNull('recurrence')
            ->firstOrFail();

        $listed = $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$personalWorkspaceId.'&skip_google_sync=1')
            ->assertOk()
            ->json('data');
        $listedOccurrence = collect($listed)->firstWhere('id', $personalOccurrence->id);
        $this->assertSame([$personalWorkspaceId, $family->id], $listedOccurrence['linked_workspace_ids']);

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$personalOccurrence->id, [
            'delete_from_workspace_ids' => [$personalWorkspaceId, $family->id],
            'recurring_delete_mode' => 'single',
            'recurring_occurrence_date' => '2026-06-02',
        ])->assertNoContent();

        $this->assertDatabaseMissing('calendar_events', ['id' => $personalOccurrence->id]);
        $this->assertDatabaseMissing('calendar_events', ['id' => $familyOccurrence->id]);
        $this->assertSame(['2026-06-02'], CalendarEvent::findOrFail($sourceId)->metadata['recurring_exception_dates']);
        $this->assertSame(['2026-06-02'], $familySource->refresh()->metadata['recurring_exception_dates']);

        CarbonImmutable::setTestNow();
    }

    public function test_deleting_generated_occurrence_future_removes_that_and_following_events(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00', 'UTC'));
        $token = $this->premiumApiToken('recurring-delete-future@example.com');
        $user = User::where('email', 'recurring-delete-future@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $sourceId = $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspaceId,
            'title' => 'Weekly club',
            'starts_at' => '2026-05-26T14:00:00Z',
            'ends_at' => '2026-05-26T15:00:00Z',
            'recurrence' => 'weekly',
        ])->assertCreated()->json('data.id');

        $occurrence = CalendarEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('starts_at', '2026-06-09 14:00:00')
            ->firstOrFail();

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$occurrence->id, [
            'recurring_delete_mode' => 'future',
            'recurring_occurrence_date' => '2026-06-09',
        ])->assertNoContent();

        $this->assertDatabaseHas('calendar_events', [
            'workspace_id' => $workspaceId,
            'title' => 'Weekly club',
            'starts_at' => '2026-06-02 14:00:00',
        ]);
        $this->assertDatabaseMissing('calendar_events', ['id' => $occurrence->id]);
        $this->assertSame('2026-06-09', CalendarEvent::findOrFail($sourceId)->metadata['recurrence_until']);

        CarbonImmutable::setTestNow();
    }

    public function test_deleting_generated_occurrence_all_removes_the_series(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00', 'UTC'));
        $token = $this->premiumApiToken('recurring-delete-all@example.com');
        $user = User::where('email', 'recurring-delete-all@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $sourceId = $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspaceId,
            'title' => 'Weekly series',
            'starts_at' => '2026-05-26T14:00:00Z',
            'ends_at' => '2026-05-26T15:00:00Z',
            'recurrence' => 'weekly',
        ])->assertCreated()->json('data.id');

        $occurrence = CalendarEvent::query()
            ->where('workspace_id', $workspaceId)
            ->where('starts_at', '2026-06-02 14:00:00')
            ->firstOrFail();

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$occurrence->id, [
            'recurring_delete_mode' => 'all',
            'recurring_occurrence_date' => '2026-06-02',
        ])->assertNoContent();

        $this->assertDatabaseMissing('calendar_events', ['id' => $sourceId]);
        $this->assertSame(0, CalendarEvent::where('workspace_id', $workspaceId)->where('title', 'Weekly series')->count());

        CarbonImmutable::setTestNow();
    }

    public function test_deleting_source_single_hides_only_the_original_occurrence(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-05-26 12:00:00', 'UTC'));
        $token = $this->premiumApiToken('recurring-delete-source-single@example.com');
        $user = User::where('email', 'recurring-delete-source-single@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $sourceId = $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspaceId,
            'title' => 'Original hidden series',
            'starts_at' => '2026-05-26T14:00:00Z',
            'ends_at' => '2026-05-26T15:00:00Z',
            'recurrence' => 'weekly',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$sourceId, [
            'recurring_delete_mode' => 'single',
            'recurring_occurrence_date' => '2026-05-26',
        ])->assertNoContent();

        $source = CalendarEvent::findOrFail($sourceId);
        $this->assertTrue($source->metadata['recurrence_source_hidden']);
        $listed = $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$workspaceId.'&skip_google_sync=1')
            ->assertOk()
            ->json('data');

        $this->assertFalse(collect($listed)->contains(fn (array $event): bool => (int) $event['id'] === (int) $sourceId));
        $this->assertTrue(collect($listed)->contains(fn (array $event): bool => $event['title'] === 'Original hidden series'));

        CarbonImmutable::setTestNow();
    }
}
