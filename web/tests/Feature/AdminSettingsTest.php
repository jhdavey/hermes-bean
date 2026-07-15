<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_external_lookup_model_and_usage_limits(): void
    {
        config()->set('services.hermes_runtime.api_key', '');
        $adminToken = $this->apiToken('settings-admin@example.com');
        $userToken = $this->apiToken('settings-user@example.com');
        $admin = User::where('email', 'settings-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();

        $this->withToken($userToken)->patchJson('/api/admin/settings', $this->settingsPayload())
            ->assertForbidden();

        $this->withToken($adminToken)->patchJson('/api/admin/settings', $this->settingsPayload([
            'external_lookup_model' => 'gpt-5-mini',
        ], [
            'base_cost_limit' => 1.25,
            'base_external_cost_limit' => 0.35,
            'premium_cost_limit' => 6.50,
            'premium_external_cost_limit' => 1.25,
            'pro_cost_limit' => 30.00,
            'pro_external_cost_limit' => 7.50,
        ], [
            'bean_chat_enabled' => false,
        ]))->assertOk()
            ->assertJsonPath('data.models.external_lookup_model.value', 'gpt-5-mini')
            ->assertJsonPath('data.usage_limits.base_cost_limit.value', 1.25)
            ->assertJsonPath('data.usage_limits.base_external_cost_limit.value', 0.35)
            ->assertJsonPath('data.usage_limits.premium_cost_limit.value', 6.5)
            ->assertJsonPath('data.usage_limits.pro_external_cost_limit.value', 7.5)
            ->assertJsonPath('data.kill_switches.bean_chat_enabled.value', false);

        $this->assertDatabaseHas('admin_settings', [
            'key' => 'models.external_lookup',
            'updated_by_user_id' => $admin->id,
        ]);

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.settings.models.external_lookup_model.value', 'gpt-5-mini')
            ->assertJsonPath('data.settings.usage_limits.base_cost_limit.value', 1.25)
            ->assertJsonPath('data.settings.kill_switches.bean_chat_enabled.value', false);
    }

    public function test_admin_model_registry_is_admin_only_and_groups_openai_models(): void
    {
        config()->set('services.hermes_runtime.api_key', 'test-openai-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');

        Http::fake([
            'https://api.openai.test/v1/models' => Http::response([
                'data' => [
                    ['id' => 'gpt-5.2'],
                    ['id' => 'gpt-5-mini'],
                    ['id' => 'gpt-4o-mini-search-preview'],
                    ['id' => 'text-embedding-3-small'],
                ],
            ], 200),
        ]);

        $adminToken = $this->apiToken('models-admin@example.com');
        $userToken = $this->apiToken('models-user@example.com');
        User::where('email', 'models-admin@example.com')->firstOrFail()->forceFill(['is_admin' => true])->save();

        $this->withToken($userToken)->getJson('/api/admin/settings/models')
            ->assertForbidden();

        $this->withToken($adminToken)->getJson('/api/admin/settings/models')
            ->assertOk()
            ->assertJsonPath('data.source', 'openai_and_curated')
            ->assertJsonPath('data.openai_available', true)
            ->assertJsonPath('data.groups.external_lookup_model.label', 'External lookup')
            ->assertJsonFragment(['id' => 'gpt-5-mini', 'source' => 'openai'])
            ->assertJsonFragment(['id' => 'gpt-4o-mini-search-preview', 'source' => 'openai']);
    }

    public function test_admin_can_view_live_lookup_provider_status_and_usage(): void
    {
        config()->set('services.hermes_runtime.api_key', 'test-openai-key');
        config()->set('services.hermes_runtime.tavily_api_key', 'test-tavily-key');
        config()->set('services.hermes_runtime.google_maps_api_key', 'test-google-key');
        config()->set('services.hermes_runtime.weather_lookup_enabled', true);

        $adminToken = $this->apiToken('lookup-admin@example.com');
        $userToken = $this->apiToken('lookup-user@example.com');
        $admin = User::where('email', 'lookup-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();

        AiUsageLog::create([
            'user_id' => $admin->id,
            'provider' => 'open_meteo',
            'model' => 'open-meteo',
            'route_tier' => 'external_lookup',
            'request_type' => 'external_lookup',
            'status' => 'completed',
            'tool_call_count' => 1,
            'estimated_cost_usd' => 0,
            'action_types' => ['external_lookup', 'open_meteo_weather'],
            'metadata' => ['live_lookup_provider' => 'open_meteo', 'latency_ms' => 240],
        ]);

        AiUsageLog::create([
            'user_id' => $admin->id,
            'provider' => 'google',
            'model' => 'google-places',
            'route_tier' => 'external_lookup',
            'request_type' => 'external_lookup',
            'status' => 'completed',
            'tool_call_count' => 2,
            'estimated_cost_usd' => 0,
            'action_types' => ['external_lookup', 'google_places'],
            'metadata' => ['live_lookup_provider' => 'google_places', 'latency_ms' => 350],
        ]);

        AiUsageLog::create([
            'user_id' => $admin->id,
            'provider' => 'tavily',
            'model' => 'tavily-search',
            'route_tier' => 'external_lookup',
            'request_type' => 'external_lookup',
            'status' => 'completed',
            'tool_call_count' => 1,
            'estimated_cost_usd' => 0,
            'action_types' => ['external_lookup', 'tavily_search'],
            'metadata' => ['live_lookup_provider' => 'tavily_search', 'latency_ms' => 420, 'credits' => 1],
        ]);

        AiUsageLog::create([
            'user_id' => $admin->id,
            'provider' => 'openai',
            'model' => 'gpt-5-mini',
            'route_tier' => 'web_search',
            'request_type' => 'web_search',
            'status' => 'failed',
            'tool_call_count' => 1,
            'estimated_cost_usd' => 0.0123,
            'action_types' => ['external_lookup', 'web_search'],
            'metadata' => ['live_lookup_provider' => 'openai_web_search', 'latency_ms' => 8100],
        ]);

        $this->withToken($userToken)->getJson('/api/admin/live-lookup/providers')
            ->assertForbidden();

        $this->withToken($adminToken)->getJson('/api/admin/live-lookup/providers')
            ->assertOk()
            ->assertJsonPath('data.providers.0.key', 'open_meteo')
            ->assertJsonPath('data.providers.0.connected', true)
            ->assertJsonPath('data.providers.0.usage.requests', 1)
            ->assertJsonPath('data.providers.0.usage.avg_latency_ms', 240)
            ->assertJsonPath('data.providers.1.key', 'google_places')
            ->assertJsonPath('data.providers.1.connected', true)
            ->assertJsonPath('data.providers.1.usage.requests', 1)
            ->assertJsonPath('data.providers.2.key', 'osm_places')
            ->assertJsonPath('data.providers.2.connected', true)
            ->assertJsonPath('data.providers.3.key', 'tavily_search')
            ->assertJsonPath('data.providers.3.connected', true)
            ->assertJsonPath('data.providers.3.usage.avg_latency_ms', 420)
            ->assertJsonPath('data.providers.4.key', 'openai_web_search')
            ->assertJsonPath('data.providers.4.connected', true)
            ->assertJsonPath('data.providers.4.usage.failed', 1);
    }

    private function settingsPayload(array $models = [], array $usageLimits = [], array $killSwitches = []): array
    {
        return [
            'model_settings' => [
                'external_lookup_model' => $models['external_lookup_model'] ?? 'gpt-5-mini',
            ],
            'kill_switches' => [
                'bean_chat_enabled' => $killSwitches['bean_chat_enabled'] ?? true,
            ],
            'usage_limits' => [
                'base_cost_limit' => $usageLimits['base_cost_limit'] ?? 1.00,
                'base_external_cost_limit' => $usageLimits['base_external_cost_limit'] ?? 0.25,
                'premium_cost_limit' => $usageLimits['premium_cost_limit'] ?? 5.00,
                'premium_external_cost_limit' => $usageLimits['premium_external_cost_limit'] ?? 1.00,
                'pro_cost_limit' => $usageLimits['pro_cost_limit'] ?? 20.00,
                'pro_external_cost_limit' => $usageLimits['pro_external_cost_limit'] ?? 5.00,
            ],
        ];
    }
}
