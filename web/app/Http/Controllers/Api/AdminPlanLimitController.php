<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EnterpriseCustomerLimit;
use App\Models\User;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class AdminPlanLimitController extends Controller
{
    public function __construct(private readonly PlanLimitService $limits) {}

    public function show(): JsonResponse
    {
        return response()->json(['data' => $this->limits->payload()]);
    }

    public function updatePlans(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plans' => ['required', 'array'],
            'plans.base' => ['sometimes', 'array'],
            'plans.premium' => ['sometimes', 'array'],
            'plans.pro' => ['sometimes', 'array'],
            ...$this->limitRules('plans.base'),
            ...$this->limitRules('plans.premium'),
            ...$this->limitRules('plans.pro'),
        ]);

        return response()->json(['data' => $this->limits->updatePlans($data['plans'], $request->user())]);
    }

    public function storeEnterpriseCustomer(Request $request): JsonResponse
    {
        $data = $this->enterpriseData($request);
        $customer = $this->limits->upsertEnterpriseCustomer($data, $request->user());

        return response()->json(['data' => $this->limits->payload()], 201);
    }

    public function updateEnterpriseCustomer(Request $request, EnterpriseCustomerLimit $enterpriseCustomerLimit): JsonResponse
    {
        $data = $this->enterpriseData($request, $enterpriseCustomerLimit);
        $this->limits->upsertEnterpriseCustomer($data, $request->user(), $enterpriseCustomerLimit);

        return response()->json(['data' => $this->limits->payload()]);
    }

    public function destroyEnterpriseCustomer(EnterpriseCustomerLimit $enterpriseCustomerLimit): JsonResponse
    {
        $user = $enterpriseCustomerLimit->user;
        $enterpriseCustomerLimit->delete();
        if ($user && $user->subscriptionTier() === 'enterprise') {
            $user->forceFill(['subscription_tier' => 'pro'])->save();
        }

        return response()->json(status: 204);
    }

    private function enterpriseData(Request $request, ?EnterpriseCustomerLimit $existing = null): array
    {
        $data = $request->validate([
            'user_id' => [
                'required',
                'integer',
                'exists:users,id',
                Rule::unique('enterprise_customer_limits', 'user_id')->ignore($existing?->id),
            ],
            'billing_type' => ['required', Rule::in(['monthly'])],
            'monthly_rate_usd' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'limits' => ['required', 'array'],
            ...$this->limitRules('limits'),
        ]);

        $data['user_id'] = (int) $data['user_id'];
        if ($data['billing_type'] === 'monthly' && ($data['monthly_rate_usd'] ?? null) === null) {
            $data['monthly_rate_usd'] = 0;
        }
        $data['notes'] = $request->validate(['notes' => ['sometimes', 'nullable', 'string', 'max:5000']])['notes'] ?? null;

        User::findOrFail($data['user_id']);

        return $data;
    }

    private function limitRules(string $prefix): array
    {
        return [
            "{$prefix}.workspace_limit" => ['nullable', 'integer', 'min:0', 'max:1000000'],
            "{$prefix}.calendar_connection_limit" => ['nullable', 'integer', 'min:0', 'max:1000000'],
            "{$prefix}.connected_account_limit" => ['nullable', 'integer', 'min:0', 'max:1000000'],
            "{$prefix}.history_days" => ['nullable', 'integer', 'min:0', 'max:1000000'],
            "{$prefix}.recurring_tasks_enabled" => ['required_with:'.$prefix, 'boolean'],
            "{$prefix}.recurring_reminders_enabled" => ['required_with:'.$prefix, 'boolean'],
            "{$prefix}.recurring_calendar_enabled" => ['required_with:'.$prefix, 'boolean'],
            "{$prefix}.email_reminders_enabled" => ['required_with:'.$prefix, 'boolean'],
        ];
    }
}
