<?php

namespace Tests\Feature;

use App\Models\Task;
use App\Models\User;
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
}
