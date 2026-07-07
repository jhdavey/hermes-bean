<?php

namespace Tests\Feature;

use App\Jobs\ProcessAssistantRun;
use App\Models\AiUsageLog;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AgentProfileService;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\StructuredHermesActionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
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
            && $request->hasHeader('OpenAI-Safety-Identifier')
            && data_get($request->data(), 'session.type') === 'realtime'
            && data_get($request->data(), 'session.tools.0.name') === 'queue_bean_work'
            && str_contains((string) data_get($request->data(), 'session.tools.0.parameters.properties.content.description'), 'exact latest user request')
            && str_contains((string) data_get($request->data(), 'session.tools.0.parameters.properties.content.description'), 'do not summarize')
            && data_get($request->data(), 'session.audio.input.transcription.model') === 'gpt-4o-mini-transcribe'
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'Hey Bean')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'Hey Ben')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'Hay Beans')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'Hey Beam')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'Hey Being')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'Hey Dean')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'clearly an assistant wake phrase')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'HeyBean')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'Google Calendar')
            && str_contains((string) data_get($request->data(), 'session.audio.input.transcription.prompt'), 'recycling')
            && data_get($request->data(), 'session.audio.input.turn_detection.type') === 'semantic_vad'
            && data_get($request->data(), 'session.audio.input.turn_detection.eagerness') === 'high'
            && data_get($request->data(), 'session.audio.input.turn_detection.create_response') === false
            && data_get($request->data(), 'session.audio.input.turn_detection.interrupt_response') === true
            && preg_match('/^[A-Za-z0-9_-]+$/', (string) data_get($request->data(), 'session.tracing.group_id')) === 1
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Mobile hold-to-talk requests are already addressed to Bean')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'For ambient realtime sessions outside hold-to-talk')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Voice brevity contract')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'at most two short sentences')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Do not read long lists')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Never describe yourself as an AI')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Never say you cannot access')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'follow-up questions')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'when is that')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'where is it')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'what time is that')
            && str_contains((string) data_get($request->data(), 'session.instructions'), "who's going")
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'short confirmations')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'absolutely')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'exactly')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'correct')
            && str_contains((string) data_get($request->data(), 'session.instructions'), "that's right")
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'you got it')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'last option')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'bottom one')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'pick option B')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'both')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'all of them')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'the first two')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Tuesday at three')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Friday morning')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'tomorrow morning')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'tomorrow at three')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'tonight at seven')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'day after tomorrow')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'this afternoon')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'next weekend')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'later today')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'anchor-time')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'after lunch')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'during lunch')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'lunchtime')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'after work')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'before school')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'on Friday')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'on June 12')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'from 2 to 3')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'between 1 and 2')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'until 5')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'by Friday')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'by end of day')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'before lunch')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'in 20 minutes')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'for half an hour')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'time-shift')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'an hour earlier')
            && str_contains((string) data_get($request->data(), 'session.instructions'), '30 minutes later')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'a little later')
            && str_contains((string) data_get($request->data(), 'session.instructions'), '10 minutes before')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'at the start')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'no alert')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'urgent')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'high priority')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'low priority')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'done')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'completed')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'still open')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'not done yet')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'every Friday')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'weekdays')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'just once')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'no repeat')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'with Sam')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'on Zoom')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'short declines')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'maybe later')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'neither')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'none of them')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Treat corrections')
            && str_contains((string) data_get($request->data(), 'session.instructions'), "that's wrong")
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'no, the other one')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'no, Tuesday')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'no, at three')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'no, make it tomorrow')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'undo or reversal follow-ups')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'exact latest user request unless the target is unclear')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'continuation, elaboration, repeat, and answer-shaping requests')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'tell me more')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'I missed that')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'quick version')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'elaborate')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'If the user interrupts while you are speaking')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'hold that thought')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'wait a second')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'let me stop you')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Accuracy contract')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Never infer absence from silence')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'realtime_fresh_context_unavailable')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'content set exactly to user_request')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Do not summarize or alter user_request')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Yes, I can hear you.')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'current time/date questions')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Dashboard context snapshot')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Conversation contract')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'exact latest user request')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'whether Bean can create, update, delete, undo, revert')
            && str_contains((string) data_get($request->data(), 'session.tools.0.description'), 'reversing calendar/task/reminder data')
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
        $user = User::where('email', 'realtime-existing-session@example.com')->firstOrFail();
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Move my dentist appointment to tomorrow afternoon.',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'I need one detail: which dentist appointment should I move?',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'I’m checking the latest app state now. If I need one more detail, I’ll ask.',
            'metadata' => ['runtime' => 'direct_queue_bridge'],
        ]);

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

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/realtime/client_secrets'
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Recent conversation turns from this Bean session')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'User: Move my dentist appointment to tomorrow afternoon.')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Bean: I need one detail: which dentist appointment should I move?')
            && ! str_contains((string) data_get($request->data(), 'session.instructions'), 'I’m checking the latest app state now'));
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
            && $request->hasHeader('OpenAI-Safety-Identifier')
            && str_contains((string) $request->body(), 'name="sdp"')
            && str_contains((string) $request->body(), 'Content-Type: application/sdp')
            && str_contains((string) $request->body(), "v=0\r\no=- offer")
            && str_contains((string) $request->body(), 'name="session"')
            && str_contains((string) $request->body(), 'Content-Type: application/json')
            && str_contains((string) $request->body(), '"type":"realtime"')
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

        $log = AiUsageLog::where('conversation_session_id', $sessionId)
            ->where('request_type', 'realtime_voice_event')
            ->firstOrFail();

        $this->assertSame('ice_webrtc_connection_failure', data_get($log->metadata, 'event_type'));
        $this->assertSame('working', data_get($log->metadata, 'phase'));
        $this->assertSame('Reconnecting', data_get($log->metadata, 'message'));
        $this->assertSame('failed', data_get($log->metadata, 'details.ice_connection_state'));
        $this->assertContains('ice_webrtc_connection_failure', $log->action_types);
    }

    public function test_realtime_quality_reports_web_voice_first_speech_instrumentation(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-04T15:00:00Z'));

        $token = $this->apiToken('web-voice-quality@example.com');
        $user = User::where('email', 'web-voice-quality@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        foreach ([
            ['event_type' => 'web_voice_turn_started', 'details' => ['user_content' => 'create a task', 'likely_needs_agent_work' => true]],
            ['event_type' => 'web_voice_first_speech', 'details' => ['elapsed_ms' => 430, 'speech_source' => 'client_fallback', 'text' => "Sure, I'll create that now."]],
        ] as $event) {
            AiUsageLog::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $sessionId,
                'provider' => 'openai',
                'model' => 'gpt-realtime-test',
                'route_tier' => 'realtime_voice_event',
                'request_type' => 'realtime_voice_event',
                'status' => 'completed',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'tool_call_count' => 0,
                'estimated_cost_usd' => 0,
                'action_types' => ['realtime_voice_event', $event['event_type']],
                'metadata' => $event,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->withToken($token)->getJson('/api/assistant/realtime/quality?days=7&session_id='.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.events.web_voice_turn_started_count', 1)
            ->assertJsonPath('data.events.web_voice_first_speech_count', 1)
            ->assertJsonPath('data.events.web_voice_first_speech_quality.status', 'pass')
            ->assertJsonPath('data.events.web_voice_first_speech_quality.sample_size', 1)
            ->assertJsonPath('data.events.web_voice_first_speech_quality.client_fallback_count', 1)
            ->assertJsonPath('data.events.web_voice_first_speech_quality.p95_first_speech_elapsed_ms', 430)
            ->assertJsonPath('data.speech.naturalness.sample_size', 1)
            ->assertJsonPath('data.speech.naturalness.violation_count', 0);
    }

    public function test_realtime_usage_persists_voice_latency_metrics(): void
    {
        $token = $this->apiToken('realtime-usage@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/usage', [
            'session_id' => $sessionId,
            'model' => 'gpt-realtime-test',
            'response_id' => 'resp_voice_123',
            'usage' => [
                'input_tokens' => 20,
                'output_tokens' => 12,
                'input_token_details' => ['audio_tokens' => 9],
                'output_token_details' => ['audio_tokens' => 6],
            ],
            'voice_seconds' => 1.25,
            'transcript_to_response_create_ms' => 180,
            'response_create_to_first_assistant_ms' => 340,
            'transcript_to_first_assistant_ms' => 520,
            'turn_completed_ms' => 1250,
            'spoken_character_count' => 38,
            'spoken_sentence_count' => 1,
            'spoken_brevity_violation' => false,
            'is_follow_up_turn' => true,
            'is_contextual_follow_up_turn' => true,
            'contextual_follow_up_kind' => 'confirmation',
            'realtime_usage_missing' => false,
            'tool_call_count' => 1,
            'action_types' => ['realtime_voice'],
        ])->assertCreated()
            ->assertJsonPath('data.ok', true);

        $log = AiUsageLog::where('conversation_session_id', $sessionId)
            ->where('request_type', 'realtime_voice')
            ->firstOrFail();

        $this->assertSame('gpt-realtime-test', $log->model);
        $this->assertSame(20, $log->input_tokens);
        $this->assertSame(12, $log->output_tokens);
        $this->assertSame(9, $log->audio_input_tokens);
        $this->assertSame(6, $log->audio_output_tokens);
        $this->assertSame(1, $log->tool_call_count);
        $this->assertSame('resp_voice_123', data_get($log->metadata, 'response_id'));
        $this->assertSame(1.25, data_get($log->metadata, 'voice_seconds'));
        $this->assertSame(180, data_get($log->metadata, 'transcript_to_response_create_ms'));
        $this->assertSame(340, data_get($log->metadata, 'response_create_to_first_assistant_ms'));
        $this->assertSame(520, data_get($log->metadata, 'transcript_to_first_assistant_ms'));
        $this->assertSame(1250, data_get($log->metadata, 'turn_completed_ms'));
        $this->assertSame(38, data_get($log->metadata, 'spoken_character_count'));
        $this->assertSame(1, data_get($log->metadata, 'spoken_sentence_count'));
        $this->assertFalse((bool) data_get($log->metadata, 'spoken_brevity_violation'));
        $this->assertTrue((bool) data_get($log->metadata, 'is_follow_up_turn'));
        $this->assertTrue((bool) data_get($log->metadata, 'is_contextual_follow_up_turn'));
        $this->assertSame('confirmation', data_get($log->metadata, 'contextual_follow_up_kind'));
        $this->assertFalse((bool) data_get($log->metadata, 'realtime_usage_missing'));
    }

    public function test_realtime_usage_accepts_missing_provider_usage_with_latency_metrics(): void
    {
        $token = $this->apiToken('realtime-usage-missing@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/usage', [
            'session_id' => $sessionId,
            'model' => 'gpt-realtime-test',
            'response_id' => 'resp_missing_usage',
            'usage' => [],
            'voice_seconds' => 2.4,
            'transcript_to_response_create_ms' => 220,
            'response_create_to_first_assistant_ms' => 410,
            'transcript_to_first_assistant_ms' => 630,
            'turn_completed_ms' => 2400,
            'spoken_character_count' => 44,
            'spoken_sentence_count' => 1,
            'spoken_brevity_violation' => false,
            'realtime_usage_missing' => true,
            'tool_call_count' => 0,
            'action_types' => ['realtime_voice'],
        ])->assertCreated()
            ->assertJsonPath('data.ok', true);

        $log = AiUsageLog::where('conversation_session_id', $sessionId)
            ->where('request_type', 'realtime_voice')
            ->firstOrFail();

        $this->assertSame(0, $log->input_tokens);
        $this->assertSame(0, $log->output_tokens);
        $this->assertSame(0, $log->audio_input_tokens);
        $this->assertSame(0, $log->audio_output_tokens);
        $this->assertSame('resp_missing_usage', data_get($log->metadata, 'response_id'));
        $this->assertSame(630, data_get($log->metadata, 'transcript_to_first_assistant_ms'));
        $this->assertSame(2400, data_get($log->metadata, 'turn_completed_ms'));
        $this->assertTrue((bool) data_get($log->metadata, 'realtime_usage_missing'));
    }

    public function test_realtime_quality_reports_voice_benchmark_status_from_recent_telemetry(): void
    {
        Carbon::setTestNow('2026-07-03 12:00:00');
        $token = $this->apiToken('realtime-quality@example.com');
        $user = User::where('email', 'realtime-quality@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        foreach ([
            ['response_id' => 'resp_fast_1', 'first_ms' => 520, 'turn_ms' => 1400, 'chars' => 42, 'sentences' => 1, 'brevity_violation' => false, 'follow_up' => false, 'contextual_follow_up' => false, 'usage_missing' => false],
            ['response_id' => 'resp_fast_2', 'first_ms' => 650, 'turn_ms' => 1800, 'chars' => 68, 'sentences' => 2, 'brevity_violation' => false, 'follow_up' => true, 'contextual_follow_up' => true, 'contextual_follow_up_kind' => 'reference', 'usage_missing' => false],
            ['response_id' => 'resp_slow_1', 'first_ms' => 1500, 'turn_ms' => 6100, 'chars' => 390, 'sentences' => 5, 'brevity_violation' => true, 'follow_up' => true, 'contextual_follow_up' => false, 'usage_missing' => true],
        ] as $index => $turn) {
            AiUsageLog::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $sessionId,
                'provider' => 'openai',
                'model' => 'gpt-realtime-test',
                'route_tier' => 'realtime_voice',
                'request_type' => 'realtime_voice',
                'status' => 'completed',
                'input_tokens' => 10,
                'output_tokens' => 8,
                'audio_input_tokens' => 4,
                'audio_output_tokens' => 3,
                'total_tokens' => 18,
                'tool_call_count' => $index === 2 ? 1 : 0,
                'estimated_cost_usd' => 0.001,
                'action_types' => ['realtime_voice'],
                'metadata' => [
                    'response_id' => $turn['response_id'],
                    'voice_seconds' => $turn['turn_ms'] / 1000,
                    'transcript_to_response_create_ms' => 180,
                    'response_create_to_first_assistant_ms' => $turn['first_ms'] - 180,
                    'transcript_to_first_assistant_ms' => $turn['first_ms'],
                    'turn_completed_ms' => $turn['turn_ms'],
                    'spoken_character_count' => $turn['chars'],
                    'spoken_sentence_count' => $turn['sentences'],
                    'spoken_brevity_violation' => $turn['brevity_violation'],
                    'is_follow_up_turn' => $turn['follow_up'],
                    'is_contextual_follow_up_turn' => $turn['contextual_follow_up'],
                    'contextual_follow_up_kind' => $turn['contextual_follow_up_kind'] ?? null,
                    'realtime_usage_missing' => $turn['usage_missing'],
                ],
                'created_at' => now()->subMinutes($index),
                'updated_at' => now()->subMinutes($index),
            ]);
        }

        foreach ([
            'flutter_realtime_barge_in',
            'flutter_realtime_followup_idle_timeout',
            'realtime_background_queued',
            'realtime_background_completed',
            'realtime_background_cancel_failure',
            'flutter_realtime_in_flight_cancel_failure',
            'flutter_realtime_premature_completion_claim',
            'dashboard_context_pre_response_success',
            'webrtc_connection_failure',
            'dashboard_context_pre_response_ack_timeout',
            'flutter_realtime_response_done',
            'flutter_realtime_barge_in_recovered',
            'flutter_realtime_followup_ready',
            'flutter_realtime_audio_done_ready',
            'flutter_realtime_progress_prompt',
            'flutter_realtime_progress_prompt_spoken',
            'flutter_realtime_pending_response_deferred_by_speech',
            'flutter_realtime_pending_response_recovered_after_non_actionable_speech',
        ] as $eventType) {
            $details = match ($eventType) {
                'flutter_realtime_barge_in' => [
                    'cancel_sent' => true,
                    'output_audio_cleared' => true,
                    'truncate_attempted' => true,
                    'truncate_sent' => true,
                    'cancel_dispatch_ms' => 18,
                    'interrupted_internal_prompt' => true,
                ],
                'dashboard_context_pre_response_success' => [
                    'elapsed_ms' => 82,
                    'ack_budget_ms' => 138,
                ],
                'realtime_background_queued' => [
                    'run_id' => 123,
                    'source' => 'tool_call',
                    'acknowledged' => true,
                    'acknowledgement_character_count' => 18,
                    'queue_elapsed_ms' => 520,
                ],
                'realtime_background_completed' => [
                    'run_id' => 123,
                    'spoken_character_count' => 42,
                    'spoken_text' => 'Lunch with Sam is scheduled for tomorrow at noon.',
                ],
                'flutter_realtime_response_done' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_text' => 'Tomorrow has two meetings.',
                    'assistant_answered' => true,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'reference',
                    'function_calls' => [],
                ],
                'flutter_realtime_barge_in_recovered' => [
                    'user_content' => 'what about tomorrow',
                    'assistant_answered' => true,
                    'has_user_content' => true,
                    'function_call_count' => 0,
                    'response_id' => 'resp_followup',
                    'recovery_elapsed_ms' => 980,
                ],
                'flutter_realtime_followup_ready' => [
                    'ready_elapsed_ms' => 12,
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'is_follow_up_turn' => true,
                    'is_contextual_follow_up_turn' => true,
                    'contextual_follow_up_kind' => 'reference',
                    'turn_completed_ms' => 1400,
                ],
                'flutter_realtime_audio_done_ready' => [
                    'response_id' => 'resp_fast_2',
                    'ready_elapsed_ms' => 0,
                    'status' => 'listening',
                    'conversation_active' => true,
                    'mic_enabled' => true,
                    'microphone_track_count' => 1,
                    'transcription_only_release_pending' => false,
                    'background_work_active' => false,
                    'audio_elapsed_ms' => 1180,
                ],
                'flutter_realtime_progress_prompt' => [
                    'user_request' => 'schedule lunch with Sam tomorrow',
                    'elapsed_ms' => 8000,
                    'instruction' => 'Give one brief, natural progress update.',
                ],
                'flutter_realtime_progress_prompt_spoken' => [
                    'user_request' => 'schedule lunch with Sam tomorrow',
                    'elapsed_ms' => 8000,
                    'spoken_text' => 'Still working on that for you.',
                ],
                'flutter_realtime_pending_response_deferred_by_speech' => [
                    'user_content' => 'what is next',
                    'response_create_was_in_flight' => true,
                ],
                'flutter_realtime_pending_response_recovered_after_non_actionable_speech' => [
                    'user_content' => 'what is next',
                    'transcript' => '',
                    'synthetic' => false,
                    'recovery_elapsed_ms' => 260,
                ],
                default => [],
            };
            AiUsageLog::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $sessionId,
                'provider' => 'openai',
                'model' => 'gpt-realtime-test',
                'route_tier' => 'realtime_voice_event',
                'request_type' => 'realtime_voice_event',
                'status' => 'completed',
                'input_tokens' => 0,
                'output_tokens' => 0,
                'total_tokens' => 0,
                'tool_call_count' => 0,
                'estimated_cost_usd' => 0,
                'action_types' => ['realtime_voice_event', $eventType],
                'metadata' => ['event_type' => $eventType, 'details' => $details],
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->withToken($token)->getJson('/api/assistant/realtime/quality?days=7&session_id='.$sessionId)
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_attention')
            ->assertJsonPath('data.benchmark', 'siri_alexa_voice_responsiveness')
            ->assertJsonPath('data.gate.status', 'fail')
            ->assertJsonPath('data.gate.requirements.latency.status', 'fail')
            ->assertJsonPath('data.gate.requirements.latency.targets.p50_transcript_to_first_assistant_ms', 700)
            ->assertJsonPath('data.gate.requirements.latency.targets.p95_transcript_to_first_assistant_ms', 1200)
            ->assertJsonPath('data.gate.requirements.latency.targets.p95_full_turn_ms', 5000)
            ->assertJsonPath('data.gate.requirements.latency.observed.p95_transcript_to_first_assistant_ms', 1500)
            ->assertJsonPath('data.gate.requirements.latency.observed.p95_full_turn_ms', 6100)
            ->assertJsonPath('data.gate.requirements.barge_in_interruption_recovery.status', 'fail')
            ->assertJsonPath('data.gate.requirements.fresh_context_accuracy.status', 'fail')
            ->assertJsonPath('data.gate.requirements.contextual_followups.status', 'fail')
            ->assertJsonPath('data.gate.requirements.natural_voice.status', 'fail')
            ->assertJsonPath('data.gate.requirements.live_session_reliability.status', 'fail')
            ->assertJsonPath('data.window.turn_sample_size', 3)
            ->assertJsonPath('data.window.event_sample_size', 18)
            ->assertJsonPath('data.metrics.transcript_to_first_assistant_ms.p50_ms', 650)
            ->assertJsonPath('data.metrics.transcript_to_first_assistant_ms.p95_ms', 1500)
            ->assertJsonPath('data.metrics.transcript_to_first_assistant_ms.status', 'fail')
            ->assertJsonPath('data.metrics.turn_completed_ms.p95_ms', 6100)
            ->assertJsonPath('data.speech.brevity.status', 'fail')
            ->assertJsonPath('data.speech.brevity.sample_size', 3)
            ->assertJsonPath('data.speech.brevity.violation_count', 1)
            ->assertJsonPath('data.speech.brevity.violation_rate', 0.3333)
            ->assertJsonPath('data.speech.brevity.max_sentence_count', 5)
            ->assertJsonPath('data.speech.naturalness.status', 'pass')
            ->assertJsonPath('data.speech.naturalness.sample_size', 3)
            ->assertJsonPath('data.speech.naturalness.violation_count', 0)
            ->assertJsonPath('data.speech.naturalness.duplicate_response_count', 0)
            ->assertJsonPath('data.speech.naturalness.target_duplicate_response_count', 0)
            ->assertJsonPath('data.conversation.status', 'fail')
            ->assertJsonPath('data.conversation.follow_up_sample_size', 3)
            ->assertJsonPath('data.conversation.follow_up_turn_count', 2)
            ->assertJsonPath('data.conversation.contextual_follow_up_turn_count', 1)
            ->assertJsonPath('data.conversation.micro_follow_up_kind_count', 1)
            ->assertJsonPath('data.conversation.micro_follow_up_kind_counts.reference', 1)
            ->assertJsonPath('data.conversation.target_min_micro_follow_up_kind_count', 5)
            ->assertJsonPath('data.conversation.untyped_contextual_follow_up_count', 0)
            ->assertJsonPath('data.conversation.follow_up_turn_rate', 0.6667)
            ->assertJsonPath('data.conversation.contextual_follow_up_turn_rate', 0.3333)
            ->assertJsonPath('data.conversation.target_min_follow_up_turn_count', 2)
            ->assertJsonPath('data.conversation.target_min_contextual_follow_up_turn_count', 1)
            ->assertJsonPath('data.contextual_follow_up_resolution.status', 'pass')
            ->assertJsonPath('data.contextual_follow_up_resolution.sample_size', 1)
            ->assertJsonPath('data.contextual_follow_up_resolution.resolved_count', 1)
            ->assertJsonPath('data.contextual_follow_up_resolution.unresolved_count', 0)
            ->assertJsonPath('data.telemetry.usage_sample_size', 3)
            ->assertJsonPath('data.telemetry.usage_missing_count', 1)
            ->assertJsonPath('data.telemetry.usage_missing_rate', 0.3333)
            ->assertJsonPath('data.events.barge_in_count', 1)
            ->assertJsonPath('data.events.barge_in_rate', 0.3333)
            ->assertJsonPath('data.events.barge_in_quality.status', 'pass')
            ->assertJsonPath('data.events.barge_in_quality.p95_cancel_dispatch_ms', 18)
            ->assertJsonPath('data.events.barge_in_quality.dispatch_error_count', 0)
            ->assertJsonPath('data.events.barge_in_quality.internal_prompt_count', 1)
            ->assertJsonPath('data.events.barge_in_quality.target_min_internal_prompt_count', 1)
            ->assertJsonPath('data.events.minimum_barge_in_count', 1)
            ->assertJsonPath('data.events.barge_in_recovery_quality.status', 'pass')
            ->assertJsonPath('data.events.barge_in_recovery_quality.sample_size', 1)
            ->assertJsonPath('data.events.barge_in_recovery_quality.p95_recovery_elapsed_ms', 980)
            ->assertJsonPath('data.events.barge_in_recovery_quality.missing_response_id_count', 0)
            ->assertJsonPath('data.events.minimum_barge_in_recovery_count', 1)
            ->assertJsonPath('data.events.follow_up_readiness_quality.status', 'fail')
            ->assertJsonPath('data.events.follow_up_readiness_quality.sample_size', 1)
            ->assertJsonPath('data.events.follow_up_readiness_quality.p95_ready_elapsed_ms', 12)
            ->assertJsonPath('data.events.follow_up_readiness_quality.target_p95_ready_elapsed_ms', 120)
            ->assertJsonPath('data.events.follow_up_readiness_quality.micro_follow_up_ready_kind_count', 1)
            ->assertJsonPath('data.events.follow_up_readiness_quality.micro_follow_up_ready_kind_counts.reference', 1)
            ->assertJsonPath('data.events.follow_up_readiness_quality.target_min_micro_follow_up_ready_kind_count', 5)
            ->assertJsonPath('data.events.follow_up_readiness_quality.untyped_contextual_follow_up_ready_count', 0)
            ->assertJsonPath('data.events.minimum_follow_up_ready_count', 1)
            ->assertJsonPath('data.events.follow_up_ready_count', 1)
            ->assertJsonPath('data.events.follow_up_ready_rate', 0.3333)
            ->assertJsonPath('data.events.pending_response_deferred_count', 1)
            ->assertJsonPath('data.events.pending_response_deferred_rate', 0.3333)
            ->assertJsonPath('data.events.pending_response_recovered_count', 1)
            ->assertJsonPath('data.events.pending_response_recovered_rate', 0.3333)
            ->assertJsonPath('data.events.pending_response_recovery_quality.status', 'pass')
            ->assertJsonPath('data.events.pending_response_recovery_quality.sample_size', 1)
            ->assertJsonPath('data.events.pending_response_recovery_quality.recovered_count', 1)
            ->assertJsonPath('data.events.pending_response_recovery_quality.unrecovered_count', 0)
            ->assertJsonPath('data.events.pending_response_recovery_quality.p95_recovery_elapsed_ms', 260)
            ->assertJsonPath('data.events.pending_response_recovery_quality.target_p95_recovery_elapsed_ms', 700)
            ->assertJsonPath('data.events.minimum_pending_response_recovery_count', 1)
            ->assertJsonPath('data.events.audio_done_readiness_quality.status', 'pass')
            ->assertJsonPath('data.events.audio_done_readiness_quality.sample_size', 1)
            ->assertJsonPath('data.events.audio_done_readiness_quality.p95_ready_elapsed_ms', 0)
            ->assertJsonPath('data.events.audio_done_readiness_quality.target_p95_ready_elapsed_ms', 80)
            ->assertJsonPath('data.events.minimum_audio_done_ready_count', 1)
            ->assertJsonPath('data.events.audio_done_ready_count', 1)
            ->assertJsonPath('data.events.audio_done_ready_rate', 0.3333)
            ->assertJsonPath('data.events.follow_up_idle_timeout_count', 1)
            ->assertJsonPath('data.events.follow_up_idle_timeout_rate', 0.3333)
            ->assertJsonPath('data.events.background_queue_quality.status', 'pass')
            ->assertJsonPath('data.events.background_queue_quality.sample_size', 1)
            ->assertJsonPath('data.events.background_queue_quality.fallback_count', 0)
            ->assertJsonPath('data.events.background_queue_quality.target_fallback_count', 0)
            ->assertJsonPath('data.events.background_queue_quality.unacknowledged_count', 0)
            ->assertJsonPath('data.events.background_queue_quality.p95_queue_elapsed_ms', 520)
            ->assertJsonPath('data.events.background_queue_quality.target_p95_queue_elapsed_ms', 1800)
            ->assertJsonPath('data.events.minimum_background_queued_count', 1)
            ->assertJsonPath('data.events.background_progress_prompt_count', 1)
            ->assertJsonPath('data.events.background_progress_prompt_rate', 0.3333)
            ->assertJsonPath('data.events.background_progress_prompt_spoken_count', 1)
            ->assertJsonPath('data.events.background_progress_prompt_spoken_rate', 0.3333)
            ->assertJsonPath('data.events.background_progress_prompt_skipped_count', 0)
            ->assertJsonPath('data.events.background_progress_prompt_skipped_rate', 0)
            ->assertJsonPath('data.events.background_progress_quality.status', 'pass')
            ->assertJsonPath('data.events.background_progress_quality.sample_size', 1)
            ->assertJsonPath('data.events.background_progress_quality.spoken_sample_size', 1)
            ->assertJsonPath('data.events.background_progress_quality.duplicate_count', 0)
            ->assertJsonPath('data.events.background_progress_quality.target_duplicate_count', 0)
            ->assertJsonPath('data.events.background_progress_quality.p95_first_progress_elapsed_ms', 8000)
            ->assertJsonPath('data.events.background_progress_quality.target_p95_first_progress_elapsed_ms', 8500)
            ->assertJsonPath('data.events.minimum_background_progress_prompt_count', 1)
            ->assertJsonPath('data.events.background_completed_count', 1)
            ->assertJsonPath('data.events.background_completed_rate', 0.3333)
            ->assertJsonPath('data.events.minimum_background_completed_count', 1)
            ->assertJsonPath('data.events.background_completion_quality.status', 'pass')
            ->assertJsonPath('data.events.background_completion_quality.duplicate_count', 0)
            ->assertJsonPath('data.events.background_completion_quality.target_duplicate_count', 0)
            ->assertJsonPath('data.events.background_failure_count', 0)
            ->assertJsonPath('data.events.background_watch_failure_count', 0)
            ->assertJsonPath('data.events.background_watch_failure_status', 'pass')
            ->assertJsonPath('data.events.background_silent_completion_count', 0)
            ->assertJsonPath('data.events.background_completed_after_voice_closed_count', 0)
            ->assertJsonPath('data.events.background_completion_deferred_count', 0)
            ->assertJsonPath('data.events.background_cancelled_after_voice_closed_count', 0)
            ->assertJsonPath('data.events.background_cancelled_after_voice_closed_status', 'pass')
            ->assertJsonPath('data.events.background_completion_status', 'pass')
            ->assertJsonPath('data.events.background_cancel_failure_count', 1)
            ->assertJsonPath('data.events.background_cancel_status', 'fail')
            ->assertJsonPath('data.events.in_flight_cancel_failure_count', 1)
            ->assertJsonPath('data.events.in_flight_cancel_failure_rate', 0.3333)
            ->assertJsonPath('data.events.in_flight_cancel_status', 'fail')
            ->assertJsonPath('data.events.interrupt_signal_failure_count', 0)
            ->assertJsonPath('data.events.interrupt_signal_status', 'pass')
            ->assertJsonPath('data.events.realtime_error_count', 0)
            ->assertJsonPath('data.events.realtime_error_status', 'pass')
            ->assertJsonPath('data.events.response_failure_count', 0)
            ->assertJsonPath('data.events.response_failure_status', 'pass')
            ->assertJsonPath('data.events.unanswered_response_quality.status', 'pass')
            ->assertJsonPath('data.events.unanswered_response_quality.unanswered_count', 0)
            ->assertJsonPath('data.events.tool_call_failure_count', 0)
            ->assertJsonPath('data.events.tool_call_failure_status', 'pass')
            ->assertJsonPath('data.events.tool_fallback_failure_count', 0)
            ->assertJsonPath('data.events.tool_fallback_failure_status', 'pass')
            ->assertJsonPath('data.events.premature_completion_claim_count', 1)
            ->assertJsonPath('data.events.premature_completion_claim_rate', 0.3333)
            ->assertJsonPath('data.events.premature_completion_claim_status', 'fail')
            ->assertJsonPath('data.events.unsupported_direct_answer_count', 0)
            ->assertJsonPath('data.events.unsupported_direct_answer_status', 'pass')
            ->assertJsonPath('data.events.unsupported_direct_answer_quality.status', 'pass')
            ->assertJsonPath('data.events.unsupported_direct_answer_quality.missing_fresh_context_count', 0)
            ->assertJsonPath('data.events.unsupported_direct_answer_quality.background_required_count', 0)
            ->assertJsonPath('data.events.unsupported_direct_answer_queued_count', 0)
            ->assertJsonPath('data.events.webrtc_failure_count', 1)
            ->assertJsonPath('data.events.webrtc_failure_rate', 0.3333)
            ->assertJsonPath('data.events.transport_failure_count', 1)
            ->assertJsonPath('data.events.transport_failure_rate', 0.3333)
            ->assertJsonPath('data.events.transport_failure_status', 'fail')
            ->assertJsonPath('data.events.dashboard_context_pre_response_success_count', 1)
            ->assertJsonPath('data.events.dashboard_context_pre_response_success_rate', 0.3333)
            ->assertJsonPath('data.events.context_refresh_success_count', 1)
            ->assertJsonPath('data.events.context_refresh_success_rate', 0.3333)
            ->assertJsonPath('data.events.minimum_context_refresh_success_count', 1)
            ->assertJsonPath('data.events.context_refresh_quality.status', 'fail')
            ->assertJsonPath('data.events.context_refresh_quality.success_count', 1)
            ->assertJsonPath('data.events.context_refresh_quality.ack_timeout_count', 1)
            ->assertJsonPath('data.events.context_refresh_quality.p95_elapsed_ms', 82)
            ->assertJsonPath('data.events.context_refresh_quality.target_p95_elapsed_ms', 220)
            ->assertJsonPath('data.events.dashboard_context_pre_response_ack_timeout_count', 1)
            ->assertJsonPath('data.events.dashboard_context_pre_response_ack_timeout_rate', 0.3333)
            ->assertJsonPath('data.events.context_freshness_failure_count', 1)
            ->assertJsonPath('data.events.context_freshness_failure_rate', 0.3333)
            ->assertJsonPath('data.events.context_freshness_status', 'fail')
            ->assertJsonPath('data.recent_slow_turns.0.transcript_to_first_assistant_ms', 1500)
            ->assertJsonPath('data.recent_slow_turns.0.turn_completed_ms', 6100);

        Carbon::setTestNow();
    }

    public function test_realtime_dashboard_context_endpoint_returns_snapshot_and_full_instructions(): void
    {
        $token = $this->apiToken('realtime-context@example.com');
        $user = User::where('email', 'realtime-context@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        $household = Workspace::create([
            'type' => 'household',
            'name' => 'Household',
            'created_by_user_id' => $user->id,
            'status' => 'active',
        ]);
        WorkspaceMembership::create([
            'workspace_id' => $household->id,
            'user_id' => $user->id,
            'role' => 'owner',
            'status' => 'active',
            'accepted_at' => now(),
        ]);
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
        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $household->id,
            'title' => 'Move laundry',
            'remind_at' => now()->addHours(2),
            'status' => 'pending',
        ]);

        $this->withToken($token)->getJson("/api/assistant/realtime/dashboard-context?session_id={$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.workspace.id', $workspace->id)
            ->assertJsonPath('data.snapshot.workspace.active', true)
            ->assertJsonPath('data.snapshot.workspaces.0.id', $workspace->id)
            ->assertJsonPath('data.snapshot.workspaces.1.id', $household->id)
            ->assertJsonPath('data.snapshot.reminders_due.0.title', 'Call insurance')
            ->assertJsonPath('data.snapshot.reminders_due.1.title', 'Move laundry')
            ->assertJsonPath('data.snapshot.reminders_due.1.workspace.id', $household->id)
            ->assertJsonPath('data.snapshot.counts.reminders_next_7_days', 2)
            ->assertJsonPath('data.snapshot.counts.workspaces', 2)
            ->assertJsonPath('data.snapshot.window.future_days', 7)
            ->assertJsonPath('data.snapshot.timezone', '-04:00')
            ->assertJson(fn ($json) => $json
                ->where('data.prompt_text', fn (string $value): bool => str_contains($value, 'cross-workspace snapshot') && str_contains($value, 'the turn is complete'))
                ->where('data.instructions', fn (string $value): bool => str_contains($value, 'Move laundry') && str_contains($value, 'Mobile hold-to-talk requests are already addressed to Bean'))
                ->etc()
            );
    }

    public function test_realtime_dashboard_context_warms_current_weather_from_default_location(): void
    {
        Http::fake(function ($request) {
            if (str_starts_with($request->url(), 'https://geocoding-api.open-meteo.com/v1/search')) {
                return Http::response([
                    'results' => [[
                        'id' => 4167147,
                        'name' => 'Orlando',
                        'latitude' => 28.5383,
                        'longitude' => -81.3792,
                        'admin1' => 'Florida',
                        'country_code' => 'US',
                    ]],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.open-meteo.com/v1/forecast')) {
                return Http::response([
                    'timezone' => 'America/New_York',
                    'current' => [
                        'time' => '2026-06-02T14:00',
                        'temperature_2m' => 88.2,
                        'relative_humidity_2m' => 62,
                        'apparent_temperature' => 91.6,
                        'precipitation' => 0,
                        'weather_code' => 2,
                        'cloud_cover' => 50,
                        'wind_speed_10m' => 8.4,
                        'wind_direction_10m' => 135,
                        'wind_gusts_10m' => 15.2,
                    ],
                ], 200);
            }

            return Http::response(['error' => 'Unexpected request '.$request->url()], 500);
        });

        $token = $this->apiToken('realtime-context-weather@example.com');
        $user = User::where('email', 'realtime-context-weather@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $user);
        $settings = $profile->settings ?? [];
        data_set($settings, 'weather.location', 'Orlando, Florida');
        $settings['home_location'] = 'Orlando, Florida';
        data_set($settings, 'memory.user_preferences.home_location', 'Orlando, Florida');
        $profile->forceFill(['settings' => $settings])->save();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-06-02T14:00:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->getJson("/api/assistant/realtime/dashboard-context?session_id={$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.weather_current.ok', true)
            ->assertJsonPath('data.snapshot.weather_current.provider', 'open_meteo')
            ->assertJsonPath('data.snapshot.weather_current.kind', 'weather_current')
            ->assertJsonPath('data.snapshot.weather_current.location', 'Orlando, Florida, US')
            ->assertJsonPath('data.snapshot.weather_current.weather.temperature_f', 88)
            ->assertJsonPath('data.snapshot.weather_current.weather.description', 'partly cloudy')
            ->assertJson(fn ($json) => $json
                ->where('data.prompt_text', fn (string $value): bool => str_contains($value, 'weather_current.ok is true'))
                ->where('data.instructions', fn (string $value): bool => str_contains($value, 'weather_current.ok=true') && str_contains($value, 'Orlando, Florida, US'))
                ->etc()
            );

        Http::assertSentCount(2);
    }

    public function test_realtime_dashboard_context_uses_client_visible_dates_for_multi_day_items(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-06-01T16:00:00Z'));

        $token = $this->apiToken('realtime-context-timezone@example.com');
        $user = User::where('email', 'realtime-context-timezone@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-06-01T12:00:00-04:00',
                    'timezone' => 'America/New_York',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspace->id,
            'title' => 'Timed multi-day event',
            'starts_at' => '2026-06-05T13:00:00-04:00',
            'ends_at' => '2026-06-08T20:00:00-04:00',
            'status' => 'confirmed',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspace->id,
            'title' => 'All-day multi-day event',
            'starts_at' => '2026-06-07T00:00:00Z',
            'ends_at' => '2026-06-10T00:00:00Z',
            'status' => 'confirmed',
            'metadata' => ['all_day' => true],
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/tasks', [
            'workspace_id' => $workspace->id,
            'title' => 'Pack bags',
            'type' => 'todo',
            'status' => 'pending',
            'due_at' => '2026-06-08T20:00:00-04:00',
        ])->assertCreated();
        $this->withToken($token)->postJson('/api/reminders', [
            'workspace_id' => $workspace->id,
            'title' => 'Check in online',
            'status' => 'pending',
            'remind_at' => '2026-06-08T20:00:00-04:00',
        ])->assertCreated();
        $this->assertSame(
            '2026-06-05T17:00:00+00:00',
            CalendarEvent::where('title', 'Timed multi-day event')->firstOrFail()->starts_at->utc()->toIso8601String(),
        );

        $this->withToken($token)->getJson("/api/assistant/realtime/dashboard-context?session_id={$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.snapshot.timezone', 'America/New_York')
            ->assertJsonPath('data.snapshot.today', '2026-06-01')
            ->assertJsonPath('data.snapshot.calendar_upcoming.0.title', 'Timed multi-day event')
            ->assertJsonPath('data.snapshot.calendar_upcoming.0.starts_at', '2026-06-05T13:00:00-04:00')
            ->assertJsonPath('data.snapshot.calendar_upcoming.0.ends_at', '2026-06-08T20:00:00-04:00')
            ->assertJsonPath('data.snapshot.calendar_upcoming.0.ends_at_utc', '2026-06-09T00:00:00+00:00')
            ->assertJsonPath('data.snapshot.calendar_upcoming.0.display_start_date', '2026-06-05')
            ->assertJsonPath('data.snapshot.calendar_upcoming.0.display_end_date', '2026-06-08')
            ->assertJsonPath('data.snapshot.calendar_upcoming.0.display_date_range', '2026-06-05 through 2026-06-08')
            ->assertJsonPath('data.snapshot.calendar_upcoming.1.title', 'All-day multi-day event')
            ->assertJsonPath('data.snapshot.calendar_upcoming.1.starts_at', '2026-06-07')
            ->assertJsonPath('data.snapshot.calendar_upcoming.1.ends_at', '2026-06-09')
            ->assertJsonPath('data.snapshot.calendar_upcoming.1.starts_at_utc', '2026-06-07T00:00:00+00:00')
            ->assertJsonPath('data.snapshot.calendar_upcoming.1.display_start_date', '2026-06-07')
            ->assertJsonPath('data.snapshot.calendar_upcoming.1.display_end_date', '2026-06-09')
            ->assertJsonPath('data.snapshot.tasks_upcoming_next_7_days.0.due_at', '2026-06-08T20:00:00-04:00')
            ->assertJsonPath('data.snapshot.tasks_upcoming_next_7_days.0.display_due_date', '2026-06-08')
            ->assertJsonPath('data.snapshot.reminders_due.0.remind_at', '2026-06-08T20:00:00-04:00')
            ->assertJsonPath('data.snapshot.reminders_due.0.display_remind_date', '2026-06-08')
            ->assertJson(fn ($json) => $json
                ->where('data.prompt_text', fn (string $value): bool => str_contains($value, 'use display_start_date/display_end_date'))
                ->where('data.instructions', fn (string $value): bool => str_contains($value, '2026-06-05 through 2026-06-08'))
                ->etc()
            );
    }

    public function test_calendar_create_normalizes_all_day_title_prefix(): void
    {
        $token = $this->apiToken('all-day-title-api@example.com');
        $user = User::where('email', 'all-day-title-api@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);

        $this->withToken($token)->postJson('/api/calendar-events', [
            'workspace_id' => $workspace->id,
            'title' => 'All day: Board retreat',
            'starts_at' => '2026-07-10T00:00:00Z',
            'status' => 'confirmed',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Board retreat')
            ->assertJsonPath('data.metadata.all_day', true);

        $event = CalendarEvent::where('title', 'Board retreat')->firstOrFail();
        $this->assertTrue($event->metadata['all_day']);
        $this->assertSame('2026-07-10T00:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-07-10T23:59:00+00:00', $event->ends_at->utc()->toIso8601String());
    }

    public function test_structured_calendar_create_normalizes_all_day_title_prefix(): void
    {
        $token = $this->apiToken('all-day-title-structured@example.com');
        $user = User::where('email', 'all-day-title-structured@example.com')->firstOrFail();
        $workspace = Workspace::findOrFail($user->default_workspace_id);
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'status' => 'active',
            'runtime_mode' => 'tools',
            'last_activity_at' => now(),
        ]);

        app(StructuredHermesActionService::class)->applyEnvelope($session, [
            'actions' => [[
                'type' => 'calendar.create',
                'risk' => 'safe',
                'parameters' => [
                    'title' => 'All-day: Team offsite',
                    'starts_at' => '2026-07-12T00:00:00Z',
                    'status' => 'confirmed',
                ],
            ]],
        ]);

        $event = CalendarEvent::where('conversation_session_id', $session->id)->firstOrFail();
        $this->assertSame('Team offsite', $event->title);
        $this->assertTrue($event->metadata['all_day']);
        $this->assertSame('2026-07-12T23:59:00+00:00', $event->ends_at->utc()->toIso8601String());
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

    public function test_realtime_tool_call_preserves_user_request_argument_when_content_is_missing(): void
    {
        Queue::fake();

        $token = $this->apiToken('realtime-tool-user-request@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        $exactRequest = 'Please add Dr Chen Cardio on 7/9 at 3pm at 100 N Dean Rd.';

        $this->withToken($token)->postJson('/api/assistant/realtime/tool-calls', [
            'session_id' => $sessionId,
            'tool_name' => 'queue_bean_work',
            'call_id' => 'call_user_request',
            'arguments' => [
                'user_request' => $exactRequest,
                'client_context' => ['timezone_offset' => '-04:00'],
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.ok', true);

        $run = AssistantRun::firstOrFail();
        $this->assertSame('realtime', $run->source);
        $this->assertSame('queued', $run->status);
        $this->assertSame($exactRequest, $run->input);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => $exactRequest,
        ]);

        Queue::assertPushed(ProcessAssistantRun::class, fn (ProcessAssistantRun $job): bool => $job->assistantRunId === $run->id);
    }

    public function test_realtime_tool_call_queues_usage_limited_background_work_for_runtime_message(): void
    {
        Queue::fake();
        config()->set('services.ai_usage.limits.base_cost_limit', 0.000001);

        $token = $this->apiToken('realtime-tool-limit@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'runtime_mode' => 'realtime',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/realtime/tool-calls', [
            'session_id' => $sessionId,
            'tool_name' => 'queue_bean_work',
            'call_id' => 'call_limit',
            'arguments' => [
                'content' => 'Add this to my calendar.',
                'client_context' => ['timezone_offset' => '-04:00'],
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.ok', true)
            ->assertJsonPath('data.message', 'Bean is working on that in the background.');

        $run = AssistantRun::firstOrFail();
        $this->assertSame('realtime', $run->source);
        $this->assertSame('queued', $run->status);
        $this->assertSame('Add this to my calendar.', $run->input);
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

    public function test_web_chat_messages_queue_without_direct_runtime_or_bridge_message(): void
    {
        Queue::fake();

        $token = $this->apiToken('web-chat-queue@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $this->bindRuntimeThatFailsIfCalled();

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'web_queued_chat',
                'client_request_id' => 'web-chat-queue-1',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.metadata.client_request_id', 'web-chat-queue-1')
            ->assertJsonPath('data.assistant_message', null);

        $this->assertSame('queued', ConversationSession::findOrFail($sessionId)->status);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_async_run_endpoint_queues_usage_limited_requests_for_runtime_message(): void
    {
        Queue::fake();
        config()->set('services.ai_usage.limits.base_cost_limit', 0.000001);

        $token = $this->apiToken('async-run-limit@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add three events to my calendar.',
            'metadata' => ['source' => 'flutter'],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.session.status', 'queued')
            ->assertJsonPath('data.user_message.content', 'Please add three events to my calendar.');

        Queue::assertPushed(ProcessAssistantRun::class);
    }

    public function test_flutter_async_run_endpoint_returns_pending_state_when_queue_creation_fails(): void
    {
        Queue::fake();
        $this->bindFailingQueueService();
        $this->bindSuccessfulDirectRuntime('Done - direct fallback handled the request.');

        $token = $this->apiToken('async-run-queue-fallback@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add Dr Chen Cardio on 7/9 at 3pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'queue-fallback-1',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run', null)
            ->assertJsonPath('data.user_message.content', 'Please add Dr Chen Cardio on 7/9 at 3pm')
            ->assertJsonPath('data.assistant_message', null);

        $this->assertSame(0, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
    }

    public function test_flutter_async_run_endpoint_does_not_create_bridge_message_when_queue_fails(): void
    {
        Queue::fake();
        $this->bindFailingQueueService();
        $this->bindFailingDirectRuntime();

        $token = $this->apiToken('async-run-queue-bridge@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add Dr Chen Cardio on 7/9 at 3pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'queue-bridge-1',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run', null)
            ->assertJsonPath('data.user_message.content', 'Please add Dr Chen Cardio on 7/9 at 3pm')
            ->assertJsonPath('data.assistant_message', null);

        $this->assertSame('queued', ConversationSession::findOrFail($sessionId)->status);
        $this->assertSame(0, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
    }

    public function test_flutter_async_run_endpoint_returns_existing_run_when_queue_fails_after_creating_it(): void
    {
        Queue::fake();
        $this->bindQueueServiceThatCreatesRunThenThrows();

        $token = $this->apiToken('async-run-partial-queue@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $this->bindRuntimeThatFailsIfCalled();

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add Dr Chen Cardio on 7/9 at 3pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'queue-created-then-failed-1',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.assistant_message', null)
            ->json('data.run.id');

        $this->assertNotNull($runId);
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_async_run_endpoint_reuses_existing_client_request_id(): void
    {
        Queue::fake();

        $token = $this->apiToken('async-run-idempotent@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $payload = [
            'content' => 'Plan my afternoon',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'flutter-chat-test-1',
            ],
        ];

        $first = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", $payload)
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->json('data.run.id');

        $second = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", $payload)
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.user_message.content', 'Plan my afternoon')
            ->json('data.run.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(1, ConversationSession::findOrFail($sessionId)->messages()->where('role', 'user')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_async_run_lookup_returns_existing_client_request_run(): void
    {
        Queue::fake();

        $token = $this->apiToken('async-run-lookup@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Plan my afternoon',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'flutter-chat-lookup-1',
            ],
        ])->assertAccepted()
            ->json('data.run.id');

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/runs/lookup?client_request_id=flutter-chat-lookup-1")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.id', $runId)
            ->assertJsonPath('data.user_message.content', 'Plan my afternoon');
    }

    public function test_async_run_lookup_missing_client_request_returns_pending_state_instead_of_bridge_message(): void
    {
        Queue::fake();

        $token = $this->apiToken('async-run-lookup-missing@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/runs/lookup?client_request_id=flutter-missing-run-1")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run', null)
            ->assertJsonPath('data.user_message', null)
            ->assertJsonPath('data.assistant_message', null);

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/runs/lookup?client_request_id=flutter-missing-run-1")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.assistant_message', null);

        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(0, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertNothingPushed();
    }

    public function test_async_run_endpoint_requeues_failed_expired_run_for_client_request_id_without_blocking(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-unexpected',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected model call.'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('async-run-idempotent-recovery@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $payload = [
            'content' => 'Please add the following events to my calendar: 7/9 Dr Chan Cardio at 100 N Dean Rd. 3pm, 7/15 Ventura 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'flutter-recover-failed-run-1',
                'client_context' => [
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                    'current_local_time' => '2026-07-02T08:14:49',
                    'current_utc_time' => '2026-07-02T12:14:49Z',
                ],
            ],
        ];

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", $payload)
            ->assertAccepted()
            ->json('data.run.id');
        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'failed',
            'error' => 'Run expired before it could be safely recovered.',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinute(),
        ])->save();

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", $payload)
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.id', $runId)
            ->assertJsonPath('data.run.metadata.background_response_retry_attempts', 1)
            ->assertJsonPath('data.assistant_message', null);

        $this->assertCount(0, CalendarEvent::where('conversation_session_id', $sessionId)->get());
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertPushed(ProcessAssistantRun::class, 2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_async_run_lookup_requeues_failed_expired_run_for_client_request_id_without_blocking(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-unexpected',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected model call.'],
                    'finish_reason' => 'stop',
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('async-run-lookup-recovery@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add the following events to my calendar: 7/9 Dr Chan Cardio at 100 N Dean Rd. 3pm, 7/15 Ventura 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'flutter-recover-failed-run-lookup-1',
                'client_context' => [
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                    'current_local_time' => '2026-07-02T08:14:49',
                    'current_utc_time' => '2026-07-02T12:14:49Z',
                ],
            ],
        ])->assertAccepted()->json('data.run.id');
        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'failed',
            'error' => 'Run expired before it could be safely recovered.',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinute(),
        ])->save();

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/runs/lookup?client_request_id=flutter-recover-failed-run-lookup-1")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.id', $runId)
            ->assertJsonPath('data.run.metadata.background_response_retry_attempts', 1)
            ->assertJsonPath('data.assistant_message', null);

        $this->assertCount(0, CalendarEvent::where('conversation_session_id', $sessionId)->get());
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertPushed(ProcessAssistantRun::class, 2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_async_run_endpoint_returns_completed_direct_message_for_client_request_id(): void
    {
        Queue::fake();

        $token = $this->apiToken('async-run-direct-completed@example.com');
        $user = User::where('email', 'async-run-direct-completed@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Can you do that?',
            'metadata' => ['client_request_id' => 'flutter-direct-test-1'],
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Yes - I can help with that.',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Can you do that?',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'flutter-direct-test-1',
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.user_message.id', $userMessage->id)
            ->assertJsonPath('data.assistant_message.content', 'Yes - I can help with that.');

        $this->assertSame(0, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertNothingPushed();
    }

    public function test_direct_message_failure_queues_existing_user_message_instead_of_returning_error(): void
    {
        Queue::fake();
        $this->bindFailingDirectRuntime();

        $token = $this->apiToken('direct-fallback@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'direct-fallback-1',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.user_message.content', 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.metadata.client_request_id', 'direct-fallback-1');

        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_flutter_direct_message_queues_without_calling_synchronous_runtime(): void
    {
        Queue::fake();

        $token = $this->apiToken('flutter-queue-first@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $this->bindRuntimeThatFailsIfCalled();

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Can you create calendar events?',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'flutter-queue-first-1',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.metadata.client_request_id', 'flutter-queue-first-1')
            ->assertJsonPath('data.assistant_message', null);

        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_direct_message_returns_bridge_when_direct_runtime_and_queue_fallback_fail(): void
    {
        Queue::fake();
        $this->bindFailingDirectRuntime();
        $this->bindFailingQueueService();

        $token = $this->apiToken('direct-fallback-bridge@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'direct-fallback-bridge-1',
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.run', null)
            ->assertJsonPath('data.user_message.content', 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm')
            ->assertJsonPath('data.assistant_message.content', 'I’m checking the latest app state now. If I need one more detail, I’ll ask.');

        $this->assertSame('active', ConversationSession::findOrFail($sessionId)->status);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(0, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertNothingPushed();
    }

    public function test_branch_message_failure_queues_edited_message_instead_of_returning_error(): void
    {
        Queue::fake();
        $this->bindFailingDirectRuntime();

        $token = $this->apiToken('branch-fallback@example.com');
        $user = User::where('email', 'branch-fallback@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $originalUserMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Original request',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Original response',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages/{$originalUserMessage->id}/branch", [
            'content' => 'Edited request',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'branch-fallback-1',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.user_message.content', 'Edited request')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.metadata.client_request_id', 'branch-fallback-1')
            ->assertJsonPath('data.run.metadata.edited_from_message_id', $originalUserMessage->id);

        $this->assertDatabaseMissing('conversation_messages', ['id' => $originalUserMessage->id]);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_branch_message_returns_bridge_when_direct_runtime_and_queue_fallback_fail(): void
    {
        Queue::fake();
        $this->bindFailingDirectRuntime();
        $this->bindFailingQueueService();

        $token = $this->apiToken('branch-fallback-bridge@example.com');
        $user = User::where('email', 'branch-fallback-bridge@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $originalUserMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Original request',
        ]);
        ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Original response',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages/{$originalUserMessage->id}/branch", [
            'content' => 'Edited request',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'branch-fallback-bridge-1',
            ],
        ])->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.run', null)
            ->assertJsonPath('data.user_message.content', 'Edited request')
            ->assertJsonPath('data.assistant_message.content', 'I’m checking the latest app state now. If I need one more detail, I’ll ask.');

        $this->assertDatabaseMissing('conversation_messages', ['id' => $originalUserMessage->id]);
        $this->assertSame('active', ConversationSession::findOrFail($sessionId)->status);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(0, AssistantRun::where('conversation_session_id', $sessionId)->count());
        Queue::assertNothingPushed();
    }

    public function test_async_run_endpoint_queues_existing_direct_message_for_client_request_id(): void
    {
        Queue::fake();

        $token = $this->apiToken('async-run-direct-existing@example.com');
        $user = User::where('email', 'async-run-direct-existing@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Can you do that?',
            'metadata' => ['client_request_id' => 'flutter-direct-test-2'],
        ]);

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Can you do that?',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'flutter-direct-test-2',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.user_message.id', $userMessage->id)
            ->json('data.run.id');

        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame($userMessage->id, AssistantRun::findOrFail($runId)->user_message_id);
        $this->assertSame(1, ConversationSession::findOrFail($sessionId)->messages()->where('role', 'user')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_polling_stale_async_run_requeues_deterministic_calendar_work_without_blocking(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        config()->set('services.hermes_runtime.assistant_run_stale_seconds', 1);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-unexpected',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected model call.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $token = $this->apiToken('async-run-recovery@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm, 7/15 Ventura at 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'flutter',
                'client_context' => [
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                    'current_local_time' => '2026-07-02T08:14:49',
                    'current_utc_time' => '2026-07-02T12:14:49Z',
                ],
            ],
        ])->assertAccepted()->json('data.run.id');

        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'running',
            'started_at' => now()->subSeconds(5),
        ])->save();
        ConversationSession::findOrFail($sessionId)->forceFill(['status' => 'running'])->save();

        $this->withToken($token)->getJson("/api/assistant/runs/{$runId}")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.metadata.background_stale_retry_attempts', 1)
            ->assertJsonPath('data.assistant_message', null);

        $events = CalendarEvent::where('conversation_session_id', $sessionId)->orderBy('starts_at')->get();
        $this->assertCount(0, $events);
        Queue::assertPushed(ProcessAssistantRun::class, 2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_polling_failed_expired_async_run_requeues_when_no_work_was_done_without_blocking(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-unexpected',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected model call.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $token = $this->apiToken('failed-async-run-recovery@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add the following events to my calendar: 7/9 Dr Chan Cardio at 100 N Dean Rd. 3pm, 7/15 Ventura 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'flutter',
                'client_context' => [
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                    'current_local_time' => '2026-07-02T08:14:49',
                    'current_utc_time' => '2026-07-02T12:14:49Z',
                ],
            ],
        ])->assertAccepted()->json('data.run.id');

        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'failed',
            'error' => 'Run expired before it could be safely recovered.',
            'started_at' => now()->subMinutes(10),
            'completed_at' => now()->subMinute(),
        ])->save();
        ConversationSession::findOrFail($sessionId)->forceFill(['status' => 'active'])->save();

        $this->withToken($token)->getJson("/api/assistant/runs/{$runId}")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.metadata.background_response_retry_attempts', 1)
            ->assertJsonPath('data.assistant_message', null);

        $run = AssistantRun::findOrFail($runId);
        $this->assertSame(1, $run->metadata['background_response_retry_attempts']);
        $this->assertNull($run->error);

        $events = CalendarEvent::where('conversation_session_id', $sessionId)->orderBy('starts_at')->get();
        $this->assertCount(0, $events);
        Queue::assertPushed(ProcessAssistantRun::class, 2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_polling_queue_timeout_failed_async_run_recovers_when_no_work_was_done(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-unexpected',
                'choices' => [[
                    'index' => 0,
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected model call.'],
                    'finish_reason' => 'stop',
                ]],
                'usage' => ['prompt_tokens' => 1, 'completion_tokens' => 1, 'total_tokens' => 2],
            ], 200),
        ]);

        $token = $this->apiToken('failed-async-run-timeout-recovery@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add the following events to my calendar: 7/9 Dr Chen Cardio at 100 N Dean Rd. 3pm, 7/15 Ventura 6pm, 7/19 Azalea Lane 2pm',
            'metadata' => [
                'source' => 'flutter',
                'client_context' => [
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                    'current_local_time' => '2026-07-02T08:14:49',
                    'current_utc_time' => '2026-07-02T12:14:49Z',
                ],
            ],
        ])->assertAccepted()->json('data.run.id');

        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'failed',
            'error' => 'App\\Jobs\\ProcessAssistantRun has timed out.',
            'started_at' => now()->subMinutes(2),
            'completed_at' => now()->subMinute(),
        ])->save();
        ConversationSession::findOrFail($sessionId)->forceFill(['status' => 'active'])->save();

        $this->withToken($token)->getJson("/api/assistant/runs/{$runId}")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.metadata.background_response_retry_attempts', 1)
            ->assertJsonPath('data.assistant_message', null);

        $run = AssistantRun::findOrFail($runId);
        $this->assertSame(1, $run->metadata['background_response_retry_attempts']);
        $this->assertNull($run->error);

        $events = CalendarEvent::where('conversation_session_id', $sessionId)->orderBy('starts_at')->get();
        $this->assertCount(0, $events);
        Queue::assertPushed(ProcessAssistantRun::class, 2);
        Http::assertNotSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/chat/completions');
    }

    public function test_polling_generic_failed_async_run_returns_bridge_message_instead_of_failed_payload(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.assistant_run_background_retry_attempts', 0);
        $this->bindFailingDirectRuntime();

        $token = $this->apiToken('failed-run-bridge@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'failed-run-bridge-1',
            ],
        ])->assertAccepted()->json('data.run.id');

        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'failed',
            'error' => 'OpenAI upstream socket closed unexpectedly.',
            'started_at' => now()->subSeconds(20),
            'completed_at' => now()->subSecond(),
        ])->save();

        $this->withToken($token)->getJson("/api/assistant/runs/{$runId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.error', null)
            ->assertJsonPath('data.assistant_message.content', 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.');

        $run = AssistantRun::findOrFail($runId);
        $this->assertSame('completed', $run->status);
        $this->assertNull($run->error);
        $this->assertTrue((bool) data_get($run->result, 'resolved_failed_run'));
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
    }

    public function test_client_request_lookup_generic_failed_async_run_returns_bridge_message_instead_of_failed_payload(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.assistant_run_background_retry_attempts', 0);
        $this->bindFailingDirectRuntime();

        $token = $this->apiToken('failed-run-lookup-bridge@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $payload = [
            'content' => 'Please add the following to my calendar: 7/9 Dr Chen Cardio at 100 N Dean rd. at 3pm',
            'metadata' => [
                'source' => 'flutter',
                'client_request_id' => 'failed-run-lookup-bridge-1',
            ],
        ];

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", $payload)
            ->assertAccepted()
            ->json('data.run.id');

        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'failed',
            'error' => 'OpenAI upstream socket closed unexpectedly.',
            'started_at' => now()->subSeconds(20),
            'completed_at' => now()->subSecond(),
        ])->save();

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/runs/lookup?client_request_id=failed-run-lookup-bridge-1")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.run.error', null)
            ->assertJsonPath('data.assistant_message.content', 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", $payload)
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.');

        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
    }

    public function test_activity_poll_closes_expired_stale_run_without_replaying_work(): void
    {
        Queue::fake();
        config()->set('services.hermes_runtime.assistant_run_stale_seconds', 1);
        config()->set('services.hermes_runtime.assistant_run_recovery_window_seconds', 60);

        $token = $this->apiToken('expired-async-run@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $runId = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Please add Dr Chen Cardio on 7/9 at 3pm',
            'metadata' => ['source' => 'flutter'],
        ])->assertAccepted()->json('data.run.id');

        AssistantRun::findOrFail($runId)->forceFill([
            'status' => 'running',
            'started_at' => now()->subMinutes(2),
        ])->save();
        ConversationSession::findOrFail($sessionId)->forceFill(['status' => 'running'])->save();

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->assertJsonFragment(['event_type' => 'runtime.run_stale_failed']);

        $run = AssistantRun::findOrFail($runId);
        $this->assertSame('failed', $run->status);
        $this->assertSame('Run expired before it could be safely recovered.', $run->error);
        $this->assertSame('active', ConversationSession::findOrFail($sessionId)->status);
        $this->assertDatabaseCount('calendar_events', 0);
    }

    private function bindFailingDirectRuntime(): void
    {
        $this->app->bind(HermesRuntimeService::class, function (): HermesRuntimeService {
            return new class implements HermesRuntimeService
            {
                public function startSession(array $attributes = []): ConversationSession
                {
                    return ConversationSession::create($attributes);
                }

                public function resumeSession(ConversationSession $session): ConversationSession
                {
                    return $session;
                }

                public function cancelSession(ConversationSession $session): ConversationSession
                {
                    return $session;
                }

                public function progressEvents(ConversationSession $session): Collection
                {
                    return collect();
                }

                public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array
                {
                    throw new \RuntimeException('Simulated direct runtime outage.');
                }

                public function sendExistingMessage(ConversationSession $session, ConversationMessage $userMessage): array
                {
                    throw new \RuntimeException('Simulated direct runtime outage.');
                }
            };
        });
    }

    private function bindSuccessfulDirectRuntime(string $content): void
    {
        $this->app->bind(HermesRuntimeService::class, function () use ($content): HermesRuntimeService {
            return new class($content) implements HermesRuntimeService
            {
                public function __construct(private readonly string $content) {}

                public function startSession(array $attributes = []): ConversationSession
                {
                    return ConversationSession::create($attributes);
                }

                public function resumeSession(ConversationSession $session): ConversationSession
                {
                    return $session;
                }

                public function cancelSession(ConversationSession $session): ConversationSession
                {
                    return $session;
                }

                public function progressEvents(ConversationSession $session): Collection
                {
                    return collect();
                }

                public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array
                {
                    $message = ConversationMessage::create([
                        'user_id' => $session->user_id,
                        'conversation_session_id' => $session->id,
                        'role' => 'user',
                        'content' => $content,
                        'metadata' => $metadata ?: null,
                    ]);

                    return $this->sendExistingMessage($session, $message);
                }

                public function sendExistingMessage(ConversationSession $session, ConversationMessage $userMessage): array
                {
                    $assistantMessage = ConversationMessage::create([
                        'user_id' => $session->user_id,
                        'conversation_session_id' => $session->id,
                        'role' => 'assistant',
                        'content' => $this->content,
                        'metadata' => ['runtime' => 'test_direct_fallback'],
                    ]);
                    $session->update(['status' => 'active', 'last_activity_at' => now()]);

                    return [
                        'status' => 'completed',
                        'session' => $session->refresh(),
                        'user_message' => $userMessage->refresh(),
                        'assistant_message' => $assistantMessage,
                        'events' => collect(),
                        'blocker' => null,
                    ];
                }
            };
        });
    }

    private function bindRuntimeThatFailsIfCalled(): void
    {
        $this->app->bind(HermesRuntimeService::class, function (): HermesRuntimeService {
            return new class implements HermesRuntimeService
            {
                public function startSession(array $attributes = []): ConversationSession
                {
                    throw new \RuntimeException('Synchronous runtime should not be called.');
                }

                public function resumeSession(ConversationSession $session): ConversationSession
                {
                    throw new \RuntimeException('Synchronous runtime should not be called.');
                }

                public function cancelSession(ConversationSession $session): ConversationSession
                {
                    throw new \RuntimeException('Synchronous runtime should not be called.');
                }

                public function progressEvents(ConversationSession $session): Collection
                {
                    throw new \RuntimeException('Synchronous runtime should not be called.');
                }

                public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array
                {
                    throw new \RuntimeException('Synchronous runtime should not be called.');
                }

                public function sendExistingMessage(ConversationSession $session, ConversationMessage $userMessage): array
                {
                    throw new \RuntimeException('Synchronous runtime should not be called.');
                }
            };
        });
    }

    private function bindFailingQueueService(): void
    {
        $this->app->bind(AssistantRunService::class, function (): AssistantRunService {
            return new class extends AssistantRunService
            {
                public function queueRun(ConversationSession $session, string $content, array $metadata = [], string $source = 'http'): array
                {
                    throw new \RuntimeException('Simulated queue outage.');
                }

                public function queueExistingMessage(ConversationSession $session, ConversationMessage $userMessage, array $metadata = [], string $source = 'http'): array
                {
                    throw new \RuntimeException('Simulated queue outage.');
                }
            };
        });
    }

    private function bindQueueServiceThatCreatesRunThenThrows(): void
    {
        $this->app->bind(AssistantRunService::class, function (): AssistantRunService {
            return new class extends AssistantRunService
            {
                public function queueRun(ConversationSession $session, string $content, array $metadata = [], string $source = 'http'): array
                {
                    $userMessage = ConversationMessage::create([
                        'user_id' => $session->user_id,
                        'conversation_session_id' => $session->id,
                        'role' => 'user',
                        'content' => $content,
                        'metadata' => $metadata ?: null,
                    ]);

                    $run = AssistantRun::create([
                        'user_id' => $session->user_id,
                        'workspace_id' => $session->workspace_id,
                        'conversation_session_id' => $session->id,
                        'user_message_id' => $userMessage->id,
                        'source' => $source,
                        'status' => 'queued',
                        'input' => $content,
                        'metadata' => $metadata ?: null,
                    ]);

                    ActivityEvent::create([
                        'user_id' => $run->user_id,
                        'workspace_id' => $run->workspace_id,
                        'conversation_session_id' => $run->conversation_session_id,
                        'conversation_message_id' => $run->user_message_id,
                        'event_type' => 'runtime.run_queued',
                        'tool_name' => 'hermes.runs',
                        'status' => 'queued',
                        'payload' => [
                            'run_id' => $run->id,
                            'message_id' => $userMessage->id,
                            'source' => $source,
                        ],
                    ]);

                    $session->update([
                        'status' => 'queued',
                        'last_activity_at' => now(),
                    ]);

                    throw new \RuntimeException('Simulated queue dispatch failure after run creation.');
                }
            };
        });
    }
}
