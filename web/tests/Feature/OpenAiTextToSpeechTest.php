<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiTextToSpeechTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_openai_tts_preferences_without_workspace_key(): void
    {
        $token = $this->apiToken('tts-user@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'agent_personality' => 'balanced',
            'onboarding_priorities' => ['Planning'],
            'onboarding_context' => 'Use voice on the wall kiosk.',
            'tts_provider' => 'openai',
            'tts_openai_api_key' => 'sk-ignored-user-key',
            'tts_openai_voice' => 'marin',
            'tts_openai_instructions' => 'Sound natural and concise.',
        ])->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.provider', 'openai')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.openai_voice', 'marin')
            ->assertJsonMissing(['openai_api_key_encrypted'])
            ->assertJsonMissing(['openai_api_key_configured']);

        $profile = AgentProfile::query()->whereNotNull('workspace_id')->firstOrFail();

        $this->assertArrayNotHasKey('openai_api_key_encrypted', $profile->settings['tts']);

        $this->withToken($token)->getJson('/api/today')
            ->assertOk()
            ->assertJsonMissing(['openai_api_key_encrypted'])
            ->assertJsonMissing(['openai_api_key_configured']);
    }

    public function test_openai_tts_endpoint_uses_app_key(): void
    {
        config(['services.openai.server_api_key' => 'sk-app-tts-key']);
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('app-wav', 200, ['Content-Type' => 'audio/wav']),
        ]);

        $token = $this->apiToken('tts-app-key@example.com');
        $this->withToken($token)->patchJson('/api/auth/me', [
            'tts_provider' => 'openai',
            'tts_openai_voice' => 'marin',
            'tts_openai_instructions' => 'Warm and friendly.',
        ])->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.provider', 'openai')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.openai_app_key_configured', true)
            ->assertJsonMissing(['openai_api_key_configured']);

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'text' => 'Hello from Bean.',
        ])->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('X-HeyBean-TTS-Key-Source', 'app')
            ->assertHeader('X-HeyBean-TTS-Voice', 'marin')
            ->assertContent('app-wav');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/audio/speech'
                && $request->hasHeader('Authorization', 'Bearer sk-app-tts-key')
                && $request['voice'] === 'marin'
                && $request['instructions'] === 'Warm and friendly.';
        });
    }

    public function test_openai_tts_endpoint_requires_app_key(): void
    {
        config(['services.openai.server_api_key' => '']);
        Http::fake();

        $token = $this->apiToken('tts-no-app-key@example.com');
        $this->withToken($token)->patchJson('/api/auth/me', [
            'tts_provider' => 'openai',
            'tts_openai_api_key' => 'sk-ignored-user-key',
            'tts_openai_voice' => 'cedar',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'text' => 'Hello from Bean.',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'openai_tts_not_configured');

        Http::assertNothingSent();
    }

    public function test_openai_tts_endpoint_ignores_legacy_browser_provider_when_app_key_exists(): void
    {
        config(['services.openai.server_api_key' => 'sk-app-tts-key']);
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('app-wav', 200, ['Content-Type' => 'audio/wav']),
        ]);

        $token = $this->apiToken('tts-legacy-browser@example.com');
        $this->withToken($token)->patchJson('/api/auth/me', [
            'tts_provider' => 'browser',
            'tts_openai_voice' => 'cedar',
        ])->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.provider', 'openai');

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'text' => 'Hello from Bean.',
        ])->assertOk()
            ->assertHeader('X-HeyBean-TTS-Key-Source', 'app')
            ->assertHeader('X-HeyBean-TTS-Voice', 'cedar')
            ->assertContent('app-wav');

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer sk-app-tts-key')
                && $request['voice'] === 'cedar';
        });
    }

    public function test_openai_tts_preferences_and_playback_use_current_workspace_without_workspace_keys(): void
    {
        config(['services.openai.server_api_key' => 'sk-app-tts-key']);
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('workspace-wav', 200, ['Content-Type' => 'audio/wav']),
        ]);

        $token = $this->apiToken('tts-workspace@example.com');
        $householdId = $this->withToken($token)->postJson('/api/workspaces', [
            'name' => 'Household',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'workspace_id' => $householdId,
            'tts_provider' => 'openai',
            'tts_openai_api_key' => 'sk-ignored-household-key',
            'tts_openai_voice' => 'coral',
            'tts_openai_instructions' => 'Sound calm and natural.',
        ])->assertOk();

        $householdProfile = AgentProfile::query()->where('workspace_id', $householdId)->firstOrFail();
        $personalProfile = AgentProfile::query()->whereNotNull('workspace_id')->where('workspace_id', '!=', $householdId)->firstOrFail();

        $this->assertSame('openai', $householdProfile->settings['tts']['provider']);
        $this->assertArrayNotHasKey('openai_api_key_encrypted', $householdProfile->settings['tts']);
        $this->assertArrayNotHasKey('openai_api_key_encrypted', $personalProfile->settings['tts'] ?? []);

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'workspace_id' => $householdId,
            'text' => 'Hello from the household workspace.',
        ])->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('X-HeyBean-TTS-Key-Source', 'app');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/audio/speech'
                && $request->hasHeader('Authorization', 'Bearer sk-app-tts-key')
                && $request['voice'] === 'coral'
                && $request['instructions'] === 'Sound calm and natural.';
        });
    }
}
