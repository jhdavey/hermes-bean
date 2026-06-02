<?php

namespace Tests\Feature;

use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
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
        $user = User::where('email', 'realtime-session@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Take out trash',
            'type' => 'chore',
            'status' => 'pending',
            'due_at' => now()->setTime(19, 0),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Dentist',
            'starts_at' => now()->setTime(14, 0),
            'ends_at' => now()->setTime(15, 0),
        ]);

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
            && data_get($request->data(), 'session.audio.input.transcription.prompt') === null
            && data_get($request->data(), 'session.audio.input.turn_detection.type') === 'server_vad'
            && data_get($request->data(), 'session.audio.input.turn_detection.silence_duration_ms') === 350
            && data_get($request->data(), 'session.audio.input.turn_detection.create_response') === false
            && preg_match('/^[A-Za-z0-9_-]+$/', (string) data_get($request->data(), 'session.tracing.group_id')) === 1
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Only respond when the user is clearly talking to Bean')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Yes, I can hear you.')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'current time/date questions')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Dashboard context snapshot')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Take out trash')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Dentist')
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

    public function test_realtime_call_endpoint_exchanges_sdp_server_side(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/calls' => Http::response("v=0\r\nanswer", 200, ['Content-Type' => 'application/sdp']),
        ]);

        $token = $this->apiToken('realtime-call@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
            'metadata' => [
                'client_context' => ['timezone_offset' => '-04:00'],
            ],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/calls', [
            'session_id' => $sessionId,
            'sdp' => "v=0\r\no=- offer",
            'voice' => 'coral',
            'metadata' => [
                'source' => 'test-realtime-call',
                'client_context' => ['timezone_offset' => '-04:00'],
            ],
        ])->assertOk()
            ->assertHeader('Content-Type', 'application/sdp')
            ->assertContent("v=0\r\nanswer");

        $this->assertTrue((bool) ConversationSession::findOrFail($sessionId)->metadata['realtime']);
        $this->assertSame('test-realtime-call', ConversationSession::findOrFail($sessionId)->metadata['source']);

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/realtime/calls'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && str_contains((string) $request->body(), 'name="sdp"')
            && str_contains((string) $request->body(), "v=0\r\no=- offer")
            && str_contains((string) $request->body(), 'name="session"')
            && str_contains((string) $request->body(), '"model":"gpt-realtime-test"')
            && str_contains((string) $request->body(), '"voice":"coral"')
            && str_contains((string) $request->body(), '"group_id":"conversation_session_'.$sessionId.'"'));
    }

    public function test_realtime_call_endpoint_marks_upstream_bad_requests_as_not_retryable(): void
    {
        Http::fake([
            'https://api.openai.test/v1/realtime/calls' => Http::response([
                'error' => [
                    'message' => 'Invalid SDP request.',
                    'type' => 'invalid_request_error',
                ],
            ], 400),
        ]);

        $token = $this->apiToken('realtime-call-400@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/calls', [
            'session_id' => $sessionId,
            'sdp' => "v=0\r\no=- offer",
        ])->assertStatus(502)
            ->assertJsonPath('code', 'openai_realtime_call_failed')
            ->assertJsonPath('status', 400)
            ->assertJsonPath('retryable', false)
            ->assertJsonPath('upstream_message', 'Invalid SDP request.');
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

    public function test_realtime_dashboard_context_endpoint_returns_snapshot_and_full_instructions(): void
    {
        $token = $this->apiToken('realtime-context@example.com');
        $user = User::where('email', 'realtime-context@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
            'metadata' => [
                'client_context' => ['timezone_offset' => '-04:00'],
            ],
        ])->assertCreated()->json('data.id');

        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'title' => 'Call insurance',
            'remind_at' => now()->addHour(),
            'status' => 'pending',
        ]);

        $this->withToken($token)->getJson("/api/assistant/realtime/dashboard-context?session_id={$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.workspace.id', $workspace->id)
            ->assertJsonPath('data.snapshot.reminders_due.0.title', 'Call insurance')
            ->assertJsonPath('data.snapshot.counts.reminders_next_7_days', 1)
            ->assertJsonPath('data.snapshot.timezone', config('app.timezone'))
            ->assertJson(fn ($json) => $json
                ->where('data.prompt_text', fn (string $value): bool => str_contains($value, 'Dashboard context snapshot'))
                ->where('data.instructions', fn (string $value): bool => str_contains($value, 'Call insurance') && str_contains($value, 'Only respond when the user is clearly talking to Bean'))
                ->etc()
            );
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
