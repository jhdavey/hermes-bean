<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeSession;
use App\Services\OpenAiVoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
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
        $this->assertSame('gpt-realtime-2.1-mini', OpenAiVoiceService::DEFAULT_REALTIME_MODEL);
        $this->assertSame([
            'gpt-realtime-2.1-mini',
            'gpt-realtime-2.1',
        ], OpenAiVoiceService::SUPPORTED_REALTIME_MODELS);
    }

    public function test_legacy_realtime_model_is_rejected_before_any_provider_request(): void
    {
        config()->set('services.openai.realtime_model', 'gpt-realtime');
        Http::preventStrayRequests();

        try {
            app(OpenAiVoiceService::class)->defaultVoiceSettings();
            $this->fail('The legacy Realtime model should have been rejected.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('configure a supported Realtime 2 model', $error->getMessage());
        }

        Http::assertNothingSent();
    }

    public function test_same_origin_session_creates_one_audio_native_call_and_returns_only_public_binding(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.openai.realtime_model', OpenAiVoiceService::DEFAULT_REALTIME_MODEL);
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
            ->assertJsonPath('data.model', OpenAiVoiceService::DEFAULT_REALTIME_MODEL)
            ->assertJsonPath('data.voice', 'shimmer')
            ->assertJsonPath('data.sideband_ready', false)
            ->assertJsonStructure(['data' => ['realtime_session_id', 'playback_capability', 'sdp']])
            ->assertJsonMissing(['call_test_123', 'test-openai-key', 'client_secret', 'tools']);
        $this->assertSame(
            "v=0\r\no=- 123 456 IN IP4 127.0.0.1\r\n",
            $response->json('data.sdp'),
            'The browser must receive the provider answer with its final SDP line terminator intact.',
        );

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

        Http::assertSent(function ($request) use ($user): bool {
            $this->assertSame('https://api.openai.test/v1/realtime/calls', $request->url());
            $this->assertTrue($request->isMultipart());
            $this->assertStringStartsWith(
                'multipart/form-data; boundary=',
                (string) ($request->header('Content-Type')[0] ?? ''),
            );
            $this->assertTrue($request->hasHeader('Authorization', 'Bearer test-openai-key'));
            $this->assertTrue($request->hasHeader('Accept', 'application/sdp'));
            $this->assertTrue($request->hasHeader(
                'OpenAI-Safety-Identifier',
                hash_hmac('sha256', (string) $user->id, (string) config('app.key')),
            ));

            $parts = $request->data();
            $this->assertCount(2, $parts);
            $this->assertSame(['name' => 'sdp', 'contents' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n"], $parts[0]);
            $this->assertSame('session', $parts[1]['name'] ?? null);
            $this->assertSame(['name', 'contents'], array_keys($parts[1]));
            $session = json_decode((string) ($parts[1]['contents'] ?? ''), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame('realtime', data_get($session, 'type'));
            $this->assertSame(OpenAiVoiceService::DEFAULT_REALTIME_MODEL, data_get($session, 'model'));
            $this->assertSame('low', data_get($session, 'reasoning.effort'));
            $this->assertSame(['audio'], data_get($session, 'output_modalities'));
            $this->assertSame('audio/pcm', data_get($session, 'audio.input.format.type'));
            $this->assertSame(24000, data_get($session, 'audio.input.format.rate'));
            $this->assertSame(2000, data_get($session, 'audio.input.turn_detection.silence_duration_ms'));
            $this->assertFalse(data_get($session, 'audio.input.turn_detection.create_response'));
            $this->assertFalse(data_get($session, 'audio.input.turn_detection.interrupt_response'));
            $this->assertArrayNotHasKey('transcription', (array) data_get($session, 'audio.input'));
            $this->assertSame([], data_get($session, 'tools'));
            $this->assertSame('none', data_get($session, 'tool_choice'));

            return true;
        });
    }

    public function test_provider_timeout_fails_closed_and_logs_only_an_actionable_sanitized_cause(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('realtime-session-timeout@example.com');
        $user = User::query()->where('email', 'realtime-session-timeout@example.com')->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();

        Http::fake(static function (): never {
            throw new ConnectionException('cURL error 28: Operation timed out while using sk-live-must-not-leak');
        });
        Log::shouldReceive('warning')
            ->once()
            ->withArgs(static function (string $message, array $context): bool {
                return $message === 'Realtime browser voice provider connection failed.'
                    && ($context['stage'] ?? null) === 'realtime_sdp'
                    && ($context['exception'] ?? null) === ConnectionException::class
                    && ($context['cause_code'] ?? null) === 'provider_connection_timeout'
                    && ! str_contains(json_encode($context, JSON_THROW_ON_ERROR), 'sk-live-must-not-leak');
            });

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', [
            'session_id' => $conversation->id,
            'workspace_id' => $conversation->workspace_id,
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
            'timezone' => 'America/New_York',
            'sdp' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n",
        ])->assertStatus(502)
            ->assertJsonPath('error.code', 'realtime_connection_failed')
            ->assertJsonMissing(['sk-live-must-not-leak']);

        $this->assertDatabaseCount('voice_realtime_sessions', 0);
        $this->assertDatabaseCount('ai_usage_logs', 0);
    }

    public function test_remote_description_failure_keeps_its_stable_code_without_persisting_native_sdp_detail(): void
    {
        $token = $this->apiToken('realtime-client-failure@example.com');
        $user = User::query()->where('email', 'realtime-client-failure@example.com')->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $payload = [
            'failure_id' => 'remote-description-failure-0001',
            'stage' => 'connection',
            'code' => 'realtime_remote_description_failed',
            'message' => 'Native SDP detail with ephemeral-secret must not persist.',
            'cause_chain' => [[
                'code' => 'realtime_remote_description_failed',
                'message' => 'a=ice-pwd:ephemeral-secret Invalid SDP line.',
            ]],
            'session_id' => $conversation->id,
        ];

        $this->withToken($token)
            ->postJson('/api/assistant/voice/client-failures', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', false);
        $this->withToken($token)
            ->postJson('/api/assistant/voice/client-failures', $payload)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true);

        $event = ActivityEvent::query()
            ->where('event_type', 'browser_voice_realtime.client_failure')
            ->sole();
        $this->assertSame('connection', data_get($event->payload, 'stage'));
        $this->assertSame('realtime_remote_description_failed', data_get($event->payload, 'code'));
        $this->assertSame('Browser voice connection failed.', data_get($event->payload, 'message'));
        $this->assertSame(
            'realtime_remote_description_failed',
            data_get($event->payload, 'cause_chain.0.code'),
        );
        $this->assertStringNotContainsString('ephemeral-secret', json_encode($event->payload, JSON_THROW_ON_ERROR));
    }

    public function test_local_pcm_ack_failure_codes_remain_specific_but_uncontrolled_codes_collapse(): void
    {
        $token = $this->apiToken('realtime-local-ack-failure@example.com');
        $user = User::query()->where('email', 'realtime-local-ack-failure@example.com')->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $base = [
            'stage' => 'local_wake',
            'message' => 'Native decoder detail must not persist.',
            'session_id' => $conversation->id,
        ];

        $this->withToken($token)
            ->postJson('/api/assistant/voice/client-failures', [
                ...$base,
                'failure_id' => 'local-ack-failure-codes-0001',
                'code' => 'pcm_decode_rejected',
                'cause_chain' => collect([
                    'pcm_ack_activation_pending',
                    'pcm_ack_not_ready',
                    'pcm_ack_failed',
                    'pcm_ack_generation_mismatch',
                ])->map(fn (string $code): array => [
                    'code' => $code,
                    'message' => 'Worker detail must not persist.',
                ])->all(),
            ])
            ->assertOk()
            ->assertJsonPath('data.turn_failure_recovery', null);
        $this->withToken($token)
            ->postJson('/api/assistant/voice/client-failures', [
                ...$base,
                'failure_id' => 'local-ack-failure-codes-0002',
                'code' => 'pcm_ack_invalid_audio',
                'cause_chain' => [
                    ['code' => 'pcm_ack_unknown', 'message' => 'Unknown worker detail.'],
                    ['code' => 'uncontrolled_native_detail', 'message' => 'Sensitive native detail.'],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('data.turn_failure_recovery', null);

        $events = ActivityEvent::query()
            ->where('event_type', 'browser_voice_realtime.client_failure')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $events);
        $this->assertSame('pcm_decode_rejected', data_get($events[0]->payload, 'code'));
        $this->assertSame([
            'pcm_ack_activation_pending',
            'pcm_ack_not_ready',
            'pcm_ack_failed',
            'pcm_ack_generation_mismatch',
        ], collect(data_get($events[0]->payload, 'cause_chain'))->pluck('code')->all());
        $this->assertSame('pcm_ack_invalid_audio', data_get($events[1]->payload, 'code'));
        $this->assertSame('pcm_ack_unknown', data_get($events[1]->payload, 'cause_chain.0.code'));
        $this->assertSame('local_wake_failure', data_get($events[1]->payload, 'cause_chain.1.code'));
        $this->assertSame('Private wake detection failed.', data_get($events[1]->payload, 'message'));
        $this->assertStringNotContainsString(
            'native detail',
            strtolower(json_encode($events->pluck('payload')->all(), JSON_THROW_ON_ERROR)),
        );
    }
}
