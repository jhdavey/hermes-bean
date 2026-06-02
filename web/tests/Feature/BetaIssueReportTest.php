<?php

namespace Tests\Feature;

use App\Models\BetaUser;
use App\Models\IssueReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BetaIssueReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_registered_users_are_labeled_beta(): void
    {
        $token = $this->apiToken('beta-user@example.com');
        $user = User::where('email', 'beta-user@example.com')->firstOrFail();

        $this->assertDatabaseHas('beta_users', [
            'user_id' => $user->id,
            'status' => 'active',
            'source' => 'self_signup',
        ]);

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_beta', true)
            ->assertJsonPath('data.beta_user.status', 'active');
    }

    public function test_beta_user_can_submit_issue_report_with_screenshot(): void
    {
        Storage::fake('public');

        $token = $this->apiToken('reporter@example.com');
        $user = User::where('email', 'reporter@example.com')->firstOrFail();
        $workspaceId = $user->fresh()->default_workspace_id;

        $this->withToken($token)->post('/api/issue-reports', [
            'workspace_id' => $workspaceId,
            'page_url' => 'https://heybean.test/app',
            'message' => 'Month view did not refresh after I added an event.',
            'screenshots' => [
                UploadedFile::fake()->image('calendar.png', 1200, 800),
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.user.email', 'reporter@example.com')
            ->assertJsonPath('data.workspace.id', $workspaceId);

        $report = IssueReport::firstOrFail();
        $this->assertSame($user->id, $report->user_id);
        $this->assertSame(BetaUser::where('user_id', $user->id)->firstOrFail()->id, $report->beta_user_id);
        $this->assertCount(1, $report->screenshots);
        Storage::disk('public')->assertExists($report->screenshots[0]['path']);
    }

    public function test_admin_summary_includes_issue_reports(): void
    {
        $token = $this->apiToken('admin-beta@example.com');
        $user = User::where('email', 'admin-beta@example.com')->firstOrFail();
        $user->forceFill(['is_admin' => true])->save();

        IssueReport::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'beta_user_id' => BetaUser::where('user_id', $user->id)->firstOrFail()->id,
            'status' => 'open',
            'message' => 'The beta banner report flow works.',
            'page_url' => 'https://heybean.test/app',
        ]);

        $this->withToken($token)->getJson('/api/admin/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.open_issue_reports', 1)
            ->assertJsonPath('data.issue_reports.0.message', 'The beta banner report flow works.');
    }

    public function test_admin_can_close_reopen_and_archive_issue_reports(): void
    {
        $adminToken = $this->apiToken('admin-issue-status@example.com');
        $userToken = $this->apiToken('normal-issue-status@example.com');
        $admin = User::where('email', 'admin-issue-status@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $user = User::where('email', 'normal-issue-status@example.com')->firstOrFail();

        $report = IssueReport::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'beta_user_id' => BetaUser::where('user_id', $user->id)->firstOrFail()->id,
            'status' => 'open',
            'message' => 'The issue actions need testing.',
            'page_url' => 'https://heybean.test/app',
        ]);

        $this->withToken($userToken)->patchJson("/api/admin/issue-reports/{$report->id}", [
            'status' => 'closed',
        ])->assertForbidden();

        $this->withToken($adminToken)->patchJson("/api/admin/issue-reports/{$report->id}", [
            'status' => 'closed',
        ])->assertOk()
            ->assertJsonPath('data.status', 'closed');

        $this->assertNotNull($report->refresh()->resolved_at);

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.open_issue_reports', 0);

        $this->withToken($adminToken)->patchJson("/api/admin/issue-reports/{$report->id}", [
            'status' => 'open',
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.resolved_at', null);

        $this->withToken($adminToken)->patchJson("/api/admin/issue-reports/{$report->id}", [
            'status' => 'archived',
        ])->assertOk()
            ->assertJsonPath('data.status', 'archived');

        $this->assertSame($admin->id, $report->refresh()->metadata['last_status_changed_by_user_id'] ?? null);
        $this->assertNotEmpty($report->metadata['archived_at'] ?? null);
    }
}
