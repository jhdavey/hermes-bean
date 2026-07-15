<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeSession;
use App\Services\OpenAiVoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class RealtimeVoiceSessionApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_realtime_voice_allowlist_matches_the_current_provider_set(): void
    {
        $voice = app(OpenAiVoiceService::class);

        $this->assertSame([
            'alloy',
            'ash',
            'ballad',
            'coral',
            'echo',
            'sage',
            'shimmer',
            'verse',
            'marin',
            'cedar',
        ], $voice->voiceKeys());
        $this->assertSame(OpenAiVoiceService::DEFAULT_VOICE, $voice->normalizeVoice('nova'));
    }

    public function test_same_origin_session_creates_one_audio_native_call_and_returns_only_public_binding(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.openai.realtime_model', 'gpt-realtime-test');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('realtime-session-api@example.com');
        $user = User::query()->where('email', 'realtime-session-api@example.com')->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $this->withToken($token)->patchJson('/api/auth/me', ['voice' => 'shimmer'])->assertOk();

        Http::fake([
            'api.openai.test/v1/realtime/calls' => Http::response(
                "v=0\r\no=- 123 456 IN IP4 127.0.0.1\r\n",
                201,
                ['Content-Type' => 'application/sdp', 'Location' => '/v1/realtime/calls/call_test_123'],
            ),
        ]);

        $response = $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', [
            'session_id' => $conversation->id,
            'workspace_id' => $conversation->workspace_id,
            'controller_generation' => 4,
            'provider_connection_generation' => 2,
            'timezone' => 'America/New_York',
            'sdp' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n",
        ])->assertOk()
            ->assertJsonPath('data.provider', 'openai_realtime')
            ->assertJsonPath('data.model', 'gpt-realtime-test')
            ->assertJsonPath('data.voice', 'shimmer')
            ->assertJsonPath('data.sideband_ready', false)
            ->assertJsonStructure(['data' => ['realtime_session_id', 'playback_capability', 'sdp']])
            ->assertJsonMissing(['call_test_123', 'test-openai-key', 'client_secret', 'tools']);

        $ledger = VoiceRealtimeSession::query()->sole();
        $this->assertSame('call_test_123', $ledger->provider_call_id);
        $this->assertSame($response->json('data.realtime_session_id'), $ledger->public_id);
        $this->assertSame(4, $ledger->controller_generation);
        $this->assertSame(2, data_get($ledger->metadata, 'provider_connection_generation'));
        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => $user->id,
            'request_type' => 'voice_realtime_session',
            'status' => 'opened',
        ]);

        Http::assertSent(function ($request): bool {
            $body = $request->body();
            preg_match('/name="session"\r\nContent-Type: application\/json\r\n\r\n(.*?)\r\n--/s', $body, $match);
            $session = json_decode((string) ($match[1] ?? ''), true);

            return $request->url() === 'https://api.openai.test/v1/realtime/calls'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && data_get($session, 'type') === 'realtime'
                && data_get($session, 'output_modalities') === ['audio']
                && data_get($session, 'audio.input.format.type') === 'audio/pcm'
                && data_get($session, 'audio.input.format.rate') === 24000
                && data_get($session, 'audio.input.turn_detection.silence_duration_ms') === 2000
                && data_get($session, 'audio.input.turn_detection.create_response') === false
                && data_get($session, 'audio.input.turn_detection.interrupt_response') === false
                && ! array_key_exists('transcription', (array) data_get($session, 'audio.input'))
                && data_get($session, 'tools') === []
                && data_get($session, 'tool_choice') === 'none';
        });
    }
}
