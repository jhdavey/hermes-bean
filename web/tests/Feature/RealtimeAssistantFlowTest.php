<?php

namespace Tests\Feature;

use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMembership;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
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
                ->where('data.instructions', fn (string $value): bool => str_contains($value, 'Move laundry') && str_contains($value, 'Only respond when the user is clearly talking to Bean'))
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

                public function progressEvents(ConversationSession $session): \Illuminate\Support\Collection
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

                public function progressEvents(ConversationSession $session): \Illuminate\Support\Collection
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

                public function progressEvents(ConversationSession $session): \Illuminate\Support\Collection
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
