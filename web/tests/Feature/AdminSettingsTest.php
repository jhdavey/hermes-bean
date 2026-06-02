<?php

namespace Tests\Feature;

use App\Models\AdminSetting;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AiUsageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_runtime_models_and_beta_limits(): void
    {
        $adminToken = $this->apiToken('settings-admin@example.com');
        $userToken = $this->apiToken('settings-user@example.com');
        $admin = User::where('email', 'settings-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();

        $this->withToken($userToken)->patchJson('/api/admin/settings', $this->settingsPayload())
            ->assertForbidden();

        $this->withToken($adminToken)->patchJson('/api/admin/settings', $this->settingsPayload([
            'main_model' => 'gpt-5.4',
            'quick_voice_model' => 'gpt-5.4-mini',
            'realtime_model' => 'gpt-realtime',
            'external_lookup_model' => 'gpt-5-mini',
        ], [
            'api_per_minute' => 12,
            'monthly_ai_actions' => 25,
        ], true))->assertOk()
            ->assertJsonPath('data.models.main_model.value', 'gpt-5.4')
            ->assertJsonPath('data.beta_limits.api_per_minute.value', 12)
            ->assertJsonPath('data.beta_limits.monthly_ai_actions.value', 25);

        $this->assertDatabaseHas('admin_settings', [
            'key' => 'models.main',
            'updated_by_user_id' => $admin->id,
        ]);
        $this->assertSame('gpt-5.4', AdminSetting::where('key', 'models.main')->firstOrFail()->value['value']);
        $this->assertTrue(AgentProfile::query()->where('model', 'gpt-5.4')->exists());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.settings.models.main_model.value', 'gpt-5.4')
            ->assertJsonPath('data.settings.beta_limits.api_per_minute.value', 12);
    }

    public function test_beta_users_use_admin_beta_budget_settings(): void
    {
        $adminToken = $this->apiToken('budget-settings-admin@example.com');
        $betaToken = $this->apiToken('budget-settings-beta@example.com');
        User::where('email', 'budget-settings-admin@example.com')->firstOrFail()->forceFill(['is_admin' => true])->save();
        $betaUser = User::where('email', 'budget-settings-beta@example.com')->firstOrFail();

        $this->withToken($adminToken)->patchJson('/api/admin/settings', $this->settingsPayload([], [
            'monthly_ai_actions' => 9,
            'monthly_tokens' => 123000,
            'daily_hard_cost_usd' => 2.75,
        ]))->assertOk();

        $budget = app(AiUsageService::class)->budgetFor($betaUser);

        $this->assertSame(9, $budget['monthly_ai_actions']);
        $this->assertSame(123000, $budget['monthly_tokens']);
        $this->assertSame(2.75, $budget['daily_hard_cost_usd']);

        $this->withToken($betaToken)->getJson('/api/auth/me')->assertOk();
    }

    private function settingsPayload(array $models = [], array $betaLimits = [], bool $apply = false): array
    {
        return [
            'model_settings' => [
                'main_model' => $models['main_model'] ?? 'gpt-5.5',
                'quick_voice_model' => $models['quick_voice_model'] ?? 'gpt-5.4-mini',
                'realtime_model' => $models['realtime_model'] ?? 'gpt-realtime',
                'external_lookup_model' => $models['external_lookup_model'] ?? 'gpt-5-mini',
            ],
            'beta_limits' => [
                'api_per_minute' => $betaLimits['api_per_minute'] ?? 60,
                'monthly_ai_actions' => $betaLimits['monthly_ai_actions'] ?? 50,
                'monthly_tokens' => $betaLimits['monthly_tokens'] ?? 1_000_000,
                'monthly_cost_usd' => $betaLimits['monthly_cost_usd'] ?? 5.00,
                'daily_soft_tokens' => $betaLimits['daily_soft_tokens'] ?? 60_000,
                'daily_hard_tokens' => $betaLimits['daily_hard_tokens'] ?? 180_000,
                'daily_soft_cost_usd' => $betaLimits['daily_soft_cost_usd'] ?? 0.35,
                'daily_hard_cost_usd' => $betaLimits['daily_hard_cost_usd'] ?? 1.00,
            ],
            'apply_main_model_to_profiles' => $apply,
        ];
    }
}
