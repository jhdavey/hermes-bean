<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiTextToSpeechTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_save_openai_tts_preferences_without_exposing_key(): void
    {
        $token = $this->apiToken('tts-user@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'agent_personality' => 'balanced',
            'onboarding_priorities' => ['Planning'],
            'onboarding_context' => 'Use voice on the wall kiosk.',
            'tts_provider' => 'openai',
            'tts_openai_api_key' => 'sk-test-user-key',
            'tts_openai_voice' => 'marin',
            'tts_openai_instructions' => 'Sound natural and concise.',
        ])->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.provider', 'openai')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.openai_voice', 'marin')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.openai_api_key_configured', true)
            ->assertJsonMissing(['openai_api_key_encrypted']);

        $profile = AgentProfile::query()->whereNotNull('workspace_id')->firstOrFail();

        $this->assertNotSame('sk-test-user-key', $profile->settings['tts']['openai_api_key_encrypted']);
        $this->assertSame('sk-test-user-key', Crypt::decryptString($profile->settings['tts']['openai_api_key_encrypted']));

        $this->withToken($token)->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.agent_profile.settings.tts.openai_api_key_configured', true)
            ->assertJsonMissing(['openai_api_key_encrypted']);
    }

    public function test_openai_tts_endpoint_uses_saved_user_key(): void
    {
        config(['services.openai.server_api_key' => '']);
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('fake-wav', 200, ['Content-Type' => 'audio/wav']),
        ]);

        $token = $this->apiToken('tts-endpoint@example.com');
        $this->withToken($token)->patchJson('/api/auth/me', [
            'tts_provider' => 'openai',
            'tts_openai_api_key' => 'sk-endpoint-key',
            'tts_openai_voice' => 'cedar',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'text' => 'Hello from Bean.',
        ])->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('X-HeyBean-TTS-Provider', 'openai')
            ->assertHeader('X-HeyBean-TTS-Voice', 'cedar');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/audio/speech'
                && $request->hasHeader('Authorization', 'Bearer sk-endpoint-key')
                && $request['model'] === 'gpt-4o-mini-tts'
                && $request['voice'] === 'cedar'
                && $request['input'] === 'Hello from Bean.'
                && $request['response_format'] === 'wav';
        });
    }

    public function test_openai_tts_endpoint_falls_back_to_app_key_when_saved_key_fails(): void
    {
        config(['services.openai.server_api_key' => 'sk-app-tts-key']);
        Http::fake(function ($request) {
            if ($request->hasHeader('Authorization', 'Bearer sk-user-tts-key')) {
                return Http::response(['error' => ['message' => 'invalid key']], 401);
            }

            return Http::response('app-wav', 200, ['Content-Type' => 'audio/wav']);
        });

        $token = $this->apiToken('tts-fallback@example.com');
        $this->withToken($token)->patchJson('/api/auth/me', [
            'tts_provider' => 'openai',
            'tts_openai_api_key' => 'sk-user-tts-key',
            'tts_openai_voice' => 'marin',
        ])->assertOk();

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'text' => 'Hello from Bean.',
        ])->assertOk()
            ->assertHeader('Content-Type', 'audio/wav')
            ->assertHeader('X-HeyBean-TTS-Key-Source', 'app')
            ->assertContent('app-wav');

        Http::assertSentCount(2);
        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer sk-user-tts-key')
                && $request['voice'] === 'marin';
        });
        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization', 'Bearer sk-app-tts-key')
                && $request['voice'] === 'marin';
        });
    }

    public function test_masked_openai_tts_key_does_not_overwrite_saved_key(): void
    {
        $token = $this->apiToken('tts-mask@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', [
            'tts_provider' => 'openai',
            'tts_openai_api_key' => 'sk-original-key',
            'tts_openai_voice' => 'marin',
        ])->assertOk();

        $this->withToken($token)->patchJson('/api/auth/me', [
            'tts_provider' => 'openai',
            'tts_openai_api_key' => '****************',
            'tts_openai_voice' => 'cedar',
        ])->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.openai_api_key_configured', true)
            ->assertJsonPath('data.active_workspace_agent_profile.settings.tts.openai_voice', 'cedar');

        $profile = AgentProfile::query()->whereNotNull('workspace_id')->firstOrFail();

        $this->assertSame('sk-original-key', Crypt::decryptString($profile->settings['tts']['openai_api_key_encrypted']));
    }

    public function test_openai_tts_preferences_and_playback_use_current_workspace(): void
    {
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
            'tts_openai_api_key' => 'sk-household-key',
            'tts_openai_voice' => 'coral',
            'tts_openai_instructions' => 'Sound calm and natural.',
        ])->assertOk();

        $householdProfile = AgentProfile::query()->where('workspace_id', $householdId)->firstOrFail();
        $personalProfile = AgentProfile::query()->whereNotNull('workspace_id')->where('workspace_id', '!=', $householdId)->firstOrFail();
        $personalTts = $personalProfile->settings['tts'] ?? [];

        $this->assertSame('openai', $householdProfile->settings['tts']['provider']);
        $this->assertSame('sk-household-key', Crypt::decryptString($householdProfile->settings['tts']['openai_api_key_encrypted']));
        $this->assertNotSame('openai', $personalTts['provider'] ?? 'browser');
        $this->assertArrayNotHasKey('openai_api_key_encrypted', $personalTts);

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'workspace_id' => $householdId,
            'text' => 'Hello from the household workspace.',
        ])->assertOk()
            ->assertHeader('Content-Type', 'audio/wav');

        $this->withToken($token)->postJson('/api/assistant/tts', [
            'text' => 'Hello from the personal workspace.',
        ])->assertStatus(409)
            ->assertJsonPath('code', 'openai_tts_not_configured');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/audio/speech'
                && $request->hasHeader('Authorization', 'Bearer sk-household-key')
                && $request['voice'] === 'coral'
                && $request['instructions'] === 'Sound calm and natural.';
        });
    }
}
