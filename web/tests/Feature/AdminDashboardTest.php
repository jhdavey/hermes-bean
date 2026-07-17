<?php

namespace Tests\Feature;

use App\Models\BeanQualityTrace;
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
                'bean_quality' => ['status', 'traces_24h', 'flagged_24h', 'top_quality_flags'],
                'server',
                'user_growth',
                'daily_activity',
            ]])
            ->assertJsonPath('data.user_growth_range', 'last_7_days')
            ->assertJsonPath('data.traffic.page_views_today', 1)
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
