<?php

namespace Tests\Feature;

use App\Models\AdminCommandRun;
use App\Models\AdminSetting;
use App\Models\AgentProfile;
use App\Models\AiUsageLog;
use App\Models\User;
use App\Services\AdminCommandRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_runtime_models_and_usage_limits(): void
    {
        config()->set('services.hermes_runtime.api_key', '');
        $adminToken = $this->apiToken('settings-admin@example.com');
        $userToken = $this->apiToken('settings-user@example.com');
        $admin = User::where('email', 'settings-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();

        $this->withToken($userToken)->patchJson('/api/admin/settings', $this->settingsPayload())
            ->assertForbidden();

        $this->withToken($adminToken)->patchJson('/api/admin/settings', $this->settingsPayload([
            'main_model' => 'gpt-5-mini',
            'quick_voice_model' => 'gpt-5-nano',
            'realtime_model' => 'gpt-realtime-mini',
            'external_lookup_model' => 'gpt-5-mini',
        ], [
            'base_cost_limit' => 1.25,
            'base_external_cost_limit' => 0.35,
            'premium_cost_limit' => 6.50,
            'premium_external_cost_limit' => 1.25,
            'pro_cost_limit' => 30.00,
            'pro_external_cost_limit' => 7.50,
        ], true, [
            'bean_chat_enabled' => false,
            'bean_voice_enabled' => true,
        ]))->assertOk()
            ->assertJsonPath('data.models.main_model.value', 'gpt-5-mini')
            ->assertJsonPath('data.usage_limits.base_cost_limit.value', 1.25)
            ->assertJsonPath('data.usage_limits.base_external_cost_limit.value', 0.35)
            ->assertJsonPath('data.usage_limits.premium_cost_limit.value', 6.5)
            ->assertJsonPath('data.usage_limits.pro_external_cost_limit.value', 7.5)
            ->assertJsonPath('data.kill_switches.bean_chat_enabled.value', false);

        $this->assertDatabaseHas('admin_settings', [
            'key' => 'models.main',
            'updated_by_user_id' => $admin->id,
        ]);
        $this->assertSame('gpt-5-mini', AdminSetting::where('key', 'models.main')->firstOrFail()->value['value']);
        $this->assertTrue(AgentProfile::query()->where('model', 'gpt-5-mini')->exists());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.settings.models.main_model.value', 'gpt-5-mini')
            ->assertJsonPath('data.settings.usage_limits.base_cost_limit.value', 1.25)
            ->assertJsonPath('data.settings.kill_switches.bean_chat_enabled.value', false);

        $this->withToken($adminToken)->patchJson('/api/admin/settings', $this->settingsPayload([
            'realtime_model' => 'gpt-5.4',
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors('model_settings.realtime_model');
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
                    ['id' => 'gpt-realtime'],
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
            ->assertJsonPath('data.groups.main_model.label', 'Main Bean reasoning/chat')
            ->assertJsonFragment(['id' => 'gpt-5.2', 'source' => 'openai'])
            ->assertJsonFragment(['id' => 'gpt-realtime', 'source' => 'openai'])
            ->assertJsonFragment(['id' => 'gpt-4o-mini-search-preview', 'source' => 'openai']);
    }

    public function test_admin_can_view_and_update_hermes_runtime(): void
    {
        $runtimeRoot = sys_get_temp_dir().'/hermes-admin-runtime-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($runtimeRoot);
        $script = $runtimeRoot.'/fake-hermes';
        File::put($script, <<<'SH'
#!/bin/sh
if [ "$1" = "--version" ]; then
  echo "Hermes Agent v1.2.3 (test)"
  echo "Project: $HERMES_BASE_HOME"
  echo "Update available: test"
  exit 0
fi
if [ "$1" = "update" ]; then
  echo "updated users at $HERMES_USERS_HOME"
  exit 0
fi
echo "unexpected command: $*" >&2
exit 2
SH);
        chmod($script, 0755);

        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.cli_workdir', $runtimeRoot);
        config()->set('services.hermes_runtime.users_home', $runtimeRoot.'/users');
        config()->set('services.hermes_runtime.base_home', $runtimeRoot.'/base');

        $adminToken = $this->apiToken('hermes-admin@example.com');
        $userToken = $this->apiToken('hermes-user@example.com');
        User::where('email', 'hermes-admin@example.com')->firstOrFail()->forceFill(['is_admin' => true])->save();

        $this->beforeApplicationDestroyed(fn () => File::isDirectory($runtimeRoot) ? File::deleteDirectory($runtimeRoot) : null);

        $this->withToken($userToken)->getJson('/api/admin/hermes/status')
            ->assertForbidden();

        $this->withToken($adminToken)->getJson('/api/admin/hermes/status')
            ->assertOk()
            ->assertJsonPath('data.version', 'Hermes Agent v1.2.3 (test)')
            ->assertJsonPath('data.update_available', true)
            ->assertJsonPath('data.cli_path', $script);

        $runId = $this->withToken($adminToken)->postJson('/api/admin/hermes/update')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.command_key', 'hermes_update')
            ->json('data.id');

        $this->withToken($userToken)->getJson("/api/admin/command-runs/{$runId}")
            ->assertForbidden();

        $run = app(AdminCommandRunService::class)->execute(AdminCommandRun::findOrFail($runId));

        $this->assertSame('completed', $run->status);
        $this->assertSame(0, $run->exit_code);
        $this->assertStringContainsString('updated users at '.$runtimeRoot.'/users', (string) $run->output);

        $this->withToken($adminToken)->getJson("/api/admin/command-runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.exit_code', 0)
            ->assertJsonPath('data.metadata.cwd', $runtimeRoot)
            ->assertJsonPath('data.metadata.command_line', $script.' update --yes')
            ->assertJsonPath('data.output', 'updated users at '.$runtimeRoot.'/users'."\n");
    }

    public function test_admin_can_view_live_lookup_provider_status_and_usage(): void
    {
        config()->set('services.hermes_runtime.api_key', 'test-openai-key');
        config()->set('services.hermes_runtime.tavily_api_key', 'test-tavily-key');
        config()->set('services.hermes_runtime.geoapify_api_key', 'test-geoapify-key');
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
            'provider' => 'geoapify',
            'model' => 'geoapify-places',
            'route_tier' => 'external_lookup',
            'request_type' => 'external_lookup',
            'status' => 'completed',
            'tool_call_count' => 2,
            'estimated_cost_usd' => 0,
            'action_types' => ['external_lookup', 'geoapify_places'],
            'metadata' => ['live_lookup_provider' => 'geoapify_places', 'latency_ms' => 350],
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
            ->assertJsonPath('data.providers.1.key', 'geoapify_places')
            ->assertJsonPath('data.providers.1.connected', true)
            ->assertJsonPath('data.providers.1.usage.requests', 1)
            ->assertJsonPath('data.providers.2.key', 'tavily_search')
            ->assertJsonPath('data.providers.2.connected', true)
            ->assertJsonPath('data.providers.2.usage.avg_latency_ms', 420)
            ->assertJsonPath('data.providers.3.key', 'openai_web_search')
            ->assertJsonPath('data.providers.3.connected', true)
            ->assertJsonPath('data.providers.3.usage.failed', 1);
    }

    private function settingsPayload(array $models = [], array $usageLimits = [], bool $apply = false, array $killSwitches = []): array
    {
        return [
            'model_settings' => [
                'main_model' => $models['main_model'] ?? 'gpt-5-mini',
                'quick_voice_model' => $models['quick_voice_model'] ?? 'gpt-5-nano',
                'realtime_model' => $models['realtime_model'] ?? 'gpt-realtime-mini',
                'external_lookup_model' => $models['external_lookup_model'] ?? 'gpt-5-mini',
            ],
            'kill_switches' => [
                'bean_chat_enabled' => $killSwitches['bean_chat_enabled'] ?? true,
                'bean_voice_enabled' => $killSwitches['bean_voice_enabled'] ?? true,
            ],
            'usage_limits' => [
                'base_cost_limit' => $usageLimits['base_cost_limit'] ?? 1.00,
                'base_external_cost_limit' => $usageLimits['base_external_cost_limit'] ?? 0.25,
                'premium_cost_limit' => $usageLimits['premium_cost_limit'] ?? 5.00,
                'premium_external_cost_limit' => $usageLimits['premium_external_cost_limit'] ?? 1.00,
                'pro_cost_limit' => $usageLimits['pro_cost_limit'] ?? 20.00,
                'pro_external_cost_limit' => $usageLimits['pro_external_cost_limit'] ?? 5.00,
            ],
            'apply_main_model_to_profiles' => $apply,
        ];
    }
}
