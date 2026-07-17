<?php

namespace Tests\Feature;

use App\Models\Reminder;
use App\Models\User;
use App\Models\Workspace;
use App\Services\DashboardChangeNotifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardChangeFeedTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_change_feed_returns_task_reminder_and_event_changes(): void
    {
        $token = $this->apiToken('changes@example.com');

        $taskId = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Realtime task',
            'type' => 'todo',
        ])->assertCreated()->json('data.id');

        $reminderId = $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'Realtime reminder',
            'remind_at' => now()->addHour()->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $eventId = $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Realtime event',
            'all_day' => false,
            'starts_at' => now()->addDay()->setHour(9)->toIso8601String(),
            'ends_at' => now()->addDay()->setHour(10)->toIso8601String(),
        ])->assertCreated()->json('data.id');

        $noteId = $this->withToken($token)->postJson('/api/notes', [
            'title' => 'Realtime note',
            'plain_text' => 'Notes should refresh the dashboard too.',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->getJson('/api/dashboard-changes?after=0&wait=0')
            ->assertOk()
            ->assertJsonFragment(['resource_type' => 'task', 'action' => 'created', 'resource_id' => $taskId])
            ->assertJsonFragment(['resource_type' => 'reminder', 'action' => 'created', 'resource_id' => $reminderId])
            ->assertJsonFragment(['resource_type' => 'calendar_event', 'action' => 'created', 'resource_id' => $eventId])
            ->assertJsonFragment(['resource_type' => 'note', 'action' => 'created', 'resource_id' => $noteId]);
    }

    public function test_dashboard_change_feed_is_scoped_to_current_user_access(): void
    {
        $ownerToken = $this->apiToken('owner-changes@example.com');
        $otherToken = $this->apiToken('other-changes@example.com');

        $this->withToken($ownerToken)->postJson('/api/tasks', [
            'title' => 'Private realtime task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($otherToken)->getJson('/api/dashboard-changes?after=0&wait=0')
            ->assertOk()
            ->assertJsonMissing(['title' => 'Private realtime task']);
    }

    public function test_reminder_alerts_are_recorded_as_dashboard_changes(): void
    {
        $token = $this->apiToken('reminder-alert@example.com');
        $user = User::where('email', 'reminder-alert@example.com')->firstOrFail();
        $workspaceId = Workspace::where('personal_owner_user_id', $user->id)->firstOrFail()->id;

        $reminder = Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Due reminder alert',
            'remind_at' => now()->subMinute(),
            'status' => 'scheduled',
            'metadata' => [],
        ]);

        app(DashboardChangeNotifier::class)->notify(
            userId: $user->id,
            workspaceId: $workspaceId,
            resourceType: 'reminder_alert',
            action: 'sent',
            resourceId: $reminder->id,
            payload: ['title' => $reminder->title],
        );

        $this->withToken($token)->getJson('/api/dashboard-changes?after=0&wait=0')
            ->assertOk()
            ->assertJsonFragment(['resource_type' => 'reminder_alert', 'action' => 'sent', 'resource_id' => $reminder->id]);

        $this->assertDatabaseHas('dashboard_changes', [
            'resource_type' => 'reminder_alert',
            'action' => 'sent',
            'resource_id' => $reminder->id,
        ]);
    }
}
