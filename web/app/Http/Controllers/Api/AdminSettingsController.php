<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminModelRegistryService;
use App\Services\AdminSettingsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class AdminSettingsController extends Controller
{
    public function show(AdminSettingsService $settings): JsonResponse
    {
        return response()->json(['data' => $settings->payload()]);
    }

    public function models(AdminModelRegistryService $registry): JsonResponse
    {
        return response()->json(['data' => $registry->payload()]);
    }

    public function update(Request $request, AdminSettingsService $settings, AdminModelRegistryService $registry): JsonResponse
    {
        $data = $request->validate([
            'model_settings' => ['required', 'array'],
            'model_settings.main_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'model_settings.quick_voice_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'model_settings.realtime_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'model_settings.external_lookup_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'kill_switches' => ['required', 'array'],
            'kill_switches.bean_chat_enabled' => ['required', 'boolean'],
            'kill_switches.bean_voice_enabled' => ['required', 'boolean'],
            'usage_limits' => ['required', 'array'],
            'usage_limits.base_cost_limit' => ['required', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.base_external_cost_limit' => ['required', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.premium_cost_limit' => ['required', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.premium_external_cost_limit' => ['required', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.pro_cost_limit' => ['required', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.pro_external_cost_limit' => ['required', 'numeric', 'min:0', 'max:100000'],
            'apply_main_model_to_profiles' => ['sometimes', 'boolean'],
        ]);

        $modelErrors = $registry->validationErrors($data['model_settings']);
        if ($modelErrors !== []) {
            throw ValidationException::withMessages($modelErrors);
        }

        $payload = $settings->update(
            $data['model_settings'],
            $data['usage_limits'],
            $request->user(),
            (bool) ($data['apply_main_model_to_profiles'] ?? false),
            $data['kill_switches'],
        );

        return response()->json(['data' => $payload]);
    }
}
