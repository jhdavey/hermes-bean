<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AgentProfileService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
        $this->assertSame('openai', $profile->provider);
        $this->assertSame('gpt-5-mini', $profile->model);
        $this->assertSame('agent', $profile->router_mode);
        $this->assertStringContainsString($profile->slug, (string) $profile->runtime_home);
        $this->assertTrue($profile->approval_policy['auto_approve_low_risk']);
        $this->assertContains('outgoing_mail', $profile->approval_policy['require_approval_for']);
        $this->assertContains('payments', $profile->approval_policy['require_approval_for']);
        $this->assertSame('app_home_top_banner', $profile->approval_policy['approval_surface']);
        $this->assertSame('balanced', $profile->settings['personality_type']);
        $this->assertSame('Balanced helper', $profile->settings['personality_label']);
        $this->assertStringContainsString('calm, practical, concise co-pilot', $profile->settings['personality_prompt']);
        $this->assertStringContainsString('one useful next suggestion', $profile->settings['personality_prompt']);
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
        $this->assertNotSame($profiles[0]->runtime_home, $profiles[1]->runtime_home);
    }

    public function test_workspace_agent_bootstraps_builtin_hermes_memory_files_only(): void
    {
        $runtimeRoot = sys_get_temp_dir().'/hermes-bean-agent-memory-'.bin2hex(random_bytes(6));
        config()->set('services.hermes_runtime.users_home', $runtimeRoot);
        $this->beforeApplicationDestroyed(fn () => File::isDirectory($runtimeRoot) ? File::deleteDirectory($runtimeRoot) : null);

        $user = User::factory()->create(['name' => 'Harley Davey']);
        $workspace = app(WorkspaceService::class)->createHousehold($user, 'Davey House');
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $user);

        $this->assertStringContainsString('/workspaces/'.$workspace->id.'/', $profile->runtime_home);
        $this->assertFileExists($profile->runtime_home.'/memories/USER.md');
        $this->assertFileExists($profile->runtime_home.'/memories/MEMORY.md');
        $this->assertFileDoesNotExist($profile->runtime_home.'/memories/HOUSEHOLD.md');
        $this->assertFileDoesNotExist($profile->runtime_home.'/memories/PREFERENCES.md');
        foreach (['USER.md', 'MEMORY.md', 'HOUSEHOLD.md', 'PREFERENCES.md', 'bean-preferences-memory.json'] as $file) {
            $this->assertFileDoesNotExist($profile->runtime_home.'/'.$file, $file.' should not be created at runtime home root.');
        }

        $this->assertStringContainsString('Davey House', File::get($profile->runtime_home.'/memories/MEMORY.md'));
        $this->assertStringContainsString('workspace_id: '.$workspace->id, File::get($profile->runtime_home.'/memories/MEMORY.md'));
        $this->assertStringContainsString('Harley Davey', File::get($profile->runtime_home.'/memories/USER.md'));
    }

    public function test_workspace_preference_updates_refresh_database_and_builtin_memory_without_cross_workspace_leakage(): void
    {
        $runtimeRoot = sys_get_temp_dir().'/hermes-bean-agent-memory-'.bin2hex(random_bytes(6));
        config()->set('services.hermes_runtime.users_home', $runtimeRoot);
        $this->beforeApplicationDestroyed(fn () => File::isDirectory($runtimeRoot) ? File::deleteDirectory($runtimeRoot) : null);

        $user = User::factory()->create(['name' => 'Harley Davey']);
        $service = app(AgentProfileService::class);
        $workspaceService = app(WorkspaceService::class);
        $personalProfile = $service->ensureForUser($user);
        $household = $workspaceService->createHousehold($user, 'Davey House');
        $householdProfile = $service->ensureForWorkspace($household, $user);

        $service->mergeSettings($householdProfile, [
            'personality_type' => 'organizer',
            'onboarding' => [
                'completed' => true,
                'priorities' => ['School pickups', 'Family dinner'],
                'context' => 'Lauren prefers reminders the night before.',
            ],
        ], 'agent');

        $householdMemory = File::get($householdProfile->runtime_home.'/memories/MEMORY.md');
        $householdUser = File::get($householdProfile->runtime_home.'/memories/USER.md');
        $this->assertStringContainsString('organizer', $householdMemory);
        $this->assertStringContainsString('School pickups', $householdMemory);
        $this->assertStringContainsString('Lauren prefers reminders the night before.', $householdMemory);
        $this->assertStringContainsString('Harley Davey', $householdUser);
        $this->assertSame('Lauren prefers reminders the night before.', $householdProfile->refresh()->settings['memory']['user_preferences']['context']);

        File::append($householdProfile->runtime_home.'/memories/MEMORY.md', PHP_EOL.'- Lauren likes dinner reminders before 5pm.'.PHP_EOL);
        $service->mergeSettings($householdProfile->refresh(), [
            'onboarding' => [
                'completed' => true,
                'priorities' => ['School pickups', 'Family dinner', 'Soccer'],
                'context' => 'Lauren prefers reminders the night before.',
            ],
        ], 'settings');
        $this->assertStringContainsString('Lauren likes dinner reminders before 5pm.', File::get($householdProfile->runtime_home.'/memories/MEMORY.md'));

        $this->assertFileExists($personalProfile->runtime_home.'/memories/MEMORY.md');
        $this->assertStringNotContainsString('Lauren prefers reminders the night before.', File::get($personalProfile->runtime_home.'/memories/MEMORY.md'));
        foreach (['USER.md', 'MEMORY.md', 'HOUSEHOLD.md', 'PREFERENCES.md'] as $file) {
            $this->assertFileDoesNotExist($householdProfile->runtime_home.'/'.$file);
            $this->assertFileDoesNotExist($personalProfile->runtime_home.'/'.$file);
        }
        $this->assertFileDoesNotExist($householdProfile->runtime_home.'/memories/HOUSEHOLD.md');
        $this->assertFileDoesNotExist($householdProfile->runtime_home.'/memories/PREFERENCES.md');
    }

    public function test_today_endpoint_includes_agent_profile(): void
    {
        $token = $this->apiToken('today-profile@example.com');

        $this->withToken($token)
            ->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.agent_profile.provider', 'openai')
            ->assertJsonPath('data.agent_profile.model', 'gpt-5-mini')
            ->assertJsonPath('data.user.needs_bean_onboarding', true)
            ->assertJsonPath('data.user.bean_preferences_ready', false)
            ->assertJsonPath('data.agent_profile.approval_policy.approval_surface', 'app_home_top_banner');
    }
}
