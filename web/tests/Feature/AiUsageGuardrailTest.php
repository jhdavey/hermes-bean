<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiUsageGuardrailTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_usage_dashboard_is_admin_only_and_returns_usage_metrics(): void
    {
        $userToken = $this->apiToken('usage-user@example.com');
        $adminToken = $this->apiToken('usage-admin@example.com');

        $user = User::where('email', 'usage-user@example.com')->firstOrFail();
        $admin = User::where('email', 'usage-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true, 'subscription_tier' => 'pro'])->save();
        $workspace = Workspace::where('personal_owner_user_id', $user->id)->firstOrFail();

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'provider' => 'openrouter',
            'model' => 'gpt-5.4-mini',
            'route_tier' => 'simple',
            'status' => 'completed',
            'input_tokens' => 120,
            'output_tokens' => 40,
            'total_tokens' => 160,
            'estimated_cost_usd' => 0.00027,
            'action_types' => ['task.create'],
        ]);

        $this->withToken($userToken)->getJson('/api/admin/usage/summary')
            ->assertForbidden();

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.ai_actions_month', 1)
            ->assertJsonPath('data.totals.tokens_month', 160)
            ->assertJsonPath('data.by_model.0.key', 'gpt-5.4-mini')
            ->assertJsonPath('data.top_users.0.email', 'usage-user@example.com')
            ->assertJsonPath('data.recent_logs.0.status', 'completed');
    }

    public function test_daily_hard_budget_blocks_before_model_invocation_and_logs_attempt(): void
    {
        @unlink(sys_get_temp_dir().'/hermes-budget-should-not-run');

        $script = $this->configureFakeHermesRuntime(<<<'PHP'
#!/usr/bin/env php
<?php
file_put_contents(getenv('HERMES_SHOULD_NOT_RUN'), 'called');
echo json_encode(['content' => 'Should not run'], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.environment', [
            'HERMES_SHOULD_NOT_RUN' => sys_get_temp_dir().'/hermes-budget-should-not-run',
        ]);
        config()->set('services.ai_usage.budgets.free.daily_hard_tokens', 1);

        $token = $this->apiToken('budget-user@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Budget test',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please plan my whole week.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.usage.status', 'blocked')
            ->assertJsonFragment(['event_type' => 'runtime.usage_budget_blocked']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'status' => 'blocked',
            'route_tier' => 'agent',
            'estimated_cost_usd' => 0,
        ]);
        $this->assertDatabaseMissing('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'runtime.hermes_cli_started',
        ]);
        $this->assertFileDoesNotExist(sys_get_temp_dir().'/hermes-budget-should-not-run');
    }
}
