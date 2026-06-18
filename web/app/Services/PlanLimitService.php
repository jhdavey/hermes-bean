<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\EnterpriseCustomerLimit;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;

class PlanLimitService
{
    public const LIMIT_KEYS = [
        'workspace_limit',
        'calendar_connection_limit',
        'connected_account_limit',
        'history_days',
        'daily_cost_limit',
        'daily_external_cost_limit',
        'recurring_tasks_enabled',
        'recurring_reminders_enabled',
        'recurring_calendar_enabled',
        'email_reminders_enabled',
        'priority_background_work',
    ];

    public const PLAN_KEYS = ['base', 'premium', 'pro'];

    public function payload(): array
    {
        return [
            'plans' => collect(self::PLAN_KEYS)
                ->mapWithKeys(fn (string $plan): array => [$plan => [
                    'key' => $plan,
                    'value' => $this->planLimits($plan),
                    'default' => $this->defaultPlanLimits($plan),
                    'is_overridden' => AdminSetting::where('key', $this->settingKey($plan))->exists(),
                ]])
                ->all(),
            'enterprise_customers' => EnterpriseCustomerLimit::query()
                ->with('user:id,name,email,subscription_tier,is_admin')
                ->latest('updated_at')
                ->get()
                ->map(fn (EnterpriseCustomerLimit $customer): array => $this->enterprisePayload($customer))
                ->values()
                ->all(),
        ];
    }

    public function updatePlans(array $plans, ?User $actor = null): array
    {
        foreach (self::PLAN_KEYS as $plan) {
            if (! array_key_exists($plan, $plans)) {
                continue;
            }

            $limits = $this->normalizeLimits((array) $plans[$plan], $this->defaultPlanLimits($plan));
            AdminSetting::updateOrCreate(
                ['key' => $this->settingKey($plan)],
                [
                    'value' => ['value' => $limits],
                    'type' => 'json',
                    'updated_by_user_id' => $actor?->id,
                ],
            );

            foreach ($this->usageSettingMap($plan) as $adminSettingKey => $limitKey) {
                AdminSetting::updateOrCreate(
                    ['key' => $adminSettingKey],
                    [
                        'value' => ['value' => (float) ($limits[$limitKey] ?? 0)],
                        'type' => 'float',
                        'updated_by_user_id' => $actor?->id,
                    ],
                );
            }
        }

        return $this->payload();
    }

    public function upsertEnterpriseCustomer(array $data, ?User $actor = null, ?EnterpriseCustomerLimit $existing = null): EnterpriseCustomerLimit
    {
        $user = User::findOrFail((int) $data['user_id']);
        $limits = $this->normalizeLimits((array) ($data['limits'] ?? []), $this->enterpriseDefaults());
        $customer = $existing ?: EnterpriseCustomerLimit::firstOrNew(['user_id' => $user->id]);
        $customer->fill([
            'user_id' => $user->id,
            'billing_type' => $data['billing_type'] ?? 'monthly',
            'monthly_rate_usd' => $data['monthly_rate_usd'] ?? null,
            'usage_rate_usd' => $data['usage_rate_usd'] ?? null,
            'limits' => $limits,
            'notes' => $data['notes'] ?? null,
            'updated_by_user_id' => $actor?->id,
        ])->save();

        $user->forceFill(['subscription_tier' => 'enterprise'])->save();

        return $customer->refresh();
    }

    public function limitsFor(User $user): array
    {
        if ($user->isAdmin()) {
            return $this->adminLimits();
        }

        $tier = $user->subscriptionTier();
        if ($tier === 'enterprise') {
            $customer = EnterpriseCustomerLimit::where('user_id', $user->id)->first();
            $limits = $this->normalizeLimits((array) ($customer?->limits ?? []), $this->enterpriseDefaults());

            return [
                ...$limits,
                'tier' => 'enterprise',
                'billing_type' => $customer?->billing_type,
                'monthly_rate_usd' => $customer?->monthly_rate_usd,
                'usage_rate_usd' => $customer?->usage_rate_usd,
            ];
        }

        $tier = in_array($tier, self::PLAN_KEYS, true) ? $tier : 'base';

        return [
            ...$this->planLimits($tier),
            'tier' => $tier,
        ];
    }

    public function budgetFor(User $user): array
    {
        $limits = $this->limitsFor($user);

        return [
            'tier' => $limits['tier'],
            'daily_cost_limit' => array_key_exists('daily_cost_limit', $limits) ? $limits['daily_cost_limit'] : 0.0,
            'daily_external_cost_limit' => array_key_exists('daily_external_cost_limit', $limits) ? $limits['daily_external_cost_limit'] : 0.0,
        ];
    }

    public function historyDaysFor(User $user): ?int
    {
        $days = $this->limitsFor($user)['history_days'] ?? null;

        return is_numeric($days) && (int) $days > 0 ? (int) $days : null;
    }

    public function historyCutoffFor(User $user): ?Carbon
    {
        $days = $this->historyDaysFor($user);

        return $days === null ? null : now()->subDays($days)->startOfDay();
    }

    public function publicLimitsFor(User $user): array
    {
        $limits = $this->limitsFor($user);

        return [
            'tier' => $limits['tier'] ?? $user->subscriptionTier(),
            'workspace_limit' => $limits['workspace_limit'] ?? null,
            'calendar_connection_limit' => $limits['calendar_connection_limit'] ?? null,
            'connected_account_limit' => $limits['connected_account_limit'] ?? null,
            'history_days' => $limits['history_days'] ?? null,
            'history_cutoff' => $this->historyCutoffFor($user)?->toIso8601String(),
            'recurring_tasks_enabled' => (bool) ($limits['recurring_tasks_enabled'] ?? false),
            'recurring_reminders_enabled' => (bool) ($limits['recurring_reminders_enabled'] ?? false),
            'recurring_calendar_enabled' => (bool) ($limits['recurring_calendar_enabled'] ?? false),
            'email_reminders_enabled' => (bool) ($limits['email_reminders_enabled'] ?? false),
            'priority_background_work' => (bool) ($limits['priority_background_work'] ?? false),
        ];
    }

    public function canUseEmailReminders(User $user): bool
    {
        return (bool) ($this->limitsFor($user)['email_reminders_enabled'] ?? false);
    }

    public function canUseRecurringTasks(User $user): bool
    {
        return (bool) ($this->limitsFor($user)['recurring_tasks_enabled'] ?? false);
    }

    public function canUseRecurringReminders(User $user): bool
    {
        return (bool) ($this->limitsFor($user)['recurring_reminders_enabled'] ?? false);
    }

    public function canUseRecurringCalendar(User $user): bool
    {
        return (bool) ($this->limitsFor($user)['recurring_calendar_enabled'] ?? false);
    }

    public function enforceWorkspaceLimit(User $user, int $currentWorkspaceCount): ?JsonResponse
    {
        $limit = $this->limitsFor($user)['workspace_limit'] ?? null;
        if ($limit !== null && $currentWorkspaceCount >= (int) $limit) {
            return $this->limitResponse("Your current plan includes up to {$limit} workspace".((int) $limit === 1 ? '' : 's').'.');
        }

        return null;
    }

    public function enforceCalendarSelectionLimit(User $user, int $selectedCalendarCount): ?JsonResponse
    {
        $limit = $this->limitsFor($user)['calendar_connection_limit'] ?? null;
        if ($limit !== null && $selectedCalendarCount > (int) $limit) {
            return $this->limitResponse("Your current plan includes up to {$limit} connected calendar".((int) $limit === 1 ? '' : 's').'.');
        }

        return null;
    }

    public function enforceConnectedAccountLimit(User $user, int $currentAccountCount): ?JsonResponse
    {
        $limit = $this->limitsFor($user)['connected_account_limit'] ?? null;
        if ($limit !== null && $currentAccountCount >= (int) $limit) {
            return $this->limitResponse("Your current plan includes up to {$limit} connected account".((int) $limit === 1 ? '' : 's').'.');
        }

        return null;
    }

    public function limitResponse(string $message): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => 'subscription_limit_reached',
                'message' => $message,
                'cta_label' => 'View plans',
                'upgrade_url' => url('/pricing'),
            ],
        ], 402);
    }

    public function planLimits(string $plan): array
    {
        $default = $this->defaultPlanLimits($plan);
        $stored = AdminSetting::where('key', $this->settingKey($plan))->first();
        $storedLimits = $stored instanceof AdminSetting ? (array) data_get($stored->value, 'value', []) : [];

        return $this->normalizeLimits($storedLimits, $default);
    }

    public function normalizeLimits(array $limits, array $fallback): array
    {
        $normalized = $fallback;
        foreach (self::LIMIT_KEYS as $key) {
            if (! array_key_exists($key, $limits)) {
                continue;
            }

            $value = $limits[$key];
            if (str_ends_with($key, '_enabled') || $key === 'priority_background_work') {
                $normalized[$key] = (bool) $value;

                continue;
            }

            if ($value === null || $value === '') {
                $normalized[$key] = null;

                continue;
            }

            $normalized[$key] = in_array($key, ['daily_cost_limit', 'daily_external_cost_limit'], true)
                ? max(0, (float) $value)
                : max(0, (int) $value);
        }

        return $normalized;
    }

    public function defaultPlanLimits(string $plan): array
    {
        $usageLimits = (array) config('services.ai_usage.limits', []);

        return match ($plan) {
            'premium' => [
                'workspace_limit' => 5,
                'calendar_connection_limit' => 5,
                'connected_account_limit' => 3,
                'history_days' => 365,
                'daily_cost_limit' => $this->usageLimitValue('premium', 'cost_limit', (float) ($usageLimits['premium_cost_limit'] ?? 5.00)),
                'daily_external_cost_limit' => $this->usageLimitValue('premium', 'external_cost_limit', (float) ($usageLimits['premium_external_cost_limit'] ?? 1.00)),
                'recurring_tasks_enabled' => true,
                'recurring_reminders_enabled' => true,
                'recurring_calendar_enabled' => true,
                'email_reminders_enabled' => true,
                'priority_background_work' => false,
            ],
            'pro' => [
                'workspace_limit' => null,
                'calendar_connection_limit' => null,
                'connected_account_limit' => null,
                'history_days' => null,
                'daily_cost_limit' => $this->usageLimitValue('pro', 'cost_limit', (float) ($usageLimits['pro_cost_limit'] ?? 20.00)),
                'daily_external_cost_limit' => $this->usageLimitValue('pro', 'external_cost_limit', (float) ($usageLimits['pro_external_cost_limit'] ?? 5.00)),
                'recurring_tasks_enabled' => true,
                'recurring_reminders_enabled' => true,
                'recurring_calendar_enabled' => true,
                'email_reminders_enabled' => true,
                'priority_background_work' => true,
            ],
            default => [
                'workspace_limit' => 2,
                'calendar_connection_limit' => 1,
                'connected_account_limit' => 1,
                'history_days' => 14,
                'daily_cost_limit' => $this->usageLimitValue('base', 'cost_limit', (float) ($usageLimits['base_cost_limit'] ?? 1.00)),
                'daily_external_cost_limit' => $this->usageLimitValue('base', 'external_cost_limit', (float) ($usageLimits['base_external_cost_limit'] ?? 0.25)),
                'recurring_tasks_enabled' => false,
                'recurring_reminders_enabled' => false,
                'recurring_calendar_enabled' => false,
                'email_reminders_enabled' => false,
                'priority_background_work' => false,
            ],
        };
    }

    private function enterpriseDefaults(): array
    {
        return [
            ...$this->defaultPlanLimits('pro'),
            'daily_cost_limit' => null,
            'daily_external_cost_limit' => null,
        ];
    }

    private function adminLimits(): array
    {
        return [
            ...$this->enterpriseDefaults(),
            'tier' => 'admin',
            'workspace_limit' => null,
            'calendar_connection_limit' => null,
            'connected_account_limit' => null,
            'history_days' => null,
            'daily_cost_limit' => null,
            'daily_external_cost_limit' => null,
            'recurring_tasks_enabled' => true,
            'recurring_reminders_enabled' => true,
            'recurring_calendar_enabled' => true,
            'email_reminders_enabled' => true,
            'priority_background_work' => true,
        ];
    }

    private function enterprisePayload(EnterpriseCustomerLimit $customer): array
    {
        return [
            'id' => $customer->id,
            'user_id' => $customer->user_id,
            'user' => $customer->user,
            'billing_type' => $customer->billing_type,
            'monthly_rate_usd' => $customer->monthly_rate_usd,
            'usage_rate_usd' => $customer->usage_rate_usd,
            'limits' => $this->normalizeLimits((array) ($customer->limits ?? []), $this->enterpriseDefaults()),
            'notes' => $customer->notes,
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }

    private function settingKey(string $plan): string
    {
        return 'plan_limits.'.$plan;
    }

    private function usageSettingMap(string $plan): array
    {
        return [
            'usage.'.$plan.'_cost_limit' => 'daily_cost_limit',
            'usage.'.$plan.'_external_cost_limit' => 'daily_external_cost_limit',
        ];
    }

    private function usageLimitValue(string $plan, string $key, float $default): float
    {
        $setting = AdminSetting::where('key', 'usage.'.$plan.'_'.$key)->first();
        $value = $setting instanceof AdminSetting ? data_get($setting->value, 'value') : null;

        return is_numeric($value) ? (float) $value : $default;
    }
}
