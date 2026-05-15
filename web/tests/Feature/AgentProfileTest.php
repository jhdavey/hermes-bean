<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AgentProfileService;
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
        $this->assertSame('balanced', $profile->settings['personality_type']);
        $this->assertSame('Balanced helper', $profile->settings['personality_label']);
        $this->assertNotNull($response->json('data.user.agent_profile.id'));
    }

    public function test_registration_keeps_agent_onboarding_incomplete_until_first_home_flow(): void
    {
        $this->postJson('/api/auth/register', [
            'name' => 'New User',
            'email' => 'new-user@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()
            ->assertJsonPath('data.user.onboard_complete', false)
            ->assertJsonPath('data.user.agent_profile.settings.personality_type', 'balanced')
            ->assertJsonPath('data.user.agent_profile.settings.onboarding.completed', false);

        $profile = AgentProfile::query()->firstOrFail();

        $this->assertSame('balanced', $profile->settings['personality_type']);
        $this->assertFalse($profile->settings['onboarding']['completed']);
    }

    public function test_authenticated_user_can_complete_agent_onboarding_after_registration(): void
    {
        $user = User::factory()->create();
        $profile = app(AgentProfileService::class)->ensureForUser($user);

        $token = 'onboarding-token';
        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
        ]);

        $this->withToken($token)
            ->patchJson('/api/auth/me', [
                'agent_personality' => 'organizer',
                'onboarding_priorities' => ['Work', 'Focus'],
                'onboarding_context' => 'Keep mornings protected.',
            ])
            ->assertOk()
            ->assertJsonPath('data.onboard_complete', true)
            ->assertJsonPath('data.agent_profile.settings.personality_type', 'organizer')
            ->assertJsonPath('data.agent_profile.settings.onboarding.completed', true)
            ->assertJsonPath('data.agent_profile.settings.onboarding.priorities.0', 'Work');

        $profile->refresh();
        $this->assertSame('organizer', $profile->settings['personality_type']);
        $this->assertTrue($profile->settings['onboarding']['completed']);
        $this->assertTrue($user->refresh()->onboard_complete);
    }

    public function test_me_endpoint_backfills_agent_profile_for_existing_users(): void
    {
        $user = User::factory()->create(['onboard_complete' => false]);
        $token = 'existing-user-token';
        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
        ]);

        $this->assertDatabaseMissing('agent_profiles', ['user_id' => $user->id]);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.onboard_complete', false)
            ->assertJsonPath('data.agent_profile.settings.personality_type', 'balanced')
            ->assertJsonPath('data.agent_profile.settings.onboarding.completed', false);

        $this->assertDatabaseHas('agent_profiles', ['user_id' => $user->id]);
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
