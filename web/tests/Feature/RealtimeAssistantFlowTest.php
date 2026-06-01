<?php

namespace Tests\Feature;

use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RealtimeAssistantFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        config()->set('services.hermes_realtime.api_key', 'test-key');
        config()->set('services.hermes_realtime.model', 'gpt-realtime-test');
        config()->set('services.hermes_realtime.voice', 'marin');
    }

    public function test_realtime_session_creates_local_session_and_ephemeral_client_secret(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response([
                'value' => 'ek_test_realtime_secret',
                'expires_at' => now()->addMinute()->timestamp,
            ], 200),
        ]);

        $token = $this->apiToken('realtime-session@example.com');

        $this->withToken($token)->postJson('/api/ai/realtime/session', [
            'title' => 'Realtime test',
            'metadata' => [
                'source' => 'test',
                'client_context' => [
                    'current_local_time' => '2026-06-01T10:15:00-04:00',
                    'timezone_offset' => '-04:00',
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.session.runtime_mode', 'realtime')
            ->assertJsonPath('data.client_secret.value', 'ek_test_realtime_secret')
            ->assertJsonPath('data.openai.model', 'gpt-realtime-test')
            ->assertJsonPath('data.openai.voice', 'marin');

        $this->assertDatabaseHas('conversation_sessions', [
            'title' => 'Realtime test',
            'runtime_mode' => 'realtime',
        ]);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/realtime/client_secrets'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && data_get($request->data(), 'session.tools.0.name') === 'queue_bean_work'
            && data_get($request->data(), 'session.audio.input.transcription.model') === 'gpt-4o-mini-transcribe'
            && data_get($request->data(), 'session.audio.input.turn_detection.type') === 'server_vad'
            && data_get($request->data(), 'session.audio.input.turn_detection.silence_duration_ms') === 350
            && data_get($request->data(), 'session.audio.input.turn_detection.create_response') === true
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Only respond when the user is clearly talking to Bean')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Yes, I can hear you.')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'current time/date questions')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'read current app data')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'timezone_offset'));
    }

    public function test_realtime_session_can_reuse_existing_daily_chat_session(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response([
                'value' => 'ek_existing_session_secret',
                'expires_at' => now()->addMinute()->timestamp,
            ], 200),
        ]);

        $token = $this->apiToken('realtime-existing-session@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Today with Bean',
            'runtime_mode' => 'chat',
            'metadata' => ['daily_date' => '2026-06-01'],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/ai/realtime/session', [
            'session_id' => $sessionId,
            'metadata' => [
                'source' => 'test-realtime',
                'client_context' => ['timezone_offset' => '-04:00'],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.session.id', $sessionId)
            ->assertJsonPath('data.session.runtime_mode', 'chat')
            ->assertJsonPath('data.session.metadata.realtime', true)
            ->assertJsonPath('data.client_secret.value', 'ek_existing_session_secret');

        $this->assertDatabaseHas('conversation_sessions', [
            'id' => $sessionId,
            'runtime_mode' => 'chat',
        ]);
    }

    public function test_realtime_session_maps_legacy_tts_voices_to_supported_realtime_voice(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response([
                'value' => 'ek_mapped_voice_secret',
                'expires_at' => now()->addMinute()->timestamp,
            ], 200),
        ]);

        $token = $this->apiToken('realtime-legacy-voice@example.com');

        $this->withToken($token)->postJson('/api/ai/realtime/session', [
            'title' => 'Realtime voice map',
            'voice' => 'nova',
        ])->assertCreated()
            ->assertJsonPath('data.openai.requested_voice', 'nova')
            ->assertJsonPath('data.openai.voice', 'shimmer');

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/realtime/client_secrets'
            && data_get($request->data(), 'session.audio.output.voice') === 'shimmer');
    }

    public function test_realtime_session_marks_upstream_bad_requests_as_not_retryable(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/client_secrets' => Http::response([
                'error' => [
                    'message' => 'Invalid realtime session configuration.',
                    'type' => 'invalid_request_error',
                ],
            ], 400),
        ]);

        $token = $this->apiToken('realtime-upstream-400@example.com');

        $this->withToken($token)->postJson('/api/ai/realtime/session', [
            'title' => 'Realtime bad request',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'openai_realtime_session_failed')
            ->assertJsonPath('status', 400)
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('upstream_message', 'Invalid realtime session configuration.');
    }

    public function test_realtime_messages_can_be_appended_without_running_agent(): void
    {
        $token = $this->apiToken('realtime-message@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'chat',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/messages', [
            'session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Yes, I can hear you.',
            'metadata' => ['realtime' => ['item_id' => 'item_123']],
        ])->assertCreated()
            ->assertJsonPath('data.role', 'assistant')
            ->assertJsonPath('data.content', 'Yes, I can hear you.')
            ->assertJsonPath('data.metadata.runtime', 'realtime');

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Yes, I can hear you.',
        ]);
    }

    public function test_realtime_client_events_are_accepted_for_voice_diagnostics(): void
    {
        $token = $this->apiToken('realtime-client-event@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/client-events', [
            'event_type' => 'ice_webrtc_connection_failure',
            'session_id' => $sessionId,
            'phase' => 'working',
            'message' => 'Reconnecting',
            'details' => [
                'ice_connection_state' => 'failed',
                'user_agent' => 'Feature test',
            ],
        ])->assertOk()
            ->assertJsonPath('data.ok', true);
    }

    public function test_realtime_tool_call_queues_background_laravel_agent_run(): void
    {
        Queue::fake();

        $token = $this->apiToken('realtime-tool@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/tool-calls', [
            'session_id' => $sessionId,
            'tool_name' => 'queue_bean_work',
            'call_id' => 'call_123',
            'arguments' => [
                'content' => 'Move lunch tomorrow to noon',
                'client_context' => ['timezone_offset' => '-04:00'],
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.message', 'Bean is working on that in the background.');

        $run = AssistantRun::firstOrFail();
        $this->assertSame('realtime', $run->source);
        $this->assertSame('queued', $run->status);
        $this->assertSame('Move lunch tomorrow to noon', $run->input);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Move lunch tomorrow to noon',
        ]);

        Queue::assertPushed(ProcessAssistantRun::class, fn (ProcessAssistantRun $job): bool => $job->assistantRunId === $run->id);
    }

    public function test_async_run_endpoint_preserves_existing_chat_session_contract(): void
    {
        Queue::fake();

        $token = $this->apiToken('async-run@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Plan my afternoon',
            'metadata' => ['source' => 'flutter'],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.session.status', 'queued')
            ->assertJsonPath('data.user_message.content', 'Plan my afternoon');

        $this->assertSame('queued', ConversationSession::findOrFail($sessionId)->status);
        Queue::assertPushed(ProcessAssistantRun::class);
    }
}
