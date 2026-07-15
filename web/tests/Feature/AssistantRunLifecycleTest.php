<?php

namespace Tests\Feature;

use App\Data\AssistantRunExecutionClaim;
use App\Exceptions\AssistantRunConflictException;
use App\Exceptions\VoiceTurnConflictException;
use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\User;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class AssistantRunLifecycleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-07-10 12:00:00', 'America/New_York'));
        config()->set('services.hermes_runtime.assistant_run_stale_recovery_attempts', 1);
        config()->set('services.hermes_runtime.assistant_run_stale_seconds', 2);
        config()->set('services.hermes_runtime.assistant_run_recovery_window_seconds', 900);
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_message_and_run_endpoints_share_one_idempotent_durable_queue_path(): void
    {
        [$token, $session] = $this->conversation('run-endpoints@example.com');
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');
        $this->app->instance(HermesRuntimeService::class, $runtime);

        $first = $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'Interpret this through Hermes.',
            'metadata' => $this->metadata('one-logical-request'),
        ])->assertCreated()->assertJsonPath('data.status', 'queued');
        $runId = (int) $first->json('data.run.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'Interpret this through Hermes.',
            'metadata' => $this->metadata('one-logical-request'),
        ])->assertOk()->assertJsonPath('data.run.id', $runId);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'Interpret a second request through Hermes.',
            'metadata' => $this->metadata('second-logical-request'),
        ])->assertCreated()->assertJsonPath('data.status', 'queued');

        $this->assertDatabaseCount('assistant_runs', 2);
        $this->assertSame(2, ConversationMessage::where('conversation_session_id', $session->id)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $session->id)->where('role', 'assistant')->count());
        Queue::assertPushed(ProcessAssistantRun::class, 2);
    }

    public function test_assistant_admission_requires_a_stable_client_request_id(): void
    {
        [$token, $session] = $this->conversation('run-stable-id@example.com');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'No identity.',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['metadata']);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'No stable identity.',
            'metadata' => ['surface' => 'test'],
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['metadata.client_request_id']);

        $this->assertDatabaseCount('assistant_runs', 0);
        Queue::assertNothingPushed();
    }

    public function test_generic_ingress_cannot_spoof_the_lifecycle_owned_voice_source(): void
    {
        [$token, $session] = $this->conversation('run-source-ownership@example.com');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'Try to spoof a voice run.',
            'source' => 'browser_voice_v2',
            'metadata' => $this->metadata('reserved-source-top-level'),
        ])->assertUnprocessable()->assertJsonValidationErrors(['source']);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'Queue one ordinary generic run.',
            'metadata' => [
                ...$this->metadata('reserved-source-metadata'),
                'source' => 'browser_voice_v2',
            ],
        ])->assertCreated();

        $run = AssistantRun::query()->sole();
        $this->assertNull($run->voice_turn_id);
        $this->assertNull($run->lane);
        $this->assertNull($run->handler);
        $this->assertSame('assistant_run_api', $run->source);
        $this->assertArrayNotHasKey('source', $run->metadata ?? []);

        try {
            app(AssistantRunService::class)->queueRun(
                $session->fresh(),
                'Defensive service boundary.',
                $this->metadata('reserved-source-service'),
                'browser_voice_v2',
            );
            $this->fail('A generic service caller must not use the reserved voice source.');
        } catch (\InvalidArgumentException $exception) {
            $this->assertSame(
                'The Browser Voice source is reserved for lifecycle-owned voice runs.',
                $exception->getMessage(),
            );
        }

        $this->assertDatabaseCount('assistant_runs', 1);
    }

    public function test_reusing_a_stable_id_with_different_input_is_a_conflict(): void
    {
        [$token, $session] = $this->conversation('run-fingerprint@example.com');
        $metadata = $this->metadata('fingerprinted-request');

        $first = $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'Keep this exact request.',
            'metadata' => $metadata,
        ])->assertCreated();
        $runId = (int) $first->json('data.run.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'This is a different request.',
            'metadata' => $metadata,
        ])->assertConflict()
            ->assertJsonPath('message', 'That client_request_id is already bound to a different assistant request.');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/runs", [
            'content' => 'Keep this exact request.',
            'metadata' => $metadata,
        ])->assertOk()->assertJsonPath('data.run.id', $runId);

        $this->assertDatabaseCount('assistant_runs', 1);
        $this->assertSame($runId, AssistantRun::sole()->id);
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_chat_and_voice_cannot_claim_the_same_stable_request_identity(): void
    {
        [, $session] = $this->conversation('run-cross-mode-id@example.com');
        $user = User::findOrFail($session->user_id);
        $runs = app(AssistantRunService::class);
        $voice = app(VoiceTurnLifecycleService::class);

        $runs->queueRun(
            $session,
            'Chat owns this identity.',
            $this->metadata('cross-mode-chat-first'),
            'web_chat',
        );
        try {
            $voice->admit($user, $session->fresh(), [
                'turn_id' => 'cross-mode-chat-first',
                'transcript' => 'Voice must not reuse it.',
                'timezone' => 'America/New_York',
            ]);
            $this->fail('Voice admission should conflict with an existing chat identity.');
        } catch (VoiceTurnConflictException $exception) {
            $this->assertSame('That stable turn ID is already owned by a chat request.', $exception->getMessage());
        }

        $voice->admit($user, $session->fresh(), [
            'turn_id' => 'cross-mode-voice-first',
            'transcript' => 'Voice owns this identity.',
            'timezone' => 'America/New_York',
        ]);
        try {
            $runs->queueRun(
                $session->fresh(),
                'Chat must not reuse it.',
                $this->metadata('cross-mode-voice-first'),
                'web_chat',
            );
            $this->fail('Chat admission should conflict with an existing voice identity.');
        } catch (AssistantRunConflictException $exception) {
            $this->assertSame('That client_request_id is already owned by a voice turn.', $exception->getMessage());
        }

        $this->assertDatabaseCount('assistant_runs', 2);
        $this->assertDatabaseCount('voice_turns', 1);
    }

    public function test_dispatch_failure_creates_one_visible_failed_final_without_reentering_runtime(): void
    {
        [$token, $session] = $this->conversation('run-dispatch-failure@example.com');
        $dispatcher = Mockery::mock(Dispatcher::class);
        $dispatcher->shouldReceive('dispatch')
            ->once()
            ->with(Mockery::type(ProcessAssistantRun::class))
            ->andThrow(new RuntimeException('queue unavailable'));
        $runs = new AssistantRunService($dispatcher);

        $queued = $runs->queueRun(
            $session,
            'Queue this once.',
            $this->metadata('dispatch-failure'),
            'web_chat',
        );
        $run = $queued['run']->fresh(['assistantMessage']);

        $this->assertSame('failed', $run->status);
        $this->assertStringContainsString('queue unavailable', (string) $run->error);
        $this->assertSame(AssistantRunService::SYSTEM_FAILURE_FINAL, $run->assistantMessage?->content);
        $this->assertSame('failed', data_get($run->assistantMessage?->metadata, 'final_status'));
        $this->assertArrayNotHasKey('runtime', $run->assistantMessage?->metadata ?? []);
        $this->assertTrue((bool) data_get($run->result, 'fault_final'));
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_dispatch_failed')->count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'runtime.run_retry_queued')->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_fault_final_created')->count());

        $replayed = $runs->queueRun(
            $session->fresh(),
            'Queue this once.',
            $this->metadata('dispatch-failure'),
            'web_chat',
        );
        $this->assertTrue($replayed['existing']);
        $this->assertSame($run->id, $replayed['run']->id);
        $this->withToken($token)
            ->getJson("/api/assistant/sessions/{$session->id}/runs/lookup?client_request_id=dispatch-failure")
            ->assertOk()
            ->assertJsonPath('data.status', 'failed')
            ->assertJsonPath('data.assistant_message.content', AssistantRunService::SYSTEM_FAILURE_FINAL);
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
    }

    public function test_success_persists_literal_hermes_content_and_links_it_atomically_once(): void
    {
        [, $session] = $this->conversation('run-success@example.com');
        $runs = app(AssistantRunService::class);
        $run = $runs->queueRun(
            $session,
            'Return the literal result.',
            $this->metadata('literal-success'),
            'web_chat',
        )['run'];
        $literal = "  {\"answer\":\"keep@example.com\"}\n";
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')->once()->andReturnUsing(
            fn (ConversationSession $runtimeSession, ConversationMessage $message): array => [
                'status' => 'completed',
                'session' => $runtimeSession,
                'user_message' => $message,
                'assistant_message' => null,
                'assistant_content' => $literal,
                'events' => collect(),
                'blocker' => null,
            ],
        );

        $this->processRun($run, $runtime, $runs);
        $this->processRun($run, $runtime, $runs);
        $completed = $run->fresh(['assistantMessage']);

        $this->assertSame('completed', $completed->status);
        $this->assertSame($literal, $completed->assistantMessage?->content);
        $this->assertSame($completed->assistant_message_id, data_get($completed->result, 'assistant_message_id'));
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
        $this->assertSame(1, MemoryEvent::where('conversation_message_id', $run->user_message_id)->count());
    }

    public function test_blocked_outcome_keeps_exact_subscription_copy_as_one_durable_terminal_final(): void
    {
        [, $session] = $this->conversation('run-blocked@example.com');
        $runs = app(AssistantRunService::class);
        $run = $runs->queueRun(
            $session,
            'Create a recurring task.',
            $this->metadata('blocked-run'),
            'flutter_chat',
        )['run'];
        $copy = 'Daily recurring tasks require a plan that includes recurrence.';
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')->once()->andReturn([
            'status' => 'blocked',
            'session' => $session,
            'user_message' => $run->userMessage,
            'assistant_message' => null,
            'assistant_content' => $copy,
            'events' => collect(),
            'blocker' => null,
        ]);

        $this->processRun($run, $runtime, $runs);
        $this->processRun($run, $runtime, $runs);
        $blocked = $run->fresh(['assistantMessage']);

        $this->assertSame('blocked', $blocked->status);
        $this->assertSame($copy, $blocked->assistantMessage?->content);
        $this->assertSame('blocked', data_get($blocked->assistantMessage?->metadata, 'final_status'));
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
    }

    public function test_no_write_runtime_failure_terminalizes_once_without_a_second_semantic_attempt(): void
    {
        [$token, $session] = $this->conversation('run-no-write-failure@example.com');
        $runs = app(AssistantRunService::class);
        $metadata = $this->metadata('no-write-failure');
        $run = $runs->queueRun($session, 'Try this safely.', $metadata, 'web_chat')['run'];
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')->once()->andReturn([
            'status' => 'failed',
            'session' => $session,
            'user_message' => $run->userMessage,
            'assistant_message' => null,
            'assistant_content' => 'Provider-safe fallback that is not a durable final.',
            'error' => 'provider timed out',
            'events' => collect(),
            'blocker' => null,
        ]);

        $this->processRun($run, $runtime, $runs);
        $this->processRun($run, $runtime, $runs);
        $failed = $run->fresh(['assistantMessage']);
        $this->assertSame('failed', $failed->status);
        $this->assertSame('provider timed out', $failed->error);
        $this->assertSame(AssistantRunService::SYSTEM_FAILURE_FINAL, $failed->assistantMessage?->content);
        $this->assertFalse((bool) data_get($failed->result, 'had_committed_writes'));

        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->withToken($token)->getJson("/api/assistant/sessions/{$session->id}")
                ->assertOk()
                ->assertJsonFragment(['content' => AssistantRunService::SYSTEM_FAILURE_FINAL]);
        }
        $replayed = $runs->queueRun($session->fresh(), 'Try this safely.', $metadata, 'web_chat');
        $this->assertTrue($replayed['existing']);
        $this->assertSame($run->id, $replayed['run']->id);
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_failed')->count());
        $this->assertSame(0, ActivityEvent::where('event_type', 'runtime.run_retry_queued')->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'runtime.run_fault_final_created')->count());
    }

    public function test_committed_write_failure_does_not_retry_or_claim_false_success(): void
    {
        [, $session] = $this->conversation('run-committed-failure@example.com');
        $runs = app(AssistantRunService::class);
        $run = $runs->queueRun(
            $session,
            'Create the task and report the result.',
            $this->metadata('committed-failure'),
            'web_chat',
        )['run'];
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')->once()->andReturnUsing(
            function (ConversationSession $runtimeSession, ConversationMessage $message, AssistantRunExecutionClaim $claim): array {
                $event = ActivityEvent::create([
                    'user_id' => $runtimeSession->user_id,
                    'workspace_id' => $runtimeSession->workspace_id,
                    'conversation_session_id' => $runtimeSession->id,
                    'event_type' => 'assistant.semantic_operation.receipt',
                    'tool_name' => 'app.task.create',
                    'status' => 'succeeded',
                    'payload' => [
                        'assistant_run_id' => $claim->runId,
                        'execution_generation' => $claim->generation,
                        'user_message_id' => $message->id,
                        'operation_id' => 'create_task',
                        'receipt' => [
                            'operation_id' => 'create_task',
                            'tool' => 'app.task.create',
                            'status' => 'completed',
                            'data' => [],
                            'side_effect_committed' => true,
                        ],
                    ],
                ]);

                return [
                    'status' => 'failed',
                    'session' => $runtimeSession,
                    'user_message' => $message,
                    'assistant_message' => null,
                    'assistant_content' => 'Do not claim completion.',
                    'error' => 'provider failed after commit',
                    'events' => collect([$event]),
                    'blocker' => null,
                ];
            },
        );

        $this->processRun($run, $runtime, $runs);
        $failed = $run->fresh(['assistantMessage']);

        $this->assertSame('failed', $failed->status);
        $this->assertSame(AssistantRunService::SYSTEM_FAILURE_FINAL, $failed->assistantMessage?->content);
        $this->assertTrue((bool) data_get($failed->result, 'had_committed_writes'));
        Queue::assertPushed(ProcessAssistantRun::class, 1);
        $runs->prepareRunForBackgroundResponse($failed);
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
    }

    public function test_running_job_redelivery_never_reenters_hermes_and_stale_recovery_requeues_same_identity(): void
    {
        [, $session] = $this->conversation('run-redelivery@example.com');
        $runs = app(AssistantRunService::class);
        $run = $runs->queueRun(
            $session,
            'Execute once.',
            $this->metadata('running-redelivery'),
            'web_chat',
        )['run'];
        $claim = $runs->claimRunExecution($run, 1);
        $this->assertInstanceOf(AssistantRunExecutionClaim::class, $claim);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');

        (new ProcessAssistantRun($run->id, 1))->handle($runtime, $runs);
        $this->assertSame('running', $run->fresh()->status);
        $this->assertNull($run->fresh()->assistant_message_id);

        Carbon::setTestNow(now()->addSeconds(3));
        $recovered = $runs->prepareRunForBackgroundResponse($run->fresh());
        $this->assertSame($run->id, $recovered->id);
        $this->assertSame('queued', $recovered->status);
        $this->assertSame(1, data_get($recovered->metadata, 'background_stale_retry_attempts'));
        Queue::assertPushed(ProcessAssistantRun::class, 2);
    }

    public function test_stale_worker_failure_callback_cannot_fail_or_finalize_the_replacement_generation(): void
    {
        [, $session] = $this->conversation('run-stale-failed-callback@example.com');
        $runs = app(AssistantRunService::class);
        $metadata = $this->metadata('stale-failed-callback');
        $run = $runs->queueRun(
            $session,
            'Let the replacement own the result.',
            $metadata,
            'web_chat',
        )['run'];
        $firstClaim = $runs->claimRunExecution($run, 1);
        $this->assertInstanceOf(AssistantRunExecutionClaim::class, $firstClaim);

        Carbon::setTestNow(now()->addSeconds(3));
        $requeued = $runs->prepareRunForBackgroundResponse($run->fresh());
        $this->assertSame('queued', $requeued->status);
        $replacementClaim = $runs->claimRunExecution($requeued, 2);
        $this->assertInstanceOf(AssistantRunExecutionClaim::class, $replacementClaim);

        (new ProcessAssistantRun($run->id, 1))->failed(new RuntimeException('The stale worker timed out.'));
        $this->assertSame('running', $run->fresh()->status);
        $this->assertSame(2, (int) $run->fresh()->execution_generation);
        $this->assertNull($run->fresh()->assistant_message_id);

        $completed = $runs->finishRuntimeResult($replacementClaim, [
            'status' => 'completed',
            'assistant_content' => 'The replacement completed this request.',
            'events' => collect(),
        ]);
        $this->assertSame('completed', $completed->status);
        $this->assertSame('The replacement completed this request.', $completed->assistantMessage?->content);
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)
            ->where('role', 'assistant')
            ->count());

        $replayed = $runs->queueRun(
            $session->fresh(),
            'Let the replacement own the result.',
            $metadata,
            'web_chat',
        );
        $this->assertTrue($replayed['existing']);
        $this->assertSame($run->id, $replayed['run']->id);
    }

    public function test_cancellation_wins_completion_and_late_request_tombstone_prevents_dispatch(): void
    {
        [, $session] = $this->conversation('run-cancel-race@example.com');
        $runs = app(AssistantRunService::class);
        $run = $runs->queueRun(
            $session,
            'Return one background result.',
            $this->metadata('cancel-race'),
            'web_chat',
        )['run'];
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')->once()->andReturnUsing(
            function (ConversationSession $runtimeSession, ConversationMessage $message) use ($run, $runs): array {
                $runs->cancelRun($run->fresh());

                return [
                    'status' => 'completed',
                    'session' => $runtimeSession,
                    'user_message' => $message,
                    'assistant_message' => null,
                    'assistant_content' => 'This losing result must never be persisted.',
                    'events' => collect(),
                    'blocker' => null,
                ];
            },
        );
        $this->processRun($run, $runtime, $runs);

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(0, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
        $this->assertSame(0, MemoryEvent::where('conversation_message_id', $run->user_message_id)->count());

        $runs->cancelSession($session->fresh(), 'late-request');
        $late = $runs->queueRun(
            $session->fresh(),
            'This arrived after Stop.',
            $this->metadata('late-request'),
            'flutter_chat',
        )['run'];
        $this->assertSame('cancelled', $late->status);
        $this->assertTrue((bool) data_get($late->metadata, 'cancelled_before_queue'));
        Queue::assertPushed(ProcessAssistantRun::class, 1);
    }

    public function test_current_generation_cancelled_result_terminalizes_once_without_a_final_message(): void
    {
        [, $session] = $this->conversation('run-current-cancelled-result@example.com');
        $runs = app(AssistantRunService::class);
        $metadata = $this->metadata('current-cancelled-result');
        $run = $runs->queueRun(
            $session,
            'Cancel this claimed request.',
            $metadata,
            'web_chat',
        )['run'];
        $claim = $runs->claimRunExecution($run, 1);
        $this->assertInstanceOf(AssistantRunExecutionClaim::class, $claim);

        $cancelled = $runs->finishRuntimeResult($claim, [
            'status' => 'cancelled',
            'events' => collect(),
        ]);
        $this->assertSame('cancelled', $cancelled->status);
        $this->assertNull($cancelled->assistant_message_id);
        $this->assertSame(1, ActivityEvent::where('conversation_session_id', $session->id)
            ->where('event_type', 'runtime.run_cancelled')
            ->where('payload->execution_generation', 1)
            ->count());

        $runs->finishRuntimeResult($claim, [
            'status' => 'cancelled',
            'events' => collect(),
        ]);
        $this->assertSame(1, ActivityEvent::where('conversation_session_id', $session->id)
            ->where('event_type', 'runtime.run_cancelled')
            ->count());
        $this->assertSame(0, ConversationMessage::where('metadata->assistant_run_id', $run->id)
            ->where('role', 'assistant')
            ->count());

        $replayed = $runs->queueRun(
            $session->fresh(),
            'Cancel this claimed request.',
            $metadata,
            'web_chat',
        );
        $this->assertTrue($replayed['existing']);
        $this->assertSame('cancelled', $replayed['run']->status);
    }

    public function test_client_metadata_cannot_forge_lifecycle_state_or_fault_final_fields(): void
    {
        [, $session] = $this->conversation('run-metadata@example.com');
        $runs = app(AssistantRunService::class);
        $metadata = [
            ...$this->metadata('metadata-forgery'),
            'context' => ['surface' => 'silent-chat'],
            'assistant_run_id' => 999999,
            'status' => 'completed',
            'cancelled_before_queue' => true,
            'cancellation_requested_at' => now()->toIso8601String(),
            'source' => 'browser_voice_v2',
            'execution_generation' => 999,
            'queued_at' => '1999-01-01T00:00:00Z',
            'request_fingerprint' => 'forged',
        ];

        $queued = $runs->queueRun($session, 'Queue a normal request.', $metadata, 'web_chat');
        $persisted = $queued['run']->fresh();

        $this->assertSame('queued', $persisted->status);
        $this->assertSame('silent-chat', data_get($persisted->metadata, 'context.surface'));
        foreach ([
            'assistant_run_id',
            'status',
            'cancelled_before_queue',
            'cancellation_requested_at',
            'source',
            'execution_generation',
            'queued_at',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $persisted->metadata ?? []);
            $this->assertArrayNotHasKey($key, $persisted->userMessage?->metadata ?? []);
        }
        $this->assertArrayNotHasKey('request_fingerprint', $persisted->metadata ?? []);
        $this->assertArrayNotHasKey('request_fingerprint', $persisted->userMessage?->metadata ?? []);
        $this->assertNotSame('forged', $persisted->request_fingerprint);
        $this->assertSame('web_chat', $persisted->source);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $persisted->request_fingerprint);
        $this->assertArrayNotHasKey('request_fingerprint', $persisted->toArray());
        $this->assertArrayNotHasKey('execution_generation', $persisted->toArray());
        $this->assertArrayNotHasKey('queued_at', $persisted->toArray());
    }

    /** @return array{0:string,1:ConversationSession} */
    private function conversation(string $email): array
    {
        $token = $this->apiToken($email);
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        return [$token, ConversationSession::findOrFail($sessionId)];
    }

    /** @return array<string, mixed> */
    private function metadata(string $clientRequestId): array
    {
        return [
            'source' => 'web_chat',
            'client_request_id' => $clientRequestId,
            'client_timezone' => 'America/New_York',
            'client_local_datetime' => '2026-07-10T12:00:00-04:00',
            'client_utc_offset_minutes' => -240,
        ];
    }

    private function processRun(
        AssistantRun $run,
        HermesRuntimeService $runtime,
        AssistantRunService $runs,
    ): void {
        $fresh = $run->fresh();
        (new ProcessAssistantRun($run->id, (int) $fresh->execution_generation + 1))->handle($runtime, $runs);
    }
}
