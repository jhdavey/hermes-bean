<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\EventCategory;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceItemLink;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_register_creates_personal_workspace_and_owner_membership(): void
    {
        $token = $this->apiToken('workspace-owner@example.com');

        $this->assertNotEmpty($token);

        $user = User::where('email', 'workspace-owner@example.com')->firstOrFail();
        $workspace = DB::table('workspaces')->where('personal_owner_user_id', $user->id)->first();

        $this->assertNotNull($workspace);
        $this->assertSame('personal', $workspace->type);
        $this->assertSame('active', $workspace->status);
        $this->assertSame('personal-'.$user->id, $workspace->slug);

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);
    }

    public function test_workspace_service_backfills_existing_user_rows_to_personal_workspace(): void
    {
        $user = User::factory()->create(['name' => 'Existing User']);
        $task = Task::create([
            'user_id' => $user->id,
            'title' => 'Pre-workspace task',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $this->assertDatabaseHas('workspaces', [
            'id' => $workspaceId,
            'type' => 'personal',
            'personal_owner_user_id' => $user->id,
            'created_by_user_id' => $user->id,
        ]);

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspaceId,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
        ]);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'created_by_user_id' => $user->id,
        ]);
    }

    public function test_workspace_service_does_not_overwrite_existing_workspace_assignments(): void
    {
        $user = User::factory()->create(['name' => 'Household Member']);
        $otherWorkspaceId = DB::table('workspaces')->insertGetId([
            'type' => 'household',
            'name' => 'Shared Home',
            'slug' => 'shared-home',
            'created_by_user_id' => $user->id,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $taskId = DB::table('tasks')->insertGetId([
            'user_id' => $user->id,
            'workspace_id' => $otherWorkspaceId,
            'title' => 'Household task',
            'type' => 'todo',
            'status' => 'open',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $this->assertDatabaseHas('tasks', [
            'id' => $taskId,
            'workspace_id' => $otherWorkspaceId,
            'created_by_user_id' => $user->id,
        ]);
    }

    public function test_workspace_list_includes_current_users_workspace_role(): void
    {
        $token = $this->apiToken('workspace-role-owner@example.com');
        $user = User::where('email', 'workspace-role-owner@example.com')->firstOrFail();
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        $householdResponse = $this->withToken($token)->postJson('/api/workspaces', [
            'name' => 'Owner Household',
        ])->assertCreated();
        $householdWorkspaceId = $householdResponse->json('data.id');

        $response = $this->withToken($token)->getJson('/api/workspaces')->assertOk();
        $workspaces = collect($response->json('data'));

        $this->assertSame('owner', $workspaces->firstWhere('id', $personalWorkspaceId)['role'] ?? null);
        $this->assertSame('owner', $workspaces->firstWhere('id', $householdWorkspaceId)['role'] ?? null);
        $this->assertSame('owner', $workspaces->firstWhere('id', $personalWorkspaceId)['membership_role'] ?? null);
        $this->assertSame('owner', $workspaces->firstWhere('id', $householdWorkspaceId)['membership_role'] ?? null);
    }

    public function test_workspace_list_includes_google_calendar_mappings_after_selection(): void
    {
        $token = $this->apiToken('workspace-calendar-map@example.com');
        $user = User::where('email', 'workspace-calendar-map@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $connectionId = DB::table('google_calendar_connections')->insertGetId([
            'user_id' => $user->id,
            'google_account_email' => $user->email,
            'calendar_id' => 'primary',
            'status' => 'connected',
            'access_token_encrypted' => 'fake-access-token',
            'refresh_token_encrypted' => 'fake-refresh-token',
            'metadata' => json_encode([
                'calendars' => [
                    ['id' => 'primary', 'summary' => 'Primary'],
                    ['id' => 'family@example.com', 'summary' => 'Family'],
                ],
                'selected_calendar_ids' => ['primary'],
            ]),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withToken($token)->patchJson("/api/workspaces/{$workspaceId}/google-calendars", [
            'google_calendar_ids' => ['primary', 'family@example.com'],
            'default_export_calendar_id' => 'family@example.com',
        ])->assertOk();

        $response = $this->withToken($token)->getJson('/api/workspaces')->assertOk();
        $personalWorkspace = collect($response->json('data'))->firstWhere('id', $workspaceId);

        $this->assertNotNull($personalWorkspace);
        $this->assertEqualsCanonicalizing(
            ['primary', 'family@example.com'],
            collect($personalWorkspace['google_calendar_mappings'] ?? [])->pluck('google_calendar_id')->all(),
        );
        $this->assertDatabaseHas('workspace_google_calendar_mappings', [
            'workspace_id' => $workspaceId,
            'google_calendar_connection_id' => $connectionId,
            'google_calendar_id' => 'family@example.com',
            'is_default_export' => true,
        ]);
    }

    public function test_event_categories_list_defaults_to_all_accessible_workspaces(): void
    {
        $token = $this->apiToken('workspace-category-options@example.com');
        $user = User::where('email', 'workspace-category-options@example.com')->firstOrFail();
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $household = app(WorkspaceService::class)->createHousehold($user, 'Family');

        EventCategory::create([
            'user_id' => $user->id,
            'workspace_id' => $personalWorkspaceId,
            'name' => 'Personal',
            'color' => '#34C759',
        ]);
        EventCategory::create([
            'user_id' => $user->id,
            'workspace_id' => $household->id,
            'name' => 'Family',
            'color' => '#007AFF',
        ]);

        $this->withToken($token)->getJson('/api/event-categories')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Personal'])
            ->assertJsonFragment(['name' => 'Family']);

        $this->withToken($token)->getJson('/api/event-categories?workspace_id='.$personalWorkspaceId)
            ->assertOk()
            ->assertJsonFragment(['name' => 'Personal'])
            ->assertJsonMissing(['name' => 'Family']);
    }

    public function test_sync_all_reuses_existing_copied_google_calendar_event(): void
    {
        $user = User::factory()->create(['email' => 'owner@example.com']);
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $household = app(WorkspaceService::class)->createHousehold($user, 'Family');
        $source = CalendarEvent::create([
            'workspace_id' => $personalWorkspaceId,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'title' => 'Flight to LGA',
            'starts_at' => '2026-02-25 07:00:00',
            'ends_at' => '2026-02-25 09:39:00',
            'status' => 'confirmed',
            'google_calendar_id' => $user->email,
            'google_event_id' => 'google-flight-1',
            'metadata' => ['source' => 'google_calendar'],
        ]);
        $existing = CalendarEvent::create([
            'workspace_id' => $household->id,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'title' => 'Older copied title',
            'starts_at' => '2026-02-25 07:00:00',
            'ends_at' => '2026-02-25 09:39:00',
            'status' => 'confirmed',
            'google_calendar_id' => $user->email,
            'google_event_id' => 'google-flight-1',
            'metadata' => ['source' => 'google_calendar'],
        ]);

        $copied = app(WorkspaceItemSyncService::class)->sync($source, $household, $user);

        $this->assertSame($existing->id, $copied->id);
        $this->assertSame('Flight to LGA', $copied->title);
        $this->assertSame(1, CalendarEvent::where('workspace_id', $household->id)->where('google_event_id', 'google-flight-1')->count());
        $this->assertDatabaseHas('workspace_item_links', [
            'source_workspace_id' => $personalWorkspaceId,
            'target_workspace_id' => $household->id,
            'source_type' => 'calendar_events',
            'source_id' => $source->id,
            'target_type' => 'calendar_events',
            'target_id' => $existing->id,
            'link_type' => 'copy',
        ]);
    }

    public function test_sync_all_recovers_from_stale_copy_link_before_reusing_existing_google_event(): void
    {
        $user = User::factory()->create(['email' => 'owner-stale-link@example.com']);
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $household = app(WorkspaceService::class)->createHousehold($user, 'Family');
        $source = CalendarEvent::create([
            'workspace_id' => $personalWorkspaceId,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'title' => 'Flight to LGA',
            'starts_at' => '2026-02-25 07:00:00',
            'ends_at' => '2026-02-25 09:39:00',
            'status' => 'confirmed',
            'google_calendar_id' => $user->email,
            'google_event_id' => 'google-flight-stale-link',
            'metadata' => ['source' => 'google_calendar'],
        ]);
        $staleTarget = CalendarEvent::create([
            'workspace_id' => $household->id,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'title' => 'Deleted copied title',
            'starts_at' => '2026-02-25 07:00:00',
            'ends_at' => '2026-02-25 09:39:00',
            'status' => 'confirmed',
            'google_calendar_id' => $user->email,
            'google_event_id' => 'google-flight-stale-link-deleted',
            'metadata' => ['source' => 'google_calendar'],
        ]);
        $existing = CalendarEvent::create([
            'workspace_id' => $household->id,
            'user_id' => $user->id,
            'created_by_user_id' => $user->id,
            'title' => 'Older copied title',
            'starts_at' => '2026-02-25 07:00:00',
            'ends_at' => '2026-02-25 09:39:00',
            'status' => 'confirmed',
            'google_calendar_id' => $user->email,
            'google_event_id' => 'google-flight-stale-link',
            'metadata' => ['source' => 'google_calendar'],
        ]);
        WorkspaceItemLink::create([
            'source_workspace_id' => $personalWorkspaceId,
            'target_workspace_id' => $household->id,
            'source_type' => 'calendar_events',
            'source_id' => $source->id,
            'target_type' => 'calendar_events',
            'target_id' => $staleTarget->id,
            'link_type' => 'copy',
        ]);
        $staleTarget->delete();

        $copied = app(WorkspaceItemSyncService::class)->sync($source, $household, $user);

        $this->assertSame($existing->id, $copied->id);
        $this->assertSame('Flight to LGA', $copied->title);
        $this->assertSame(1, CalendarEvent::where('workspace_id', $household->id)->where('google_event_id', 'google-flight-stale-link')->count());
        $this->assertDatabaseHas('workspace_item_links', [
            'source_workspace_id' => $personalWorkspaceId,
            'target_workspace_id' => $household->id,
            'source_type' => 'calendar_events',
            'source_id' => $source->id,
            'target_type' => 'calendar_events',
            'target_id' => $existing->id,
            'link_type' => 'copy',
        ]);
    }

    public function test_calendar_event_delete_can_remove_selected_linked_workspace_copies(): void
    {
        $token = $this->apiToken('delete-linked-calendar@example.com');
        $user = User::where('email', 'delete-linked-calendar@example.com')->firstOrFail();
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $household = app(WorkspaceService::class)->createHousehold($user, 'Family');

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $personalWorkspaceId,
            'title' => 'School meeting',
            'starts_at' => '2026-05-21T14:00:00Z',
            'sync_to_workspace_ids' => [$household->id],
        ])->assertCreated()->json('data.id');

        $copiedId = CalendarEvent::where('workspace_id', $household->id)->value('id');

        $this->withToken($token)->getJson('/api/calendar-events?workspace_id='.$personalWorkspaceId)
            ->assertOk()
            ->assertJsonPath('data.0.linked_workspace_ids', [$personalWorkspaceId, $household->id]);

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$eventId, [
            'delete_from_workspace_ids' => [$personalWorkspaceId],
        ])->assertNoContent();

        $this->assertDatabaseMissing('calendar_events', ['id' => $eventId]);
        $this->assertDatabaseHas('calendar_events', ['id' => $copiedId, 'workspace_id' => $household->id]);

        $this->withToken($token)->deleteJson('/api/calendar-events/'.$copiedId, [
            'delete_from_workspace_ids' => [$household->id],
        ])->assertNoContent();

        $this->assertDatabaseMissing('calendar_events', ['id' => $copiedId]);
        $this->assertSame(0, WorkspaceItemLink::where('source_type', 'calendar_events')->count());
    }
}
