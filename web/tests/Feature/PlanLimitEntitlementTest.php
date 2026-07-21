<?php

namespace Tests\Feature;

use App\Models\CalendarEvent;
use App\Models\DashboardChange;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceItemLink;
use App\Services\PlanLimitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PlanLimitEntitlementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_plan_limits_and_enterprise_customer_overrides(): void
    {
        $adminToken = $this->apiToken('plan-admin@example.com');
        $userToken = $this->apiToken('plan-user@example.com');
        $admin = User::where('email', 'plan-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $customer = User::factory()->create(['email' => 'enterprise-customer@example.com']);

        $this->withToken($userToken)->getJson('/api/admin/plan-limits')
            ->assertForbidden();

        $this->withToken($adminToken)->patchJson('/api/admin/plan-limits/plans', [
            'plans' => [
                'base' => $this->limits(['workspace_limit' => 3]),
                'premium' => $this->limits(['workspace_limit' => 8, 'email_reminders_enabled' => true]),
                'pro' => $this->limits(['workspace_limit' => null]),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.plans.base.value.workspace_limit', 3);

        $this->assertDatabaseHas('admin_settings', ['key' => 'plan_limits.base']);

        $this->withToken($adminToken)->postJson('/api/admin/plan-limits/enterprise-customers', [
            'user_id' => $customer->id,
            'billing_type' => 'monthly',
            'monthly_rate_usd' => 199,
            'notes' => 'Pilot agreement',
            'limits' => $this->limits([
                'workspace_limit' => 42,
            ]),
        ])
            ->assertCreated()
            ->assertJsonPath('data.enterprise_customers.0.user_id', $customer->id)
            ->assertJsonPath('data.enterprise_customers.0.billing_type', 'monthly')
            ->assertJsonPath('data.enterprise_customers.0.limits.workspace_limit', 42);

        $customer->refresh();
        $this->assertSame('enterprise', $customer->subscription_tier);
    }

    public function test_workspace_limit_blocks_base_users_but_admins_are_unlimited(): void
    {
        $token = $this->apiToken('workspace-limit@example.com');
        $user = User::where('email', 'workspace-limit@example.com')->firstOrFail();
        $workspace = Workspace::where('personal_owner_user_id', $user->id)->firstOrFail();
        $this->withToken($token)->postJson('/api/workspaces', ['name' => 'Family'])
            ->assertCreated();

        $this->withToken($token)->postJson('/api/workspaces', ['name' => 'Work'])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'subscription_limit_reached')
            ->assertJsonPath('error.cta_label', 'View plans')
            ->assertJsonPath('error.upgrade_url', url('/pricing'));

        $adminToken = $this->apiToken('workspace-admin@example.com');
        $admin = User::where('email', 'workspace-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true, 'subscription_tier' => 'base'])->save();
        $this->withToken($adminToken)->postJson('/api/workspaces', ['name' => 'Admin Family'])
            ->assertCreated();
        $this->withToken($adminToken)->postJson('/api/workspaces', ['name' => 'Admin Work'])
            ->assertCreated();

        $this->assertSame('personal', $workspace->type);
    }

    public function test_base_plan_blocks_recurring_resources_and_premium_allows_them(): void
    {
        $baseToken = $this->apiToken('base-recurring@example.com');
        $premiumToken = $this->apiToken('premium-recurring@example.com');
        User::where('email', 'premium-recurring@example.com')->firstOrFail()
            ->forceFill(['subscription_tier' => 'premium'])
            ->save();

        $this->withToken($baseToken)->postJson('/api/tasks', [
            'title' => 'Pay rent',
            'type' => 'todo',
            'metadata' => ['recurrence' => 'monthly'],
        ])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'subscription_limit_reached');

        $this->withToken($baseToken)->postJson('/api/reminders', [
            'title' => 'Take vitamins',
            'remind_at' => now()->addHour()->toIso8601String(),
            'metadata' => ['recurrence' => 'daily'],
        ])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'subscription_limit_reached');

        $this->withToken($baseToken)->postJson('/api/calendar-events', [
            'title' => 'Weekly planning',
            'all_day' => false,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'recurrence' => 'weekly',
        ])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'subscription_limit_reached');

        $this->withToken($premiumToken)->postJson('/api/tasks', [
            'title' => 'Pay rent',
            'type' => 'todo',
            'metadata' => ['recurrence' => 'monthly'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.metadata.recurrence', 'monthly');

        $this->withToken($premiumToken)->postJson('/api/reminders', [
            'title' => 'Take vitamins',
            'remind_at' => now()->addHour()->toIso8601String(),
            'metadata' => ['recurrence' => 'daily'],
        ])->assertCreated()
            ->assertJsonPath('data.metadata.recurrence', 'daily');

        $this->withToken($premiumToken)->postJson('/api/calendar-events', [
            'title' => 'Weekly planning',
            'all_day' => false,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'recurrence' => 'weekly',
        ])->assertCreated()
            ->assertJsonPath('data.recurrence', 'weekly');
    }

    public function test_calendar_selection_limit_and_email_reminders_follow_plan(): void
    {
        $baseToken = $this->apiToken('base-calendar@example.com');
        $premiumToken = $this->apiToken('premium-calendar@example.com');
        User::where('email', 'premium-calendar@example.com')->firstOrFail()
            ->forceFill(['subscription_tier' => 'premium'])
            ->save();

        $this->withToken($baseToken)->patchJson('/api/google-calendar/calendars', [
            'selected_calendar_ids' => ['primary', 'family'],
            'default_calendar_id' => 'primary',
        ])
            ->assertStatus(402);

        $this->withToken($premiumToken)->patchJson('/api/auth/me', [
            'notification_preferences' => ['reminder_email' => true],
        ])
            ->assertOk()
            ->assertJsonPath('data.notification_preferences.reminder_email', true);

        $this->withToken($baseToken)->patchJson('/api/auth/me', [
            'notification_preferences' => ['reminder_email' => true],
        ])
            ->assertStatus(402);
    }

    public function test_base_plan_allows_ten_notes_then_requires_upgrade(): void
    {
        $baseToken = $this->apiToken('base-notes@example.com');
        $premiumToken = $this->apiToken('premium-notes@example.com');
        $baseUser = User::where('email', 'base-notes@example.com')->firstOrFail();
        $baseWorkspace = Workspace::where('personal_owner_user_id', $baseUser->id)->firstOrFail();
        $premiumUser = User::where('email', 'premium-notes@example.com')->firstOrFail();
        $premiumUser->forceFill(['subscription_tier' => 'premium'])->save();
        $premiumWorkspace = Workspace::where('personal_owner_user_id', $premiumUser->id)->firstOrFail();

        $this->withToken($baseToken)->getJson('/api/notes')
            ->assertOk()
            ->assertJsonCount(0, 'data');

        $baseFolder = $this->withToken($baseToken)->postJson('/api/note-folders', [
            'name' => 'Projects',
        ])
            ->assertCreated()
            ->json('data');

        for ($i = 1; $i <= 10; $i++) {
            $this->withToken($baseToken)->postJson('/api/notes', [
                'title' => "Base note {$i}",
                'body_markdown' => 'Base users can write up to ten notes.',
                'note_folder_id' => $baseFolder['id'],
            ])
                ->assertCreated()
                ->assertJsonPath('data.title', "Base note {$i}");
        }

        $this->withToken($baseToken)->postJson('/api/notes', [
            'title' => 'Eleventh note',
            'body_markdown' => 'Base users need to upgrade after ten notes.',
            'note_folder_id' => $baseFolder['id'],
        ])
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'subscription_limit_reached');

        $folder = $this->withToken($premiumToken)->postJson('/api/note-folders', [
            'name' => 'Projects',
        ])
            ->assertCreated()
            ->json('data');

        $this->withToken($premiumToken)->postJson('/api/notes', [
            'title' => 'Premium note',
            'body_markdown' => 'Premium users can write notes.',
            'note_folder_id' => $folder['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Premium note');

        $this->assertDatabaseHas('note_folders', [
            'user_id' => $baseUser->id,
            'workspace_id' => $baseWorkspace->id,
            'name' => 'Projects',
        ]);
        $this->assertDatabaseHas('note_folders', [
            'user_id' => $premiumUser->id,
            'workspace_id' => $premiumWorkspace->id,
            'name' => 'Projects',
        ]);
        $this->assertSame(10, Note::where('user_id', $baseUser->id)->count());
        $this->assertSame(1, NoteFolder::where('user_id', $baseUser->id)->count());
        $this->assertSame(1, Note::where('user_id', $premiumUser->id)->count());
        $this->assertSame(1, NoteFolder::where('user_id', $premiumUser->id)->count());
    }

    public function test_admin_accounts_bypass_all_plan_limits(): void
    {
        $token = $this->apiToken('unlimited-admin@example.com');
        $admin = User::where('email', 'unlimited-admin@example.com')->firstOrFail();
        $admin->forceFill([
            'is_admin' => true,
            'subscription_tier' => 'base',
            'subscription_status' => null,
        ])->save();

        $limits = app(PlanLimitService::class)->publicLimitsFor($admin->refresh());

        $this->assertSame('admin', $limits['tier']);
        $this->assertNull($limits['workspace_limit']);
        $this->assertNull($limits['calendar_connection_limit']);
        $this->assertNull($limits['connected_account_limit']);
        $this->assertNull($limits['history_days']);
        $this->assertNull($limits['note_limit']);
        $this->assertTrue($limits['recurring_tasks_enabled']);
        $this->assertTrue($limits['recurring_reminders_enabled']);
        $this->assertTrue($limits['recurring_calendar_enabled']);
        $this->assertTrue($limits['email_reminders_enabled']);
        $this->assertTrue($limits['notes_enabled']);
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.is_admin', true)
            ->assertJsonPath('data.plan_limits.tier', 'admin')
            ->assertJsonPath('data.plan_limits.notes_enabled', true)
            ->assertJsonPath('data.plan_limits.recurring_tasks_enabled', true)
            ->assertJsonPath('data.plan_limits.recurring_reminders_enabled', true)
            ->assertJsonPath('data.plan_limits.recurring_calendar_enabled', true)
            ->assertJsonPath('data.plan_limits.email_reminders_enabled', true);

        $this->withToken($token)->postJson('/api/workspaces', ['name' => 'Admin Home'])
            ->assertCreated();
        $this->withToken($token)->postJson('/api/workspaces', ['name' => 'Admin Work'])
            ->assertCreated();
        $this->withToken($token)->postJson('/api/workspaces', ['name' => 'Admin Projects'])
            ->assertCreated();

        $this->withToken($token)->patchJson('/api/auth/me', [
            'notification_preferences' => ['reminder_email' => true],
        ])
            ->assertOk()
            ->assertJsonPath('data.notification_preferences.reminder_email', true);

        $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Admin recurring task',
            'type' => 'todo',
            'metadata' => ['recurrence' => 'weekly'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.metadata.recurrence', 'weekly');

        $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'Admin recurring reminder',
            'remind_at' => now()->addHour()->toIso8601String(),
            'metadata' => ['recurrence' => 'daily'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.metadata.recurrence', 'daily');

        $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Admin recurring calendar',
            'all_day' => false,
            'starts_at' => now()->addDay()->toIso8601String(),
            'ends_at' => now()->addDay()->addHour()->toIso8601String(),
            'recurrence' => 'weekly',
        ])
            ->assertCreated()
            ->assertJsonPath('data.recurrence', 'weekly');

        $folder = $this->withToken($token)->postJson('/api/note-folders', [
            'name' => 'Admin Notes',
        ])
            ->assertCreated()
            ->json('data');

        $this->withToken($token)->postJson('/api/notes', [
            'title' => 'Admin note',
            'body_markdown' => 'Admin accounts can use Notes without a paid plan.',
            'note_folder_id' => $folder['id'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.title', 'Admin note');
    }

    public function test_base_plan_history_filtering_and_pruning_preserve_active_and_future_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-18 12:00:00'));

        try {
            $token = $this->apiToken('history-prune@example.com');
            $user = User::where('email', 'history-prune@example.com')->firstOrFail();
            $workspace = Workspace::where('personal_owner_user_id', $user->id)->firstOrFail();
            $old = now()->subDays(20);
            $recent = now()->subDays(7);
            $future = now()->addDays(10);

            $oldCompletedTask = Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old completed task',
                'status' => 'completed',
                'completed_at' => $old,
                'updated_at' => $old,
            ]);
            $recentCompletedTask = Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Recent completed task',
                'status' => 'completed',
                'completed_at' => $recent,
                'updated_at' => $recent,
            ]);
            $oldOpenTask = Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old open task',
                'status' => 'open',
                'due_at' => $old,
                'updated_at' => $old,
            ]);

            $oldCompletedReminder = Reminder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old completed reminder',
                'status' => 'completed',
                'remind_at' => $old,
            ]);
            $oldScheduledReminder = Reminder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old scheduled reminder',
                'status' => 'scheduled',
                'remind_at' => $old,
            ]);
            $recentCompletedReminder = Reminder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Recent completed reminder',
                'status' => 'completed',
                'remind_at' => $recent,
            ]);

            $oldSingleEvent = CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old single event',
                'starts_at' => $old,
                'ends_at' => $old->copy()->addHour(),
            ]);
            $oldGeneratedOccurrence = CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old generated occurrence',
                'starts_at' => $old,
                'ends_at' => $old->copy()->addHour(),
                'metadata' => ['recurrence_generated' => true, 'recurrence_parent_event_id' => 999],
            ]);
            $ongoingRecurringEvent = CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Ongoing monthly event',
                'starts_at' => now()->subDays(60),
                'ends_at' => now()->subDays(60)->addHour(),
                'recurrence' => 'monthly',
            ]);
            $futureEvent = CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Future event',
                'starts_at' => $future,
                'ends_at' => $future->copy()->addHour(),
            ]);

            WorkspaceItemLink::create([
                'source_workspace_id' => $workspace->id,
                'target_workspace_id' => $workspace->id,
                'source_type' => 'tasks',
                'source_id' => $oldCompletedTask->id,
                'target_type' => 'calendar_events',
                'target_id' => $futureEvent->id,
                'link_type' => 'sync',
            ]);

            $oldDashboardChange = DashboardChange::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'resource_type' => 'task',
                'action' => 'updated',
                'resource_id' => $oldCompletedTask->id,
                'created_at' => $old,
                'updated_at' => $old,
            ]);
            $this->stampModel($oldDashboardChange, $old);

            $this->withToken($token)->getJson('/api/tasks/past')
                ->assertOk()
                ->assertJsonMissing(['title' => 'Old completed task'])
                ->assertJsonFragment(['title' => 'Recent completed task']);

            $this->withToken($token)->getJson('/api/reminders')
                ->assertOk()
                ->assertJsonMissing(['title' => 'Old completed reminder'])
                ->assertJsonFragment(['title' => 'Old scheduled reminder'])
                ->assertJsonFragment(['title' => 'Recent completed reminder']);

            $this->withToken($token)->getJson('/api/calendar-events')
                ->assertOk()
                ->assertJsonMissing(['title' => 'Old single event'])
                ->assertJsonMissing(['title' => 'Old generated occurrence'])
                ->assertJsonFragment(['title' => 'Ongoing monthly event'])
                ->assertJsonFragment(['title' => 'Future event']);

            $this->artisan('plan-history:prune')->assertExitCode(0);

            $this->assertDatabaseMissing('tasks', ['id' => $oldCompletedTask->id]);
            $this->assertDatabaseHas('tasks', ['id' => $recentCompletedTask->id]);
            $this->assertDatabaseHas('tasks', ['id' => $oldOpenTask->id]);
            $this->assertDatabaseMissing('reminders', ['id' => $oldCompletedReminder->id]);
            $this->assertDatabaseHas('reminders', ['id' => $oldScheduledReminder->id]);
            $this->assertDatabaseHas('reminders', ['id' => $recentCompletedReminder->id]);
            $this->assertDatabaseMissing('calendar_events', ['id' => $oldSingleEvent->id]);
            $this->assertDatabaseMissing('calendar_events', ['id' => $oldGeneratedOccurrence->id]);
            $this->assertDatabaseHas('calendar_events', ['id' => $ongoingRecurringEvent->id]);
            $this->assertDatabaseHas('calendar_events', ['id' => $futureEvent->id]);
            $this->assertDatabaseMissing('dashboard_changes', ['id' => $oldDashboardChange->id]);
            $this->assertDatabaseMissing('workspace_item_links', ['source_type' => 'tasks', 'source_id' => $oldCompletedTask->id]);
        } finally {
            Carbon::setTestNow();
        }
    }

    private function limits(array $overrides = []): array
    {
        return [
            'workspace_limit' => 2,
            'calendar_connection_limit' => 1,
            'connected_account_limit' => 1,
            'history_days' => 14,
            'note_limit' => 10,
            'recurring_tasks_enabled' => false,
            'recurring_reminders_enabled' => false,
            'recurring_calendar_enabled' => false,
            'email_reminders_enabled' => false,
            'notes_enabled' => true,
            ...$overrides,
        ];
    }

    private function stampModel($model, Carbon $timestamp): void
    {
        $model->forceFill([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ])->saveQuietly();
    }
}
