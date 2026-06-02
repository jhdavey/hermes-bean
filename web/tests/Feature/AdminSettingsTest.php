<?php

namespace Tests\Feature;

use App\Models\AdminCommandRun;
use App\Models\AdminSetting;
use App\Models\AgentProfile;
use App\Models\User;
use App\Services\AdminCommandRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminSettingsTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_update_runtime_models_and_beta_limits(): void
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
            'api_per_minute' => 12,
            'monthly_ai_actions' => 25,
            'daily_text_requests' => 50,
            'daily_voice_turns' => 20,
            'daily_voice_minutes' => 10.0,
            'daily_external_tool_calls' => 10,
            'daily_web_search_calls' => 3,
        ], true, [
            'bean_chat_enabled' => false,
            'bean_voice_enabled' => true,
        ]))->assertOk()
            ->assertJsonPath('data.models.main_model.value', 'gpt-5-mini')
            ->assertJsonPath('data.beta_limits.api_per_minute.value', 12)
            ->assertJsonPath('data.beta_limits.monthly_ai_actions.value', 25)
            ->assertJsonPath('data.beta_limits.daily_text_requests.value', 50)
            ->assertJsonPath('data.beta_limits.daily_voice_minutes.value', 10)
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
            ->assertJsonPath('data.settings.beta_limits.api_per_minute.value', 12)
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

    private function settingsPayload(array $models = [], array $betaLimits = [], bool $apply = false, array $killSwitches = []): array
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
            'beta_limits' => [
                'api_per_minute' => $betaLimits['api_per_minute'] ?? 60,
                'monthly_ai_actions' => $betaLimits['monthly_ai_actions'] ?? 2_000,
                'monthly_tokens' => $betaLimits['monthly_tokens'] ?? 1_000_000,
                'monthly_cost_usd' => $betaLimits['monthly_cost_usd'] ?? 4.00,
                'daily_text_requests' => $betaLimits['daily_text_requests'] ?? 50,
                'daily_voice_turns' => $betaLimits['daily_voice_turns'] ?? 20,
                'daily_voice_minutes' => $betaLimits['daily_voice_minutes'] ?? 10.00,
                'daily_external_tool_calls' => $betaLimits['daily_external_tool_calls'] ?? 10,
                'daily_web_search_calls' => $betaLimits['daily_web_search_calls'] ?? 3,
                'daily_soft_tokens' => $betaLimits['daily_soft_tokens'] ?? 60_000,
                'daily_hard_tokens' => $betaLimits['daily_hard_tokens'] ?? 180_000,
                'daily_soft_cost_usd' => $betaLimits['daily_soft_cost_usd'] ?? 0.50,
                'daily_hard_cost_usd' => $betaLimits['daily_hard_cost_usd'] ?? 1.00,
            ],
            'apply_main_model_to_profiles' => $apply,
        ];
    }
}
