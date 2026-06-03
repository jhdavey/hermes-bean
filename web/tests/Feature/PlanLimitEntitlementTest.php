<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\EnterpriseCustomerLimit;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
}
