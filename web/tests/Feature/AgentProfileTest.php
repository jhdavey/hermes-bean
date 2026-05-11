<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_registration_creates_unique_server_hosted_agent_profile(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'name' => 'Harley Davey',
            'email' => 'harley@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated();

        $profile = AgentProfile::query()->firstOrFail();

        $this->assertSame($response->json('data.user.id'), $profile->user_id);
        $this->assertSame('openrouter', $profile->provider);
        $this->assertSame('gpt-5.5', $profile->model);
        $this->assertSame('fixed', $profile->router_mode);
        $this->assertStringContainsString($profile->slug, (string) $profile->runtime_home);
        $this->assertTrue($profile->approval_policy['auto_approve_low_risk']);
        $this->assertContains('outgoing_mail', $profile->approval_policy['require_approval_for']);
        $this->assertContains('payments', $profile->approval_policy['require_approval_for']);
        $this->assertSame('app_home_top_banner', $profile->approval_policy['approval_surface']);
        $this->assertNotNull($response->json('data.user.agent_profile.id'));
    }

    public function test_each_user_gets_a_distinct_agent_profile(): void
    {
        $this->apiToken('alice@example.com');
        $this->apiToken('bob@example.com');

        $profiles = AgentProfile::query()->orderBy('id')->get();

        $this->assertCount(2, $profiles);
        $this->assertNotSame($profiles[0]->slug, $profiles[1]->slug);
        $this->assertNotSame($profiles[0]->runtime_home, $profiles[1]->runtime_home);
    }

    public function test_today_endpoint_includes_agent_profile(): void
    {
        $token = $this->apiToken('today-profile@example.com');

        $this->withToken($token)
            ->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.agent_profile.provider', 'openrouter')
            ->assertJsonPath('data.agent_profile.model', 'gpt-5.5')
            ->assertJsonPath('data.agent_profile.approval_policy.approval_surface', 'app_home_top_banner');
    }
}
