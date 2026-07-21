<?php

namespace Tests\Feature;

use App\Models\BeanQualityTrace;
use App\Models\BeanUsageRecord;
use App\Models\PageViewEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_dashboard_retains_business_product_and_server_metrics(): void
    {
        $token = $this->apiToken('dashboard-admin@example.com');
        $admin = User::where('email', 'dashboard-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        BeanQualityTrace::create([
            'user_id' => $admin->id,
            'user_message' => 'What time is it?',
            'assistant_answer' => 'Done.',
            'intent' => 'time.now',
            'actions' => ['time.now'],
            'tool_results_count' => 1,
            'quality_flags' => ['generic_done_after_factual_question'],
            'latency_ms' => 123,
            'voice' => false,
        ]);
        BeanUsageRecord::create([
            'user_id' => $admin->id,
            'provider' => 'openai',
            'service' => 'bean_hermes',
            'usage_type' => 'llm_tokens',
            'model' => 'gpt-4.1-mini',
            'source' => 'flutter_text',
            'external_id' => 'test-openai-usage',
            'unit' => 'tokens',
            'quantity' => 150,
            'input_tokens' => 100,
            'output_tokens' => 50,
            'total_tokens' => 150,
            'estimated_cost_usd' => 0.00012,
            'is_estimate' => true,
            'recorded_at' => now(),
        ]);
        BeanUsageRecord::create([
            'user_id' => $admin->id,
            'provider' => 'elevenlabs',
            'service' => 'conversational_ai_agent',
            'usage_type' => 'voice_session',
            'source' => 'elevenlabs_agent',
            'external_id' => 'test-elevenlabs-usage',
            'unit' => 'seconds',
            'quantity' => 60,
            'credits' => 666.67,
            'estimated_cost_usd' => 0.08,
            'is_estimate' => true,
            'recorded_at' => now(),
        ]);
        PageViewEvent::create([
            'path' => '/',
            'visitor_key' => 'dashboard-test-visitor',
            'user_id' => null,
        ]);

        $this->withToken($token)
            ->getJson('/api/admin/dashboard/summary?growth_range=last_7_days')
            ->assertOk()
            ->assertJsonStructure(['data' => [
                'totals' => ['users', 'workspaces', 'open_issue_reports'],
                'business',
                'traffic',
                'activation',
                'app_usage' => ['created_today', 'created_week', 'created_month'],
                'ai_usage' => ['today', 'week', 'month', 'pricing_assumptions'],
                'bean_quality' => ['status', 'traces_24h', 'flagged_24h', 'top_quality_flags'],
                'server',
                'user_growth',
                'daily_activity',
            ]])
            ->assertJsonPath('data.user_growth_range', 'last_7_days')
            ->assertJsonPath('data.traffic.page_views_today', 1)
            ->assertJsonPath('data.ai_usage.month.openai.total_tokens', 150)
            ->assertJsonPath('data.ai_usage.month.elevenlabs.voice_seconds', 60)
            ->assertJsonPath('data.ai_usage.pricing_assumptions.elevenlabs_max_duration_seconds', 60)
            ->assertJsonPath('data.ai_usage.pricing_assumptions.elevenlabs_silence_timeout_seconds', 5)
            ->assertJsonPath('data.bean_quality.status', 'watch')
            ->assertJsonPath('data.bean_quality.flagged_24h', 1)
            ->assertJsonPath('data.bean_quality.top_quality_flags.0', 'generic_done_after_factual_question');
    }

    public function test_admin_dashboard_rejects_non_admin_users(): void
    {
        $this->withToken($this->apiToken('dashboard-user@example.com'))
            ->getJson('/api/admin/dashboard/summary')
            ->assertForbidden();
    }
}
