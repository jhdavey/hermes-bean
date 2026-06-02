<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminSettingsController extends Controller
{
    public function show(AdminSettingsService $settings): JsonResponse
    {
        return response()->json(['data' => $settings->payload()]);
    }

    public function update(Request $request, AdminSettingsService $settings): JsonResponse
    {
        $data = $request->validate([
            'model_settings' => ['required', 'array'],
            'model_settings.main_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'model_settings.quick_voice_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'model_settings.realtime_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'model_settings.external_lookup_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'beta_limits' => ['required', 'array'],
            'beta_limits.api_per_minute' => ['required', 'integer', 'min:1', 'max:10000'],
            'beta_limits.monthly_ai_actions' => ['required', 'integer', 'min:1', 'max:1000000'],
            'beta_limits.monthly_tokens' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'beta_limits.monthly_cost_usd' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'beta_limits.daily_soft_tokens' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'beta_limits.daily_hard_tokens' => ['required', 'integer', 'min:1000', 'max:1000000000'],
            'beta_limits.daily_soft_cost_usd' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'beta_limits.daily_hard_cost_usd' => ['required', 'numeric', 'min:0.01', 'max:100000'],
            'apply_main_model_to_profiles' => ['sometimes', 'boolean'],
        ]);

        $payload = $settings->update(
            $data['model_settings'],
            $data['beta_limits'],
            $request->user(),
            (bool) ($data['apply_main_model_to_profiles'] ?? false),
        );

        return response()->json(['data' => $payload]);
    }
}
