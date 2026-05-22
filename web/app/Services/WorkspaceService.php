<?php

namespace App\Services;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

        $defaultWorkspaceId = (int) $user->fresh()->default_workspace_id;

        return Workspace::query()
            ->whereHas('memberships', fn ($query) => $query->where('user_id', $user->id)->where('status', 'active'))
            ->with(['memberships.user', 'agentProfile', 'googleCalendarMappings'])
            ->orderByRaw("case when type = 'personal' then 0 else 1 end")
            ->orderBy('name')
            ->get()
            ->each(function (Workspace $workspace) use ($user, $defaultWorkspaceId): void {
                $membership = $workspace->memberships->firstWhere('user_id', $user->id);
                $role = $membership?->role ?? 'member';
                $workspace->setAttribute('role', $role);
                $workspace->setAttribute('membership_role', $role);
                $workspace->setAttribute('active', (int) $workspace->id === $defaultWorkspaceId);
                $workspace->setAttribute('is_default', (int) $workspace->id === $defaultWorkspaceId);
            });
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

        $membership = DB::transaction(function () use ($actor, $workspace, $email, $invitee): WorkspaceMembership {
            $existingActiveMembership = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->where('status', 'active')
                ->where(function ($query) use ($email, $invitee): void {
                    $query->where('invited_email', $email);
                    if ($invitee) {
                        $query->orWhere('user_id', $invitee->id);
                    }
                })
                ->first();

            if ($existingActiveMembership) {
                throw new InvalidArgumentException('User is already an active member of this workspace.');
            }

            $membership = WorkspaceMembership::query()
                ->where('workspace_id', $workspace->id)
                ->whereIn('status', ['pending', 'invited'])
                ->where(function ($query) use ($email, $invitee): void {
                    $query->where('invited_email', $email);
                    if ($invitee) {
                        $query->orWhere('user_id', $invitee->id);
                    }
                })
                ->first() ?? new WorkspaceMembership(['workspace_id' => $workspace->id]);

            $token = Str::random(64);
            $membership->forceFill([
                'workspace_id' => $workspace->id,
                'user_id' => $invitee?->id,
                'role' => 'member',
                'status' => 'invited',
                'invited_by_user_id' => $actor->id,
                'invited_email' => $email,
                'accepted_at' => null,
                'metadata' => [
                    'invitation_token_hash' => hash('sha256', $token),
                    'invited_at' => now()->toISOString(),
                ],
            ])->save();

            return $membership->refresh()
                ->setAttribute('invitation_token', $token)
                ->setAttribute('invitation_accept_url', route('workspace-invitations.accept', ['token' => $token]));
        });

        Notification::route('mail', $email)->notify(
            new WorkspaceInvitationNotification($workspace->refresh(), $actor, $membership->invitation_token),
        );

        return $membership;
    }

    public function acceptInvite(User $user, string $token): WorkspaceMembership
    {
        $membership = $this->pendingInvitationByToken($token);

        if ($membership->user_id && (int) $membership->user_id !== (int) $user->id) {
            throw new AuthorizationException('This invitation belongs to another user.');
        }

        if ($membership->invited_email && Str::lower($membership->invited_email) !== Str::lower($user->email)) {
            throw new AuthorizationException('This invitation belongs to another email address.');
        }

        return $this->activateInvite($membership, $user);
    }

    public function acceptInviteFromEmailLink(string $token): WorkspaceMembership
    {
        $membership = $this->pendingInvitationByToken($token);
        $user = $membership->user_id
            ? User::find($membership->user_id)
            : User::where('email', Str::lower((string) $membership->invited_email))->first();

        if (! $user) {
            throw new InvalidArgumentException('Create a HeyBean account with the invited email address before accepting this invitation.');
        }

        return $this->activateInvite($membership, $user);
    }

    private function pendingInvitationByToken(string $token): WorkspaceMembership
    {
        $tokenHash = hash('sha256', $token);

        return WorkspaceMembership::query()
            ->whereIn('status', ['pending', 'invited'])
            ->where(function ($query) use ($token, $tokenHash): void {
                $query->where('metadata->invitation_token_hash', $tokenHash)
                    ->orWhere('metadata->invitation_token', $token);
            })
            ->firstOrFail();
    }

    private function activateInvite(WorkspaceMembership $membership, User $user): WorkspaceMembership
    {
        if ($membership->invited_email && Str::lower($membership->invited_email) !== Str::lower($user->email)) {
            throw new AuthorizationException('This invitation belongs to another email address.');
        }

        return DB::transaction(function () use ($membership, $user): WorkspaceMembership {
            $activeMembership = WorkspaceMembership::query()
                ->where('workspace_id', $membership->workspace_id)
                ->where('user_id', $user->id)
                ->where('status', 'active')
                ->first();

            if ($activeMembership && (int) $activeMembership->id !== (int) $membership->id) {
                $membership->delete();

                return $activeMembership->refresh();
            }

            $metadata = $membership->metadata ?? [];
            unset($metadata['invitation_token_hash'], $metadata['invitation_token']);
            $metadata['accepted_at'] = now()->toISOString();

            $membership->forceFill([
                'user_id' => $user->id,
                'status' => 'active',
                'accepted_at' => now(),
                'metadata' => $metadata,
            ])->save();

            return $membership->refresh();
        });
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
        return ['agent_profiles', 'conversation_sessions', 'activity_events', 'tasks', 'reminders', 'calendar_events', 'approvals', 'blockers', 'event_categories'];
    }
}
