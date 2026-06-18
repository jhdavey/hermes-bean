<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\DashboardChange;
use App\Models\EnterpriseCustomerLimit;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceItemLink;
use App\Services\AiUsageService;
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
                'base' => $this->limits(['workspace_limit' => 3, 'daily_cost_limit' => 2.5]),
                'premium' => $this->limits(['workspace_limit' => 8, 'email_reminders_enabled' => true]),
                'pro' => $this->limits(['workspace_limit' => null, 'priority_background_work' => true]),
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.plans.base.value.workspace_limit', 3)
            ->assertJsonPath('data.plans.base.value.daily_cost_limit', 2.5);

        $this->assertDatabaseHas('admin_settings', ['key' => 'plan_limits.base']);
        $this->assertSame(2.5, AdminSetting::where('key', 'usage.base_cost_limit')->firstOrFail()->value['value']);

        $this->withToken($adminToken)->postJson('/api/admin/plan-limits/enterprise-customers', [
            'user_id' => $customer->id,
            'billing_type' => 'usage',
            'monthly_rate_usd' => null,
            'usage_rate_usd' => 0.18,
            'notes' => 'Pilot agreement',
            'limits' => $this->limits([
                'workspace_limit' => 42,
                'daily_cost_limit' => 99,
                'daily_external_cost_limit' => 12,
            ]),
        ])
            ->assertCreated()
            ->assertJsonPath('data.enterprise_customers.0.user_id', $customer->id)
            ->assertJsonPath('data.enterprise_customers.0.billing_type', 'usage')
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

    public function test_enterprise_customer_limits_drive_ai_budget_and_admins_have_unlimited_usage(): void
    {
        $enterprise = User::factory()->create(['subscription_tier' => 'enterprise']);
        EnterpriseCustomerLimit::create([
            'user_id' => $enterprise->id,
            'billing_type' => 'monthly',
            'monthly_rate_usd' => 500,
            'limits' => $this->limits(['daily_cost_limit' => 50, 'daily_external_cost_limit' => 15]),
        ]);
        $admin = User::factory()->create(['is_admin' => true, 'subscription_tier' => 'base']);
        $usage = app(AiUsageService::class);

        $enterpriseBudget = $usage->budgetFor($enterprise);
        $adminBudget = $usage->budgetFor($admin);

        $this->assertSame('enterprise', $enterpriseBudget['tier']);
        $this->assertSame(50.0, $enterpriseBudget['daily_cost_limit']);
        $this->assertSame('admin', $adminBudget['tier']);
        $this->assertNull($adminBudget['daily_cost_limit']);
        $this->assertTrue($usage->preflightDirect($admin, null, 'gpt-test-tools', 0, 0, 1_000_000, 'text')['allowed']);
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

            $oldSession = ConversationSession::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old chat',
                'last_activity_at' => $old,
                'updated_at' => $old,
            ]);
            $recentSession = ConversationSession::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Recent chat',
                'last_activity_at' => $recent,
                'updated_at' => $recent,
            ]);
            $oldMessage = ConversationMessage::create([
                'user_id' => $user->id,
                'conversation_session_id' => $oldSession->id,
                'role' => 'user',
                'content' => 'Old message',
                'created_at' => $old,
                'updated_at' => $old,
            ]);
            $oldMessageInRecentSession = ConversationMessage::create([
                'user_id' => $user->id,
                'conversation_session_id' => $recentSession->id,
                'role' => 'user',
                'content' => 'Old message in recent session',
                'created_at' => $old,
                'updated_at' => $old,
            ]);
            $recentMessage = ConversationMessage::create([
                'user_id' => $user->id,
                'conversation_session_id' => $recentSession->id,
                'role' => 'assistant',
                'content' => 'Recent message',
                'created_at' => $recent,
                'updated_at' => $recent,
            ]);

            $oldActivity = ActivityEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $recentSession->id,
                'event_type' => 'tool',
                'status' => 'recorded',
                'created_at' => $old,
                'updated_at' => $old,
            ]);
            $recentActivity = ActivityEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $recentSession->id,
                'event_type' => 'tool',
                'status' => 'recorded',
                'created_at' => $recent,
                'updated_at' => $recent,
            ]);

            $oldApprovedApproval = Approval::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old approved approval',
                'status' => 'approved',
                'updated_at' => $old,
                'created_at' => $old,
            ]);
            $oldPendingApproval = Approval::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => 'Old pending approval',
                'status' => 'pending',
                'updated_at' => $old,
                'created_at' => $old,
            ]);
            $oldResolvedBlocker = Blocker::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'reason' => 'Old resolved blocker',
                'status' => 'resolved',
                'updated_at' => $old,
                'created_at' => $old,
            ]);
            $oldOpenBlocker = Blocker::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'reason' => 'Old open blocker',
                'status' => 'open',
                'updated_at' => $old,
                'created_at' => $old,
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

            foreach ([
                $oldSession,
                $oldMessage,
                $oldMessageInRecentSession,
                $oldActivity,
                $oldApprovedApproval,
                $oldPendingApproval,
                $oldResolvedBlocker,
                $oldOpenBlocker,
                $oldDashboardChange,
            ] as $model) {
                $this->stampModel($model, $old);
            }
            foreach ([$recentSession, $recentMessage, $recentActivity] as $model) {
                $this->stampModel($model, $recent);
            }

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

            $this->withToken($token)->getJson('/api/approvals')
                ->assertOk()
                ->assertJsonMissing(['title' => 'Old approved approval'])
                ->assertJsonFragment(['title' => 'Old pending approval']);

            $this->withToken($token)->getJson('/api/blockers')
                ->assertOk()
                ->assertJsonMissing(['reason' => 'Old resolved blocker'])
                ->assertJsonFragment(['reason' => 'Old open blocker']);

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
            $this->assertDatabaseMissing('conversation_sessions', ['id' => $oldSession->id]);
            $this->assertDatabaseMissing('conversation_messages', ['id' => $oldMessage->id]);
            $this->assertDatabaseMissing('conversation_messages', ['id' => $oldMessageInRecentSession->id]);
            $this->assertDatabaseHas('conversation_messages', ['id' => $recentMessage->id]);
            $this->assertDatabaseMissing('activity_events', ['id' => $oldActivity->id]);
            $this->assertDatabaseHas('activity_events', ['id' => $recentActivity->id]);
            $this->assertDatabaseMissing('approvals', ['id' => $oldApprovedApproval->id]);
            $this->assertDatabaseHas('approvals', ['id' => $oldPendingApproval->id]);
            $this->assertDatabaseMissing('blockers', ['id' => $oldResolvedBlocker->id]);
            $this->assertDatabaseHas('blockers', ['id' => $oldOpenBlocker->id]);
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
            'daily_cost_limit' => 1.0,
            'daily_external_cost_limit' => 0.25,
            'recurring_tasks_enabled' => false,
            'recurring_reminders_enabled' => false,
            'recurring_calendar_enabled' => false,
            'email_reminders_enabled' => false,
            'priority_background_work' => false,
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
