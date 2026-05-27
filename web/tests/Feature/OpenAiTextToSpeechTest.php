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
        Http::fake([
            'api.openai.com/v1/audio/speech' => Http::response('fake-mp3', 200, ['Content-Type' => 'audio/mpeg']),
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
            ->assertHeader('Content-Type', 'audio/mpeg');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.com/v1/audio/speech'
                && $request->hasHeader('Authorization', 'Bearer sk-endpoint-key')
                && $request['model'] === 'gpt-4o-mini-tts'
                && $request['voice'] === 'cedar'
                && $request['input'] === 'Hello from Bean.';
        });
    }
}
