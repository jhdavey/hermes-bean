<?php

namespace Tests\Feature;

use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\AssistantRunService;
use App\Services\BeanMemoryService;
use App\Services\HermesRuntimeService;
use App\Services\WorkspaceItemSyncService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class VoiceRunSupersessionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'America/New_York'));
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.default_model', 'gpt-test-tools');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        config()->set('services.hermes_runtime.crud_planner_enabled', true);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_session_cancel_cancels_a_queued_voice_run_before_it_can_mutate(): void
    {
        $token = $this->apiToken('voice-cancel-queued@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun($token, $sessionId, 'Create a reminder to call Mom tomorrow at 5:00 PM.', 'voice-original')
            ->assertAccepted()
            ->json('data.run.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'active');

        $this->assertSame('cancelled', AssistantRun::findOrFail($runId)->status);
        $this->assertSame('active', ConversationSession::findOrFail($sessionId)->status);
        (new ProcessAssistantRun($runId))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $this->assertDatabaseCount('reminders', 0);
        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
        ]);
        $this->assertSame('cancelled', AssistantRun::findOrFail($runId)->status);
        $this->assertSame('active', ConversationSession::findOrFail($sessionId)->status);
    }

    public function test_session_cancel_keeps_a_synchronous_running_session_in_cancelling_state(): void
    {
        $token = $this->apiToken('voice-cancel-synchronous@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        ConversationSession::findOrFail($sessionId)->update(['status' => 'running']);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'cancelling');

        $this->assertDatabaseCount('assistant_runs', 0);
        $this->assertSame('cancelling', ConversationSession::findOrFail($sessionId)->status);
    }

    public function test_cancel_tombstone_catches_a_run_created_after_the_cancel_request(): void
    {
        $token = $this->apiToken('voice-cancel-before-run@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel", [
            'client_request_id' => 'voice-late-arrival',
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.metadata.cancelled_client_request_ids.0', 'voice-late-arrival');

        $lateRunId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-late-arrival',
        )->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.run.metadata.cancelled_before_queue', true)
            ->json('data.run.id');

        Queue::assertNotPushed(ProcessAssistantRun::class);
        (new ProcessAssistantRun($lateRunId))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        $this->assertDatabaseCount('reminders', 0);
        $this->assertSame('cancelled', AssistantRun::findOrFail($lateRunId)->status);
    }

    public function test_cancel_tombstone_also_blocks_queueing_an_existing_late_message(): void
    {
        $token = $this->apiToken('voice-cancel-existing-message@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel", [
            'client_request_id' => 'voice-existing-late',
        ])->assertAccepted();
        $session = ConversationSession::findOrFail($sessionId);
        $message = ConversationMessage::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'metadata' => $this->voiceMetadata('voice-existing-late'),
        ]);

        $queued = app(AssistantRunService::class)->queueExistingMessage(
            $session,
            $message,
            $this->voiceMetadata('voice-existing-late'),
            'web_routed_chat',
        );

        $this->assertSame('cancelled', $queued['run']->status);
        $this->assertTrue((bool) data_get($queued['run']->metadata, 'cancelled_before_queue'));
        Queue::assertNotPushed(ProcessAssistantRun::class);
    }

    public function test_cancel_tombstone_blocks_a_late_superseding_run_too(): void
    {
        $token = $this->apiToken('voice-cancel-superseding@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-before-stop',
        )->assertAccepted()->json('data');
        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel", [
            'client_request_id' => 'voice-before-stop',
        ])->assertAccepted();
        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel", [
            'client_request_id' => 'voice-corrected-but-stopped',
        ])->assertAccepted();

        $successor = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-corrected-but-stopped',
            'voice-before-stop',
        )->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.run.metadata.cancelled_before_queue', true)
            ->json('data');

        $this->assertSame('cancelled', AssistantRun::findOrFail($first['run']['id'])->status);
        $this->assertSame('cancelled', AssistantRun::findOrFail($successor['run']['id'])->status);
        Queue::assertPushed(ProcessAssistantRun::class, 1);
        $this->assertDatabaseCount('reminders', 0);
    }

    public function test_simple_voice_request_is_queued_so_every_correctable_turn_has_a_run_token(): void
    {
        Http::fake();
        $token = $this->apiToken('voice-simple-run-token@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->queueVoiceRun($token, $sessionId, 'Tell me a short joke.', 'voice-simple')
            ->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.metadata.client_request_id', 'voice-simple')
            ->assertJsonPath('data.intent.lane', 'simple_conversation');

        Http::assertNothingSent();
        Queue::assertPushed(ProcessAssistantRun::class);
    }

    public function test_initial_client_request_retry_rechecks_idempotency_under_the_session_lock(): void
    {
        $token = $this->apiToken('voice-initial-idempotency@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);
        $metadata = $this->voiceMetadata('voice-one-logical-request');
        $runs = app(AssistantRunService::class);

        $first = $runs->queueRun($session, 'Create a reminder to call Mom tomorrow at 5:00 PM.', $metadata, 'web_routed_chat');
        $retry = $runs->queueRun($session->fresh(), 'Create a reminder to call Mom tomorrow at 5:00 PM.', $metadata, 'web_routed_chat');

        $this->assertSame($first['run']->id, $retry['run']->id);
        $this->assertSame($first['user_message']->id, $retry['user_message']->id);
        $this->assertTrue($retry['existing']);
        $this->assertDatabaseCount('assistant_runs', 1);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_run_specific_cancel_does_not_cancel_another_run_or_rewrite_completed_redelivery(): void
    {
        Http::fake([
            '*' => Http::response([
                'id' => 'run-b-response',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Here is the answer from run B.'],
                ]],
            ], 200),
        ]);
        $token = $this->apiToken('voice-independent-runs@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runA = $this->queueVoiceRun($token, $sessionId, 'Tell me one fact.', 'voice-run-a')->assertAccepted()->json('data.run.id');
        $runB = $this->queueVoiceRun($token, $sessionId, 'Tell me another fact.', 'voice-run-b')->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $runs->cancelRun(AssistantRun::findOrFail($runA));
        ConversationSession::findOrFail($sessionId)->update(['status' => 'cancelling']);

        (new ProcessAssistantRun($runB))->handle(app(HermesRuntimeService::class), $runs);

        $completed = AssistantRun::findOrFail($runB);
        $this->assertSame('cancelled', AssistantRun::findOrFail($runA)->status);
        $this->assertSame('completed', $completed->status);
        $this->assertNotNull($completed->assistant_message_id);
        $this->assertSame($runB, data_get($completed->assistantMessage?->metadata, 'assistant_run_id'));
        $this->assertSame(1, MemoryEvent::where('conversation_session_id', $sessionId)->count());
        $assistantMessageId = $completed->assistant_message_id;
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');
        ConversationSession::findOrFail($sessionId)->update(['status' => 'cancelling']);

        (new ProcessAssistantRun($runB))->handle($runtime, $runs);

        $completed->refresh();
        $this->assertSame('completed', $completed->status);
        $this->assertSame($assistantMessageId, $completed->assistant_message_id);
        $this->assertDatabaseHas('conversation_messages', ['id' => $assistantMessageId]);
        $this->assertSame(1, MemoryEvent::where('conversation_session_id', $sessionId)->count());
    }

    public function test_correction_becomes_a_fresh_run_when_the_predecessor_never_reached_the_server(): void
    {
        $token = $this->apiToken('voice-missing-predecessor@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $successor = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-after-network-failure',
            'voice-never-accepted',
        )->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.metadata.supersession_predecessor_missing', true)
            ->assertJsonPath('data.user_message.content', 'Create a reminder to call Mom tomorrow at 6:00 PM.')
            ->json('data');

        $this->assertDatabaseCount('assistant_runs', 1);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());

        $lateOriginal = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-never-accepted',
        )->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.run.metadata.cancelled_before_queue', true)
            ->assertJsonPath('data.run.metadata.late_superseded_request_coalesced', true)
            ->assertJsonPath('data.run.user_message_id', $successor['user_message']['id'])
            ->assertJsonPath('data.user_message.id', $successor['user_message']['id'])
            ->assertJsonPath('data.user_message.content', 'Create a reminder to call Mom tomorrow at 6:00 PM.')
            ->json('data.run');
        $this->assertSame('cancelled', $lateOriginal['status']);
        $this->assertSame('Create a reminder to call Mom tomorrow at 5:00 PM.', $lateOriginal['input']);
        $this->assertSame(
            ['Create a reminder to call Mom tomorrow at 6:00 PM.'],
            ConversationMessage::where('conversation_session_id', $sessionId)
                ->where('role', 'user')
                ->orderBy('id')
                ->pluck('content')
                ->all(),
        );
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->pluck('user_message_id')->unique()->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_latest_correction_wins_when_multiple_corrections_target_a_missing_original(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'unexpected-model-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected fallback.'],
                ]],
            ], 200),
        ]);
        $token = $this->apiToken('voice-multiple-missing-corrections@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $firstCorrection = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-missing-correction-b',
            'voice-missing-original-a',
        )->assertAccepted()->json('data');
        $latestCorrection = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 7:00 PM.',
            'voice-missing-correction-c',
            'voice-missing-original-a',
        )->assertAccepted()
            ->assertJsonPath('data.user_message.id', $firstCorrection['user_message']['id'])
            ->assertJsonPath('data.run.metadata.supersedes_run_id', $firstCorrection['run']['id'])
            ->assertJsonPath('data.run.metadata.supersession_predecessor_missing', true)
            ->json('data');

        $this->assertSame('cancelled', AssistantRun::findOrFail($firstCorrection['run']['id'])->status);
        $this->assertSame('queued', AssistantRun::findOrFail($latestCorrection['run']['id'])->status);
        $this->assertSame(
            ['Create a reminder to call Mom tomorrow at 7:00 PM.'],
            ConversationMessage::where('conversation_session_id', $sessionId)
                ->where('role', 'user')
                ->orderBy('id')
                ->pluck('content')
                ->all(),
        );

        $lateOriginal = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-missing-original-a',
        )->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.run.metadata.coalesced_into_run_id', $latestCorrection['run']['id'])
            ->assertJsonPath('data.user_message.id', $latestCorrection['user_message']['id'])
            ->assertJsonPath('data.user_message.content', 'Create a reminder to call Mom tomorrow at 7:00 PM.')
            ->json('data');

        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->pluck('user_message_id')->unique()->count());
        Queue::assertPushed(ProcessAssistantRun::class, 2);

        $runs = app(AssistantRunService::class);
        (new ProcessAssistantRun($firstCorrection['run']['id']))->handle(app(HermesRuntimeService::class), $runs);
        (new ProcessAssistantRun($lateOriginal['run']['id']))->handle(app(HermesRuntimeService::class), $runs);
        (new ProcessAssistantRun($latestCorrection['run']['id']))->handle(app(HermesRuntimeService::class), $runs);

        $reminder = Reminder::where('conversation_session_id', $sessionId)->sole();
        $this->assertSame('19:00', $reminder->remind_at->setTimezone('America/New_York')->format('H:i'));
        $this->assertDatabaseCount('reminders', 1);
        $this->assertSame('cancelled', AssistantRun::findOrFail($firstCorrection['run']['id'])->status);
        $this->assertSame('cancelled', AssistantRun::findOrFail($lateOriginal['run']['id'])->status);
        $this->assertSame('completed', AssistantRun::findOrFail($latestCorrection['run']['id'])->status);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(
            ['Create a reminder to call Mom tomorrow at 7:00 PM.'],
            ConversationMessage::where('conversation_session_id', $sessionId)
                ->where('role', 'user')
                ->orderBy('id')
                ->pluck('content')
                ->all(),
        );
    }

    public function test_corrected_voice_run_reuses_one_user_message_and_only_executes_the_successor(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'unexpected-model-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Unexpected fallback.'],
                ]],
            ], 200),
        ]);
        $token = $this->apiToken('voice-correction@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-five',
        )->assertAccepted()->json('data');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel")
            ->assertAccepted();

        $second = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-six',
            'voice-five',
        )->assertAccepted()
            ->assertJsonPath('data.user_message.id', $first['user_message']['id'])
            ->assertJsonPath('data.user_message.content', 'Create a reminder to call Mom tomorrow at 6:00 PM.')
            ->assertJsonPath('data.run.metadata.supersedes_run_id', $first['run']['id'])
            ->json('data');

        $this->assertSame('cancelled', AssistantRun::findOrFail($first['run']['id'])->status);
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-six',
            'voice-five',
        )->assertAccepted()
            ->assertJsonPath('data.run.id', $second['run']['id']);
        $this->assertSame(2, AssistantRun::where('conversation_session_id', $sessionId)->count());
        (new ProcessAssistantRun($first['run']['id']))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        (new ProcessAssistantRun($second['run']['id']))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $reminder = Reminder::where('conversation_session_id', $sessionId)->sole();
        $this->assertSame('18:00', $reminder->remind_at->setTimezone('America/New_York')->format('H:i'));
        $this->assertDatabaseCount('reminders', 1);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame('completed', AssistantRun::findOrFail($second['run']['id'])->status);
    }

    public function test_an_already_superseded_predecessor_cannot_spawn_a_second_successor(): void
    {
        $token = $this->apiToken('voice-repeat-supersession@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-repeat-five',
        )->assertAccepted()->json('data');
        $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-repeat-six',
            'voice-repeat-five',
        )->assertAccepted();

        $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 7:00 PM.',
            'voice-repeat-seven',
            'voice-repeat-five',
        )->assertStatus(409)
            ->assertJsonPath('code', 'assistant_run_supersession_conflict');

        $this->assertSame(2, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            ConversationMessage::findOrFail($first['user_message']['id'])->content,
        );
    }

    public function test_running_predecessor_keeps_its_original_snapshot_and_its_own_cancellation_token(): void
    {
        $token = $this->apiToken('voice-running-race@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-running-five',
        )->assertAccepted()->json('data');
        $runs = app(AssistantRunService::class);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->withArgs(function (ConversationSession $session, ConversationMessage $message) use ($first): bool {
                $this->assertSame('Create a reminder to call Mom tomorrow at 5:00 PM.', $message->content);
                $this->assertSame($first['run']['id'], data_get($message->metadata, 'assistant_run_id'));

                return true;
            })
            ->andReturnUsing(function (ConversationSession $session, ConversationMessage $message) use ($runs): array {
                app(HermesRuntimeService::class)->cancelSession($session);
                $runs->queueSupersedingRun(
                    $session,
                    'voice-running-five',
                    'Create a reminder to call Mom tomorrow at 6:00 PM.',
                    $this->voiceMetadata('voice-running-six', 'voice-running-five'),
                    'web_routed_chat',
                );
                $this->assertSame('Create a reminder to call Mom tomorrow at 6:00 PM.', $message->fresh()->content);
                $this->assertSame('Create a reminder to call Mom tomorrow at 5:00 PM.', $message->content);

                return [
                    'status' => 'cancelled',
                    'session' => $session,
                    'user_message' => $message,
                    'assistant_message' => null,
                    'events' => collect(),
                    'blocker' => null,
                ];
            });

        (new ProcessAssistantRun($first['run']['id']))->handle($runtime, $runs);

        $successor = AssistantRun::where('metadata->client_request_id', 'voice-running-six')->sole();
        $this->assertSame('cancelled', AssistantRun::findOrFail($first['run']['id'])->status);
        $this->assertSame('queued', $successor->status);
        $this->assertSame($first['user_message']['id'], $successor->user_message_id);
        $this->assertDatabaseCount('reminders', 0);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
    }

    public function test_cancel_wins_completion_race_without_leaking_assistant_or_memory_candidate(): void
    {
        $token = $this->apiToken('voice-memory-race@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            'voice-memory-race',
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session, ConversationMessage $message) use ($runId, $runs): array {
                $this->assertTrue((bool) data_get($message->metadata, 'defer_memory_candidate'));
                $assistant = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'This response must be retracted.',
                ]);
                if (! data_get($message->metadata, 'defer_memory_candidate', false)) {
                    app(BeanMemoryService::class)->recordTurnCandidate($session, $message, $assistant);
                }
                $runs->cancelRun(AssistantRun::findOrFail($runId));

                return [
                    'status' => 'completed',
                    'session' => $session,
                    'user_message' => $message,
                    'assistant_message' => $assistant,
                    'events' => collect(),
                    'blocker' => null,
                ];
            });

        (new ProcessAssistantRun($runId))->handle($runtime, $runs);

        $this->assertSame('cancelled', AssistantRun::findOrFail($runId)->status);
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(0, MemoryEvent::where('conversation_session_id', $sessionId)->count());
    }

    public function test_exception_path_cleans_a_run_tagged_assistant_persisted_after_cancel_won(): void
    {
        $token = $this->apiToken('voice-cancel-exception-race@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            'voice-cancel-exception-race',
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session, ConversationMessage $message) use ($runId, $runs): never {
                // Model the losing catch path: cancellation cleaned up before the
                // runtime's final assistant became visible, then the runtime threw.
                $runs->cancelRun(AssistantRun::findOrFail($runId));
                $assistant = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'This late response must be retracted.',
                    'metadata' => ['assistant_run_id' => $runId, 'runtime' => 'fast_no_tools'],
                ]);
                app(BeanMemoryService::class)->recordTurnCandidate($session, $message, $assistant);

                throw new \RuntimeException('Runtime failed after assistant persistence.');
            });

        (new ProcessAssistantRun($runId))->handle($runtime, $runs);

        $this->assertSame('cancelled', AssistantRun::findOrFail($runId)->status);
        $this->assertSame('active', ConversationSession::findOrFail($sessionId)->status);
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertSame(0, MemoryEvent::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'runtime.run_failed')->count());
    }

    public function test_exception_path_preserves_an_assistant_already_owned_by_reconciled_completion(): void
    {
        $token = $this->apiToken('voice-reconcile-exception-race@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            'voice-reconcile-exception-race',
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session) use ($runId, $runs): never {
                $assistant = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'This committed response belongs to the completed run.',
                    'metadata' => ['assistant_run_id' => $runId, 'runtime' => 'fast_no_tools'],
                ]);
                $reconciled = $runs->prepareRunForBackgroundResponse(AssistantRun::findOrFail($runId));
                $this->assertSame('completed', $reconciled->status);
                $this->assertSame($assistant->id, $reconciled->assistant_message_id);

                throw new \RuntimeException('Worker failed after completion was reconciled.');
            });

        (new ProcessAssistantRun($runId))->handle($runtime, $runs);

        $run = AssistantRun::findOrFail($runId);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->assistant_message_id);
        $this->assertDatabaseHas('conversation_messages', ['id' => $run->assistant_message_id]);
        $this->assertSame(1, MemoryEvent::where('assistant_message_id', $run->assistant_message_id)->count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'runtime.run_failed')->count());
    }

    public function test_exception_after_assistant_commit_is_reconciled_from_failed_run(): void
    {
        $token = $this->apiToken('voice-failed-orphan-recovery@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            'voice-failed-orphan-recovery',
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $assistantId = null;
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session) use ($runId, &$assistantId): never {
                $assistantId = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'Committed immediately before the worker failed.',
                    'metadata' => ['assistant_run_id' => $runId, 'runtime' => 'fast_no_tools'],
                ])->id;

                throw new \RuntimeException('Worker failed after assistant commit.');
            });

        (new ProcessAssistantRun($runId))->handle($runtime, $runs);

        $failed = AssistantRun::findOrFail($runId);
        $this->assertSame('failed', $failed->status);
        $this->assertNull($failed->assistant_message_id);
        $this->assertDatabaseHas('conversation_messages', ['id' => $assistantId]);

        // A duplicate framework failure callback must not destroy the recoverable
        // committed assistant before response polling can reconcile it.
        (new ProcessAssistantRun($runId))->failed(new \RuntimeException('Late duplicate failure callback.'));
        $this->assertDatabaseHas('conversation_messages', ['id' => $assistantId]);

        $reconciled = $runs->prepareRunForBackgroundResponse($failed);

        $this->assertSame('completed', $reconciled->status);
        $this->assertSame($assistantId, $reconciled->assistant_message_id);
        $this->assertDatabaseHas('conversation_messages', ['id' => $assistantId]);
        $this->assertSame(1, MemoryEvent::where('assistant_message_id', $assistantId)->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_failed')->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_orphan_assistant_reconciled')->count());
    }

    public function test_scoped_stop_makes_a_plain_failed_run_terminal_before_background_recovery(): void
    {
        $clientRequestId = 'voice-plain-failed-stop';
        $token = $this->apiToken('voice-plain-failed-stop@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            $clientRequestId,
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andThrow(new \RuntimeException('Runtime failed before creating an assistant.'));

        (new ProcessAssistantRun($runId))->handle($runtime, $runs);

        $this->assertSame('failed', AssistantRun::findOrFail($runId)->status);
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel", [
            'client_request_id' => $clientRequestId,
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'active');

        $prepared = $runs->prepareRunForBackgroundResponse(AssistantRun::findOrFail($runId));
        $this->assertSame('cancelled', $prepared->status);
        $this->assertNotNull($prepared->cancelled_at);
        $this->assertNull($prepared->assistant_message_id);
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_explicit_cancel_deletes_a_failed_unlinked_assistant_before_recovery(): void
    {
        $token = $this->apiToken('voice-tagged-failed-stop@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            'voice-tagged-failed-stop',
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $assistantId = null;
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session) use ($runId, &$assistantId): never {
                $assistantId = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'This ambiguous response must be deleted by Stop.',
                    'metadata' => ['assistant_run_id' => $runId, 'runtime' => 'fast_no_tools'],
                ])->id;

                throw new \RuntimeException('Runtime failed after assistant commit.');
            });

        (new ProcessAssistantRun($runId))->handle($runtime, $runs);
        $this->assertSame('failed', AssistantRun::findOrFail($runId)->status);
        $this->assertDatabaseHas('conversation_messages', ['id' => $assistantId]);

        $cancelled = $runs->cancelRun(AssistantRun::findOrFail($runId));
        $prepared = $runs->prepareRunForBackgroundResponse($cancelled);

        $this->assertSame('cancelled', $prepared->status);
        $this->assertNull($prepared->assistant_message_id);
        $this->assertDatabaseMissing('conversation_messages', ['id' => $assistantId]);
        $this->assertSame(0, MemoryEvent::where('assistant_message_id', $assistantId)->count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'runtime.run_orphan_assistant_reconciled')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_background_response_preparation_never_resurrects_a_cancelled_stale_run(): void
    {
        $token = $this->apiToken('voice-cancelled-recovery@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-cancelled-recovery',
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $runs->cancelRun(AssistantRun::findOrFail($runId));
        AssistantRun::whereKey($runId)->update([
            'created_at' => now()->subHour(),
            'started_at' => now()->subHour(),
        ]);
        config()->set('services.hermes_runtime.assistant_run_stale_seconds', 1);

        $prepared = $runs->prepareRunForBackgroundResponse(AssistantRun::findOrFail($runId));

        $this->assertSame('cancelled', $prepared->status);
        $this->assertNotNull($prepared->cancelled_at);
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_committed_orphan_assistant_is_reconciled_once_and_cancelled_orphan_is_deleted(): void
    {
        $token = $this->apiToken('voice-orphan-reconcile@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            'voice-orphan-completed',
        )->assertAccepted()->json('data.run.id');
        AssistantRun::whereKey($runId)->update(['status' => 'running', 'started_at' => now()]);
        $session = ConversationSession::findOrFail($sessionId);
        $orphan = ConversationMessage::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'Committed before the worker died.',
            'metadata' => ['assistant_run_id' => $runId, 'runtime' => 'fast_no_tools'],
        ]);
        $runs = app(AssistantRunService::class);

        $reconciled = $runs->prepareRunForBackgroundResponse(AssistantRun::findOrFail($runId));
        $again = $runs->prepareRunForBackgroundResponse($reconciled);

        $this->assertSame('completed', $reconciled->status);
        $this->assertSame($orphan->id, $reconciled->assistant_message_id);
        $this->assertSame($orphan->id, $again->assistant_message_id);
        $this->assertSame(1, MemoryEvent::where('assistant_message_id', $orphan->id)->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_orphan_assistant_reconciled')->count());

        $cancelledRunId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me another short fact.',
            'voice-orphan-cancelled',
        )->assertAccepted()->json('data.run.id');
        AssistantRun::whereKey($cancelledRunId)->update(['status' => 'running', 'started_at' => now()]);
        $cancelledOrphan = ConversationMessage::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'This orphan must be deleted.',
            'metadata' => ['assistant_run_id' => $cancelledRunId, 'runtime' => 'fast_no_tools'],
        ]);

        $runs->cancelRun(AssistantRun::findOrFail($cancelledRunId));

        $this->assertDatabaseMissing('conversation_messages', ['id' => $cancelledOrphan->id]);
        $this->assertSame('cancelled', AssistantRun::findOrFail($cancelledRunId)->status);
    }

    public function test_process_completion_preserves_an_assistant_already_linked_by_reconciliation(): void
    {
        $token = $this->apiToken('voice-live-reconcile-race@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Tell me one short fact.',
            'voice-live-reconcile-race',
        )->assertAccepted()->json('data.run.id');
        $runs = app(AssistantRunService::class);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session, ConversationMessage $message) use ($runId, $runs): array {
                $assistant = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'Committed while the worker was still alive.',
                    'metadata' => ['assistant_run_id' => $runId, 'runtime' => 'fast_no_tools'],
                ]);

                $reconciled = $runs->prepareRunForBackgroundResponse(AssistantRun::findOrFail($runId));
                $this->assertSame('completed', $reconciled->status);
                $this->assertSame($assistant->id, $reconciled->assistant_message_id);

                return [
                    'status' => 'completed',
                    'session' => $session,
                    'user_message' => $message,
                    'assistant_message' => $assistant,
                    'events' => collect(),
                    'blocker' => null,
                ];
            });

        (new ProcessAssistantRun($runId))->handle($runtime, $runs);

        $run = AssistantRun::findOrFail($runId);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->assistant_message_id);
        $this->assertDatabaseHas('conversation_messages', ['id' => $run->assistant_message_id]);
        $this->assertSame(1, MemoryEvent::where('assistant_message_id', $run->assistant_message_id)->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_orphan_assistant_reconciled')->count());
    }

    public function test_failed_bridge_completion_cannot_overwrite_a_correction_that_won_the_lock(): void
    {
        $token = $this->apiToken('voice-failed-bridge-race@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-failed-bridge-five',
        )->assertAccepted()->json('data');
        AssistantRun::whereKey($first['run']['id'])->update([
            'status' => 'failed',
            'error' => 'Simulated failed response.',
            'completed_at' => now(),
        ]);
        $staleFailedRun = AssistantRun::findOrFail($first['run']['id']);
        $successor = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-failed-bridge-six',
            'voice-failed-bridge-five',
        )->assertAccepted()->json('data');
        $service = app(AssistantRunService::class);
        $bridge = new \ReflectionMethod($service, 'completeFailedRunWithBridgeMessage');

        /** @var AssistantRun $afterBridge */
        $afterBridge = $bridge->invoke($service, $staleFailedRun);

        $this->assertSame('cancelled', $afterBridge->status);
        $this->assertNull($afterBridge->assistant_message_id);
        $this->assertSame('queued', AssistantRun::findOrFail($successor['run']['id'])->status);
        $this->assertSame('queued', ConversationSession::findOrFail($sessionId)->status);
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
    }

    public function test_failed_action_rolls_back_partial_mutation_before_recording_failure_witness(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', false);
        $token = $this->apiToken('voice-atomic-action@example.com');
        $user = User::where('email', 'voice-atomic-action@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Roll bins out',
            'status' => 'pending',
        ]);
        $sync = Mockery::mock(WorkspaceItemSyncService::class);
        $sync->shouldReceive('propagateStatusUpdate')
            ->once()
            ->andThrow(new \RuntimeException('Simulated propagation failure.'));
        $this->app->instance(WorkspaceItemSyncService::class, $sync);
        Http::fakeSequence()
            ->push([
                'id' => 'atomic-update-tool-call',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'update-task-call',
                            'type' => 'function',
                            'function' => [
                                'name' => 'update_task',
                                'arguments' => json_encode([
                                    'id' => $task->id,
                                    'status' => 'completed',
                                ], JSON_THROW_ON_ERROR),
                            ],
                        ]],
                    ],
                ]],
            ], 200)
            ->push([
                'id' => 'atomic-update-final',
                'model' => 'gpt-test-tools',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I could not update that task.',
                    ],
                ]],
            ], 200);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Mark Roll bins out complete.',
        ])->assertCreated();

        $task->refresh();
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->completed_at);
        $this->assertDatabaseMissing('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.task.updated',
            'status' => 'succeeded',
        ]);
        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.action.failed',
            'status' => 'failed',
        ]);
    }

    public function test_action_transaction_rechecks_run_cancellation_before_mutating(): void
    {
        $token = $this->apiToken('voice-action-cancel-window@example.com');
        $user = User::where('email', 'voice-action-cancel-window@example.com')->firstOrFail();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user);
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $user->id,
            'title' => 'Do not complete',
            'status' => 'pending',
        ]);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $runId = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Complete the Do not complete task.',
            'voice-action-cancel-window',
        )->assertAccepted()->json('data.run.id');
        AssistantRun::whereKey($runId)->update(['status' => 'running', 'started_at' => now()]);
        $runs = app(AssistantRunService::class);
        $runs->cancelRun(AssistantRun::findOrFail($runId));
        $runtime = app(HermesRuntimeService::class);
        $atomicAction = new \ReflectionMethod($runtime, 'executeActionEnvelopeAtomically');
        $exception = null;

        try {
            $atomicAction->invoke($runtime, ConversationSession::findOrFail($sessionId), [
                'type' => 'task.update',
                'risk' => 'low',
                'parameters' => ['id' => $task->id, 'status' => 'completed'],
            ], null, $runId);
        } catch (\Throwable $caught) {
            $exception = $caught;
        }

        $this->assertInstanceOf(\RuntimeException::class, $exception);
        $this->assertStringContainsString('cancelled before action execution', $exception->getMessage());
        $task->refresh();
        $this->assertSame('pending', $task->status);
        $this->assertNull($task->completed_at);
    }

    public function test_run_scoped_token_stops_old_tool_execution_after_successor_resets_session_status(): void
    {
        config()->set('services.hermes_runtime.crud_planner_enabled', false);
        $token = $this->apiToken('voice-run-token-race@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-token-five',
        )->assertAccepted()->json('data');
        $runs = app(AssistantRunService::class);
        Http::fake([
            '*' => function () use ($runs, $sessionId) {
                $runs->queueSupersedingRun(
                    ConversationSession::findOrFail($sessionId),
                    'voice-token-five',
                    'Create a reminder to call Mom tomorrow at 6:00 PM.',
                    $this->voiceMetadata('voice-token-six', 'voice-token-five'),
                    'web_routed_chat',
                );

                return Http::response([
                    'id' => 'old-run-tool-call',
                    'model' => 'gpt-test-tools',
                    'choices' => [[
                        'finish_reason' => 'tool_calls',
                        'message' => [
                            'role' => 'assistant',
                            'content' => null,
                            'tool_calls' => [[
                                'id' => 'old-reminder-call',
                                'type' => 'function',
                                'function' => [
                                    'name' => 'create_reminder',
                                    'arguments' => json_encode([
                                        'title' => 'Call Mom',
                                        'remind_at' => '2026-07-11T17:00:00-04:00',
                                    ], JSON_THROW_ON_ERROR),
                                ],
                            ]],
                        ],
                    ]],
                ], 200);
            },
        ]);

        (new ProcessAssistantRun($first['run']['id']))->handle(
            app(HermesRuntimeService::class),
            $runs,
        );

        $this->assertDatabaseCount('reminders', 0);
        $this->assertSame('cancelled', AssistantRun::findOrFail($first['run']['id'])->status);
        $this->assertSame('queued', AssistantRun::where('metadata->client_request_id', 'voice-token-six')->sole()->status);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
    }

    public function test_server_rejects_a_correction_after_the_predecessor_committed_mutating_work(): void
    {
        $token = $this->apiToken('voice-committed-conflict@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-committed-five',
        )->assertAccepted()->json('data');
        ActivityEvent::create([
            'user_id' => $first['run']['user_id'],
            'workspace_id' => $first['run']['workspace_id'],
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.reminder.created',
            'tool_name' => 'reminders.create',
            'status' => 'succeeded',
            'payload' => [
                'work_item_id' => 'crud-plan-'.$first['user_message']['id'].'-0',
                'message_id' => $first['user_message']['id'],
            ],
        ]);

        $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-committed-six',
            'voice-committed-five',
        )->assertStatus(409)
            ->assertJsonPath('code', 'assistant_run_supersession_conflict');

        $this->assertSame(1, AssistantRun::where('conversation_session_id', $sessionId)->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame('Create a reminder to call Mom tomorrow at 5:00 PM.', ConversationMessage::findOrFail($first['user_message']['id'])->content);
    }

    public function test_successor_worker_refuses_a_second_mutation_if_the_predecessor_commits_during_the_race(): void
    {
        $token = $this->apiToken('voice-late-commit@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-late-five',
        )->assertAccepted()->json('data');
        $second = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 6:00 PM.',
            'voice-late-six',
            'voice-late-five',
        )->assertAccepted()->json('data');
        ActivityEvent::create([
            'user_id' => $first['run']['user_id'],
            'workspace_id' => $first['run']['workspace_id'],
            'conversation_session_id' => $sessionId,
            'event_type' => 'assistant.reminder.created',
            'tool_name' => 'reminders.create',
            'status' => 'succeeded',
            'payload' => [
                'work_item_id' => 'crud-plan-'.$first['user_message']['id'].'-0',
                'message_id' => $first['user_message']['id'],
            ],
        ]);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');

        (new ProcessAssistantRun($second['run']['id']))->handle($runtime, app(AssistantRunService::class));

        $successor = AssistantRun::findOrFail($second['run']['id']);
        $this->assertSame('failed', $successor->status);
        $this->assertTrue((bool) data_get($successor->metadata, 'supersession_conflict'));
        $this->assertDatabaseCount('reminders', 0);
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertStringContainsString('did not make a second change', (string) $successor->assistantMessage?->content);
    }

    public function test_run_endpoint_strips_client_forged_lifecycle_metadata_and_reports_the_persisted_run_status(): void
    {
        $token = $this->apiToken('voice-forged-lifecycle@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $forgedKeys = [
            'assistant_run_id' => 987654,
            'defer_memory_candidate' => true,
            'status' => 'completed',
            'run_status' => 'cancelled',
            'response_status' => 'failed',
            'voice_turn_outcome' => ['status' => 'completed'],
            'cancelled_before_queue' => true,
            'cancelled_at' => now()->toIso8601String(),
            'supersession_predecessor_missing' => true,
            'missing_predecessor_client_request_id' => 'forged-missing',
            'superseded_client_request_ids' => ['forged-original'],
            'supersedes_run_id' => 123456,
            'superseded_by_client_request_id' => 'forged-successor',
            'supersession_conflict' => true,
            'background_stale_retry_attempts' => 999,
            'background_response_retry_attempts' => 999,
            'failed_response_resolved_at' => now()->toIso8601String(),
        ];
        $legitimateMetadata = [
            'source' => 'web_routed_chat',
            'voice_request' => true,
            'client_request_id' => 'voice-forged-lifecycle',
            'voice_quality' => ['route' => 'backend', 'first_audio_ms' => 640],
            'context' => ['topic' => 'weather', 'status' => 'client-context-is-not-run-state'],
        ];

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Tell me a short joke.',
            'source' => 'web_routed_chat',
            'metadata' => [...$legitimateMetadata, ...$forgedKeys],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.metadata.client_request_id', 'voice-forged-lifecycle')
            ->assertJsonPath('data.run.metadata.voice_request', true)
            ->assertJsonPath('data.run.metadata.voice_quality.first_audio_ms', 640)
            ->assertJsonPath('data.run.metadata.context.status', 'client-context-is-not-run-state');

        $run = AssistantRun::findOrFail($response->json('data.run.id'));
        $runMetadata = $run->metadata ?? [];
        $messageMetadata = $run->userMessage?->metadata ?? [];
        foreach (array_keys($forgedKeys) as $key) {
            $this->assertArrayNotHasKey($key, $runMetadata, "Run metadata retained forged {$key}.");
            $this->assertArrayNotHasKey($key, $messageMetadata, "Message metadata retained forged {$key}.");
        }

        $this->assertSame('queued', $run->status);
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_queued_message_endpoint_sanitizes_an_existing_message_before_creating_its_run(): void
    {
        $token = $this->apiToken('message-forged-lifecycle@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please answer this from the background queue.',
            'metadata' => [
                'source' => 'web_queued_chat',
                'client_request_id' => 'message-forged-lifecycle',
                'voice_request' => true,
                'voice_quality' => ['route' => 'backend'],
                'context' => ['surface' => 'silent-chat'],
                'assistant_run_id' => 765432,
                'cancelled_before_queue' => true,
                'supersedes_run_id' => 654321,
                'background_response_retry_attempts' => 99,
                'voice_turn_outcome' => ['status' => 'completed'],
                'status' => 'completed',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.metadata.client_request_id', 'message-forged-lifecycle')
            ->assertJsonPath('data.run.metadata.voice_quality.route', 'backend')
            ->assertJsonPath('data.run.metadata.context.surface', 'silent-chat');

        $run = AssistantRun::findOrFail($response->json('data.run.id'));
        foreach (['assistant_run_id', 'cancelled_before_queue', 'supersedes_run_id', 'background_response_retry_attempts', 'voice_turn_outcome', 'status'] as $key) {
            $this->assertArrayNotHasKey($key, $run->metadata ?? []);
            $this->assertArrayNotHasKey($key, $run->userMessage?->metadata ?? []);
        }
    }

    public function test_queued_branch_endpoint_preserves_server_branch_context_but_not_forged_run_state(): void
    {
        $token = $this->apiToken('branch-forged-lifecycle@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);
        $original = ConversationMessage::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => 'Original text.',
        ]);

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages/{$original->id}/branch", [
            'content' => 'Corrected text.',
            'metadata' => [
                'source' => 'web_queued_chat',
                'client_request_id' => 'branch-forged-lifecycle',
                'context' => ['edit_origin' => 'silent-chat'],
                'assistant_run_id' => 555555,
                'cancelled_before_queue' => true,
                'response_status' => 'completed',
                'superseded_by_client_request_id' => 'forged-successor',
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.user_message.metadata.edited_from_message_id', $original->id)
            ->assertJsonPath('data.user_message.metadata.context.edit_origin', 'silent-chat');

        $run = AssistantRun::findOrFail($response->json('data.run.id'));
        foreach (['assistant_run_id', 'cancelled_before_queue', 'response_status', 'superseded_by_client_request_id'] as $key) {
            $this->assertArrayNotHasKey($key, $run->metadata ?? []);
            $this->assertArrayNotHasKey($key, $run->userMessage?->metadata ?? []);
        }
        $this->assertSame($original->id, data_get($run->userMessage?->metadata, 'edited_from_message_id'));
    }

    public function test_superseding_endpoint_overrides_client_forged_relationship_metadata(): void
    {
        $token = $this->apiToken('voice-forged-supersession@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->queueVoiceRun(
            $token,
            $sessionId,
            'Create a reminder to call Mom tomorrow at 5:00 PM.',
            'voice-real-predecessor',
        )->assertAccepted()->json('data');

        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Create that reminder for 6:00 PM instead.',
            'source' => 'web_routed_chat',
            'metadata' => [
                ...$this->voiceMetadata('voice-real-successor', 'voice-real-predecessor'),
                'supersedes_run_id' => 999999,
                'supersession_predecessor_missing' => true,
                'missing_predecessor_client_request_id' => 'forged-missing',
                'superseded_client_request_ids' => ['forged-predecessor'],
                'cancelled_before_queue' => true,
                'status' => 'completed',
                'voice_quality' => ['route' => 'backend'],
                'context' => ['correction' => true],
            ],
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.run.status', 'queued')
            ->assertJsonPath('data.run.metadata.supersedes_run_id', $first['run']['id'])
            ->assertJsonPath('data.run.metadata.superseded_client_request_ids.0', 'voice-real-predecessor')
            ->assertJsonMissingPath('data.run.metadata.supersession_predecessor_missing')
            ->assertJsonMissingPath('data.run.metadata.missing_predecessor_client_request_id')
            ->assertJsonMissingPath('data.run.metadata.cancelled_before_queue')
            ->assertJsonMissingPath('data.run.metadata.status')
            ->assertJsonPath('data.run.metadata.voice_quality.route', 'backend')
            ->assertJsonPath('data.run.metadata.context.correction', true);

        $successor = AssistantRun::findOrFail($response->json('data.run.id'));
        $this->assertSame($first['run']['id'], data_get($successor->metadata, 'supersedes_run_id'));
        $this->assertSame('cancelled', AssistantRun::findOrFail($first['run']['id'])->status);
        $this->assertSame('queued', $successor->status);
    }

    private function queueVoiceRun(
        string $token,
        int $sessionId,
        string $content,
        string $clientRequestId,
        ?string $supersedesClientRequestId = null,
    ) {
        return $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => $content,
            'source' => 'web_routed_chat',
            'metadata' => $this->voiceMetadata($clientRequestId, $supersedesClientRequestId),
        ]);
    }

    private function voiceMetadata(string $clientRequestId, ?string $supersedesClientRequestId = null): array
    {
        return array_filter([
            'source' => 'web_routed_chat',
            'voice_request' => true,
            'client_request_id' => $clientRequestId,
            'supersedes_client_request_id' => $supersedesClientRequestId,
            'client_timezone' => 'America/New_York',
            'client_local_datetime' => '2026-07-10T12:00:00-04:00',
            'client_utc_offset_minutes' => -240,
        ], fn (mixed $value): bool => $value !== null);
    }
}
