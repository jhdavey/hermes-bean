<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnState;
use App\Exceptions\BrowserVoiceHandlerException;
use App\Exceptions\VoiceTurnConflictException;
use App\Jobs\EnforceBrowserVoiceTurnDeadline;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\AssistantRunService;
use App\Services\FastDomainWriteService;
use App\Services\FastWeatherReadService;
use App\Services\HermesRuntimeService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use LogicException;
use Mockery;
use Tests\TestCase;

class BrowserVoiceV2LifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_feature_flag_is_the_only_runtime_activation_gate(): void
    {
        config()->set('features.browser_voice_v2', false);
        $token = $this->apiToken('voice-v2-flag@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->getJson('/api/assistant/voice/capabilities')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_v2_enabled', false);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload($sessionId, 'flag-off-0001'))
            ->assertNotFound();

        config()->set('features.browser_voice_v2', true);
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_v2_enabled', true);
        $this->withToken($token)->getJson('/api/assistant/voice/capabilities')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_v2_enabled', true);
    }

    public function test_instant_admission_is_idempotent_and_creates_exactly_one_user_and_final_message(): void
    {
        $token = $this->apiToken('voice-v2-instant@example.com');
        $sessionId = $this->sessionId($token);
        $payload = $this->payload($sessionId, 'instant-turn-0001', 'Hey Bean, what time is it?') + [
            'timezone' => 'America/New_York',
            'declared_local_handler' => 'instant.current_time',
        ];

        $first = $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertCreated()
            ->assertJsonPath('data.turn.turn_id', 'instant-turn-0001')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.lane', 'instant')
            ->assertJsonPath('data.turn.handler', 'instant.current_time')
            ->assertJsonPath('data.turn.acknowledgement_required', false)
            ->assertJsonPath('data.turn.acknowledgement_text', null)
            ->assertJsonPath('data.jobs', [])
            ->assertJsonCount(2, 'data.messages');

        $second = $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertOk()
            ->assertJsonPath('data.turn.id', $first->json('data.turn.id'))
            ->assertJsonPath('data.turn.final_assistant_message_id', $first->json('data.turn.final_assistant_message_id'));

        $this->assertSame(1, VoiceTurn::where('turn_id', 'instant-turn-0001')->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->where('client_turn_id', 'instant-turn-0001')->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->where('client_turn_id', 'instant-turn-0001')->count());
        Queue::assertNotPushed(ProcessAssistantRun::class);
    }

    public function test_non_instant_admission_creates_one_linked_job_and_dispatches_it_once(): void
    {
        $token = $this->apiToken('voice-v2-job@example.com');
        $sessionId = $this->sessionId($token);
        $payload = $this->payload($sessionId, 'calendar-turn-0001', 'What is on my calendar tomorrow?');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertCreated()
            ->assertJsonPath('data.turn.state', 'accepted')
            ->assertJsonPath('data.turn.lane', 'app_read')
            ->assertJsonPath('data.turn.handler', 'app.calendar.read')
            ->assertJsonPath('data.jobs.0.label', 'Check calendar')
            ->assertJsonPath('data.jobs.0.status', 'queued');
        Queue::assertPushed(ProcessAssistantRun::class, 1);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertOk();

        $turn = VoiceTurn::where('turn_id', 'calendar-turn-0001')->firstOrFail();
        $this->assertSame(1, AssistantRun::where('voice_turn_id', $turn->id)->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
        Queue::assertPushed(EnforceBrowserVoiceTurnDeadline::class);
    }

    public function test_instant_time_uses_the_client_timezone_and_natural_twelve_hour_speech(): void
    {
        Carbon::setTestNow('2026-07-11 16:00:00', 'UTC');
        $token = $this->apiToken('voice-v2-natural-time@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'natural-time-turn-0001', 'What time is it?'),
            'timezone' => 'America/New_York',
        ])->assertCreated()
            ->assertJsonPath('data.turn.final_text', 'It’s twelve p.m.');
    }

    public function test_missed_hey_address_routes_to_local_time_without_acknowledgement_or_work(): void
    {
        Carbon::setTestNow('2026-07-13 17:10:00', 'UTC');
        $token = $this->apiToken('voice-v2-missed-hey-time@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'missed-hey-time-0001', 'Bean, what time is it?'),
            'timezone' => 'America/New_York',
        ])->assertCreated()
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.lane', 'instant')
            ->assertJsonPath('data.turn.handler', 'instant.current_time')
            ->assertJsonPath('data.turn.acknowledgement_required', false)
            ->assertJsonPath('data.turn.final_text', 'It’s 1:10 p.m.')
            ->assertJsonPath('data.jobs', []);

        Queue::assertNotPushed(ProcessAssistantRun::class);
    }

    public function test_address_only_transcript_cannot_create_a_turn_or_generic_work(): void
    {
        $token = $this->apiToken('voice-v2-address-only@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns',
            $this->payload($sessionId, 'address-only-turn-0001', 'Bean.')
        )->assertUnprocessable()
            ->assertJsonPath('code', 'voice_request_incomplete')
            ->assertJsonPath('question', 'What can I help you with?');

        $this->assertSame(0, VoiceTurn::where('turn_id', 'address-only-turn-0001')->count());
        Queue::assertNotPushed(ProcessAssistantRun::class);
    }

    public function test_conflicting_duplicate_admission_cannot_reuse_a_stable_turn_id(): void
    {
        $token = $this->apiToken('voice-v2-conflict@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'stable-turn-0001',
            'What is on my calendar tomorrow?',
        ))->assertCreated();

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'stable-turn-0001',
            'Delete all my reminders.',
        ))->assertConflict();

        $this->assertSame(1, VoiceTurn::where('turn_id', 'stable-turn-0001')->count());
        $this->assertSame('What is on my calendar tomorrow?', VoiceTurn::where('turn_id', 'stable-turn-0001')->value('transcript'));
    }

    public function test_generic_run_path_cannot_execute_a_v2_stable_turn_id(): void
    {
        $token = $this->apiToken('voice-v2-exclusive@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'v2-first-turn-0001',
            'What is on my calendar tomorrow?',
        ))->assertCreated();
        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'A legacy run duplicate.',
            'source' => 'web_routed_chat',
            'metadata' => ['client_request_id' => 'v2-first-turn-0001'],
        ])->assertConflict();

        $this->assertSame(1, AssistantRun::whereHas('voiceTurn', fn ($query) => $query->where('turn_id', 'v2-first-turn-0001'))->count());
    }

    public function test_lifecycle_is_monotonic_and_route_fields_are_immutable(): void
    {
        $token = $this->apiToken('voice-v2-monotonic@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'monotonic-turn-0001',
            'What is on my calendar tomorrow?',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'monotonic-turn-0001')->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $running = $lifecycle->transition($turn, VoiceTurnState::Running, $turn->version);

        try {
            $lifecycle->transition($running, VoiceTurnState::Accepted, $running->version);
            $this->fail('A lifecycle regression should be rejected.');
        } catch (VoiceTurnConflictException) {
            $this->assertSame('running', $running->fresh()->state->value);
        }
        $this->assertDatabaseHas('voice_turn_events', [
            'voice_turn_id' => $turn->id,
            'event_type' => 'transition_rejected',
            'from_state' => 'running',
            'to_state' => 'accepted',
        ]);

        $this->expectException(LogicException::class);
        $running->fresh()->update(['handler' => 'agent.complex']);
    }

    public function test_finalizer_is_idempotent_and_first_final_message_wins_exactly_once(): void
    {
        $token = $this->apiToken('voice-v2-final@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'final-turn-0001',
            'What is on my calendar tomorrow?',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'final-turn-0001')->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $first = $lifecycle->complete($turn, 'Your first and authoritative answer.');
        $second = $lifecycle->complete($turn->fresh(), 'A late duplicate answer.');

        $this->assertSame($first->final_assistant_message_id, $second->final_assistant_message_id);
        $this->assertSame('Your first and authoritative answer.', $second->finalAssistantMessage->content);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('client_turn_id', 'final-turn-0001')->where('role', 'assistant')->count());
        $this->assertDatabaseHas('voice_turn_events', [
            'voice_turn_id' => $turn->id,
            'event_type' => 'finalization_deduplicated',
        ]);
    }

    public function test_admitted_transcript_and_run_route_cannot_be_reinterpreted(): void
    {
        $token = $this->apiToken('voice-v2-immutable-route@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'immutable-route-turn-0001',
            'What is on my calendar tomorrow?',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'immutable-route-turn-0001')->firstOrFail();

        try {
            $turn->update(['transcript' => 'Delete every reminder.']);
            $this->fail('An admitted logical transcript must be immutable.');
        } catch (LogicException) {
            $this->assertSame('What is on my calendar tomorrow?', $turn->fresh()->transcript);
        }

        $run = $turn->runs()->firstOrFail();
        try {
            $run->update(['handler' => 'agent.complex']);
            $this->fail('A Browser Voice run handler must be immutable.');
        } catch (LogicException) {
            $this->assertSame('app.calendar.read', $run->fresh()->handler);
        }
    }

    public function test_explicit_cancellation_is_authoritative_and_snapshot_hides_normal_chat(): void
    {
        $token = $this->apiToken('voice-v2-cancel@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'cancel-turn-0001',
            'Create a reminder for tomorrow at noon called Lunch.',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'cancel-turn-0001')->firstOrFail();

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $sessionId,
            'turn_id' => $turn->turn_id,
            'reason' => 'User changed their mind.',
        ])->assertOk()
            ->assertJsonPath('data.turn.state', 'canceled')
            ->assertJsonPath('data.turn.visible_in_chat', false)
            ->assertJsonPath('data.messages.0.visible_in_chat', false)
            ->assertJsonPath('data.jobs.0.status', 'canceled')
            ->assertJsonPath('data.confirmation_text', 'Canceled.');

        $this->assertSame('canceled', $turn->fresh()->state->value);
        $this->assertSame('cancelled', $turn->runs()->firstOrFail()->status);
        $this->assertNull($turn->fresh()->final_assistant_message_id);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('client_turn_id', $turn->turn_id)->count());
    }

    public function test_reload_snapshot_returns_all_recent_turns_jobs_messages_and_cursor_events(): void
    {
        $token = $this->apiToken('voice-v2-reload@example.com');
        $sessionId = $this->sessionId($token);
        foreach ([
            ['reload-turn-0001', 'What is on my calendar tomorrow?'],
            ['reload-turn-0002', 'What will the weather be at Universal Studios tonight?'],
        ] as [$turnId, $transcript]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload($sessionId, $turnId, $transcript))
                ->assertCreated();
        }

        $snapshot = $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$sessionId}")
            ->assertOk()
            ->assertJsonCount(2, 'data.turns')
            ->assertJsonCount(2, 'data.jobs')
            ->assertJsonCount(2, 'data.messages')
            ->assertJsonPath('data.jobs.0.label', 'Check calendar');
        $cursor = (int) $snapshot->json('data.cursor');
        $this->assertGreaterThan(0, $cursor);

        $turn = VoiceTurn::where('turn_id', 'reload-turn-0002')->firstOrFail();
        app(VoiceTurnLifecycleService::class)->markProgress($turn, ['phase' => 'provider_started']);

        $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$sessionId}&cursor={$cursor}")
            ->assertOk()
            ->assertJsonCount(1, 'data.events')
            ->assertJsonPath('data.events.0.type', 'progress_recorded')
            ->assertJsonPath('data.events.0.turn_id', 'reload-turn-0002');
    }

    public function test_reload_event_cursor_advances_one_complete_page_without_skipping_overflow_events(): void
    {
        $token = $this->apiToken('voice-v2-event-pages@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'event-pages-turn-0001',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'event-pages-turn-0001')->firstOrFail();
        $nextSequence = (int) $turn->events()->max('sequence') + 1;
        $timestamp = now();
        $rows = [];

        for ($index = 0; $index < 510; $index++) {
            $rows[] = [
                'voice_turn_id' => $turn->id,
                'user_id' => $turn->user_id,
                'workspace_id' => $turn->workspace_id,
                'conversation_session_id' => $turn->conversation_session_id,
                'sequence' => $nextSequence + $index,
                'event_type' => 'pagination_probe',
                'from_state' => $turn->state->value,
                'to_state' => $turn->state->value,
                'version' => $turn->version,
                'source' => 'test',
                'payload' => json_encode(['index' => $index], JSON_THROW_ON_ERROR),
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ];
        }
        foreach (array_chunk($rows, 100) as $chunk) {
            VoiceTurnEvent::query()->insert($chunk);
        }

        $expectedEventIds = VoiceTurnEvent::query()
            ->where('conversation_session_id', $sessionId)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $firstPage = $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$sessionId}")
            ->assertOk()
            ->assertJsonCount(500, 'data.events');
        $firstEventIds = collect($firstPage->json('data.events'))
            ->pluck('cursor')
            ->map(fn ($id): int => (int) $id)
            ->values();
        $firstCursor = (int) $firstPage->json('data.cursor');

        $this->assertSame($firstEventIds->last(), $firstCursor);
        $this->assertLessThan($expectedEventIds->last(), $firstCursor);

        $secondPage = $this->withToken($token)->getJson(
            "/api/assistant/voice/state?session_id={$sessionId}&cursor={$firstCursor}",
        )->assertOk();
        $secondEventIds = collect($secondPage->json('data.events'))
            ->pluck('cursor')
            ->map(fn ($id): int => (int) $id)
            ->values();

        $this->assertSame($expectedEventIds->all(), $firstEventIds->concat($secondEventIds)->all());
        $this->assertSame($expectedEventIds->last(), (int) $secondPage->json('data.cursor'));
    }

    public function test_disabling_v2_blocks_new_admission_but_preserves_recovery_endpoints_for_admitted_work(): void
    {
        $token = $this->apiToken('voice-v2-kill-switch-recovery@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'kill-switch-existing-0001',
            'Create a detailed seven-day family itinerary with a packing plan.',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'kill-switch-existing-0001')->firstOrFail();

        config()->set('features.browser_voice_v2', false);

        $this->withToken($token)->getJson('/api/assistant/voice/capabilities')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_v2_enabled', false);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'kill-switch-new-0001',
        ))->assertNotFound();
        $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.turns.0.turn_id', $turn->turn_id);
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/delivery", [
            'session_id' => $sessionId,
            'event' => 'acknowledgement_started',
            'timing' => ['latency_ms' => 400],
        ])->assertOk()
            ->assertJsonPath('data.turn.turn_id', $turn->turn_id);
        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $sessionId,
            'turn_id' => $turn->turn_id,
        ])->assertOk()
            ->assertJsonPath('data.turn.state', 'canceled');

        $this->assertDatabaseMissing('voice_turns', ['turn_id' => 'kill-switch-new-0001']);
        $this->assertSame(VoiceTurnState::Canceled, $turn->fresh()->state);
    }

    public function test_raw_audio_is_rejected_and_never_persisted_in_turns_or_events(): void
    {
        $token = $this->apiToken('voice-v2-privacy@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'privacy-turn-0001'),
            'raw_audio' => base64_encode('private microphone bytes'),
        ])->assertUnprocessable();
        $this->assertDatabaseCount('voice_turns', 0);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'privacy-turn-0002', 'What is on my calendar tomorrow?'),
            'transcript_timing' => ['duration_ms' => 800],
        ])->assertCreated();

        $persisted = VoiceTurn::with('events')->where('turn_id', 'privacy-turn-0002')->firstOrFail()->toArray();
        $serialized = json_encode($persisted, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('private microphone bytes', $serialized);
        $this->assertStringNotContainsString(base64_encode('private microphone bytes'), $serialized);
        $this->assertFalse((bool) data_get($persisted, 'metadata.raw_audio_retained', true));
    }

    public function test_deadline_enforcement_terminalizes_with_one_natural_failure_message(): void
    {
        Carbon::setTestNow('2026-07-11 18:00:00');
        $token = $this->apiToken('voice-v2-deadline@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'deadline-turn-0001',
            'What is on my calendar tomorrow?',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'deadline-turn-0001')->firstOrFail();

        Carbon::setTestNow('2026-07-11 18:00:04');
        $this->assertSame(1, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));

        $turn->refresh();
        $this->assertSame('failed', $turn->state->value);
        $this->assertSame('hard_deadline_timeout', $turn->failure_category);
        $this->assertStringContainsString('Would you like me to try again?', $turn->finalAssistantMessage->content);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_v2_worker_routes_runtime_output_through_the_single_turn_finalizer(): void
    {
        $token = $this->apiToken('voice-v2-worker@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'worker-turn-0001',
            'Create a short meal plan note.',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'worker-turn-0001')->firstOrFail();
        $run = $turn->runs()->firstOrFail();
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session, ConversationMessage $message): array {
                $provisional = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'I created the meal plan note.',
                    'metadata' => ['assistant_run_id' => data_get($message->metadata, 'assistant_run_id')],
                ]);

                return [
                    'status' => 'completed',
                    'session' => $session,
                    'user_message' => $message,
                    'assistant_message' => $provisional,
                    'events' => collect(),
                    'blocker' => null,
                ];
            });

        (new ProcessAssistantRun($run->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh()->load('finalAssistantMessage');
        $run->refresh();
        $this->assertSame('completed', $turn->state->value);
        $this->assertSame('completed', $run->status);
        $this->assertSame($turn->final_assistant_message_id, $run->assistant_message_id);
        $this->assertSame('Done—I created the note “Meal Plan”.', $turn->finalAssistantMessage->content);
        $this->assertDatabaseHas('notes', [
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'title' => 'Meal Plan',
            'plain_text' => 'I created the meal plan note.',
        ]);
        $this->assertSame('committed', data_get($run->metadata, 'write_receipt.status'));
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', 'worker-turn-0001')->where('role', 'assistant')->count());
    }

    public function test_contextual_voice_cancellation_is_a_separate_exact_once_turn_and_cancels_work_authoritatively(): void
    {
        $token = $this->apiToken('voice-v2-context-cancel@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'work-to-cancel-0001',
            'Create a three-day meal plan and save it as a note.',
        ))->assertCreated();

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'cancel-command-0001',
            'Cancel that work request.',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.cancel')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.final_text', 'Canceled.')
            ->assertJsonPath('data.jobs', []);

        $this->assertSame(VoiceTurnState::Canceled, VoiceTurn::where('turn_id', 'work-to-cancel-0001')->value('state'));
        $this->assertSame('cancelled', VoiceTurn::where('turn_id', 'work-to-cancel-0001')->firstOrFail()->runs()->value('status'));
        $this->assertSame(1, ConversationMessage::where('client_turn_id', 'cancel-command-0001')->where('role', 'assistant')->count());
    }

    public function test_reminder_task_and_note_reads_use_typed_handlers_without_agent_runtime(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00');
        $token = $this->apiToken('voice-v2-domain-reads@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Call the dentist',
            'remind_at' => now()->addHour(),
            'status' => 'scheduled',
        ]);
        Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Send the invoice',
            'status' => 'open',
            'due_at' => now()->addHours(2),
        ]);
        Note::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Packing list',
            'plain_text' => 'Shoes and passport',
            'body_html' => '<p>Shoes and passport</p>',
        ]);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');

        foreach ([
            ['typed-reminder-read-0001', 'What reminders do I have?', 'Call the dentist'],
            ['typed-task-read-0001', 'What tasks do I have?', 'Send the invoice'],
            ['typed-note-read-0001', 'Find my note called Packing list.', 'Shoes and passport'],
        ] as [$turnId, $request, $expected]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload($sessionId, $turnId, $request))
                ->assertCreated();
            $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
            (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
                $runtime,
                app(AssistantRunService::class),
                app(VoiceTurnLifecycleService::class),
            );
            $turn->refresh()->load('finalAssistantMessage');
            $this->assertSame(VoiceTurnState::Completed, $turn->state);
            $this->assertStringContainsString($expected, $turn->finalAssistantMessage->content);
        }
    }

    public function test_fully_specified_reminder_write_uses_deterministic_tools_and_is_idempotent(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        Http::preventStrayRequests();
        $token = $this->apiToken('voice-v2-direct-write@example.com');
        $sessionId = $this->sessionId($token);
        $payload = $this->payload(
            $sessionId,
            'typed-reminder-write-0001',
            'Create a reminder titled Universal for today at 4 p.m.',
        ) + ['timezone' => 'America/New_York'];
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'typed-reminder-write-0001')->firstOrFail();
        $run = $turn->runs()->firstOrFail();

        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );
        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $this->assertSame(1, Reminder::where('workspace_id', $turn->workspace_id)->where('title', 'Universal')->count());
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
        Http::assertNothingSent();
    }

    public function test_calendar_read_uses_its_persisted_typed_handler_without_agent_fallback(): void
    {
        $token = $this->apiToken('voice-v2-typed-read@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'typed-read-turn-0001', 'What is on my calendar tomorrow?'),
            'timezone' => 'America/New_York',
        ])->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'typed-read-turn-0001')->firstOrFail();
        $run = $turn->runs()->firstOrFail();
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');

        (new ProcessAssistantRun($run->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh()->load('finalAssistantMessage');
        $this->assertSame('completed', $turn->state->value);
        $this->assertSame('app.calendar.read', $turn->handler);
        $this->assertStringContainsString('calendar tomorrow', $turn->finalAssistantMessage->content);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_scheduler_allows_three_background_jobs_read_bypass_and_then_releases_capacity(): void
    {
        $token = $this->apiToken('voice-v2-capacity@example.com');
        $sessionId = $this->sessionId($token);
        $requests = [
            ['capacity-reminder-0001', 'Create a reminder for tomorrow at noon called Lunch.'],
            ['capacity-task-0001', 'Create a task called Send RSVP.'],
            ['capacity-note-0001', 'Create a note called Grocery ideas.'],
            ['capacity-complex-0001', 'Plan a seven-day family trip with a detailed itinerary.'],
            ['capacity-read-0001', 'What is on my calendar tomorrow?'],
        ];
        foreach ($requests as [$turnId, $transcript]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload($sessionId, $turnId, $transcript))
                ->assertCreated();
        }

        $lifecycle = app(VoiceTurnLifecycleService::class);
        $runs = VoiceTurn::query()->whereIn('turn_id', collect($requests)->pluck(0))->get()
            ->keyBy('turn_id')
            ->map(fn (VoiceTurn $turn): AssistantRun => $turn->runs()->firstOrFail());
        foreach (['capacity-reminder-0001', 'capacity-task-0001', 'capacity-note-0001'] as $turnId) {
            $this->assertTrue($lifecycle->claimJobExecution($runs[$turnId]));
        }

        $this->assertFalse($lifecycle->claimJobExecution($runs['capacity-complex-0001']));
        $this->assertSame('queued', $runs['capacity-complex-0001']->fresh()->status);
        $this->assertNotNull(data_get($runs['capacity-complex-0001']->fresh()->metadata, 'capacity_wait_started_at'));

        $this->assertTrue($lifecycle->claimJobExecution($runs['capacity-read-0001']));
        $this->assertSame('running', $runs['capacity-read-0001']->fresh()->status);

        $lifecycle->cancel(VoiceTurn::where('turn_id', 'capacity-reminder-0001')->firstOrFail());
        $this->assertTrue($lifecycle->claimJobExecution($runs['capacity-complex-0001']->fresh()));
        $this->assertSame('running', $runs['capacity-complex-0001']->fresh()->status);
    }

    public function test_scheduler_allows_independent_same_domain_creates_to_run_concurrently(): void
    {
        $token = $this->apiToken('voice-v2-resource@example.com');
        $sessionId = $this->sessionId($token);
        foreach ([
            ['resource-turn-0001', 'Create a reminder for tomorrow at noon called Lunch.'],
            ['resource-turn-0002', 'Create a reminder for tomorrow at four p.m. called Dinner.'],
        ] as [$turnId, $transcript]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload($sessionId, $turnId, $transcript))
                ->assertCreated();
        }
        $first = VoiceTurn::where('turn_id', 'resource-turn-0001')->firstOrFail()->runs()->firstOrFail();
        $second = VoiceTurn::where('turn_id', 'resource-turn-0002')->firstOrFail()->runs()->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $this->assertNotNull($first->resource_lock_key);
        $this->assertNotNull($second->resource_lock_key);
        $this->assertNotSame($first->resource_lock_key, $second->resource_lock_key);
        $this->assertTrue($lifecycle->claimJobExecution($first));
        $this->assertTrue($lifecycle->claimJobExecution($second));
        $this->assertSame('running', $second->fresh()->status);
    }

    public function test_delivery_markers_are_idempotent_and_recoverable_with_timing_events(): void
    {
        $token = $this->apiToken('voice-v2-delivery@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'delivery-turn-0001',
            'Plan a seven-day family trip with a detailed itinerary.',
        ))->assertCreated()
            ->assertJsonPath('data.turn.acknowledgement_required', true);
        $turn = VoiceTurn::where('turn_id', 'delivery-turn-0001')->firstOrFail();
        $ackPayload = [
            'session_id' => $sessionId,
            'event' => 'acknowledgement_started',
            'timing' => ['latency_ms' => 420, 'speech_item_id' => 'speech-ack-1'],
        ];

        $firstAck = $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/delivery", $ackPayload)
            ->assertOk();
        $ackVersion = $firstAck->json('data.turn.version');
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/delivery", $ackPayload)
            ->assertOk()
            ->assertJsonPath('data.turn.version', $ackVersion);
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $turn->id)->where('event_type', 'acknowledgement_started')->count());
        $ackEvent = VoiceTurnEvent::where('voice_turn_id', $turn->id)->where('event_type', 'acknowledgement_started')->firstOrFail();
        $this->assertSame(420, data_get($ackEvent->payload, 'latency_ms'));

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/delivery", [
            'session_id' => $sessionId,
            'event' => 'final_text_delivered',
        ])->assertConflict();

        app(VoiceTurnLifecycleService::class)->complete($turn->fresh(), 'Here is the itinerary.');
        $finalPayload = [
            'session_id' => $sessionId,
            'event' => 'final_text_delivered',
            'timing' => ['latency_ms' => 1800, 'speech_item_id' => 'speech-final-1'],
        ];
        $firstFinal = $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/delivery", $finalPayload)
            ->assertOk();
        $finalVersion = $firstFinal->json('data.turn.version');
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/delivery", $finalPayload)
            ->assertOk()
            ->assertJsonPath('data.turn.version', $finalVersion);
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $turn->id)->where('event_type', 'final_text_delivered')->count());
        $this->assertNotNull($turn->fresh()->final_delivered_at);
    }

    public function test_contextual_calendar_follow_up_keeps_the_typed_handler_and_answers_the_first_event(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-calendar-follow-up@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'title' => 'Jiu-jitsu open mat',
            'starts_at' => Carbon::parse('2026-07-12 10:00:00', 'America/New_York')->utc(),
            'ends_at' => Carbon::parse('2026-07-12 11:00:00', 'America/New_York')->utc(),
            'status' => 'scheduled',
        ]);

        foreach ([
            ['calendar-context-turn-0001', 'What is on my calendar tomorrow?', 'new_conversation'],
            ['calendar-context-turn-0002', 'What time is the first one?', 'contextual_follow_up'],
        ] as [$turnId, $transcript, $contextMode]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', [
                ...$this->payload($sessionId, $turnId, $transcript),
                'timezone' => 'America/New_York',
                'conversation_context' => ['mode' => $contextMode, 'epoch' => 1],
            ])->assertCreated();
            $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
            (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
                app(VoiceTurnLifecycleService::class),
            );
        }

        $followUp = VoiceTurn::where('turn_id', 'calendar-context-turn-0002')->firstOrFail();
        $this->assertSame('app.calendar.read', $followUp->handler);
        $this->assertSame(VoiceTurnState::Completed, $followUp->state);
        $this->assertStringContainsString('Jiu-jitsu open mat', $followUp->finalAssistantMessage->content);
        $this->assertStringContainsString('10 a.m.', $followUp->finalAssistantMessage->content);
    }

    public function test_contextual_calendar_follow_up_current_day_overrides_the_prior_day(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-calendar-day-override@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        foreach ([
            ['Today focus review', '2026-07-11 14:00:00', '2026-07-11 15:00:00'],
            ['Tomorrow open mat', '2026-07-12 10:00:00', '2026-07-12 11:00:00'],
        ] as [$title, $startsAt, $endsAt]) {
            CalendarEvent::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'created_by_user_id' => $session->user_id,
                'title' => $title,
                'starts_at' => Carbon::parse($startsAt, 'America/New_York')->utc(),
                'ends_at' => Carbon::parse($endsAt, 'America/New_York')->utc(),
                'status' => 'scheduled',
            ]);
        }

        foreach ([
            ['calendar-day-override-0001', 'What is on my calendar tomorrow?', 'new_conversation'],
            ['calendar-day-override-0002', 'What about today?', 'contextual_follow_up'],
        ] as [$turnId, $transcript, $contextMode]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', [
                ...$this->payload($sessionId, $turnId, $transcript),
                'timezone' => 'America/New_York',
                'conversation_context' => ['mode' => $contextMode, 'epoch' => 1],
            ])->assertCreated();
            $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
            (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
                app(VoiceTurnLifecycleService::class),
            );
        }

        $followUp = VoiceTurn::where('turn_id', 'calendar-day-override-0002')->firstOrFail();
        $this->assertSame('app.calendar.read', $followUp->handler);
        $this->assertSame(VoiceTurnState::Completed, $followUp->state);
        $this->assertStringContainsString('Today focus review', $followUp->finalAssistantMessage->content);
        $this->assertStringNotContainsString('Tomorrow open mat', $followUp->finalAssistantMessage->content);
    }

    public function test_contextual_weather_follow_up_preserves_the_external_handler_without_agent_fallback(): void
    {
        $token = $this->apiToken('voice-v2-weather-follow-up@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'weather-context-turn-0001', 'What is the weather at Universal Studios this evening?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
            'location_context' => ['label' => 'Orlando, Florida', 'is_local' => false, 'source' => 'spoken'],
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'weather-context-turn-0002', 'What about tomorrow?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.turn.lane', 'external')
            ->assertJsonPath('data.turn.handler', 'external.weather');
    }

    public function test_weather_time_is_not_treated_as_a_location_and_default_location_satisfies_clarification(): void
    {
        $token = $this->apiToken('voice-v2-weather-location-completeness@example.com');
        $sessionId = $this->sessionId($token);
        $payload = [
            ...$this->payload($sessionId, 'weather-location-turn-0001', 'What is the weather at 5 p.m.?'),
            'timezone' => 'America/New_York',
        ];

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'voice_request_incomplete')
            ->assertJsonPath('question', 'Which location should I check?');
        $this->assertDatabaseMissing('voice_turns', ['turn_id' => 'weather-location-turn-0001']);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$payload,
            'location_context' => [
                'label' => 'Orlando, Florida',
                'is_local' => false,
                'source' => 'spoken',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.turn.handler', 'external.weather');
    }

    public function test_natural_reminder_title_after_the_time_is_a_typed_idempotent_write(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-natural-reminder@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload(
                $sessionId,
                'typed-natural-reminder-0001',
                'Set a reminder for 4 p.m. today titled Universal.',
            ),
            'timezone' => 'America/New_York',
        ])->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'typed-natural-reminder-0001')->firstOrFail();

        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $reminder = Reminder::where('workspace_id', $turn->workspace_id)->where('title', 'Universal')->sole();
        $this->assertSame('2026-07-11 16:00', $reminder->remind_at->timezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertSame('committed', data_get($turn->fresh()->metadata, 'write_receipt.status'));
    }

    public function test_date_only_reminder_clarifies_for_a_clock_time_before_admission(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-date-only-reminder@example.com');
        $sessionId = $this->sessionId($token);
        $payload = [
            ...$this->payload(
                $sessionId,
                'typed-date-only-reminder-0001',
                'Set a reminder titled Call Mom for tomorrow.',
            ),
            'timezone' => 'America/New_York',
        ];

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertUnprocessable()
            ->assertJsonPath('code', 'voice_request_incomplete')
            ->assertJsonPath('question', 'What time should I remind you?');
        $this->assertDatabaseMissing('voice_turns', ['turn_id' => 'typed-date-only-reminder-0001']);

        $payload['transcript'] = 'Set a reminder titled Call Mom for tomorrow at 4 p.m.';
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'typed-date-only-reminder-0001')->firstOrFail();
        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $reminder = Reminder::where('metadata->browser_voice_turn_id', $turn->turn_id)->sole();
        $this->assertSame('Call Mom', $reminder->title);
        $this->assertSame('2026-07-12 16:00', $reminder->remind_at->timezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_date_only_calendar_write_clarifies_for_a_clock_time_before_admission(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-date-only-calendar@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload(
                $sessionId,
                'typed-date-only-calendar-0001',
                'Schedule dentist tomorrow.',
            ),
            'timezone' => 'America/New_York',
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'voice_request_incomplete')
            ->assertJsonPath('question', 'What time should I schedule it?');

        $this->assertDatabaseMissing('voice_turns', ['turn_id' => 'typed-date-only-calendar-0001']);
        $this->assertDatabaseCount('calendar_events', 0);
    }

    public function test_natural_calendar_title_and_time_are_parsed_and_executed_by_the_typed_handler(): void
    {
        Carbon::setTestNow('2026-07-11 23:59:58', 'America/New_York');
        $token = $this->apiToken('voice-v2-natural-calendar@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload(
                $sessionId,
                'typed-natural-calendar-0001',
                'Schedule dentist tomorrow at 4 p.m.',
            ),
            'timezone' => 'America/New_York',
        ])->assertCreated()
            ->assertJsonPath('data.turn.lane', 'app_write')
            ->assertJsonPath('data.turn.handler', 'app.calendar.create');
        $turn = VoiceTurn::where('turn_id', 'typed-natural-calendar-0001')->firstOrFail();
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');
        Carbon::setTestNow('2026-07-12 00:00:01', 'America/New_York');

        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $event = CalendarEvent::where('metadata->browser_voice_turn_id', $turn->turn_id)->sole();
        $this->assertSame('dentist', $event->title);
        $this->assertSame('2026-07-12 16:00', $event->starts_at->timezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame('2026-07-12 17:00', $event->ends_at->timezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertStringContainsString('dentist', $turn->fresh()->finalAssistantMessage->content);
    }

    public function test_delete_that_reminder_uses_one_typed_target_and_never_the_agent_runtime(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-delete-reminder@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Long run',
            'remind_at' => Carbon::parse('2026-07-11 20:00:00', 'America/New_York')->utc(),
            'status' => 'scheduled',
        ]);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'typed-delete-reminder-0001', 'Delete that 8 p.m. reminder.'),
            'timezone' => 'America/New_York',
        ])->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'typed-delete-reminder-0001')->firstOrFail();
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');

        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $this->assertDatabaseMissing('reminders', ['workspace_id' => $session->workspace_id, 'title' => 'Long run']);
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertStringContainsString('Long run', $turn->fresh()->finalAssistantMessage->content);
    }

    public function test_cancel_that_races_an_already_committed_direct_write_reconciles_to_completed(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-cancel-write-race@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'write-race-turn-0001', 'Set a reminder for 4 p.m. today titled Universal.'),
            'timezone' => 'America/New_York',
        ])->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'write-race-turn-0001')->firstOrFail();
        $this->assertNotNull(app(FastDomainWriteService::class)->execute($turn));

        $terminal = app(VoiceTurnLifecycleService::class)->cancel($turn->fresh());

        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('committed', $terminal->side_effect_status->value);
        $this->assertSame(1, Reminder::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
        $this->assertStringContainsString('created the reminder', $terminal->finalAssistantMessage->content);
    }

    public function test_read_only_provider_failure_retries_once_inside_the_original_turn(): void
    {
        $token = $this->apiToken('voice-v2-read-retry@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'weather-retry-turn-0001',
            'What will the weather be at Universal Studios this evening?',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'weather-retry-turn-0001')->firstOrFail();
        $weather = Mockery::mock(FastWeatherReadService::class);
        $weather->shouldReceive('resolve')->once()->andThrow(new BrowserVoiceHandlerException(
            'weather_lookup_timeout',
            'The provider timed out.',
            'I couldn’t reach the weather service. Would you like me to try again?',
            true,
        ));
        $weather->shouldReceive('resolve')->once()->andReturn('It will be 88 degrees with scattered storms.');

        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
            null,
            $weather,
        );

        $turn->refresh();
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame(1, $turn->retry_count);
        $this->assertSame(1, $turn->events()->where('event_type', 'retry_started')->count());
        $this->assertStringContainsString('88 degrees', $turn->finalAssistantMessage->content);
    }

    public function test_no_progress_watchdog_still_terminalizes_after_initial_worker_progress(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00');
        $token = $this->apiToken('voice-v2-no-progress@example.com');
        $sessionId = $this->sessionId($token);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'no-progress-turn-0001',
            'Create a detailed seven-day family itinerary with a packing plan.',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', 'no-progress-turn-0001')->firstOrFail();
        app(VoiceTurnLifecycleService::class)->markProgress($turn, ['phase' => 'worker_claimed']);

        Carbon::setTestNow('2026-07-11 12:00:11');
        $this->assertSame(1, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));

        $turn->refresh();
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('no_progress_timeout', $turn->failure_category);
        $this->assertSame('failed', $turn->runs()->firstOrFail()->status);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_direct_app_read_completes_in_the_admission_request_without_waiting_for_a_worker(): void
    {
        Queue::fake([EnforceBrowserVoiceTurnDeadline::class]);
        $token = $this->apiToken('voice-v2-sync-read@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'sync-read-turn-0001',
            'What is on my calendar tomorrow?',
        ))->assertCreated()
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.jobs.0.status', 'completed');
    }

    public function test_voice_state_answer_then_to_do_follow_up_uses_authoritative_tasks_without_an_acknowledgement(): void
    {
        Carbon::setTestNow('2026-07-13 14:30:00', 'America/New_York');
        Queue::fake([EnforceBrowserVoiceTurnDeadline::class]);
        $token = $this->apiToken('voice-v2-to-do-follow-up@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Review launch checklist',
            'status' => 'open',
            'due_at' => Carbon::parse('2026-07-13 16:00:00', 'America/New_York')->utc(),
        ]);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'voice-state-before-task-0001', 'Can you hear me?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.turn.handler', 'instant.voice_state')
            ->assertJsonPath('data.turn.final_text', 'Yes—I can hear you.');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'to-do-follow-up-0001', 'Is there anything on my to-do list for today?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.turn.lane', 'app_read')
            ->assertJsonPath('data.turn.handler', 'app.task.read')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.acknowledgement_required', false)
            ->assertJsonPath('data.jobs.0.label', 'Check tasks')
            ->assertJsonPath('data.jobs.0.status', 'completed')
            ->assertJsonPath('data.turn.final_text', fn (string $text): bool => str_contains($text, 'Review launch checklist'));

        $turn = VoiceTurn::where('turn_id', 'to-do-follow-up-0001')->firstOrFail();
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
        $this->assertSame(0, $turn->events()->where('event_type', 'acknowledgement_started')->count());
    }

    public function test_task_read_then_that_task_reminder_is_one_durable_typed_contextual_write(): void
    {
        Carbon::setTestNow('2026-07-13 14:49:00', 'America/New_York');
        Queue::fake([EnforceBrowserVoiceTurnDeadline::class]);
        $token = $this->apiToken('voice-v2-task-reminder-follow-up@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'salt',
            'status' => 'open',
            'due_at' => Carbon::parse('2026-07-13 18:00:00', 'America/New_York')->utc(),
        ]);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'salt-task-read-0001', 'What is on my to-do list for today?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.task.read')
            ->assertJsonPath('data.turn.final_text', fn (string $text): bool => str_contains($text, '“salt”'));

        $reminderPayload = [
            ...$this->payload($sessionId, 'salt-reminder-follow-up-0001', 'Okay, great. Can you set a reminder at 5 p.m. for that task?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 1],
        ];
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $reminderPayload)->assertCreated()
            ->assertJsonPath('data.turn.lane', 'app_write')
            ->assertJsonPath('data.turn.handler', 'app.reminder.create')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.final_text', 'Done—I created the reminder “salt” for today at 5 p.m.')
            ->assertJsonPath('data.turn.acknowledgement_required', false)
            ->assertJsonPath('data.jobs.0.label', 'Update reminders')
            ->assertJsonPath('data.jobs.0.status', 'completed');

        $turn = VoiceTurn::where('turn_id', 'salt-reminder-follow-up-0001')->firstOrFail();
        $reminder = Reminder::where('metadata->browser_voice_turn_id', $turn->turn_id)->sole();
        $this->assertSame('salt', $reminder->title);
        $this->assertSame('2026-07-13 17:00', $reminder->remind_at->timezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame('app.task.read', data_get($turn->metadata, 'prior_handler'));
        $this->assertSame('salt', data_get($turn->metadata, 'contextual_reference.title'));
        $this->assertSame('task', data_get($turn->metadata, 'contextual_reference.domain'));
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'salt-reminder-correction-0001', 'Set it for 5 p.m.'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.turn.lane', 'app_write')
            ->assertJsonPath('data.turn.handler', 'app.reminder.reschedule')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.side_effect_status', 'committed')
            ->assertJsonPath('data.turn.final_text', 'Done—I moved “salt” to today at 5 p.m.')
            ->assertJsonPath('data.jobs.0.label', 'Update reminders')
            ->assertJsonPath('data.jobs.0.status', 'completed');

        $correction = VoiceTurn::where('turn_id', 'salt-reminder-correction-0001')->firstOrFail();
        $this->assertSame('app.reminder.create', data_get($correction->metadata, 'prior_handler'));
        $this->assertSame(1, Reminder::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
        $this->assertSame('2026-07-13 17:00', $reminder->fresh()->remind_at->timezone('America/New_York')->format('Y-m-d H:i'));
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $correction->turn_id)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $correction->turn_id)->where('role', 'assistant')->count());

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $reminderPayload)
            ->assertOk()
            ->assertJsonPath('data.turn.id', $turn->id)
            ->assertJsonPath('data.turn.state', 'completed');
        $this->assertSame(1, Reminder::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_ambiguous_that_task_reminder_clarifies_without_admitting_or_writing(): void
    {
        Carbon::setTestNow('2026-07-13 15:19:00', 'America/New_York');
        Queue::fake([EnforceBrowserVoiceTurnDeadline::class]);
        $token = $this->apiToken('voice-v2-ambiguous-task-reminder@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        foreach (['salt', 'buy groceries'] as $title) {
            Task::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'title' => $title,
                'status' => 'open',
                'due_at' => Carbon::parse('2026-07-13 18:00:00', 'America/New_York')->utc(),
            ]);
        }

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'ambiguous-task-read-0001', 'What is on my to-do list for today?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
        ])->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.task.read');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'ambiguous-reminder-follow-up-0001', 'Can you set a reminder at 5 p.m. for that task?'),
            'timezone' => 'America/New_York',
            'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 1],
        ])->assertUnprocessable()
            ->assertJsonPath('code', 'voice_request_incomplete')
            ->assertJsonPath('question', 'What should I remind you about?');

        $this->assertDatabaseMissing('voice_turns', ['turn_id' => 'ambiguous-reminder-follow-up-0001']);
        $this->assertSame(0, ConversationMessage::where('client_turn_id', 'ambiguous-reminder-follow-up-0001')->count());
        $this->assertSame(0, Reminder::where('metadata->browser_voice_turn_id', 'ambiguous-reminder-follow-up-0001')->count());
    }

    /** @return array<string, mixed> */
    private function payload(int $sessionId, string $turnId, string $transcript = 'Hey Bean, can you hear me?'): array
    {
        return [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
        ];
    }

    private function sessionId(string $token): int
    {
        return (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
    }
}
