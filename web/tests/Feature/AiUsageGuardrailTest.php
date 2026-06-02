<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
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
        User::factory()->create(['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);
        $workspace = Workspace::where('personal_owner_user_id', $user->id)->firstOrFail();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Admin usage test',
            'status' => 'active',
        ]);
        $message = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Please add take out the trash as a task for tonight.',
        ]);

        AiUsageLog::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'conversation_session_id' => $session->id,
            'conversation_message_id' => $message->id,
            'provider' => 'openai',
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
            ->assertJsonPath('data.user_growth_range', 'last_30_days')
            ->assertJsonCount(30, 'data.user_growth')
            ->assertJsonPath('data.user_growth.29.total_users', User::count())
            ->assertJsonPath('data.recent_logs.0.status', 'completed')
            ->assertJsonPath('data.recent_logs.0.use_case', 'Task management')
            ->assertJsonPath('data.recent_logs.0.request_preview', 'Please add take out the trash as a task for tonight.')
            ->assertJsonPath('data.recent_logs.0.request_full', 'Please add take out the trash as a task for tonight.')
            ->assertJsonPath('data.recent_logs.0.input_prompt_full', 'Please add take out the trash as a task for tonight.')
            ->assertJsonPath('data.recent_logs.0.action_summary', 'Task Create');

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary?user_growth_range=today')
            ->assertOk()
            ->assertJsonPath('data.user_growth_range', 'today')
            ->assertJsonCount(1, 'data.user_growth')
            ->assertJsonPath('data.user_growth.0.total_users', User::count());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary?user_growth_range=last_7_days')
            ->assertOk()
            ->assertJsonPath('data.user_growth_range', 'last_7_days')
            ->assertJsonCount(7, 'data.user_growth')
            ->assertJsonPath('data.user_growth.6.total_users', User::count());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary?user_growth_range=all_time')
            ->assertOk()
            ->assertJsonPath('data.user_growth_range', 'all_time')
            ->assertJsonCount(3, 'data.user_growth')
            ->assertJsonPath('data.user_growth.2.total_users', User::count());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary?user_growth_range=bad')
            ->assertOk()
            ->assertJsonPath('data.user_growth_range', 'last_30_days')
            ->assertJsonCount(30, 'data.user_growth');
    }

    public function test_daily_hard_budget_blocks_tool_runtime_invocation(): void
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
        ])->assertStatus(429)
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.assistant_message.content', 'This account has reached today\'s AI token limit.')
            ->assertJsonFragment(['event_type' => 'runtime.usage_blocked']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'status' => 'blocked',
            'route_tier' => 'agent',
        ]);
        Http::assertSentCount(0);
    }
}
