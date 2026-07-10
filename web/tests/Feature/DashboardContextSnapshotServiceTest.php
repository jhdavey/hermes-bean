<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DashboardContextSnapshotService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DashboardContextSnapshotServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_snapshot_separates_today_reminders_from_future_reminders(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-10 13:00:00', 'UTC'));
        config()->set('services.hermes_runtime.weather_lookup_enabled', false);
        $this->apiToken('snapshot-reminders@example.com');
        $user = User::where('email', 'snapshot-reminders@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);

        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Today reminder',
            'status' => 'pending',
            'remind_at' => Carbon::parse('2026-07-10 18:00:00', 'UTC'),
        ]);
        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Tomorrow reminder',
            'status' => 'pending',
            'remind_at' => Carbon::parse('2026-07-11 18:00:00', 'UTC'),
        ]);

        $snapshot = app(DashboardContextSnapshotService::class)->snapshot($user, $workspace, [
            'timezone' => 'UTC',
        ]);

        $this->assertSame(['Today reminder'], collect($snapshot['reminders_due_today'])->pluck('title')->all());
        $this->assertSame(['Tomorrow reminder'], collect($snapshot['reminders_upcoming_next_7_days'])->pluck('title')->all());
    }
}
