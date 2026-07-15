<?php

namespace Tests\Feature;

use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BrowserVoiceV2SemanticLifecycleTest extends TestCase
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

    public function test_every_spoken_request_is_admitted_to_one_async_semantic_path(): void
    {
        $token = $this->apiToken('voice-semantic-admission@example.com');
        $sessionId = $this->sessionId($token);
        $requests = [
            ['semantic-time-0001', 'What time is it?'],
            ['semantic-date-0001', 'What is today’s date?'],
            ['semantic-state-0001', 'Are you listening?'],
            ['semantic-read-0001', 'What is on my calendar tomorrow?'],
            ['semantic-stop-0001', 'Stop.'],
            ['semantic-write-0001', 'Move the first meeting to Friday and remind me after it.'],
        ];

        foreach ($requests as [$turnId, $transcript]) {
            $this->withToken($token)->postJson(
                '/api/assistant/voice/turns',
                $this->payload($sessionId, $turnId, $transcript),
            )
                ->assertCreated()
                ->assertJsonPath('data.turn.state', VoiceTurnState::Accepted->value)
                ->assertJsonPath('data.turn.acknowledgement_required', false)
                ->assertJsonPath('data.turn.acknowledgement_text', null)
                ->assertJsonPath('data.turn.final_assistant_message_id', null)
                ->assertJsonCount(1, 'data.jobs')
                ->assertJsonPath('data.jobs.0.handler', HermesSemanticOperationExecutor::INTERPRETATION_HANDLER)
                ->assertJsonCount(1, 'data.messages');

            $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
            $run = $turn->runs()->sole();
            $this->assertSame('semantic-1', $run->idempotency_key === null
                ? null
                : str($run->idempotency_key)->afterLast(':')->toString());
            $this->assertSame(1, data_get($run->metadata, 'semantic_sequence'));
            $this->assertTrue((bool) data_get($run->metadata, 'required'));
        }

        Queue::assertPushed(ProcessAssistantRun::class, count($requests));

        $this->withToken($token)->postJson(
            '/api/assistant/voice/turns',
            $this->payload($sessionId, 'semantic-time-0001', 'What time is it?'),
        )
            ->assertOk()
            ->assertJsonCount(1, 'data.jobs');

        $turn = VoiceTurn::where('turn_id', 'semantic-time-0001')->firstOrFail();
        $this->assertSame(1, $turn->runs()->count());
        Queue::assertPushed(ProcessAssistantRun::class, count($requests));
    }

    public function test_context_authorization_is_preserved_without_local_reference_interpretation(): void
    {
        $token = $this->apiToken('voice-semantic-context@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'semantic-context-0001', 'Show me my tasks.'),
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 7],
        ])->assertCreated();

        $first = VoiceTurn::where('turn_id', 'semantic-context-0001')->firstOrFail();
        app(VoiceTurnLifecycleService::class)->complete($first, 'Here are your tasks.');
        $this->withToken($token)->postJson('/api/assistant/voice/turns/semantic-context-0001/delivery', [
            'session_id' => $sessionId,
            'event' => 'final_audio_started',
            'timing' => [
                'speech_item_id' => 'semantic-context-0001-final',
                'controller_generation' => 1,
                'purpose' => 'final',
            ],
        ])->assertOk();
        $this->withToken($token)->postJson('/api/assistant/voice/turns/semantic-context-0001/delivery', [
            'session_id' => $sessionId,
            'event' => 'playback_finished',
            'timing' => [
                'speech_item_id' => 'semantic-context-0001-final',
                'controller_generation' => 1,
                'purpose' => 'final',
            ],
        ])->assertOk();

        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($sessionId, 'semantic-context-0002', 'Move the first one to Friday.'),
            'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 7],
        ])->assertCreated();

        $followUp = VoiceTurn::where('turn_id', 'semantic-context-0002')->firstOrFail();
        $this->assertTrue((bool) data_get($followUp->metadata, 'prior_context_authorized'));
        $this->assertSame('semantic-context-0001', data_get($followUp->metadata, 'prior_turn_id'));
        $this->assertArrayNotHasKey('prior_handler', $followUp->metadata);
        $this->assertArrayNotHasKey('prior_transcript', $followUp->metadata);
        $this->assertArrayNotHasKey('contextual_reference', $followUp->metadata);
    }

    public function test_semantic_clarification_resumes_the_same_turn_and_finalizes_once(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-semantic-clarification@example.com');
        $sessionId = $this->sessionId($token);
        $turnId = 'semantic-clarification-0001';

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            $turnId,
            'Create a reminder.',
        ))->assertCreated();

        $lifecycle = app(VoiceTurnLifecycleService::class);
        $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
        $firstRun = $turn->runs()->sole();
        $this->assertTrue($lifecycle->claimJobExecution($firstRun));
        $running = $lifecycle->markProgress($turn, ['run_id' => $firstRun->id], 'worker');
        $this->assertSame(VoiceTurnState::Running, $running->state);

        $awaiting = $lifecycle->requestSemanticClarification(
            $firstRun,
            'What time should I remind you?',
            ['reason' => 'missing_time'],
        );
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame('What time should I remind you?', data_get($awaiting->metadata, 'clarification_question'));
        $this->assertSame(1, data_get($awaiting->metadata, 'clarification_sequence'));
        $this->assertNotNull($awaiting->hard_deadline_at);
        $this->assertSame(
            $awaiting->hard_deadline_at?->toIso8601String(),
            data_get($awaiting->metadata, 'clarification_prompt_delivery_deadline_at'),
        );
        $this->assertNull($awaiting->no_progress_deadline_at);
        $this->assertNull($awaiting->final_assistant_message_id);
        $this->assertSame('completed', $firstRun->fresh()->status);
        $this->assertFalse((bool) data_get($firstRun->fresh()->metadata, 'required'));
        $this->assertSame('awaiting_clarification', data_get($firstRun->fresh()->result, 'status'));

        $duplicate = $lifecycle->requestSemanticClarification($firstRun, 'A different duplicate question.');
        $this->assertSame(1, data_get($duplicate->metadata, 'clarification_sequence'));
        $this->assertSame('What time should I remind you?', data_get($duplicate->metadata, 'clarification_question'));
        $this->assertFalse($duplicate->acknowledgement_required);

        Carbon::setTestNow(now()->addSeconds(2));
        $answerDeadline = $lifecycle->startClarificationDeadline($duplicate, 5);
        $answerDeadlineAt = $answerDeadline->hard_deadline_at?->toIso8601String();
        $this->assertNotNull($answerDeadlineAt);
        $this->assertNotNull(data_get($answerDeadline->metadata, 'clarification_deadline_started_at'));
        $this->assertSame(
            $answerDeadlineAt,
            $lifecycle->startClarificationDeadline($answerDeadline, 5)->hard_deadline_at?->toIso8601String(),
            'A duplicate playback event may not extend the five-second answer window.',
        );

        $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$sessionId}&cursor=0")
            ->assertOk()
            ->assertJsonPath('data.turns.0.turn_id', $turnId)
            ->assertJsonPath('data.turns.0.state', VoiceTurnState::AwaitingClarification->value)
            ->assertJsonPath('data.turns.0.clarification.question', 'What time should I remind you?');

        $clarification = [
            'session_id' => $sessionId,
            'answer' => 'At 5 p.m. today.',
            'clarification_id' => 'semantic-answer-0001',
        ];
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turnId}/clarifications", $clarification)
            ->assertOk()
            ->assertJsonPath('data.turn.turn_id', $turnId)
            ->assertJsonPath('data.turn.state', VoiceTurnState::Accepted->value)
            ->assertJsonPath('data.turn.resolved_clarification_ids.0', 'semantic-answer-0001')
            ->assertJsonCount(2, 'data.jobs')
            ->assertJsonPath('data.messages.0.content', "Create a reminder.\nAt 5 p.m. today.");

        $resumed = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
        $this->assertSame(2, data_get($resumed->metadata, 'semantic_sequence'));
        $this->assertSame('At 5 p.m. today.', data_get($resumed->metadata, 'semantic_utterances.1.text'));
        $this->assertSame('What time should I remind you?', data_get($resumed->metadata, 'semantic_clarification_history.0.question'));
        $this->assertSame('At 5 p.m. today.', data_get($resumed->metadata, 'semantic_clarification_history.0.answer'));

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turnId}/clarifications", $clarification)
            ->assertOk()
            ->assertJsonCount(1, 'data.turn.resolved_clarification_ids')
            ->assertJsonCount(2, 'data.jobs');
        $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$sessionId}&cursor=0")
            ->assertOk()
            ->assertJsonPath('data.turns.0.resolved_clarification_ids.0', 'semantic-answer-0001');
        $this->assertSame(2, $resumed->fresh()->runs()->count());
        Queue::assertPushed(ProcessAssistantRun::class, 2);

        $secondRun = $resumed->fresh()->runs()->orderBy('id')->get()->last();
        $this->assertInstanceOf(AssistantRun::class, $secondRun);
        $this->assertSame(2, data_get($secondRun->metadata, 'semantic_sequence'));
        $this->assertSame(now()->addSeconds(2)->timestamp, $secondRun->hard_deadline_at?->timestamp);
        $this->assertGreaterThan($resumed->accepted_at?->copy()->addSeconds(2)->timestamp, $secondRun->hard_deadline_at?->timestamp);
        $this->assertSame("Create a reminder.\nAt 5 p.m. today.", $secondRun->input);
        $this->assertTrue($lifecycle->claimJobExecution($secondRun));
        $lifecycle->markProgress($resumed->fresh(), ['run_id' => $secondRun->id], 'worker');
        $terminal = $lifecycle->finishJob($secondRun, 'completed', finalText: 'I set that reminder for 5 p.m. today.');

        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('I set that reminder for 5 p.m. today.', $terminal->finalAssistantMessage->content);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turnId)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turnId)->where('role', 'assistant')->count());
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $terminal->id)->where('event_type', 'clarification_requested')->count());
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $terminal->id)->where('event_type', 'clarification_resolved')->count());
    }

    public function test_semantic_response_directives_and_diagnostics_are_lifecycle_owned(): void
    {
        $token = $this->apiToken('voice-semantic-delivery@example.com');
        $sessionId = $this->sessionId($token);
        $turnId = 'semantic-delivery-0001';

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            $turnId,
            'Compare my schedule and tasks, then suggest a plan.',
        ))->assertCreated();

        $lifecycle = app(VoiceTurnLifecycleService::class);
        $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
        $run = $turn->runs()->sole();
        $this->assertTrue($lifecycle->claimJobExecution($run));
        $turn = $lifecycle->markProgress($turn, ['run_id' => $run->id], 'worker');
        $turn->update(['metadata' => [
            ...$turn->metadata,
            'response_directives' => ['stop_playback' => true],
        ]]);

        $directed = $lifecycle->publishSemanticResponseDirectives($run, true, false);
        $this->assertTrue((bool) data_get($directed->metadata, 'response_directives.stop_playback'));
        $this->assertTrue((bool) data_get($directed->metadata, 'response_directives.close_after_response'));
        $this->assertFalse((bool) data_get($directed->metadata, 'response_directives.response_expected'));
        $this->assertSame(1, data_get($directed->metadata, 'response_directives.semantic_sequence'));
        $this->assertSame($run->id, data_get($directed->metadata, 'response_directives.run_id'));
        $lifecycle->publishSemanticResponseDirectives($run, true, false);

        $lifecycle->recordSemanticEvent($directed, 'semantic.operation_completed', [
            'run_id' => $run->id,
            'operation_id' => 'operation-1',
        ]);
        $event = VoiceTurnEvent::where('voice_turn_id', $turn->id)
            ->where('event_type', 'semantic.operation_completed')
            ->sole();
        $this->assertSame('semantic_interpreter', $event->source);
        $this->assertSame('operation-1', data_get($event->payload, 'operation_id'));
        $this->assertSame(0, VoiceTurnEvent::where('voice_turn_id', $turn->id)->where('event_type', 'semantic_acknowledgement_published')->count());
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $turn->id)->where('event_type', 'semantic_response_directives_published')->count());

        $terminal = $lifecycle->finishJob($run, 'completed', finalText: 'Here is the plan.');
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertFalse($terminal->acknowledgement_required);
        $this->assertNull($terminal->acknowledgement_text);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turnId)->where('role', 'assistant')->count());
    }

    public function test_valid_hermes_final_clarification_and_acknowledgement_text_is_persisted_literally(): void
    {
        $token = $this->apiToken('voice-semantic-literal-output@example.com');
        $sessionId = $this->sessionId($token);
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $finalText = "  {\"final\":\"keep@example.com\"}\n";
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'semantic-literal-final-0001',
            'Give me a literal response.',
        ))->assertCreated();
        $finalTurn = VoiceTurn::where('turn_id', 'semantic-literal-final-0001')->firstOrFail();
        $terminal = $lifecycle->complete($finalTurn, $finalText);
        $this->assertSame($finalText, $terminal->finalAssistantMessage?->content);

        $clarificationText = "\t{\"question\":\"Which one?\"}  ";
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'semantic-literal-clarification-0001',
            'Move it.',
        ))->assertCreated();
        $clarificationTurn = VoiceTurn::where('turn_id', 'semantic-literal-clarification-0001')->firstOrFail();
        $clarificationRun = $clarificationTurn->runs()->sole();
        $this->assertTrue($lifecycle->claimJobExecution($clarificationRun));
        $lifecycle->markProgress($clarificationTurn, ['run_id' => $clarificationRun->id], 'worker');
        $awaiting = $lifecycle->requestSemanticClarification($clarificationRun, $clarificationText);
        $this->assertSame($clarificationText, data_get($awaiting->metadata, 'clarification_question'));
        $this->assertSame($clarificationText, data_get($clarificationRun->fresh()->result, 'question'));

        $acknowledgementText = "  {\"ack\":\"I’ll do that.\"}\n";
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'semantic-literal-acknowledgement-0001',
            'Check the current time.',
        ))->assertCreated();
        $acknowledgementTurn = VoiceTurn::where('turn_id', 'semantic-literal-acknowledgement-0001')->firstOrFail();
        $interpretationRun = $acknowledgementTurn->runs()->sole();
        $this->assertTrue($lifecycle->claimJobExecution($interpretationRun));
        $lifecycle->markProgress($acknowledgementTurn, ['run_id' => $interpretationRun->id], 'worker');
        $staged = $lifecycle->stageSemanticExecution(
            $acknowledgementTurn,
            $interpretationRun,
            [[
                'id' => 'read-clock',
                'tool' => 'system.clock.read',
                'operation' => [
                    'id' => 'read-clock',
                    'tool' => 'system.clock.read',
                    'arguments' => [],
                    'dependencies' => [],
                ],
                'lane' => 'app_read',
                'label' => 'Read clock',
                'priority' => 0,
                'resource_lock_key' => null,
            ]],
            [
                'outcome' => 'execute',
                'response_text' => null,
                'clarification_question' => null,
                'acknowledgement_text' => $acknowledgementText,
                'close_after_response' => false,
                'response_expected' => false,
                'operations' => [],
            ],
        );
        $this->assertSame($acknowledgementText, $acknowledgementTurn->fresh()->acknowledgement_text);
        $this->assertArrayNotHasKey('resource_lock_from', $staged['operation_runs'][0]->metadata ?? []);
    }

    public function test_semantic_worker_clarifies_then_answers_on_the_same_stable_turn(): void
    {
        $token = $this->apiToken('voice-semantic-worker-clarification@example.com');
        $sessionId = $this->sessionId($token);
        $turnId = 'semantic-worker-clarification-0001';
        $requests = [];
        $interpretations = [
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'What time should I remind you?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'I can handle that reminder now.',
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
        ];
        $interpreter = Mockery::mock(HermesSemanticInterpreter::class);
        $interpreter->shouldReceive('interpret')
            ->twice()
            ->andReturnUsing(function (HermesSemanticInterpretationRequest $request) use (&$requests, &$interpretations): HermesSemanticInterpretation {
                $requests[] = $request;

                return array_shift($interpretations);
            });
        $interpreter->shouldNotReceive('compose');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            $turnId,
            'Create a reminder.',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
        $this->processSemantic($turn->runs()->sole(), $interpreter);

        $turn->refresh();
        $this->assertSame(VoiceTurnState::AwaitingClarification, $turn->state);
        $this->assertTrue((bool) data_get($turn->metadata, 'response_directives.response_expected'));

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turnId}/clarifications", [
            'session_id' => $sessionId,
            'answer' => 'At 5 p.m. today.',
            'clarification_id' => 'semantic-worker-answer-0001',
        ])->assertOk();
        $secondRun = $turn->fresh()->runs()->orderBy('id')->get()->last();
        $this->assertInstanceOf(AssistantRun::class, $secondRun);
        $this->processSemantic($secondRun, $interpreter);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('I can handle that reminder now.', $terminal->finalAssistantMessage->content);
        $this->assertFalse((bool) data_get($terminal->metadata, 'response_directives.response_expected'));
        $this->assertCount(2, $requests);
        $this->assertSame($turnId, $requests[0]->stableTurnId);
        $this->assertSame($turnId, $requests[1]->stableTurnId);
        $this->assertSame("Create a reminder.\nAt 5 p.m. today.", $requests[1]->transcript);
        $this->assertSame(
            'What time should I remind you?',
            data_get($requests[1]->context, 'logical_request.clarification_history.0.question'),
        );
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turnId)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turnId)->where('role', 'assistant')->count());
    }

    public function test_reload_repairs_a_missing_interpretation_job_and_reaches_one_final(): void
    {
        $token = $this->apiToken('voice-semantic-admission-recovery@example.com');
        $sessionId = $this->sessionId($token);
        $turnId = 'semantic-admission-recovery-0001';

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            $turnId,
            'Hello Bean.',
        ))->assertCreated();
        $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();
        $turn->runs()->sole()->delete();
        Queue::fake();

        $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$sessionId}&cursor=0")
            ->assertOk()
            ->assertJsonPath('data.turns.0.turn_id', $turnId)
            ->assertJsonPath('data.turns.0.state', VoiceTurnState::Accepted->value)
            ->assertJsonCount(1, 'data.jobs');
        Queue::assertPushed(ProcessAssistantRun::class, 1);

        $recoveredRun = $turn->fresh()->runs()->sole();
        $this->assertSame('semantic_interpretation', data_get($recoveredRun->metadata, 'role'));
        $this->assertNotNull($recoveredRun->dispatch_requested_at);
        $interpreter = Mockery::mock(HermesSemanticInterpreter::class);
        $interpreter->shouldReceive('interpret')->once()->andReturn(new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
            responseText: 'Hello. How can I help?',
            clarificationQuestion: null,
            acknowledgementText: null,
            closeAfterResponse: false,
            responseExpected: true,
            operations: [],
        ));
        $interpreter->shouldNotReceive('compose');
        $this->processSemantic($recoveredRun, $interpreter);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('Hello. How can I help?', $terminal->finalAssistantMessage?->content);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turnId)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turnId)->where('role', 'assistant')->count());
    }

    /** @return array<string, mixed> */
    private function payload(int $sessionId, string $turnId, string $transcript): array
    {
        return [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
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

    private function processSemantic(AssistantRun $run, HermesSemanticInterpreter $interpreter): void
    {
        (new ProcessAssistantRun($run->id))->handle(
            runtime: app(HermesRuntimeService::class),
            runs: app(AssistantRunService::class),
            voiceTurns: app(VoiceTurnLifecycleService::class),
            semanticInterpreter: $interpreter,
        );
    }
}
