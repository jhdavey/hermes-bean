<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Notifications\WorkspaceInvitationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\TestCase;

class WorkspaceInvitationTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_invite_without_leaking_invitation_token(): void
    {
        Notification::fake();
        $ownerToken = $this->apiToken('invite-owner@example.com');
        $workspaceId = $this->withToken($ownerToken)->postJson('/api/workspaces', [
            'name' => 'Invite Household',
        ])->assertCreated()->json('data.id');

        $response = $this->withToken($ownerToken)->postJson("/api/workspaces/{$workspaceId}/invitations", [
            'email' => ' New.Member@Example.COM ',
        ])->assertCreated();

        $response->assertJsonPath('data.status', 'invited')
            ->assertJsonPath('data.invited_email', 'new.member@example.com')
            ->assertJsonMissingPath('data.metadata')
            ->assertJsonMissingPath('data.metadata.invitation_token')
            ->assertJsonMissingPath('data.metadata.invitation_token_hash');

        $this->assertIsString($response->json('data.invitation_token'));
        $this->assertSame(64, strlen($response->json('data.invitation_token')));
        $this->assertStringContainsString('/workspace-invitations/', $response->json('data.invitation_accept_url'));
        $this->assertStringEndsWith('/accept', $response->json('data.invitation_accept_url'));

        $membership = WorkspaceMembership::where('workspace_id', $workspaceId)
            ->where('invited_email', 'new.member@example.com')
            ->firstOrFail();

        $this->assertSame('invited', $membership->status);
        $this->assertArrayHasKey('invitation_token_hash', $membership->metadata);
        $this->assertArrayNotHasKey('invitation_token', $membership->metadata);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $membership->metadata['invitation_token_hash']);

        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function (WorkspaceInvitationNotification $notification, array $channels, AnonymousNotifiable $notifiable): bool {
            return $channels === ['mail']
                && ($notifiable->routes['mail'] ?? null) === 'new.member@example.com'
                && str_contains($notification->acceptUrl(), '/workspace-invitations/')
                && str_ends_with($notification->acceptUrl(), '/accept');
        });
    }

    public function test_email_accept_link_adds_member_and_refreshes_workspace_membership_views(): void
    {
        Notification::fake();
        $ownerToken = $this->apiToken('invite-owner-email-link@example.com');
        $inviteeToken = $this->apiToken('email-link-invitee@example.com');
        $invitee = User::where('email', 'email-link-invitee@example.com')->firstOrFail();
        $workspace = $this->householdForToken($ownerToken, 'Daveys Household');

        $this->withToken($ownerToken)->postJson("/api/workspaces/{$workspace->id}/invitations", [
            'email' => 'email-link-invitee@example.com',
        ])->assertCreated();

        $acceptUrl = null;
        Notification::assertSentOnDemand(WorkspaceInvitationNotification::class, function (WorkspaceInvitationNotification $notification) use (&$acceptUrl): bool {
            $acceptUrl = $notification->acceptUrl();

            return true;
        });

        $this->assertNotNull($acceptUrl);
        $this->get($acceptUrl)
            ->assertOk()
            ->assertSee('Daveys Household')
            ->assertSee('The household has been added to your HeyBean workspace settings.');

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'status' => 'active',
            'role' => 'member',
        ]);

        $this->withToken($inviteeToken)->getJson('/api/workspaces')
            ->assertOk()
            ->assertJsonFragment(['name' => 'Daveys Household'])
            ->assertJsonFragment(['email' => 'email-link-invitee@example.com']);

        $this->withToken($ownerToken)->getJson("/api/workspaces/{$workspace->id}")
            ->assertOk()
            ->assertJsonFragment(['name' => 'Daveys Household'])
            ->assertJsonFragment(['email' => 'email-link-invitee@example.com']);
    }

    public function test_non_owner_cannot_invite_to_workspace(): void
    {
        $ownerToken = $this->apiToken('invite-owner-2@example.com');
        $memberToken = $this->apiToken('invite-member-2@example.com');
        $member = User::where('email', 'invite-member-2@example.com')->firstOrFail();
        $workspace = $this->householdForToken($ownerToken, 'Protected Household');
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        $this->withToken($memberToken)->postJson("/api/workspaces/{$workspace->id}/invitations", [
            'email' => 'outsider@example.com',
        ])->assertForbidden();
    }

    public function test_inviting_existing_active_member_returns_conflict(): void
    {
        $ownerToken = $this->apiToken('invite-owner-3@example.com');
        $memberToken = $this->apiToken('invite-member-3@example.com');
        $member = User::where('email', 'invite-member-3@example.com')->firstOrFail();
        $workspace = $this->householdForToken($ownerToken, 'Duplicate Household');
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $member->id,
            'role' => 'member',
            'status' => 'active',
            'accepted_at' => now(),
        ]);

        $this->assertNotEmpty($memberToken);
        $this->withToken($ownerToken)->postJson("/api/workspaces/{$workspace->id}/invitations", [
            'email' => 'INVITE-MEMBER-3@example.com',
        ])->assertStatus(409);

        $this->assertSame(1, WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $member->id)->count());
    }

    public function test_owner_can_invite_new_user_and_that_user_can_accept_after_registration(): void
    {
        $ownerToken = $this->apiToken('invite-owner-e2e@example.com');
        $workspace = $this->householdForToken($ownerToken, 'E2E Household');

        $inviteResponse = $this->withToken($ownerToken)->postJson("/api/workspaces/{$workspace->id}/invitations", [
            'email' => 'e2e-invitee@example.com',
        ])->assertCreated();

        $token = $inviteResponse->json('data.invitation_token');
        $inviteeToken = $this->apiToken('e2e-invitee@example.com');
        $invitee = User::where('email', 'e2e-invitee@example.com')->firstOrFail();

        $this->withToken($inviteeToken)->postJson("/api/workspace-invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user_id', $invitee->id)
            ->assertJsonMissingPath('data.metadata')
            ->assertJsonMissingPath('data.invitation_token');

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'invited_email' => 'e2e-invitee@example.com',
            'status' => 'active',
        ]);
    }

    public function test_invited_existing_user_can_accept_once(): void
    {
        $ownerToken = $this->apiToken('invite-owner-4@example.com');
        $inviteeToken = $this->apiToken('invite-existing@example.com');
        $invitee = User::where('email', 'invite-existing@example.com')->firstOrFail();
        $workspace = $this->householdForToken($ownerToken, 'Existing User Household');
        $token = $this->createInvitation($workspace, 'invite-existing@example.com', $invitee);

        $this->withToken($inviteeToken)->postJson("/api/workspace-invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user_id', $invitee->id)
            ->assertJsonMissingPath('data.metadata');

        $this->assertDatabaseHas('workspace_memberships', [
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'status' => 'active',
            'role' => 'member',
        ]);
        $this->assertArrayNotHasKey('invitation_token_hash', WorkspaceMembership::where('workspace_id', $workspace->id)->where('user_id', $invitee->id)->firstOrFail()->metadata ?? []);

        $this->withToken($inviteeToken)->postJson("/api/workspace-invitations/{$token}/accept")->assertNotFound();
    }

    public function test_legacy_plaintext_pending_invitation_can_still_be_accepted_and_is_scrubbed(): void
    {
        $ownerToken = $this->apiToken('invite-owner-legacy@example.com');
        $inviteeToken = $this->apiToken('legacy-invitee@example.com');
        $invitee = User::where('email', 'legacy-invitee@example.com')->firstOrFail();
        $workspace = $this->householdForToken($ownerToken, 'Legacy Invite Household');
        $token = 'legacy-token-value';
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $invitee->id,
            'role' => 'member',
            'status' => 'pending',
            'invited_email' => 'legacy-invitee@example.com',
            'metadata' => ['invitation_token' => $token],
        ]);

        $this->withToken($inviteeToken)->postJson("/api/workspace-invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonMissingPath('data.metadata');

        $metadata = WorkspaceMembership::where('workspace_id', $workspace->id)
            ->where('user_id', $invitee->id)
            ->firstOrFail()
            ->metadata ?? [];

        $this->assertArrayNotHasKey('invitation_token', $metadata);
        $this->assertArrayNotHasKey('invitation_token_hash', $metadata);
    }

    public function test_invited_new_user_can_accept_after_registering_with_matching_email(): void
    {
        $ownerToken = $this->apiToken('invite-owner-5@example.com');
        $workspace = $this->householdForToken($ownerToken, 'New User Household');
        $token = $this->createInvitation($workspace, 'fresh-invitee@example.com');
        $inviteeToken = $this->apiToken('fresh-invitee@example.com');
        $invitee = User::where('email', 'fresh-invitee@example.com')->firstOrFail();

        $this->withToken($inviteeToken)->postJson("/api/workspace-invitations/{$token}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.user_id', $invitee->id);
    }

    public function test_invitation_cannot_be_accepted_by_wrong_email(): void
    {
        $ownerToken = $this->apiToken('invite-owner-6@example.com');
        $wrongUserToken = $this->apiToken('wrong-invitee@example.com');
        $workspace = $this->householdForToken($ownerToken, 'Wrong Email Household');
        $token = $this->createInvitation($workspace, 'right-invitee@example.com');

        $this->withToken($wrongUserToken)->postJson("/api/workspace-invitations/{$token}/accept")
            ->assertForbidden();
    }

    private function householdForToken(string $token, string $name): Workspace
    {
        $workspaceId = $this->withToken($token)->postJson('/api/workspaces', [
            'name' => $name,
        ])->assertCreated()->json('data.id');

        return Workspace::findOrFail($workspaceId);
    }

    private function createInvitation(Workspace $workspace, string $email, ?User $user = null): string
    {
        $token = Str::random(64);
        WorkspaceMembership::create([
            'workspace_id' => $workspace->id,
            'user_id' => $user?->id,
            'role' => 'member',
            'status' => 'invited',
            'invited_by_user_id' => $workspace->created_by_user_id,
            'invited_email' => Str::lower($email),
            'metadata' => [
                'invitation_token_hash' => hash('sha256', $token),
                'invited_at' => now()->toISOString(),
            ],
        ]);

        return $token;
    }
}
