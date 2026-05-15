<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use InvalidArgumentException;

class WorkspaceService
{
    public function ensurePersonalWorkspaceForUser(User $user): int
    {
        return DB::transaction(function () use ($user): int {
            $workspace = Workspace::firstOrCreate(
                ['personal_owner_user_id' => $user->id],
                [
                    'type' => 'personal',
                    'name' => $this->personalWorkspaceName($user),
                    'slug' => 'personal-'.$user->id,
                    'created_by_user_id' => $user->id,
                    'status' => 'active',
                ]
            );

            WorkspaceMembership::updateOrCreate(
                ['workspace_id' => $workspace->id, 'user_id' => $user->id],
                ['role' => 'owner', 'status' => 'active', 'invited_by_user_id' => null, 'invited_email' => null, 'accepted_at' => now()]
            );

            if (! $user->default_workspace_id) {
                $user->forceFill(['default_workspace_id' => $workspace->id])->save();
            }

            $this->backfillUserRows((int) $user->id, (int) $workspace->id);

            return (int) $workspace->id;
        });
    }

    public function createHousehold(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name): Workspace {
            $workspace = Workspace::create([
                'type' => 'household',
                'name' => $name,
                'slug' => Str::slug($name).'-'.Str::lower(Str::random(6)),
                'created_by_user_id' => $owner->id,
                'status' => 'active',
            ]);

            WorkspaceMembership::create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'role' => 'owner',
                'status' => 'active',
                'accepted_at' => now(),
            ]);

            return $workspace->refresh();
        });
    }

    public function accessibleWorkspaces(User $user): Collection
    {
        $this->ensurePersonalWorkspaceForUser($user);

        return Workspace::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id)->where('status', 'active'))
            ->with(['memberships.user', 'agentProfile'])
            ->orderByRaw("case when type = 'personal' then 0 else 1 end")
            ->orderBy('name')
            ->get();
    }

    public function resolveWorkspace(User $user, mixed $workspaceId = null): Workspace
    {
        $this->ensurePersonalWorkspaceForUser($user);
        $workspaceId = $workspaceId ?: $user->fresh()->default_workspace_id;
        $workspace = $workspaceId ? Workspace::find($workspaceId) : null;
        $workspace ??= Workspace::where('personal_owner_user_id', $user->id)->firstOrFail();
        $this->authorizeMember($user, $workspace);

        return $workspace;
    }

    public function setDefaultWorkspace(User $user, Workspace $workspace): Workspace
    {
        $this->authorizeMember($user, $workspace);
        $user->forceFill(['default_workspace_id' => $workspace->id])->save();

        return $workspace;
    }

    public function authorizeMember(User $user, Workspace $workspace): WorkspaceMembership
    {
        $membership = WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $user->id)->where('status', 'active')->first();
        if (! $membership) {
            throw new AuthorizationException('You are not a member of this workspace.');
        }

        return $membership;
    }

    public function authorizeOwner(User $user, Workspace $workspace): WorkspaceMembership
    {
        $membership = $this->authorizeMember($user, $workspace);
        if ($membership->role !== 'owner') {
            throw new AuthorizationException('Workspace owner role is required.');
        }

        return $membership;
    }

    public function invite(User $actor, Workspace $workspace, string $email): WorkspaceMembership
    {
        $this->authorizeOwner($actor, $workspace);
        $email = Str::lower(trim($email));
        $invitee = User::where('email', $email)->first();
        $token = Str::random(40);

        return WorkspaceMembership::updateOrCreate(
            ['workspace_id' => $workspace->id, 'invited_email' => $email],
            [
                'user_id' => $invitee?->id,
                'role' => 'member',
                'status' => $invitee ? 'pending' : 'invited',
                'invited_by_user_id' => $actor->id,
                'accepted_at' => null,
                'metadata' => ['invitation_token' => $token, 'invited_at' => now()->toISOString()],
            ]
        );
    }

    public function acceptInvite(User $user, string $token): WorkspaceMembership
    {
        $membership = WorkspaceMembership::query()
            ->whereIn('status', ['pending', 'invited'])
            ->where('metadata->invitation_token', $token)
            ->firstOrFail();

        if ($membership->user_id && (int) $membership->user_id !== (int) $user->id) {
            throw new AuthorizationException('This invitation belongs to another user.');
        }

        if ($membership->invited_email && Str::lower($membership->invited_email) !== Str::lower($user->email)) {
            throw new AuthorizationException('This invitation belongs to another email address.');
        }

        $membership->forceFill(['user_id' => $user->id, 'status' => 'active', 'accepted_at' => now()])->save();

        return $membership->refresh();
    }

    public function updateMemberRole(User $actor, Workspace $workspace, WorkspaceMembership $member, string $role): WorkspaceMembership
    {
        $this->authorizeOwner($actor, $workspace);
        $this->assertMembershipInWorkspace($workspace, $member);
        if (! in_array($role, ['owner', 'member'], true)) {
            throw new InvalidArgumentException('Invalid role.');
        }
        if ($member->role === 'owner' && $role !== 'owner') {
            $this->assertNotLastOwner($workspace, $member);
        }
        $member->forceFill(['role' => $role])->save();

        return $member->refresh();
    }

    public function removeMember(User $actor, Workspace $workspace, WorkspaceMembership $member): void
    {
        $this->authorizeOwner($actor, $workspace);
        $this->assertMembershipInWorkspace($workspace, $member);
        if ($member->role === 'owner') {
            $this->assertNotLastOwner($workspace, $member);
        }
        $member->delete();
    }

    public function leave(User $user, Workspace $workspace): void
    {
        $membership = $this->authorizeMember($user, $workspace);
        if ($workspace->type === 'personal') {
            throw new InvalidArgumentException('Personal workspace cannot be left.');
        }
        if ($membership->role === 'owner') {
            $this->assertNotLastOwner($workspace, $membership);
        }
        $membership->delete();
        if ((int) $user->default_workspace_id === (int) $workspace->id) {
            $user->forceFill(['default_workspace_id' => $this->ensurePersonalWorkspaceForUser($user)])->save();
        }
    }

    private function assertMembershipInWorkspace(Workspace $workspace, WorkspaceMembership $membership): void
    {
        if ((int) $membership->workspace_id !== (int) $workspace->id) {
            throw new AuthorizationException('Membership is not in this workspace.');
        }
    }

    private function assertNotLastOwner(Workspace $workspace, WorkspaceMembership $membership): void
    {
        $owners = WorkspaceMembership::where('workspace_id', $workspace->id)->where('status', 'active')->where('role', 'owner')->where('id', '!=', $membership->id)->count();
        if ($owners < 1) {
            throw new InvalidArgumentException('Workspace must keep at least one owner.');
        }
    }

    private function personalWorkspaceName(User $user): string
    {
        $name = trim((string) $user->name);

        return $name !== '' ? $name.' Personal Workspace' : 'Personal Workspace';
    }

    private function backfillUserRows(int $userId, int $workspaceId): void
    {
        foreach ($this->workspaceScopedTables() as $tableName) {
            if (! Schema::hasTable($tableName) || ! Schema::hasColumn($tableName, 'user_id')) {
                continue;
            }
            if (Schema::hasColumn($tableName, 'workspace_id')) {
                DB::table($tableName)->where('user_id', $userId)->whereNull('workspace_id')->update(['workspace_id' => $workspaceId]);
            }
            if (Schema::hasColumn($tableName, 'created_by_user_id')) {
                DB::table($tableName)->where('user_id', $userId)->whereNull('created_by_user_id')->update(['created_by_user_id' => $userId]);
            }
        }
    }

    private function workspaceScopedTables(): array
    {
        return ['agent_profiles', 'conversation_sessions', 'activity_events', 'tasks', 'reminders', 'calendar_events', 'approvals', 'blockers', 'scheduler_job_records', 'event_categories'];
    }
}
