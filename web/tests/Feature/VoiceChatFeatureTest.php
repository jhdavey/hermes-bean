<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_me_exposes_default_realtime_voice_settings_and_available_openai_voices(): void
    {
        $token = $this->apiToken('voice-default@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.provider', 'openai_realtime')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.voice', 'alloy')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.realtime_model', 'gpt-realtime')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.available_voices.0.key', 'alloy')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.available_voices.0.label', 'Alloy');
    }

    public function test_user_can_update_bean_voice_preference_per_active_workspace(): void
    {
        $token = $this->apiToken('voice-update@example.com');
        $user = User::where('email', 'voice-update@example.com')->firstOrFail();

        $this->withToken($token)->patchJson('/api/auth/me', [
            'voice' => 'nova',
        ])->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.provider', 'openai_realtime')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.voice', 'nova');

        $profile = AgentProfile::where('workspace_id', $user->fresh()->default_workspace_id)->firstOrFail();
        $this->assertSame('nova', data_get($profile->settings, 'voice.voice'));

        $this->withToken($token)->patchJson('/api/auth/me', [
            'voice' => 'not-a-real-openai-voice',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_create_transcription_and_speech_only_realtime_session(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.openai.realtime_model', 'gpt-realtime-test');
        config()->set('services.openai.realtime_webrtc_url', 'https://api.openai.test/v1/realtime/calls');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('voice-realtime@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', ['voice' => 'shimmer'])->assertOk();

        Http::fake([
            'api.openai.test/v1/realtime/client_secrets' => Http::response([
                'value' => 'ephemeral-client-secret',
                'expires_at' => 1893456000,
                'session' => [
                    'id' => 'sess_test_123',
                ],
            ], 200),
        ]);

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', [
            'timezone' => 'America/New_York',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'openai_realtime')
            ->assertJsonPath('data.model', 'gpt-realtime-test')
            ->assertJsonPath('data.voice', 'shimmer')
            ->assertJsonPath('data.client_secret', 'ephemeral-client-secret')
            ->assertJsonPath('data.session_id', 'sess_test_123')
            ->assertJsonPath('data.realtime_url', 'https://api.openai.test/v1/realtime/calls')
            ->assertJsonPath('data.tools', [])
            ->assertJsonMissing(['test-openai-key']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/realtime/client_secrets'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && data_get($payload, 'session.type') === 'realtime'
                && data_get($payload, 'session.model') === 'gpt-realtime-test'
                && data_get($payload, 'session.audio.output.voice') === 'shimmer'
                && data_get($payload, 'session.audio.input.format.type') === 'audio/pcm'
                && data_get($payload, 'session.audio.input.format.rate') === 24000
                && data_get($payload, 'session.audio.input.transcription.language') === 'en'
                && str_contains((string) data_get($payload, 'session.audio.input.transcription.prompt'), 'background noise')
                && data_get($payload, 'session.audio.input.turn_detection.type') === 'server_vad'
                && data_get($payload, 'session.audio.input.turn_detection.silence_duration_ms') === 2000
                && data_get($payload, 'session.audio.input.turn_detection.create_response') === false
                && data_get($payload, 'session.audio.input.turn_detection.interrupt_response') === false
                && data_get($payload, 'session.tools') === []
                && data_get($payload, 'session.tool_choice') === 'none'
                && str_contains((string) data_get($payload, 'session.instructions'), 'US English transcription and speech surface')
                && str_contains((string) data_get($payload, 'session.instructions'), 'Never call tools')
                && str_contains((string) data_get($payload, 'session.instructions'), 'Never call tools or independently answer microphone input');
        });
    }
}
