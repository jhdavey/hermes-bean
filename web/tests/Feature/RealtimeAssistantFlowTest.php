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
use Illuminate\Support\Carbon;
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
            && data_get($request->data(), 'session.audio.input.transcription.model') === 'gpt-4o-mini-transcribe'
            && data_get($request->data(), 'session.audio.input.transcription.prompt') === null
            && data_get($request->data(), 'session.audio.input.turn_detection.type') === 'server_vad'
            && data_get($request->data(), 'session.audio.input.turn_detection.silence_duration_ms') === 800
            && data_get($request->data(), 'session.audio.input.turn_detection.create_response') === false
            && preg_match('/^[A-Za-z0-9_-]+$/', (string) data_get($request->data(), 'session.tracing.group_id')) === 1
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Only respond when the user is clearly talking to Bean')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Yes, I can hear you.')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'current time/date questions')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Dashboard context snapshot')
            && str_contains((string) data_get($request->data(), 'session.instructions'), 'Conversation contract')
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
            ->assertJsonPath('data.snapshot.timezone', '-04:00')
            ->assertJson(fn ($json) => $json
                ->where('data.prompt_text', fn (string $value): bool => str_contains($value, 'Dashboard context snapshot') && str_contains($value, 'the turn is complete'))
                ->where('data.instructions', fn (string $value): bool => str_contains($value, 'Call insurance') && str_contains($value, 'Only respond when the user is clearly talking to Bean'))
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
        $profile = app(\App\Services\AgentProfileService::class)->ensureForWorkspace($workspace, $user);
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
            ->assertJsonPath('data.snapshot.tasks_upcoming_month.0.due_at', '2026-06-08T20:00:00-04:00')
            ->assertJsonPath('data.snapshot.tasks_upcoming_month.0.display_due_date', '2026-06-08')
            ->assertJsonPath('data.snapshot.reminders_due.0.remind_at', '2026-06-08T20:00:00-04:00')
            ->assertJsonPath('data.snapshot.reminders_due.0.display_remind_date', '2026-06-08')
            ->assertJson(fn ($json) => $json
                ->where('data.prompt_text', fn (string $value): bool => str_contains($value, 'use display_start_date/display_end_date'))
                ->where('data.instructions', fn (string $value): bool => str_contains($value, '2026-06-05 through 2026-06-08'))
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
