<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
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

    public function test_daily_hard_budget_alert_does_not_block_tool_runtime_invocation(): void
    {
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        config()->set('services.ai_usage.budgets.free.daily_hard_tokens', 1);
        Http::fakeSequence()->push([
            'id' => 'chatcmpl-budget',
            'model' => 'gpt-test-tools',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => ['role' => 'assistant', 'content' => 'I can still help with that.'],
            ]],
        ], 200);

        $token = $this->apiToken('budget-user@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Budget test',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please plan my whole week.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I can still help with that.')
            ->assertJsonFragment(['event_type' => 'runtime.tool_model_completed']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'status' => 'completed',
            'route_tier' => 'agent',
        ]);
        Http::assertSentCount(1);
    }
}
