<?php

namespace Tests\Feature;

use App\Models\IssueReport;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class IssueReportTest extends TestCase
{
    use RefreshDatabase;

    public function test_signed_in_user_can_submit_issue_report_with_screenshot(): void
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
        $this->assertCount(1, $report->screenshots);
        Storage::disk('public')->assertExists($report->screenshots[0]['path']);
    }

    public function test_admin_summary_includes_open_and_archived_issue_reports(): void
    {
        $token = $this->apiToken('admin-beta@example.com');
        $user = User::where('email', 'admin-beta@example.com')->firstOrFail();
        $user->forceFill(['is_admin' => true])->save();

        IssueReport::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'open',
            'message' => 'The beta banner report flow works.',
            'page_url' => 'https://heybean.test/app',
        ]);

        IssueReport::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'status' => 'closed',
            'message' => 'This closed report should be archived.',
            'page_url' => 'https://heybean.test/app',
            'resolved_at' => now(),
        ]);

        $this->withToken($token)->getJson('/api/admin/issue-reports/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.open_issue_reports', 1)
            ->assertJsonPath('data.totals.archived_issue_reports', 1)
            ->assertJsonPath('data.issue_reports.0.message', 'The beta banner report flow works.')
            ->assertJsonPath('data.archived_issue_reports.0.message', 'This closed report should be archived.');
    }

    public function test_admin_can_close_and_reopen_issue_reports(): void
    {
        $adminToken = $this->apiToken('admin-issue-status@example.com');
        $userToken = $this->apiToken('normal-issue-status@example.com');
        $admin = User::where('email', 'admin-issue-status@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $user = User::where('email', 'normal-issue-status@example.com')->firstOrFail();

        $report = IssueReport::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
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
        $this->assertNotEmpty($report->metadata['archived_at'] ?? null);

        $this->withToken($adminToken)->getJson('/api/admin/issue-reports/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.open_issue_reports', 0)
            ->assertJsonPath('data.totals.archived_issue_reports', 1)
            ->assertJsonPath('data.archived_issue_reports.0.id', $report->id);

        $this->withToken($adminToken)->patchJson("/api/admin/issue-reports/{$report->id}", [
            'status' => 'open',
        ])->assertOk()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.resolved_at', null);

        $this->withToken($adminToken)->patchJson("/api/admin/issue-reports/{$report->id}", [
            'status' => 'archived',
        ])->assertUnprocessable();

        $this->assertSame($admin->id, $report->refresh()->metadata['last_status_changed_by_user_id'] ?? null);
        $this->assertArrayNotHasKey('archived_at', $report->metadata ?? []);
    }
}
