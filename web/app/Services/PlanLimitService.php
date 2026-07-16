<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\EnterpriseCustomerLimit;
use App\Models\Note;
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
        'note_limit',
        'recurring_tasks_enabled',
        'recurring_reminders_enabled',
        'recurring_calendar_enabled',
        'email_reminders_enabled',
        'notes_enabled',
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
            ];
        }

        $tier = in_array($tier, self::PLAN_KEYS, true) ? $tier : 'base';

        return [
            ...$this->planLimits($tier),
            'tier' => $tier,
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
            'note_limit' => $limits['note_limit'] ?? null,
            'recurring_tasks_enabled' => (bool) ($limits['recurring_tasks_enabled'] ?? false),
            'recurring_reminders_enabled' => (bool) ($limits['recurring_reminders_enabled'] ?? false),
            'recurring_calendar_enabled' => (bool) ($limits['recurring_calendar_enabled'] ?? false),
            'email_reminders_enabled' => (bool) ($limits['email_reminders_enabled'] ?? false),
            'notes_enabled' => $this->notesAllowedByLimits($limits),
        ];
    }

    public function canUseEmailReminders(User $user): bool
    {
        return (bool) ($this->limitsFor($user)['email_reminders_enabled'] ?? false);
    }

    public function canUseNotes(User $user): bool
    {
        $limits = $this->limitsFor($user);
        $noteLimit = $limits['note_limit'] ?? null;

        return (bool) ($limits['notes_enabled'] ?? false)
            || $this->notesAllowedByLimitValue($noteLimit);
    }

    public function noteLimitFor(User $user): ?int
    {
        $limit = $this->limitsFor($user)['note_limit'] ?? null;

        return is_numeric($limit) ? (int) $limit : null;
    }

    public function enforceNoteCreationLimit(User $user, int $additionalNotes = 1): ?JsonResponse
    {
        $message = $this->noteCreationLimitMessage($user, $additionalNotes);

        return $message === null ? null : $this->limitResponse($message);
    }

    public function noteCreationLimitMessage(User $user, int $additionalNotes = 1): ?string
    {
        if (! $this->canUseNotes($user)) {
            return 'Notes are available on this plan after upgrading.';
        }

        $limit = $this->noteLimitFor($user);
        if ($limit === null) {
            return null;
        }

        $currentNotes = Note::query()->where('user_id', $user->id)->count();

        return $currentNotes + max(0, $additionalNotes) > $limit
            ? "Your current plan includes up to {$limit} note".($limit === 1 ? '' : 's').'.'
            : null;
    }

    public function noteCreationUpgradeMessage(User $user, int $additionalNotes = 1): ?string
    {
        $message = $this->noteCreationLimitMessage($user, $additionalNotes);
        if ($message === null || str_contains(mb_strtolower($message), 'upgrad')) {
            return $message;
        }

        return rtrim($message, '.').'. Upgrade your plan to create and manage more notes.';
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

    public function limitResponse(string $message, array $context = []): JsonResponse
    {
        return response()->json([
            'message' => $message,
            'error' => [
                'code' => 'subscription_limit_reached',
                'message' => $message,
                'cta_label' => 'View plans',
                'upgrade_url' => url('/pricing'),
                ...$context,
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
            if (str_ends_with($key, '_enabled')) {
                $normalized[$key] = (bool) $value;

                continue;
            }

            if ($value === null || $value === '') {
                $normalized[$key] = null;

                continue;
            }

            $normalized[$key] = max(0, (int) $value);
        }

        return $normalized;
    }

    public function defaultPlanLimits(string $plan): array
    {
        return match ($plan) {
            'premium' => [
                'workspace_limit' => 5,
                'calendar_connection_limit' => 5,
                'connected_account_limit' => 3,
                'history_days' => 365,
                'note_limit' => null,
                'recurring_tasks_enabled' => true,
                'recurring_reminders_enabled' => true,
                'recurring_calendar_enabled' => true,
                'email_reminders_enabled' => true,
                'notes_enabled' => true,
            ],
            'pro' => [
                'workspace_limit' => null,
                'calendar_connection_limit' => null,
                'connected_account_limit' => null,
                'history_days' => null,
                'note_limit' => null,
                'recurring_tasks_enabled' => true,
                'recurring_reminders_enabled' => true,
                'recurring_calendar_enabled' => true,
                'email_reminders_enabled' => true,
                'notes_enabled' => true,
            ],
            default => [
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
            ],
        };
    }

    private function enterpriseDefaults(): array
    {
        return $this->defaultPlanLimits('pro');
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
            'note_limit' => null,
            'recurring_tasks_enabled' => true,
            'recurring_reminders_enabled' => true,
            'recurring_calendar_enabled' => true,
            'email_reminders_enabled' => true,
            'notes_enabled' => true,
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
            'limits' => $this->normalizeLimits((array) ($customer->limits ?? []), $this->enterpriseDefaults()),
            'notes' => $customer->notes,
            'updated_at' => $customer->updated_at?->toIso8601String(),
        ];
    }

    private function settingKey(string $plan): string
    {
        return 'plan_limits.'.$plan;
    }

    private function notesAllowedByLimits(array $limits): bool
    {
        return (bool) ($limits['notes_enabled'] ?? false)
            || $this->notesAllowedByLimitValue($limits['note_limit'] ?? null);
    }

    private function notesAllowedByLimitValue(mixed $noteLimit): bool
    {
        return $noteLimit === null || (is_numeric($noteLimit) && (int) $noteLimit > 0);
    }

}
