<?php

namespace Tests\Feature;

use App\Models\AiUsageLog;
use App\Models\AdminSetting;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\PageViewEvent;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Models\Workspace;
use App\Services\AiUsageService;
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

        config()->set('services.stripe.plan_amounts.premium.monthly', 19.99);

        $user = User::where('email', 'usage-user@example.com')->firstOrFail();
        $admin = User::where('email', 'usage-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true, 'subscription_tier' => 'pro'])->save();
        $user->forceFill([
            'subscription_tier' => 'premium',
            'subscription_status' => 'active',
            'stripe_subscription_id' => 'sub_usage_test',
        ])->save();
        User::factory()->create(['created_at' => now()->subMonths(2)->startOfMonth()->addDay()]);
        PageViewEvent::create([
            'visitor_key' => 'visitor-1',
            'path' => '/pricing',
            'utm_source' => 'influencer',
            'status_code' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PageViewEvent::create([
            'user_id' => $admin->id,
            'visitor_key' => 'admin-visitor',
            'path' => '/admin',
            'utm_source' => 'internal',
            'status_code' => 200,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        PersonalAccessToken::where('user_id', $admin->id)->update(['last_used_at' => now()]);
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
            'metadata' => ['duration_ms' => 400, 'model_call_ms' => 100, 'tool_execution_ms' => 80],
        ]);

        $this->withToken($userToken)->getJson('/api/admin/usage/summary')
            ->assertForbidden();

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary')
            ->assertOk()
            ->assertJsonPath('data.totals.ai_actions_month', 1)
            ->assertJsonPath('data.totals.tokens_month', 160)
            ->assertJsonPath('data.business.mrr', 19.99)
            ->assertJsonPath('data.business.arr', 239.88)
            ->assertJsonPath('data.traffic.page_views_month', 1)
            ->assertJsonPath('data.traffic.signups_month', 1)
            ->assertJsonPath('data.traffic.top_pages.0.path', '/pricing')
            ->assertJsonPath('data.traffic.top_sources.0.source', 'influencer')
            ->assertJsonPath('data.activation.active_users_today', 1)
            ->assertJsonPath('data.activation.premium_users', 1)
            ->assertJsonPath('data.app_usage.chat_messages_month', 1)
            ->assertJsonPath('data.app_usage.avg_request_latency_ms', 400)
            ->assertJsonPath('data.server.queue.connection', config('queue.default'))
            ->assertJsonPath('data.by_model.0.key', 'gpt-5.4-mini')
            ->assertJsonPath('data.top_users.0.email', 'usage-user@example.com')
            ->assertJsonPath('data.user_growth_range', 'last_30_days')
            ->assertJsonCount(30, 'data.user_growth')
            ->assertJsonPath('data.user_growth.29.total_users', User::where('is_admin', false)->count())
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
            ->assertJsonPath('data.user_growth.0.total_users', User::where('is_admin', false)->count());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary?user_growth_range=last_7_days')
            ->assertOk()
            ->assertJsonPath('data.user_growth_range', 'last_7_days')
            ->assertJsonCount(7, 'data.user_growth')
            ->assertJsonPath('data.user_growth.6.total_users', User::where('is_admin', false)->count());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary?user_growth_range=all_time')
            ->assertOk()
            ->assertJsonPath('data.user_growth_range', 'all_time')
            ->assertJsonCount(3, 'data.user_growth')
            ->assertJsonPath('data.user_growth.2.total_users', User::where('is_admin', false)->count());

        $this->withToken($adminToken)->getJson('/api/admin/usage/summary?user_growth_range=bad')
            ->assertOk()
            ->assertJsonPath('data.user_growth_range', 'last_30_days')
            ->assertJsonCount(30, 'data.user_growth');
    }

    public function test_daily_cost_limit_blocks_tool_runtime_invocation(): void
    {
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        config()->set('services.ai_usage.limits.base_cost_limit', 0.000001);
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
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.assistant_message.content', 'This account has reached today\'s AI usage limit.')
            ->assertJsonFragment(['event_type' => 'runtime.usage_blocked']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'status' => 'blocked',
            'route_tier' => 'agent',
        ]);
        Http::assertSentCount(0);
    }

    public function test_admin_daily_usage_limits_are_enforced_by_tier(): void
    {
        AdminSetting::create([
            'key' => 'usage.base_cost_limit',
            'value' => ['value' => 0.01],
            'type' => 'float',
        ]);
        AdminSetting::create([
            'key' => 'usage.premium_cost_limit',
            'value' => ['value' => 0.50],
            'type' => 'float',
        ]);
        AdminSetting::create([
            'key' => 'usage.pro_cost_limit',
            'value' => ['value' => 1.50],
            'type' => 'float',
        ]);

        $baseUser = User::factory()->create(['subscription_tier' => 'free']);
        $premiumUser = User::factory()->create(['subscription_tier' => 'premium']);
        $proUser = User::factory()->create(['subscription_tier' => 'pro']);
        $usage = app(AiUsageService::class);

        $basePreflight = $usage->preflightDirect($baseUser, null, 'gpt-test-tools', 0, 0, 0.02, 'text');
        $premiumPreflight = $usage->preflightDirect($premiumUser, null, 'gpt-test-tools', 0, 0, 0.02, 'text');
        $proPreflight = $usage->preflightDirect($proUser, null, 'gpt-test-tools', 0, 0, 0.75, 'text');

        $this->assertFalse($basePreflight['allowed']);
        $this->assertSame('This account has reached today\'s AI usage limit.', $basePreflight['reason']);
        $this->assertTrue($premiumPreflight['allowed']);
        $this->assertSame('premium', $premiumPreflight['budget']['tier']);
        $this->assertTrue($proPreflight['allowed']);
        $this->assertSame('pro', $proPreflight['budget']['tier']);
    }

    public function test_external_daily_cost_limit_blocks_external_lookup_without_blocking_regular_chat(): void
    {
        AdminSetting::create([
            'key' => 'usage.base_cost_limit',
            'value' => ['value' => 100.00],
            'type' => 'float',
        ]);
        AdminSetting::create([
            'key' => 'usage.base_external_cost_limit',
            'value' => ['value' => 0.01],
            'type' => 'float',
        ]);

        $user = User::factory()->create(['subscription_tier' => 'free']);
        $usage = app(AiUsageService::class);

        $chatPreflight = $usage->preflightDirect($user, null, 'gpt-test-tools', 0, 0, 0.02, 'text');
        $externalPreflight = $usage->preflightDirect($user, null, 'gpt-test-tools', 0, 0, 0.02, 'external_lookup');

        $this->assertTrue($chatPreflight['allowed']);
        $this->assertFalse($externalPreflight['allowed']);
        $this->assertSame('This account has reached today\'s external lookup usage limit.', $externalPreflight['reason']);
    }
}
