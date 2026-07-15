<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AgentProfileService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AgentProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_user_provisioning_creates_unique_agent_profile(): void
    {
        $token = $this->apiToken('harley@example.com');
        $user = User::where('email', 'harley@example.com')->firstOrFail();

        $profile = AgentProfile::query()->firstOrFail();

        $this->assertSame($user->id, $profile->user_id);
        $this->assertNotSame('', trim($profile->slug));
        $this->assertSame('balanced', $profile->settings['personality_type']);
        $this->assertSame('Balanced helper', $profile->settings['personality_label']);
        $this->assertStringContainsString('calm, practical, concise co-pilot', $profile->settings['personality_prompt']);
        $this->assertStringContainsString('one useful next suggestion', $profile->settings['personality_prompt']);
        $this->assertContains('direct', AgentProfileService::personalityKeys());
        $this->assertContains('gentle', AgentProfileService::personalityKeys());
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.agent_profile.settings.personality_type', 'balanced');
    }

    public function test_internal_user_provisioning_keeps_agent_onboarding_incomplete_until_first_home_flow(): void
    {
        $token = $this->apiToken('new-user@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.onboard_complete', false)
            ->assertJsonPath('data.agent_profile.settings.personality_type', 'balanced')
            ->assertJsonPath('data.agent_profile.settings.onboarding.completed', false);

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
            ->assertJsonPath('data.agent_profile.settings.onboarding.priorities.0', 'Work')
            ->assertJsonPath('data.agent_profile.settings.personality_label', 'Detail organizer');

        $profile->refresh();
        $this->assertStringContainsString('structured, precise, schedule-aware', $profile->settings['personality_prompt']);
        $this->assertStringContainsString('missing dates, times, recurrence', $profile->settings['personality_prompt']);
        $this->assertSame('organizer', $profile->settings['personality_type']);
        $this->assertTrue($profile->settings['onboarding']['completed']);
        $this->assertTrue($user->refresh()->onboard_complete);
    }

    public function test_auth_me_updates_active_workspace_bean_preferences(): void
    {
        $user = User::factory()->create();
        $workspaceService = app(WorkspaceService::class);
        $personalProfile = app(AgentProfileService::class)->ensureForUser($user);
        $household = $workspaceService->createHousehold($user, 'Davey House');
        $user->forceFill(['default_workspace_id' => $household->id])->save();
        $householdProfile = app(AgentProfileService::class)->ensureForWorkspace($household, $user);

        $token = 'active-workspace-preferences-token';
        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
        ]);

        $this->withToken($token)
            ->patchJson('/api/auth/me', [
                'agent_personality' => 'coach',
                'onboarding_priorities' => ['Family', 'Planning'],
                'onboarding_context' => 'Protect dinner.',
            ])
            ->assertOk()
            ->assertJsonPath('data.onboard_complete', true)
            ->assertJsonPath('data.needs_bean_onboarding', false)
            ->assertJsonPath('data.bean_preferences_ready', true)
            ->assertJsonPath('data.active_workspace.id', $household->id)
            ->assertJsonPath('data.active_workspace_agent_profile.id', $householdProfile->id)
            ->assertJsonPath('data.active_workspace_agent_profile.settings.personality_type', 'coach')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.onboarding.priorities.0', 'Family');

        $this->assertSame('balanced', $personalProfile->refresh()->settings['personality_type']);
        $this->assertSame('coach', $householdProfile->refresh()->settings['personality_type']);
    }

    public function test_auth_me_updates_active_workspace_home_city(): void
    {
        $user = User::factory()->create();
        $workspaceService = app(WorkspaceService::class);
        $personalProfile = app(AgentProfileService::class)->ensureForUser($user);
        $household = $workspaceService->createHousehold($user, 'Davey House');
        $user->forceFill(['default_workspace_id' => $household->id])->save();
        $householdProfile = app(AgentProfileService::class)->ensureForWorkspace($household, $user);

        $token = 'home-city-token';
        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
        ]);

        $this->withToken($token)
            ->patchJson('/api/auth/me', [
                'home_city' => 'Orlando, Florida',
            ])
            ->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.id', $householdProfile->id)
            ->assertJsonPath('data.active_workspace_agent_profile.settings.weather.location', 'Orlando, Florida')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.home_location', 'Orlando, Florida');

        $this->assertNull(data_get($personalProfile->refresh()->settings, 'weather.location'));
        $this->assertSame('Orlando, Florida', data_get($householdProfile->refresh()->settings, 'weather.location'));
        $this->assertSame('Orlando, Florida', data_get($householdProfile->settings, 'memory.user_preferences.home_location'));

        $this->withToken($token)
            ->patchJson('/api/auth/me', [
                'home_city' => null,
            ])
            ->assertOk()
            ->assertJsonMissingPath('data.active_workspace_agent_profile.settings.weather.location')
            ->assertJsonMissingPath('data.active_workspace_agent_profile.settings.home_location');

        $this->assertNull(data_get($householdProfile->refresh()->settings, 'weather.location'));
        $this->assertNull(data_get($householdProfile->settings, 'memory.user_preferences.home_location'));
    }

    public function test_me_endpoint_syncs_user_onboarding_flag_from_completed_agent_profile(): void
    {
        $user = User::factory()->create(['onboard_complete' => false]);
        $profile = app(AgentProfileService::class)->ensureForUser($user);
        app(AgentProfileService::class)->applyOnboarding($profile, [
            'agent_personality' => 'organizer',
            'onboarding_priorities' => ['Work'],
            'onboarding_context' => 'Protect deep work.',
        ], 'settings');

        $token = 'sync-onboarding-token';
        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
        ]);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.onboard_complete', true)
            ->assertJsonPath('data.active_workspace_agent_profile.settings.onboarding.completed', true);

        $this->assertTrue($user->refresh()->onboard_complete);
    }

    public function test_me_endpoint_marks_legacy_empty_preferences_as_needing_onboarding(): void
    {
        $user = User::factory()->create(['onboard_complete' => true]);
        app(AgentProfileService::class)->ensureForUser($user);

        $token = 'legacy-empty-preferences-token';
        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
        ]);

        $this->withToken($token)
            ->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.onboard_complete', false)
            ->assertJsonPath('data.needs_bean_onboarding', true)
            ->assertJsonPath('data.bean_preferences_ready', false)
            ->assertJsonPath('data.active_workspace_agent_profile.settings.onboarding.completed', false);

        $this->assertFalse((bool) $user->refresh()->onboard_complete);
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
    }

    public function test_today_endpoint_includes_agent_profile(): void
    {
        $token = $this->apiToken('today-profile@example.com');

        $this->withToken($token)
            ->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.user.needs_bean_onboarding', true)
            ->assertJsonPath('data.user.bean_preferences_ready', false)
            ->assertJsonPath('data.agent_profile.settings.personality_type', 'balanced');
    }
}
