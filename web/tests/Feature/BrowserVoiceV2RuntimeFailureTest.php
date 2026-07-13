<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\FastDomainWriteService;
use App\Services\HermesRuntimeService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class BrowserVoiceV2RuntimeFailureTest extends TestCase
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

    public function test_completed_runtime_envelope_with_fast_response_failure_event_terminalizes_as_failed(): void
    {
        $turn = $this->admitComplexTurn(
            'voice-v2-fast-failure-event@example.com',
            'runtime-fast-failure-0001',
        );

        $this->processRuntimeResult($turn, [
            'status' => 'completed',
            'events' => [['id' => 501, 'event_type' => 'runtime.fast_response_failed_terminal']],
        ]);

        $this->assertRuntimeFailure($turn, 'runtime_failed');
    }

    public function test_completed_runtime_envelope_with_tool_model_failure_event_terminalizes_as_failed(): void
    {
        $turn = $this->admitComplexTurn(
            'voice-v2-tool-failure-event@example.com',
            'runtime-tool-failure-0001',
        );

        $this->processRuntimeResult($turn, [
            'status' => 'completed',
            'events' => [['id' => 502, 'event_type' => 'runtime.tool_model_failed']],
        ]);

        $this->assertRuntimeFailure($turn, 'runtime_failed');
    }

    public function test_explicit_blocked_runtime_result_terminalizes_as_failed_with_retry_offer(): void
    {
        $turn = $this->admitComplexTurn(
            'voice-v2-runtime-blocked@example.com',
            'runtime-blocked-0001',
        );

        $this->processRuntimeResult($turn, [
            'status' => 'blocked',
            'events' => [],
            'blocker' => ['reason' => 'The provider rejected this request.'],
        ]);

        $this->assertRuntimeFailure($turn, 'runtime_blocked');
        $this->assertStringContainsString('The provider rejected this request.', $turn->fresh()->internal_failure_detail);
    }

    public function test_generated_note_commit_exception_removes_the_provisional_assistant_message(): void
    {
        $turn = $this->admitGeneratedNoteTurn(
            'voice-v2-note-commit-exception@example.com',
            'note-commit-exception-0001',
        );
        $writes = Mockery::mock(FastDomainWriteService::class);
        $writes->shouldReceive('reconcile')->zeroOrMoreTimes()->andReturn(null);
        $writes->shouldReceive('createGeneratedNote')
            ->once()
            ->andThrow(new RuntimeException('The note database write failed.'));

        $this->processRuntimeResult($turn, ['status' => 'completed', 'events' => []], $writes);

        $turn->refresh();
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('worker_failure', $turn->failure_category);
        $this->assertSame(0, $this->provisionalMessages($turn));
        $this->assertSame(1, $this->namedFinalMessages($turn));
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_cancellation_winning_during_generated_note_commit_removes_the_provisional_message(): void
    {
        $turn = $this->admitGeneratedNoteTurn(
            'voice-v2-note-cancel-race@example.com',
            'note-cancel-race-0001',
        );
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $writes = Mockery::mock(FastDomainWriteService::class);
        $writes->shouldReceive('reconcile')->zeroOrMoreTimes()->andReturn(null);
        $writes->shouldReceive('createGeneratedNote')
            ->once()
            ->andReturnUsing(function (VoiceTurn $operationTurn, $run) use ($lifecycle): ?string {
                $lifecycle->cancelJob($run->fresh(), 'test_cancellation_race');

                return null;
            });

        $this->processRuntimeResult($turn, ['status' => 'completed', 'events' => []], $writes);

        $turn->refresh();
        $this->assertSame(VoiceTurnState::Canceled, $turn->state);
        $this->assertSame(0, $this->provisionalMessages($turn));
        $this->assertSame(0, $this->namedFinalMessages($turn));
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_deadline_winning_during_generated_note_commit_removes_the_provisional_message(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $turn = $this->admitGeneratedNoteTurn(
            'voice-v2-note-deadline-race@example.com',
            'note-deadline-race-0001',
        );
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $writes = Mockery::mock(FastDomainWriteService::class);
        $writes->shouldReceive('reconcile')->zeroOrMoreTimes()->andReturn(null);
        $writes->shouldReceive('createGeneratedNote')
            ->once()
            ->andReturnUsing(function (VoiceTurn $operationTurn, $run) use ($lifecycle, $turn): ?string {
                Carbon::setTestNow('2026-07-11 12:00:11', 'America/New_York');
                $this->assertSame(1, $lifecycle->enforceDeadlines($turn->id));

                return null;
            });

        $this->processRuntimeResult($turn, ['status' => 'completed', 'events' => []], $writes);

        $turn->refresh();
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('no_progress_timeout', $turn->failure_category);
        $this->assertSame(0, $this->provisionalMessages($turn));
        $this->assertSame(1, $this->namedFinalMessages($turn));
        $this->assertDatabaseCount('notes', 0);
    }

    public function test_cancellation_reconciles_a_committed_generated_note_receipt_without_duplication(): void
    {
        $turn = $this->admitGeneratedNoteTurn(
            'voice-v2-note-commit-cancel@example.com',
            'note-commit-cancel-0001',
        );
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $run = $turn->runs()->firstOrFail();
        $this->assertTrue($lifecycle->claimJobExecution($run));
        $committed = app(FastDomainWriteService::class)->createGeneratedNote(
            $turn->fresh(),
            $run->fresh(),
            "Monday: pasta and salad.\nTuesday: tacos and rice.",
        );
        $this->assertNotNull($committed);

        $terminal = $lifecycle->cancelJob($run->fresh(), 'test_after_commit');

        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('committed', $terminal->side_effect_status->value);
        $this->assertSame(1, Note::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
        $this->assertSame(1, $this->namedFinalMessages($terminal));
        $this->assertSame(
            $committed,
            app(FastDomainWriteService::class)->createGeneratedNote($terminal, $run->fresh(), 'A duplicate body'),
        );
        $this->assertSame(1, Note::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
    }

    public function test_spoken_whole_turn_cancellation_reports_too_late_when_the_only_generated_note_job_committed(): void
    {
        $token = $this->apiToken('voice-v2-note-spoken-cancel-after-commit@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            'turn_id' => 'note-spoken-cancel-target-0001',
            'session_id' => $sessionId,
            'transcript' => 'Create a three-day meal plan and save it as a note.',
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
        ])->assertCreated();
        $target = VoiceTurn::where('turn_id', 'note-spoken-cancel-target-0001')->firstOrFail();
        $run = $target->runs()->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $this->assertTrue($lifecycle->claimJobExecution($run));
        $this->assertNotNull(app(FastDomainWriteService::class)->createGeneratedNote(
            $target->fresh(),
            $run->fresh(),
            "Monday: pasta and salad.\nTuesday: tacos and rice.\nWednesday: salmon and vegetables.",
        ));
        $this->assertSame('running', $run->fresh()->status);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            'turn_id' => 'note-spoken-cancel-command-0001',
            'session_id' => $sessionId,
            'transcript' => 'Cancel that note request.',
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
        ])->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.cancel')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.final_text', 'That had already finished, so I couldn’t cancel it.');

        $target->refresh()->load(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $target->state);
        $this->assertSame('completed', $target->runs->sole()->status);
        $this->assertSame('committed', $target->side_effect_status->value);
        $this->assertSame('Done—I created the note “Three-Day Meal Plan”.', $target->finalAssistantMessage->content);
        $this->assertSame(1, Note::where('metadata->browser_voice_turn_id', $target->turn_id)->count());
        $this->assertSame(1, $this->namedFinalMessages($target));
    }

    public function test_deadline_reconciles_a_committed_generated_note_receipt_without_duplication(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $turn = $this->admitGeneratedNoteTurn(
            'voice-v2-note-commit-deadline@example.com',
            'note-commit-deadline-0001',
        );
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $run = $turn->runs()->firstOrFail();
        $this->assertTrue($lifecycle->claimJobExecution($run));
        $committed = app(FastDomainWriteService::class)->createGeneratedNote(
            $turn->fresh(),
            $run->fresh(),
            "Monday: pasta and salad.\nTuesday: tacos and rice.",
        );
        $this->assertNotNull($committed);

        Carbon::setTestNow('2026-07-11 12:00:11', 'America/New_York');
        $this->assertSame(1, $lifecycle->enforceDeadlines($turn->id));

        $turn->refresh();
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('committed', $turn->side_effect_status->value);
        $this->assertSame(1, Note::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
        $this->assertSame(1, $this->namedFinalMessages($turn));
        $this->assertSame(0, $this->provisionalMessages($turn));
    }

    public function test_real_complex_runtime_has_no_tools_and_only_the_typed_writer_saves_one_note(): void
    {
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.fast_chat_model', 'gpt-test-fast');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        Http::fakeSequence()->push([
            'id' => 'chatcmpl-browser-voice-generated-note',
            'model' => 'gpt-test-fast',
            'choices' => [[
                'finish_reason' => 'stop',
                'message' => [
                    'role' => 'assistant',
                    'content' => "Monday: chicken tacos.\nTuesday: vegetable pasta.\nWednesday: salmon and rice.",
                ],
            ]],
        ], 200);
        $turn = $this->admitGeneratedNoteTurn(
            'voice-v2-real-generated-note@example.com',
            'real-generated-note-0001',
        );
        $run = $turn->runs()->firstOrFail();

        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );
        // Duplicate queue delivery must not call the model or write again.
        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh()->load('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame('Done—I created the note “Three-Day Meal Plan”.', $turn->finalAssistantMessage->content);
        $this->assertSame(1, Note::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
        $this->assertDatabaseMissing('activity_events', ['event_type' => 'runtime.tool_loop_started']);
        Http::assertSentCount(1);
        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $systemText = collect($payload['messages'] ?? [])
                ->where('role', 'system')
                ->pluck('content')
                ->implode(' ');

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && ! array_key_exists('tools', $payload)
                && ! array_key_exists('tool_choice', $payload)
                && str_contains($systemText, 'Generate only the complete note body')
                && str_contains($systemText, 'Include the full requested number of items')
                && str_contains($systemText, 'claim to save the note');
        });
    }

    public function test_direct_voice_note_creation_respects_the_base_plan_note_limit_and_returns_one_upgrade_final(): void
    {
        $turn = $this->admit(
            'voice-v2-direct-note-limit@example.com',
            'direct-note-limit-0001',
            'Create a note titled Groceries that says milk and eggs.',
        );
        $this->fillNoteAllowance($turn);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');

        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh()->load('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('subscription_limit_reached', $turn->failure_category);
        $this->assertStringContainsString('current plan includes up to 10 notes', $turn->finalAssistantMessage->content);
        $this->assertStringContainsString('Upgrade your plan', $turn->finalAssistantMessage->content);
        $this->assertSame(10, Note::where('user_id', $turn->user_id)->count());
        $this->assertSame(1, $this->namedFinalMessages($turn));
    }

    public function test_generated_voice_note_checks_the_plan_limit_before_model_work_and_returns_one_upgrade_final(): void
    {
        $turn = $this->admitGeneratedNoteTurn(
            'voice-v2-generated-note-limit@example.com',
            'generated-note-limit-0001',
        );
        $this->fillNoteAllowance($turn);
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldNotReceive('sendExistingMessage');

        (new ProcessAssistantRun($turn->runs()->firstOrFail()->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh()->load('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('subscription_limit_reached', $turn->failure_category);
        $this->assertStringContainsString('Upgrade your plan', $turn->finalAssistantMessage->content);
        $this->assertSame(10, Note::where('user_id', $turn->user_id)->count());
        $this->assertSame(1, $this->namedFinalMessages($turn));
    }

    public function test_complex_provider_retry_records_progress_and_stays_inside_the_turn_deadline(): void
    {
        $this->configureRealRuntime();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            if ($attempts === 1) {
                throw new ConnectionException('The first provider attempt timed out.');
            }

            return Http::response([
                'id' => 'chatcmpl-browser-voice-retry',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'A concise three-day meal plan.'],
                ]],
            ], 200);
        });
        $turn = $this->admitGeneratedNoteTurn('voice-v2-retry@example.com', 'complex-retry-0001');
        $run = $turn->runs()->firstOrFail();

        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh();
        $this->assertSame(2, $attempts);
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame(1, $turn->retry_count);
        $this->assertSame(1, $turn->events()->where('event_type', 'retry_started')->count());
        $this->assertSame(1, Note::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
    }

    public function test_complex_runtime_never_retries_after_deadline_terminalizes_the_run(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $this->configureRealRuntime();
        $attempts = 0;
        $turn = $this->admitGeneratedNoteTurn('voice-v2-no-late-retry@example.com', 'no-late-retry-0001');
        Http::fake(function () use (&$attempts, $turn) {
            $attempts++;
            Carbon::setTestNow('2026-07-11 12:00:11', 'America/New_York');
            $this->assertSame(1, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));

            throw new ConnectionException('The provider returned after the no-progress deadline.');
        });
        $run = $turn->runs()->firstOrFail();

        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh();
        $this->assertSame(1, $attempts);
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('no_progress_timeout', $turn->failure_category);
        $this->assertSame(0, $turn->retry_count);
        $this->assertSame(0, Note::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
    }

    public function test_concurrent_complex_runtime_receives_only_its_sealed_turn_not_unresolved_neighbors(): void
    {
        $this->configureRealRuntime();
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-browser-voice-isolated',
                'model' => 'gpt-test-fast',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => ['role' => 'assistant', 'content' => 'Here are three practical focus techniques.'],
                ]],
            ], 200),
        ]);
        $token = $this->apiToken('voice-v2-isolated-complex@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $firstText = 'Draft an unresolved seven-day travel plan for Boston.';
        $secondText = 'Explain three practical focus techniques.';
        foreach ([
            ['isolated-complex-0001', $firstText],
            ['isolated-complex-0002', $secondText],
        ] as [$turnId, $transcript]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', [
                'turn_id' => $turnId,
                'session_id' => $sessionId,
                'transcript' => $transcript,
                'timezone' => 'America/New_York',
            ])->assertCreated();
        }
        $second = VoiceTurn::where('turn_id', 'isolated-complex-0002')->firstOrFail();
        $run = $second->runs()->firstOrFail();

        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $this->assertSame(VoiceTurnState::Completed, $second->fresh()->state);
        Http::assertSent(function ($request) use ($firstText, $secondText): bool {
            $contents = collect(data_get($request->data(), 'messages', []))->pluck('content');

            return $contents->contains($secondText) && ! $contents->contains($firstText);
        });
    }

    public function test_empty_complex_model_output_fails_and_never_creates_a_placeholder_note(): void
    {
        $this->configureRealRuntime();
        Http::fakeSequence()
            ->push([
                'id' => 'chatcmpl-empty-complex-1',
                'model' => 'gpt-test-fast',
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '']]],
            ], 200)
            ->push([
                'id' => 'chatcmpl-empty-complex-2',
                'model' => 'gpt-test-fast',
                'choices' => [['finish_reason' => 'stop', 'message' => ['role' => 'assistant', 'content' => '']]],
            ], 200);
        $turn = $this->admitGeneratedNoteTurn('voice-v2-empty-note@example.com', 'empty-note-0001');
        $run = $turn->runs()->firstOrFail();

        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );

        $turn->refresh()->load('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertStringContainsString('Would you like me to try again?', $turn->finalAssistantMessage->content);
        $this->assertSame(0, Note::where('metadata->browser_voice_turn_id', $turn->turn_id)->count());
        $this->assertDatabaseMissing('notes', ['plain_text' => 'I’m here.']);
        Http::assertSentCount(2);
    }

    /** @param array<string, mixed> $runtimeResult */
    private function processRuntimeResult(
        VoiceTurn $turn,
        array $runtimeResult,
        ?FastDomainWriteService $writes = null,
    ): void {
        $run = $turn->runs()->firstOrFail();
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->once()
            ->andReturnUsing(function (ConversationSession $session, ConversationMessage $message) use ($run, $runtimeResult): array {
                $provisional = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => 'PROVISIONAL RESPONSE FOR '.$run->id,
                    'metadata' => ['assistant_run_id' => $run->id],
                ]);

                return [
                    'status' => $runtimeResult['status'] ?? 'completed',
                    'session' => $session,
                    'user_message' => $message,
                    'assistant_message' => $provisional,
                    'events' => collect($runtimeResult['events'] ?? []),
                    'blocker' => $runtimeResult['blocker'] ?? null,
                ];
            });

        (new ProcessAssistantRun($run->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
            null,
            null,
            null,
            $writes,
        );
    }

    private function assertRuntimeFailure(VoiceTurn $turn, string $category): void
    {
        $turn->refresh()->load(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame($category, $turn->failure_category);
        $this->assertSame('failed', $turn->runs->sole()->status);
        $this->assertStringContainsString('Would you like me to try again?', $turn->finalAssistantMessage->content);
        $this->assertSame(0, $this->provisionalMessages($turn));
        $this->assertSame(1, $this->namedFinalMessages($turn));
    }

    private function admitComplexTurn(string $email, string $turnId): VoiceTurn
    {
        return $this->admit(
            $email,
            $turnId,
            'Explain three practical ways to organize a busy week.',
        );
    }

    private function configureRealRuntime(): void
    {
        config()->set('services.hermes_runtime.default_provider', 'openai');
        config()->set('services.hermes_runtime.fast_chat_model', 'gpt-test-fast');
        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
    }

    private function admitGeneratedNoteTurn(string $email, string $turnId): VoiceTurn
    {
        return $this->admit(
            $email,
            $turnId,
            'Create a three-day meal plan and save it as a note.',
        );
    }

    private function admit(string $email, string $turnId, string $transcript): VoiceTurn
    {
        $token = $this->apiToken($email);
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
        ])->assertCreated();

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }

    private function fillNoteAllowance(VoiceTurn $turn): void
    {
        foreach (range(1, 10) as $index) {
            Note::create([
                'user_id' => $turn->user_id,
                'workspace_id' => $turn->workspace_id,
                'created_by_user_id' => $turn->user_id,
                'title' => "Existing note {$index}",
                'plain_text' => 'Existing plan-limited note.',
            ]);
        }
    }

    private function provisionalMessages(VoiceTurn $turn): int
    {
        return ConversationMessage::query()
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('role', 'assistant')
            ->where('content', 'like', 'PROVISIONAL RESPONSE FOR %')
            ->count();
    }

    private function namedFinalMessages(VoiceTurn $turn): int
    {
        return ConversationMessage::query()
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('role', 'assistant')
            ->where('client_turn_id', $turn->turn_id)
            ->count();
    }
}
