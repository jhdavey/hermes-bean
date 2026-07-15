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
            'model_settings.external_lookup_model' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9._:\/-]+$/'],
            'kill_switches' => ['required', 'array'],
            'kill_switches.bean_chat_enabled' => ['required', 'boolean'],
            'usage_limits' => ['sometimes', 'array'],
            'usage_limits.base_cost_limit' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.base_external_cost_limit' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.premium_cost_limit' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.premium_external_cost_limit' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.pro_cost_limit' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
            'usage_limits.pro_external_cost_limit' => ['sometimes', 'numeric', 'min:0', 'max:100000'],
        ]);

        $modelErrors = $registry->validationErrors($data['model_settings']);
        if ($modelErrors !== []) {
            throw ValidationException::withMessages($modelErrors);
        }

        $payload = $settings->update(
            $data['model_settings'],
            $data['usage_limits'] ?? [],
            $request->user(),
            $data['kill_switches'],
        );

        return response()->json(['data' => $payload]);
    }
}
