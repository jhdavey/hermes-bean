<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_me_exposes_default_voice_settings_and_available_openai_voices(): void
    {
        $token = $this->apiToken('voice-default@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.provider', 'openai')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.voice', 'alloy')
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
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.voice', 'nova');

        $profile = AgentProfile::where('workspace_id', $user->fresh()->default_workspace_id)->firstOrFail();
        $this->assertSame('nova', data_get($profile->settings, 'voice.voice'));

        $this->withToken($token)->patchJson('/api/auth/me', [
            'voice' => 'not-a-real-openai-voice',
        ])->assertUnprocessable();
    }

    public function test_voice_speech_endpoint_uses_selected_openai_voice_and_returns_audio_payload(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('voice-speech@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', ['voice' => 'shimmer'])->assertOk();

        Http::fake([
            'api.openai.test/v1/audio/speech' => Http::response('fake-mp3-bytes', 200, [
                'Content-Type' => 'audio/mpeg',
            ]),
        ]);

        $this->withToken($token)->postJson('/api/assistant/voice/speech', [
            'text' => 'Done — I moved lunch to tomorrow.',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'openai')
            ->assertJsonPath('data.voice', 'shimmer')
            ->assertJsonPath('data.mime_type', 'audio/mpeg')
            ->assertJsonPath('data.audio_base64', base64_encode('fake-mp3-bytes'));

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.test/v1/audio/speech'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && $request['model'] === 'gpt-4o-mini-tts'
                && $request['voice'] === 'shimmer'
                && $request['input'] === 'Done — I moved lunch to tomorrow.';
        });
    }

    public function test_voice_transcription_endpoint_uses_openai_and_returns_text(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('voice-transcribe@example.com');

        Http::fake([
            'api.openai.test/v1/audio/transcriptions' => Http::response([
                'text' => 'Move lunch to tomorrow at noon.',
            ], 200),
        ]);

        $this->withToken($token)->post('/api/assistant/voice/transcriptions', [
            'audio' => UploadedFile::fake()->createWithContent('voice.webm', 'fake-audio-bytes'),
        ])->assertOk()
            ->assertJsonPath('data.text', 'Move lunch to tomorrow at noon.')
            ->assertJsonPath('data.provider', 'openai');

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.openai.test/v1/audio/transcriptions'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key');
        });
    }
}
