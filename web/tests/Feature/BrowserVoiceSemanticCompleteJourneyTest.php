<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\HermesSemanticProviderException;
use App\Exceptions\HermesSemanticUsageLimitException;
use App\Exceptions\VoiceTurnConflictException;
use App\Jobs\EnforceBrowserVoiceTurnDeadline;
use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\MemoryItem;
use App\Models\Note;
use App\Models\Task;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\AssistantRunService;
use App\Services\BeanMemoryService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\LiveLookupService;
use App\Services\PlanLimitService;
use App\Services\StructuredHermesActionService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * Complete deterministic journeys for the single-Hermes-path contract.
 *
 * These tests intentionally cross admission, interpretation, durable typed
 * jobs, the composition barrier, projection/reload, and final delivery state.
 * A service-only assertion is not sufficient coverage for these journeys.
 */
class BrowserVoiceSemanticCompleteJourneyTest extends TestCase
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

    public function test_first_acoustic_hearing_check_reaches_hermes_and_reloads_one_durable_final(): void
    {
        [$token, $session] = $this->conversation('first-acoustic-hearing-check@example.com');
        $turnId = 'first-hearing-check';
        $transcript = 'Can you hear me?';
        $finalText = 'Yes—I can hear you.';
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: $finalText,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
        ]);

        // The paired browser journey proves real prerecorded "Hey Bean" PCM
        // releases no provider audio before confirmation and produces this
        // exact provider-final transcript. This half crosses the real durable
        // admission, Hermes interpretation, final projection, and reload path.
        $turn = $this->admit($token, $session, $turnId, $transcript);
        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertFalse($terminal->acknowledgement_required);
        $this->assertNull($terminal->acknowledgement_text);
        $this->assertNull($terminal->acknowledged_at);
        $this->assertSame($finalText, $terminal->finalAssistantMessage?->content);
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertSame($turnId, $fake->interpretationRequests[0]->stableTurnId);
        $this->assertSame($transcript, $fake->interpretationRequests[0]->transcript);
        $this->assertSame(1, $terminal->runs->count());
        $this->assertSame(1, $terminal->runs->where(
            'handler',
            HermesSemanticOperationExecutor::INTERPRETATION_HANDLER,
        )->count());
        $this->assertSame(0, $terminal->runs->where(
            'handler',
            HermesSemanticOperationExecutor::OPERATION_HANDLER,
        )->count());
        $this->assertSame(0, $terminal->runs->where(
            'handler',
            HermesSemanticOperationExecutor::COMPOSITION_HANDLER,
        )->count());
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $terminal->id)
            ->whereIn('event_type', ['semantic_acknowledgement_published', 'acknowledgement_started'])
            ->count());
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        // A browser retry after the terminal projection reuses the same stable
        // identity and cannot invoke Hermes or create another durable message.
        $this->withToken($token)->postJson(
            '/api/assistant/voice/turns',
            $this->payload($session, $turnId, $transcript),
        )->assertOk()
            ->assertJsonPath('data.turn.id', $terminal->id)
            ->assertJsonPath('data.turn.turn_id', $turnId)
            ->assertJsonPath('data.turn.state', VoiceTurnState::Completed->value)
            ->assertJsonPath('data.turn.final_text', $finalText)
            ->assertJsonPath('data.turn.acknowledgement_required', false)
            ->assertJsonPath('data.turn.acknowledgement_text', null)
            ->assertJsonCount(1, 'data.jobs');
        Queue::assertPushed(ProcessAssistantRun::class, 1);
        $this->assertSame(1, VoiceTurn::where('turn_id', $turnId)->count());
        $this->assertCount(1, $fake->interpretationRequests);
        $afterRetry = $terminal->fresh(['runs']);
        $this->assertSame(VoiceTurnState::Completed, $afterRetry->state);
        $this->assertFalse($afterRetry->acknowledgement_required);
        $this->assertNull($afterRetry->acknowledgement_text);
        $this->assertNull($afterRetry->acknowledged_at);
        $this->assertSame(1, $afterRetry->runs->count());
        $this->assertSame(1, $afterRetry->runs->where(
            'handler',
            HermesSemanticOperationExecutor::INTERPRETATION_HANDLER,
        )->count());
        $this->assertSame(0, $afterRetry->runs->where(
            'handler',
            HermesSemanticOperationExecutor::OPERATION_HANDLER,
        )->count());
        $this->assertSame(0, $afterRetry->runs->where(
            'handler',
            HermesSemanticOperationExecutor::COMPOSITION_HANDLER,
        )->count());
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $afterRetry->id)
            ->whereIn('event_type', ['semantic_acknowledgement_published', 'acknowledgement_started'])
            ->count());
        $this->assertExactlyOneAcceptedAndFinalMessage($afterRetry);

        $firstProjection = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $reloadProjection = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');

        foreach ([$firstProjection, $reloadProjection] as $projection) {
            $projectedTurn = collect($projection['turns'])->firstWhere('turn_id', $turnId);
            $projectedUser = collect($projection['messages'])->where('turn_id', $turnId)->where('role', 'user');
            $projectedFinal = collect($projection['messages'])->where('turn_id', $turnId)->where('role', 'assistant');
            $this->assertSame(VoiceTurnState::Completed->value, $projectedTurn['state'] ?? null);
            $this->assertSame($finalText, $projectedTurn['final_text'] ?? null);
            $this->assertCount(1, $projectedUser);
            $this->assertCount(1, $projectedFinal);
            $this->assertSame($finalText, $projectedFinal->first()['content'] ?? null);
        }
    }

    public function test_contextual_named_mutation_resolves_in_hermes_then_executes_once(): void
    {
        Carbon::setTestNow('2026-07-14 14:00:00', 'America/New_York');
        [$token, $session] = $this->conversation('semantic-contextual-mutation@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'I found the task “Plan the launch”.',
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll move that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find_task', 'app.task.search', [
                        'query' => 'Plan the launch',
                        'match_mode' => 'exact_title',
                        'require_unique' => true,
                        'limit' => 5,
                    ]),
                    new HermesSemanticOperation('move_task', 'app.task.update', [
                        'result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id'],
                        'due_at' => '2026-07-16T15:00:00-04:00',
                    ], ['find_task']),
                ],
            ),
        ], [
            new HermesSemanticComposition(
                'I moved “Plan the launch” to Thursday at 3 p.m.',
                false,
                false,
            ),
        ]);

        $first = $this->admit(
            $token,
            $session,
            'semantic-context-source-0001',
            'Find the task named Plan the launch.',
            ['conversation_context' => ['mode' => 'new_conversation', 'epoch' => 11]],
        );
        $this->drainTurn($first, $fake);
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$first->turn_id}/delivery", [
            'session_id' => $session->id,
            'event' => 'final_audio_started',
            'timing' => [
                'speech_item_id' => $first->turn_id.':final',
                'controller_generation' => 1,
                'purpose' => 'final',
            ],
        ])->assertOk();
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$first->turn_id}/delivery", [
            'session_id' => $session->id,
            'event' => 'playback_finished',
            'timing' => [
                'speech_item_id' => $first->turn_id.':final',
                'controller_generation' => 1,
                'purpose' => 'final',
            ],
        ])->assertOk();

        $followUp = $this->admit(
            $token,
            $session,
            'semantic-context-mutation-0002',
            'Move that task to Thursday at three.',
            ['conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 11]],
        );
        $this->drainTurn($followUp, $fake);

        $terminal = $followUp->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('2026-07-16T19:00:00+00:00', $task->fresh()->due_at?->toIso8601String());
        $this->assertSame('I moved “Plan the launch” to Thursday at 3 p.m.', $terminal->finalAssistantMessage?->content);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertCount(2, $fake->interpretationRequests);
        $authorizedConversation = data_get($fake->interpretationRequests[1]->context, 'authorized_conversation', []);
        $this->assertTrue(collect($authorizedConversation)->contains(
            fn (array $message): bool => ($message['stable_turn_id'] ?? null) === $first->turn_id
                && ($message['role'] ?? null) === 'user'
                && str_contains((string) ($message['content'] ?? ''), 'Plan the launch'),
        ));
        $this->assertTrue(collect($authorizedConversation)->contains(
            fn (array $message): bool => ($message['stable_turn_id'] ?? null) === $first->turn_id
                && ($message['role'] ?? null) === 'assistant',
        ));
        $this->assertSame($task->id, data_get(
            $this->operationRun($terminal, 'move_task')->metadata,
            'semantic_operation_receipt.data.events.0.data.task_id',
        ));
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_multiple_exact_title_matches_return_to_hermes_clarification_with_zero_speculative_writes(): void
    {
        [$token, $session] = $this->conversation('semantic-ambiguous-target@example.com');
        $first = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
            'notes' => 'Marketing launch',
        ]);
        $second = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
            'notes' => 'Product launch',
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find_task', 'app.task.search', [
                        'query' => 'Plan the launch',
                        'match_mode' => 'exact_title',
                        'require_unique' => true,
                    ]),
                    new HermesSemanticOperation('move_task', 'app.task.update', [
                        'result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id'],
                        'due_at' => '2026-07-16T15:00:00-04:00',
                    ], ['find_task']),
                ],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Which “Plan the launch” task—the marketing launch or the product launch?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('move_task', 'app.task.update', [
                    'id' => $second->id,
                    'due_at' => '2026-07-16T15:00:00-04:00',
                ])],
            ),
        ], [
            new HermesSemanticComposition('I moved the product launch task to Thursday at 3 p.m.', false, false),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-ambiguous-target-0001',
            'Move Plan the launch to Thursday at three.',
        );

        $this->drainTurn($turn, $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame(
            'Which “Plan the launch” task—the marketing launch or the product launch?',
            data_get($awaiting->metadata, 'clarification_question'),
        );
        $this->assertNull($first->fresh()->due_at);
        $this->assertNull($second->fresh()->due_at);
        $this->assertSame(0, $awaiting->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertSame('deterministic_validation_failure', data_get(
            $fake->interpretationRequests[1]->context,
            'prior_interpretation_feedback.kind',
        ));
        $this->assertStringContainsString('matched more than one resources', (string) data_get(
            $fake->interpretationRequests[1]->context,
            'prior_interpretation_feedback.detail',
        ));

        $deliveryUrl = "/api/assistant/voice/turns/{$turn->turn_id}/delivery";
        foreach (['playback_finished', 'playback_stopped'] as $event) {
            $this->withToken($token)->postJson($deliveryUrl, [
                'session_id' => $session->id,
                'event' => $event,
                'timing' => [
                    'purpose' => 'clarification',
                    'speech_item_id' => 'ambiguous-target-clarification',
                ],
            ])->assertStatus(409);
        }
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_started',
            'timing' => [
                'purpose' => 'clarification',
                'speech_item_id' => 'ambiguous-target-clarification',
            ],
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_finished',
            'timing' => [
                'purpose' => 'clarification',
                'speech_item_id' => 'ambiguous-target-clarification',
            ],
        ])->assertOk();
        $this->assertNotNull(data_get($turn->fresh()->metadata, 'clarification_deadline_started_at'));

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/clarifications", [
            'session_id' => $session->id,
            'answer' => 'The product launch task.',
            'clarification_id' => 'semantic-ambiguous-target-answer-0001',
        ])->assertOk()
            ->assertJsonPath('data.turn.turn_id', $turn->turn_id);

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertNull($first->fresh()->due_at);
        $this->assertSame('2026-07-16T19:00:00+00:00', $second->fresh()->due_at?->toIso8601String());
        $this->assertSame('I moved the product launch task to Thursday at 3 p.m.', $terminal->finalAssistantMessage?->content);
        $this->assertCount(3, $fake->interpretationRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_explicit_concrete_id_mutation_executes_without_a_search_reference(): void
    {
        [$token, $session] = $this->conversation('semantic-concrete-id@example.com');
        $selected = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $other = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('move_task', 'app.task.update', [
                    'id' => $selected->id,
                    'due_at' => '2026-07-16T15:00:00-04:00',
                ])],
            ),
        ], [
            new HermesSemanticComposition('I moved the selected launch task to Thursday at 3 p.m.', false, false),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-concrete-id-0001',
            'Move the selected launch task to Thursday at three.',
        );

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('2026-07-16T19:00:00+00:00', $selected->fresh()->due_at?->toIso8601String());
        $this->assertNull($other->fresh()->due_at);
        $this->assertSame(1, $terminal->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertSame($selected->id, data_get(
            $this->operationRun($terminal, 'move_task')->metadata,
            'semantic_operation_receipt.data.events.0.data.task_id',
        ));
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_unique_exact_result_reference_is_sealed_to_one_id_before_execution(): void
    {
        [$token, $session] = $this->conversation('semantic-unique-reference@example.com');
        $selected = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find_task', 'app.task.search', [
                        'query' => 'Plan the launch',
                        'match_mode' => 'exact_title',
                        'require_unique' => true,
                    ]),
                    new HermesSemanticOperation('move_task', 'app.task.update', [
                        'result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id'],
                        'due_at' => '2026-07-16T15:00:00-04:00',
                    ], ['find_task']),
                ],
            ),
        ], [
            new HermesSemanticComposition('I moved “Plan the launch” to Thursday at 3 p.m.', false, false),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-unique-reference-0001',
            'Move Plan the launch to Thursday at three.',
        );
        $executor = app(HermesSemanticOperationExecutor::class);

        $this->processRun($turn->runs()->sole(), $fake, $executor);

        $staged = $turn->fresh(['runs']);
        $sealedRun = $this->operationRun($staged, 'move_task');
        $sealedOperation = json_decode((string) $sealedRun->input, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($selected->id, data_get($sealedOperation, 'arguments.id'));
        $this->assertArrayNotHasKey('result_ref', $sealedOperation['arguments']);
        $this->assertSame(
            "semantic:{$session->workspace_id}:task:{$selected->id}",
            $sealedRun->resource_lock_key,
        );

        $laterDuplicate = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('2026-07-16T19:00:00+00:00', $selected->fresh()->due_at?->toIso8601String());
        $this->assertNull($laterDuplicate->fresh()->due_at);
        $this->assertSame($selected->id, data_get(
            $this->operationRun($terminal, 'move_task')->metadata,
            'semantic_operation_receipt.data.events.0.data.task_id',
        ));
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_named_task_disappearing_after_plan_seal_returns_typed_stale_receipt_to_hermes(): void
    {
        [$token, $session] = $this->conversation('semantic-stale-task-race@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $turnId = 'semantic-stale-task-race-0001';
        $hermesFinal = 'That task changed before I could move it, so I did not make the change.';
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('move_task', 'app.task.update', [
                    'id' => $task->id,
                    'due_at' => '2026-07-16T15:00:00-04:00',
                ])],
            ),
        ], [function (HermesSemanticCompositionRequest $request) use ($turnId, $hermesFinal): HermesSemanticComposition {
            $this->assertSame($turnId, $request->stableTurnId);
            $this->assertSame('Move Plan the launch to Thursday at three.', $request->transcript);
            $this->assertCount(1, $request->results);
            $result = $request->results[0];
            $this->assertSame('move_task', $result->operationId);
            $this->assertSame('app.task.update', $result->tool);
            $this->assertSame('failed', $result->status);
            $this->assertSame('stale_target', $result->data['category'] ?? null);
            $this->assertSame('target_changed_after_staging', $result->data['internal_detail'] ?? null);
            $this->assertSame('operation', $result->data['failure_scope'] ?? null);
            $this->assertFalse($result->data['side_effect_committed'] ?? true);
            $this->assertArrayNotHasKey('user_facing', $result->data);
            $this->assertArrayNotHasKey('message', $result->data);
            $this->assertArrayNotHasKey('clarification_question', $result->data);

            return new HermesSemanticComposition($hermesFinal, false, false);
        }]);
        $turn = $this->admit(
            $token,
            $session,
            $turnId,
            'Move Plan the launch to Thursday at three.',
        );
        $executor = app(HermesSemanticOperationExecutor::class);

        // Hermes selected the named task and deterministic authorization sealed
        // its concrete id before a concurrent actor removed the target.
        $this->processRun($turn->runs()->sole(), $fake, $executor);
        $sealed = $this->operationRun($turn->fresh(['runs']), 'move_task');
        $sealedOperation = json_decode((string) $sealed->input, true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($task->id, data_get($sealedOperation, 'arguments.id'));
        $task->delete();

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $operation = $this->operationRun($terminal, 'move_task');
        $receiptData = (array) data_get($operation->metadata, 'semantic_operation_receipt.data');
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::NotCommitted, $terminal->side_effect_status);
        $this->assertSame('completed', $operation->status);
        $this->assertSame('failed', data_get($operation->metadata, 'semantic_operation_receipt.status'));
        $this->assertSame('stale_target', $receiptData['category'] ?? null);
        $this->assertSame('target_changed_after_staging', $receiptData['internal_detail'] ?? null);
        $this->assertSame('operation', $receiptData['failure_scope'] ?? null);
        $this->assertArrayNotHasKey('user_facing', $receiptData);
        $this->assertArrayNotHasKey('message', $receiptData);
        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertSame(0, ActivityEvent::query()->where('event_type', 'assistant.task.updated')->count());
        $this->assertSame($hermesFinal, $terminal->finalAssistantMessage?->content);
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertSame($turnId, $fake->compositionRequests[0]->stableTurnId);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_missing_timezone_returns_machine_constraints_to_hermes_owned_clarification(): void
    {
        $cases = [
            [
                'email' => 'semantic-missing-clock-timezone@example.com',
                'turn_id' => 'semantic-missing-clock-timezone-0001',
                'transcript' => 'What time is it?',
                'operation' => new HermesSemanticOperation('clock', 'system.clock.read', ['kind' => 'time']),
                'constraint' => 'timezone_required_for_clock_read',
                'question' => 'Which timezone should I use for the current time?',
            ],
            [
                'email' => 'semantic-missing-day-timezone@example.com',
                'turn_id' => 'semantic-missing-day-timezone-0001',
                'transcript' => 'What is on my day?',
                'operation' => new HermesSemanticOperation('day', 'app.day.read', ['date' => '2026-07-14']),
                'constraint' => 'timezone_required_for_day_read',
                'question' => 'Which timezone defines your day?',
            ],
        ];

        foreach ($cases as $case) {
            [$token, $session] = $this->conversation($case['email']);
            $fake = new CompleteJourneyHermesInterpreter([
                new HermesSemanticInterpretation(
                    outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                    responseText: null,
                    clarificationQuestion: null,
                    acknowledgementText: null,
                    closeAfterResponse: false,
                    responseExpected: false,
                    operations: [$case['operation']],
                ),
                new HermesSemanticInterpretation(
                    outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                    responseText: null,
                    clarificationQuestion: $case['question'],
                    acknowledgementText: null,
                    closeAfterResponse: false,
                    responseExpected: true,
                    operations: [],
                ),
            ]);
            $turn = $this->admit(
                $token,
                $session,
                $case['turn_id'],
                $case['transcript'],
                ['timezone' => null],
            );

            $this->drainTurn($turn, $fake);

            $awaiting = $turn->fresh(['runs']);
            $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
            $this->assertSame(VoiceTurnSideEffectStatus::None, $awaiting->side_effect_status);
            $this->assertSame($case['question'], data_get($awaiting->metadata, 'clarification_question'));
            $this->assertSame(
                0,
                $awaiting->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count(),
                "{$case['constraint']} must not stage an operation run.",
            );
            $this->assertCount(2, $fake->interpretationRequests);
            $this->assertSame($case['turn_id'], $fake->interpretationRequests[0]->stableTurnId);
            $this->assertSame($case['turn_id'], $fake->interpretationRequests[1]->stableTurnId);
            $this->assertSame('deterministic_validation_failure', data_get(
                $fake->interpretationRequests[1]->context,
                'prior_interpretation_feedback.kind',
            ));
            $this->assertSame($case['constraint'], data_get(
                $fake->interpretationRequests[1]->context,
                'prior_interpretation_feedback.detail',
            ));
            $this->assertCount(0, $fake->compositionRequests, "{$case['constraint']} must return to interpretation clarification.");
            $this->assertNull($awaiting->finalAssistantMessage);
            $this->assertSame(1, VoiceTurnEvent::query()
                ->where('voice_turn_id', $awaiting->id)
                ->where('event_type', 'clarification_requested')
                ->count());
        }
    }

    public function test_mutation_cannot_supply_both_concrete_id_and_result_reference(): void
    {
        [$token, $session] = $this->conversation('semantic-conflicting-target@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find_task', 'app.task.search', [
                        'query' => 'Plan the launch',
                        'match_mode' => 'exact_title',
                        'require_unique' => true,
                    ]),
                    new HermesSemanticOperation('move_task', 'app.task.update', [
                        'id' => $task->id,
                        'result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id'],
                        'due_at' => '2026-07-16T15:00:00-04:00',
                    ], ['find_task']),
                ],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Should I move the task named “Plan the launch”?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-conflicting-target-0001',
            'Move Plan the launch to Thursday at three.',
        );

        $this->drainTurn($turn, $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertNull($task->fresh()->due_at);
        $this->assertSame(0, $awaiting->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertStringContainsString('may not supply both id and result_ref', (string) data_get(
            $fake->interpretationRequests[1]->context,
            'prior_interpretation_feedback.detail',
        ));
        $this->assertSame($turn->turn_id, $awaiting->turn_id);
    }

    public function test_fractional_second_admission_preserves_the_full_interpretation_repair_deadline(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00.999500', 'America/New_York'));
        [$token, $session] = $this->conversation('semantic-fractional-deadline@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Fractional boundary task',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find_task', 'app.task.search', [
                        'query' => 'Fractional boundary task',
                        'match_mode' => 'exact_title',
                        'require_unique' => true,
                    ]),
                    new HermesSemanticOperation('invalid_move', 'app.task.update', [
                        'id' => $task->id,
                        'result_ref' => ['operation_id' => 'find_task', 'path' => 'unique_id'],
                        'due_at' => '2026-07-16T15:00:00-04:00',
                    ], ['find_task']),
                ],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Should I move the fractional boundary task?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-fractional-deadline-0001',
            'Move the fractional boundary task.',
        );
        $interpretationRun = $turn->runs()->sole();

        $this->assertSame('999500', $interpretationRun->created_at?->format('u'));
        $this->assertSame('999500', $interpretationRun->hard_deadline_at?->format('u'));
        $this->assertSame(
            2000.0,
            $interpretationRun->created_at?->diffInMilliseconds($interpretationRun->hard_deadline_at, false),
        );
        Queue::assertPushed(
            EnforceBrowserVoiceTurnDeadline::class,
            function (EnforceBrowserVoiceTurnDeadline $job) use ($turn, $interpretationRun): bool {
                $payloadDeadline = Carbon::parse($job->deadlineAt);
                $queueAt = Carbon::parse($job->delay);

                return $job->voiceTurnId === $turn->id
                    && $payloadDeadline->equalTo($interpretationRun->hard_deadline_at)
                    && $payloadDeadline->format('u') === '999500'
                    && $queueAt->greaterThan($payloadDeadline)
                    && $queueAt->format('u') === '000000';
            },
        );

        // Defensive recovery keeps the exact payload and schedules another
        // one-shot enforcer if a queue ever delivers the job before the true
        // microsecond cutoff.
        $scheduledDeadlineJobs = Queue::pushed(EnforceBrowserVoiceTurnDeadline::class)->count();
        (new EnforceBrowserVoiceTurnDeadline(
            $turn->id,
            $interpretationRun->hard_deadline_at?->format('Y-m-d\TH:i:s.uP') ?? '',
        ))->handle(app(VoiceTurnLifecycleService::class));
        $this->assertCount(
            $scheduledDeadlineJobs + 1,
            Queue::pushed(EnforceBrowserVoiceTurnDeadline::class),
        );
        $this->assertSame(VoiceTurnState::Accepted, $turn->fresh()->state);

        $this->drainTurn($turn, $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame('Should I move the fractional boundary task?', data_get($awaiting->metadata, 'clarification_question'));
        $this->assertSame(1, $awaiting->retry_count);
        $this->assertCount(2, $fake->interpretationRequests);
        $this->assertSame(0, $awaiting->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertNull($task->fresh()->due_at);
    }

    public function test_temporal_weather_uses_only_hermes_structured_arguments_on_the_typed_provider_path(): void
    {
        Carbon::setTestNow('2026-07-14 10:00:00', 'America/New_York');
        [$token, $session] = $this->conversation('semantic-structured-weather@example.com');
        $arguments = [
            'kind' => 'forecast',
            'query' => 'Return the selected forecast.',
            'location' => 'Orlando, Florida',
            'date' => '2026-07-15',
            'time' => '17:00',
            'units' => 'imperial',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(function (ConversationSession $actualSession, array $actualArguments) use ($session, $arguments): bool {
                $this->assertTrue($actualSession->is($session));
                $this->assertSame($arguments, $actualArguments);

                return true;
            })
            ->andReturn([
                'ok' => true,
                'kind' => 'forecast',
                'provider' => 'open_meteo',
                'location' => 'Orlando, Florida',
                'date' => '2026-07-15',
                'time' => '17:00',
                'temperature_f' => 91,
                'summary' => 'Partly cloudy',
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('forecast', 'external.lookup', $arguments)],
            ),
        ], [
            new HermesSemanticComposition(
                'Tomorrow at 5 p.m. in Orlando, it should be about 91 degrees and partly cloudy.',
                false,
                false,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-weather-structured-0001',
            'What will the weather be in Orlando tomorrow at five?',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(
            'Tomorrow at 5 p.m. in Orlando, it should be about 91 degrees and partly cloudy.',
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertCount(1, $fake->compositionRequests);
        $result = $fake->compositionRequests[0]->results[0];
        $this->assertSame('forecast', $result->data['kind']);
        $this->assertSame('2026-07-15', $result->data['date']);
        $this->assertSame('17:00', $result->data['time']);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_trusted_location_is_semantic_input_and_must_be_copied_into_validated_weather_arguments(): void
    {
        [$token, $session] = $this->conversation('semantic-trusted-location@example.com');
        $structuredArguments = [
            'kind' => 'forecast',
            'query' => 'Return the selected forecast.',
            'latitude' => 28.5383,
            'longitude' => -81.3792,
            'date' => '2026-07-15',
            'units' => 'imperial',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(function (ConversationSession $actualSession, array $actualArguments) use ($session, $structuredArguments): bool {
                $this->assertTrue($actualSession->is($session));
                $this->assertSame($structuredArguments, $actualArguments);
                $this->assertArrayNotHasKey('location', $actualArguments);

                return true;
            })
            ->andReturn([
                'ok' => true,
                'provider' => 'open_meteo',
                'kind' => 'forecast',
                'date' => '2026-07-15',
                'latitude' => 28.5383,
                'longitude' => -81.3792,
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('forecast', 'external.lookup', [
                    'kind' => 'forecast',
                    'query' => 'Return the selected forecast.',
                    'date' => '2026-07-15',
                    'target_date' => 'tomorrow',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('forecast', 'external.lookup', $structuredArguments)],
            ),
        ], [
            new HermesSemanticComposition(
                'Tomorrow at your current location, the forecast is ready.',
                false,
                false,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-trusted-location-0001',
            'What will the weather be here tomorrow?',
            ['location_context' => [
                'label' => 'Orlando, Florida',
                'latitude' => 28.5383,
                'longitude' => -81.3792,
                'is_local' => true,
                'source' => 'browser_geolocation',
            ]],
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('Tomorrow at your current location, the forecast is ready.', $terminal->finalAssistantMessage?->content);
        $this->assertCount(2, $fake->interpretationRequests);
        $this->assertSame([
            'label' => 'Orlando, Florida',
            'latitude' => 28.5383,
            'longitude' => -81.3792,
            'is_local' => true,
        ], data_get($fake->interpretationRequests[0]->context, 'trusted_location'));
        $this->assertSame(
            'deterministic_validation_failure',
            data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.kind'),
        );
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_web_topic_selected_by_hermes_reaches_the_typed_provider_without_reclassification(): void
    {
        [$token, $session] = $this->conversation('semantic-web-topic@example.com');
        $arguments = [
            'kind' => 'web',
            'query' => 'Apple stock earnings announcement',
            'topic' => 'news',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(fn (ConversationSession $actualSession, array $actualArguments): bool => $actualSession->is($session)
                && $actualArguments === $arguments)
            ->andReturn([
                'ok' => true,
                'provider' => 'tavily_search',
                'query' => 'Apple stock earnings announcement',
                'topic' => 'news',
                'text' => 'A receipt-backed news result.',
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('web', 'external.lookup', $arguments)],
            ),
        ], [
            new HermesSemanticComposition('Here’s the latest earnings announcement.', false, false),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-web-topic-0001',
            'What is the latest Apple earnings announcement?',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('Here’s the latest earnings announcement.', $terminal->finalAssistantMessage?->content);
        $this->assertSame('news', $fake->compositionRequests[0]->results[0]->data['topic']);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_named_place_uses_only_hermes_structured_search_and_location_on_the_typed_provider_path(): void
    {
        [$token, $session] = $this->conversation('semantic-structured-place@example.com');
        $arguments = [
            'kind' => 'places',
            'query' => 'The UPS Store',
            'location' => 'Orlando, Florida',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(function (ConversationSession $actualSession, array $actualArguments) use ($session, $arguments): bool {
                $this->assertTrue($actualSession->is($session));
                $this->assertSame($arguments, $actualArguments);

                return true;
            })
            ->andReturn([
                'ok' => true,
                'provider' => 'google_places',
                'query' => 'The UPS Store',
                'location' => 'Orlando, Florida',
                'places' => [[
                    'name' => 'The UPS Store',
                    'address' => '123 Main St, Orlando, FL 32801',
                ]],
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('place', 'external.lookup', $arguments)],
            ),
        ], [
            new HermesSemanticComposition(
                'The nearest UPS Store I found is at 123 Main Street in Orlando.',
                false,
                false,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-place-structured-0001',
            'Where is the nearest UPS Store in Orlando?',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(
            'The nearest UPS Store I found is at 123 Main Street in Orlando.',
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertSame('The UPS Store', $fake->compositionRequests[0]->results[0]->data['query']);
        $this->assertSame('Orlando, Florida', $fake->compositionRequests[0]->results[0]->data['location']);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_ambiguous_provider_location_returns_candidates_to_hermes_without_guessing(): void
    {
        [$token, $session] = $this->conversation('semantic-ambiguous-provider-location@example.com');
        $arguments = [
            'kind' => 'weather',
            'query' => 'Return current conditions.',
            'location' => 'Springfield',
            'units' => 'imperial',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(fn (ConversationSession $actualSession, array $actualArguments): bool => $actualSession->is($session)
                && $actualArguments === $arguments)
            ->andReturn([
                'ok' => false,
                'provider' => 'open_meteo',
                'error_code' => 'weather_location_ambiguous',
                'message' => 'The supplied location matched multiple places.',
                'candidates' => [
                    ['name' => 'Springfield, Illinois, US', 'latitude' => 39.8017, 'longitude' => -89.6436],
                    ['name' => 'Springfield, Missouri, US', 'latitude' => 37.2089, 'longitude' => -93.2923],
                ],
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('weather', 'external.lookup', $arguments)],
            ),
        ], [
            new HermesSemanticComposition(
                'Which Springfield do you mean—Illinois or Missouri?',
                false,
                true,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-ambiguous-provider-location-0001',
            'What is the weather in Springfield?',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(
            'Which Springfield do you mean—Illinois or Missouri?',
            $terminal->finalAssistantMessage?->content,
        );
        $receipt = $fake->compositionRequests[0]->results[0];
        $this->assertSame('weather_location_ambiguous', $receipt->data['error_code']);
        $this->assertCount(2, $receipt->data['candidates']);
        $this->assertTrue($fake->compositionRequests[0]->interpretation->outcome === HermesSemanticInterpretation::OUTCOME_EXECUTE);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_ambiguous_named_place_candidates_reach_hermes_for_one_natural_follow_up(): void
    {
        [$token, $session] = $this->conversation('semantic-ambiguous-named-place@example.com');
        $arguments = [
            'kind' => 'places',
            'query' => 'The UPS Store',
            'location' => 'Springfield',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(fn (ConversationSession $actualSession, array $actualArguments): bool => $actualSession->is($session)
                && $actualArguments === $arguments)
            ->andReturn([
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'google_places',
                'error_code' => 'places_location_ambiguous',
                'fallback_allowed' => false,
                'candidates' => [
                    ['formatted_address' => 'Springfield, IL, USA', 'lat' => 39.8017, 'lon' => -89.6436],
                    ['formatted_address' => 'Springfield, MO, USA', 'lat' => 37.2089, 'lon' => -93.2923],
                ],
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('place', 'external.lookup', $arguments)],
            ),
        ], [
            new HermesSemanticComposition(
                'Which Springfield should I search near—Illinois or Missouri?',
                false,
                true,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-ambiguous-named-place-0001',
            'Find the nearest UPS Store in Springfield.',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(
            'Which Springfield should I search near—Illinois or Missouri?',
            $terminal->finalAssistantMessage?->content,
        );
        $receipt = $fake->compositionRequests[0]->results[0];
        $this->assertSame('completed', $receipt->status);
        $this->assertSame('google_places', $receipt->data['provider']);
        $this->assertSame('places_location_ambiguous', $receipt->data['error_code']);
        $this->assertFalse($receipt->data['fallback_allowed']);
        $this->assertCount(2, $receipt->data['candidates']);
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_scoped_places_failure_reaches_hermes_for_one_natural_retry_offer(): void
    {
        [$token, $session] = $this->conversation('semantic-scoped-places-failure@example.com');
        $arguments = [
            'kind' => 'places',
            'query' => 'The UPS Store',
            'location' => 'Nowhere, Florida',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(fn (ConversationSession $actualSession, array $actualArguments): bool => $actualSession->is($session)
                && $actualArguments === $arguments)
            ->andReturn([
                'ok' => false,
                'tool' => 'external_lookup',
                'provider' => 'places',
                'kind' => 'places',
                'query' => 'The UPS Store',
                'location' => 'Nowhere, Florida',
                'error_code' => 'places_not_found',
                'fallback_allowed' => false,
                'provider_failures' => [
                    ['provider' => 'google_places', 'error_code' => 'places_location_not_found'],
                    ['provider' => 'osm_places', 'error_code' => 'osm_location_not_found'],
                ],
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('place', 'external.lookup', $arguments)],
            ),
        ], [
            new HermesSemanticComposition(
                'I couldn’t find that store near Nowhere, Florida. Should I try another city or ZIP code?',
                false,
                true,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-scoped-places-failure-0001',
            'Find the nearest UPS Store in Nowhere, Florida.',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(
            'I couldn’t find that store near Nowhere, Florida. Should I try another city or ZIP code?',
            $terminal->finalAssistantMessage?->content,
        );
        $receipt = $fake->compositionRequests[0]->results[0];
        $this->assertSame('completed', $receipt->status);
        $this->assertSame('places', $receipt->data['provider']);
        $this->assertSame('places_not_found', $receipt->data['error_code']);
        $this->assertFalse($receipt->data['fallback_allowed']);
        $this->assertCount(2, $receipt->data['provider_failures']);
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_ambiguous_place_output_is_rejected_then_clarified_on_the_same_semantic_path(): void
    {
        [$token, $session] = $this->conversation('semantic-place-clarification@example.com');
        $structuredArguments = [
            'kind' => 'places',
            'query' => 'The UPS Store',
            'location' => 'Orlando, Florida',
        ];
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->withArgs(fn (ConversationSession $actualSession, array $actualArguments): bool => $actualSession->is($session)
                && $actualArguments === $structuredArguments)
            ->andReturn([
                'ok' => true,
                'provider' => 'google_places',
                'query' => 'The UPS Store',
                'location' => 'Orlando, Florida',
                'places' => [[
                    'name' => 'The UPS Store',
                    'address' => '123 Main St, Orlando, FL 32801',
                ]],
            ]);
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll find that store.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('place', 'external.lookup', [
                    'kind' => 'places',
                    'query' => 'Find the nearest The UPS Store',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Which city or ZIP code should I search near?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('place', 'external.lookup', $structuredArguments)],
            ),
        ], [
            new HermesSemanticComposition(
                'The nearest UPS Store I found is at 123 Main Street in Orlando.',
                false,
                false,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-place-clarification-0001',
            'Find the nearest UPS Store.',
        );

        $this->drainTurn($turn, $fake, $executor);

        $awaiting = $turn->fresh();
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame('Which city or ZIP code should I search near?', data_get($awaiting->metadata, 'clarification_question'));
        $this->assertFalse($awaiting->acknowledgement_required);
        $this->assertNull($awaiting->acknowledgement_text);
        $this->assertSame(0, VoiceTurnEvent::where('voice_turn_id', $awaiting->id)
            ->where('event_type', 'semantic_acknowledgement_published')
            ->count());
        $this->assertSame(
            'deterministic_validation_failure',
            data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.kind'),
        );
        $this->assertCount(2, $fake->interpretationRequests);

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/clarifications", [
            'session_id' => $session->id,
            'answer' => 'In Orlando, Florida.',
            'clarification_id' => 'semantic-place-answer-0001',
        ])->assertOk()
            ->assertJsonPath('data.turn.turn_id', $turn->turn_id);

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(
            'The nearest UPS Store I found is at 123 Main Street in Orlando.',
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertCount(3, $fake->interpretationRequests);
        $this->assertSame(
            'In Orlando, Florida.',
            data_get($fake->interpretationRequests[2]->context, 'logical_request.clarification_history.0.answer'),
        );
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_multi_clause_plan_materializes_separate_jobs_and_publishes_one_combined_final(): void
    {
        [$token, $session] = $this->conversation('semantic-multi-clause@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll set those up.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('task', 'app.task.create', [
                        ...$this->taskCreateDefaults(),
                        'title' => 'Send the launch brief',
                        'type' => 'todo',
                        'due_at' => '2026-07-15T09:00:00-04:00',
                    ]),
                    new HermesSemanticOperation('reminder', 'app.reminder.create', [
                        ...$this->reminderCreateDefaults(),
                        'title' => 'Review launch metrics',
                        'remind_at' => '2026-07-15T16:00:00-04:00',
                    ]),
                    new HermesSemanticOperation('meeting', 'app.calendar.create', [
                        ...$this->calendarCreateDefaults(),
                        'title' => 'Launch review',
                        'starts_at' => '2026-07-16T14:00:00-04:00',
                        'ends_at' => '2026-07-16T14:30:00-04:00',
                    ]),
                ],
            ),
        ], [
            new HermesSemanticComposition(
                'I created the launch task, the metrics reminder, and the Thursday launch-review meeting.',
                false,
                false,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-multi-clause-0001',
            'Create a launch task for tomorrow morning, remind me to review metrics at four, and add a launch review Thursday at two.',
        );

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertDatabaseHas('tasks', ['workspace_id' => $session->workspace_id, 'title' => 'Send the launch brief']);
        $this->assertDatabaseHas('reminders', ['workspace_id' => $session->workspace_id, 'title' => 'Review launch metrics']);
        $this->assertDatabaseHas('calendar_events', ['workspace_id' => $session->workspace_id, 'title' => 'Launch review']);
        $this->assertSame(3, $terminal->runs->filter(
            fn (AssistantRun $run): bool => data_get($run->metadata, 'role') === 'semantic_operation',
        )->count());
        $this->assertSame(1, $terminal->runs->filter(
            fn (AssistantRun $run): bool => data_get($run->metadata, 'role') === 'semantic_composition',
        )->count());
        $this->assertCount(3, $fake->compositionRequests[0]->results);
        $this->assertSame(
            ['completed', 'completed', 'completed'],
            collect($fake->compositionRequests[0]->results)->pluck('status')->all(),
        );
        $this->assertSame(
            'I created the launch task, the metrics reminder, and the Thursday launch-review meeting.',
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_spoken_semantic_cancellation_targets_a_request_awaiting_hermes_clarification(): void
    {
        [$token, $session] = $this->conversation('semantic-cancel-clarification@example.com');
        $clarificationFake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'When should I remind you?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
        ]);
        $request = $this->admit(
            $token,
            $session,
            'semantic-cancel-clarification-target-0001',
            'Remind me to file the report.',
        );
        $this->drainTurn($request, $clarificationFake);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $request->fresh()->state);
        $this->assertSame('When should I remind you?', data_get($request->fresh()->metadata, 'clarification_question'));

        $cancelFake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('cancel_request', 'voice.work.cancel', [
                    'target_turn_id' => $request->turn_id,
                ])],
            ),
        ], [new HermesSemanticComposition('I canceled that reminder request.', false, false)]);
        $cancellation = $this->admit(
            $token,
            $session,
            'semantic-cancel-clarification-command-0001',
            'Cancel that reminder request.',
        );
        $this->drainTurn($cancellation, $cancelFake);

        $workFact = collect($cancelFake->interpretationRequests[0]->context['recent_voice_turns'])
            ->firstWhere('stable_turn_id', $request->turn_id);
        $this->assertIsArray($workFact);
        $this->assertSame(VoiceTurnState::AwaitingClarification->value, $workFact['state']);
        $this->assertTrue($workFact['active']);
        $result = $cancelFake->compositionRequests[0]->results[0]->data;
        $this->assertTrue($result['canceled']);
        $this->assertFalse($result['completed_before_cancellation']);
        $this->assertFalse($result['partially_committed']);
        $this->assertSame([], $result['committed_operation_ids']);
        $this->assertSame([$request->turn_id], $result['canceled_turn_ids']);
        $this->assertSame(VoiceTurnState::Canceled, $request->fresh()->state);
        $this->assertNull($request->fresh(['finalAssistantMessage'])->finalAssistantMessage);
        $terminalCancellation = $cancellation->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminalCancellation->state);
        $this->assertSame('I canceled that reminder request.', $terminalCancellation->finalAssistantMessage?->content);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminalCancellation);
    }

    public function test_semantic_cancel_receipt_reports_authoritative_partial_commit_facts_to_hermes(): void
    {
        [$token, $session] = $this->conversation('semantic-partial-cancel-receipt@example.com');
        $targetFake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create both tasks.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('committed_task', 'app.task.create', [
                        ...$this->taskCreateDefaults(),
                        'title' => 'Committed before semantic cancellation',
                        'type' => 'todo',
                    ]),
                    new HermesSemanticOperation('pending_task', 'app.task.create', [
                        ...$this->taskCreateDefaults(),
                        'title' => 'Pending at semantic cancellation',
                        'type' => 'todo',
                    ]),
                ],
            ),
        ]);
        $target = $this->admit(
            $token,
            $session,
            'semantic-partial-cancel-target-0001',
            'Create two launch tasks.',
        );
        $executor = app(HermesSemanticOperationExecutor::class);
        $this->processRun($target->runs()->sole(), $targetFake, $executor);
        $staged = $target->fresh(['runs']);
        $committedRun = $this->operationRun($staged, 'committed_task');
        $pendingRun = $this->operationRun($staged, 'pending_task');
        $this->processRun($committedRun, $targetFake, $executor);
        $this->assertSame('completed', $committedRun->fresh()->status);
        $this->assertSame('queued', $pendingRun->fresh()->status);

        $cancelFake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('cancel_request', 'voice.work.cancel', [
                    'target_turn_id' => $target->turn_id,
                ])],
            ),
        ], [function (HermesSemanticCompositionRequest $request) use ($target): HermesSemanticComposition {
            $data = $request->results[0]->data;
            $this->assertTrue($data['canceled']);
            $this->assertFalse($data['completed_before_cancellation']);
            $this->assertTrue($data['partially_committed']);
            $this->assertSame(['committed_task'], $data['committed_operation_ids']);
            $this->assertSame([$target->turn_id], $data['canceled_turn_ids']);
            $this->assertSame(VoiceTurnState::Canceled->value, $data['target_outcomes'][0]['state']);
            $this->assertSame(['committed_task'], $data['target_outcomes'][0]['committed_operation_ids']);

            return new HermesSemanticComposition(
                'I canceled the remaining work. The first task was already created, so it remains.',
                false,
                false,
            );
        }]);
        $cancellation = $this->admit(
            $token,
            $session,
            'semantic-partial-cancel-command-0001',
            'Cancel that task request.',
        );
        $this->drainTurn($cancellation, $cancelFake, $executor);

        $canceledTarget = $target->fresh(['runs', 'finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Canceled, $canceledTarget->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $canceledTarget->side_effect_status);
        $this->assertNull($canceledTarget->finalAssistantMessage);
        $this->assertSame('completed', $committedRun->fresh()->status);
        $this->assertSame('cancelled', $pendingRun->fresh()->status);
        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Committed before semantic cancellation',
        ]);
        $this->assertDatabaseMissing('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Pending at semantic cancellation',
        ]);
        $terminalCancellation = $cancellation->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminalCancellation->state);
        $this->assertSame(
            'I canceled the remaining work. The first task was already created, so it remains.',
            $terminalCancellation->finalAssistantMessage?->content,
        );
        $this->assertExactlyOneAcceptedAndFinalMessage($terminalCancellation);
    }

    public function test_semantic_cancel_receipt_reports_work_completed_before_cancellation(): void
    {
        [$token, $session] = $this->conversation('semantic-completed-cancel-receipt@example.com');
        $targetFake = $this->singleTaskCreationInterpreter(
            'Completed before semantic cancellation',
            'I created the task.',
        );
        $target = $this->admit(
            $token,
            $session,
            'semantic-completed-cancel-target-0001',
            'Create the completed task.',
        );
        $this->drainTurn($target, $targetFake);
        $this->assertSame(VoiceTurnState::Completed, $target->fresh()->state);

        $cancelFake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('cancel_request', 'voice.work.cancel', [
                    'target_turn_id' => $target->turn_id,
                ])],
            ),
        ], [function (HermesSemanticCompositionRequest $request): HermesSemanticComposition {
            $data = $request->results[0]->data;
            $this->assertFalse($data['canceled']);
            $this->assertTrue($data['completed_before_cancellation']);
            $this->assertFalse($data['partially_committed']);
            $this->assertSame(['create_task'], $data['committed_operation_ids']);
            $this->assertSame([], $data['canceled_turn_ids']);

            return new HermesSemanticComposition(
                'That task had already been created before I received the cancellation, so it remains.',
                false,
                false,
            );
        }]);
        $cancellation = $this->admit(
            $token,
            $session,
            'semantic-completed-cancel-command-0001',
            'Cancel that task request.',
        );
        $this->drainTurn($cancellation, $cancelFake);

        $this->assertSame(VoiceTurnState::Completed, $target->fresh()->state);
        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Completed before semantic cancellation',
        ]);
        $terminalCancellation = $cancellation->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminalCancellation->state);
        $this->assertSame(
            'That task had already been created before I received the cancellation, so it remains.',
            $terminalCancellation->finalAssistantMessage?->content,
        );
        $this->assertExactlyOneAcceptedAndFinalMessage($terminalCancellation);
    }

    public function test_job_cancellation_after_a_committed_receipt_preserves_and_reports_the_write(): void
    {
        [$token, $session] = $this->conversation('semantic-post-commit-cancel@example.com');
        $fake = $this->singleTaskCreationInterpreter(
            'Create the committed cancellation task.',
            'The task was already created before cancellation reached it, so it remains in your task list.',
        );
        $turn = $this->admit(
            $token,
            $session,
            'semantic-post-commit-cancel-0001',
            'Create a task called Committed cancellation task.',
        );
        $executor = app(HermesSemanticOperationExecutor::class);
        $this->processRun($turn->runs()->sole(), $fake, $executor);
        $operation = $this->operationRun($turn->fresh(['runs']), 'create_task');

        $this->assertTrue(app(VoiceTurnLifecycleService::class)->claimJobExecution($operation));
        $receipt = $executor->executeRun($turn->fresh(), $operation->fresh());
        $this->assertTrue((bool) ($receipt['side_effect_committed'] ?? false));
        $this->assertSame('running', $operation->fresh()->status);

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $session->id,
            'job_id' => $operation->id,
            'reason' => 'user_requested_after_commit',
        ])->assertOk()
            ->assertJsonPath('data.cancellation.canceled', false)
            ->assertJsonPath('data.cancellation.completed_before_cancellation', true)
            ->assertJsonPath('data.cancellation.partially_committed', false)
            ->assertJsonPath('data.turn.state', VoiceTurnState::Running->value)
            ->assertJsonPath('data.turn.side_effect_status', VoiceTurnSideEffectStatus::Committed->value);

        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Create the committed cancellation task.',
        ]);
        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertStringContainsString('already created', (string) $terminal->finalAssistantMessage?->content);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_turn_cancellation_after_claim_prevents_the_paused_worker_from_executing_the_write(): void
    {
        [$token, $session] = $this->conversation('semantic-cancel-after-claim@example.com');
        $fake = $this->singleTaskCreationInterpreter(
            'Never create after cancellation',
            'I created the task.',
        );
        $turn = $this->admit(
            $token,
            $session,
            'semantic-cancel-after-claim-0001',
            'Create a task called Never create after cancellation.',
        );
        $executor = app(HermesSemanticOperationExecutor::class);
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $this->processRun($turn->runs()->sole(), $fake, $executor);
        $operation = $this->operationRun($turn->fresh(['runs']), 'create_task');

        // Model the worker pausing immediately after its durable claim. The
        // cancellation must win before the typed execution boundary resumes.
        $this->assertTrue($lifecycle->claimJobExecution($operation));
        $this->assertSame('running', $operation->fresh()->status);

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $session->id,
            'turn_id' => $turn->turn_id,
            'reason' => 'user_requested_before_execution',
        ])->assertOk()
            ->assertJsonPath('data.cancellation.canceled', true)
            ->assertJsonPath('data.cancellation.completed_before_cancellation', false)
            ->assertJsonPath('data.cancellation.partially_committed', false)
            ->assertJsonPath('data.turn.state', VoiceTurnState::Canceled->value)
            ->assertJsonPath('data.turn.visible_in_chat', false);

        try {
            $executor->executeRun($turn->fresh(), $operation->fresh());
            $this->fail('A canceled claimed job must not cross the typed execution boundary.');
        } catch (VoiceTurnConflictException $exception) {
            $this->assertSame('That voice job is no longer active for execution.', $exception->getMessage());
        }

        // A duplicate queue delivery after reload/recovery is also inert.
        $this->processRun($operation->fresh(), $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Canceled, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::None, $terminal->side_effect_status);
        $this->assertNull($terminal->finalAssistantMessage);
        $this->assertTrue($terminal->runs->contains(
            fn (AssistantRun $run): bool => $run->id === $operation->id && $run->status === 'cancelled',
        ));
        $this->assertFalse($terminal->runs->contains(
            fn (AssistantRun $run): bool => in_array($run->status, ['queued', 'running', 'finalizing'], true),
        ));
        $this->assertNull(data_get($operation->fresh()->metadata, 'semantic_operation_receipt'));
        $this->assertDatabaseMissing('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Never create after cancellation',
        ]);
        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'turn_canceled')
            ->count());
        $this->assertSame(1, ConversationMessage::query()
            ->where('conversation_session_id', $session->id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'user')
            ->count());
        $this->assertSame(0, ConversationMessage::query()
            ->where('conversation_session_id', $session->id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'assistant')
            ->count());
    }

    public function test_external_provider_releases_lifecycle_locks_so_cancellation_wins_and_discards_late_output(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00', 'America/New_York');
        [$token, $session] = $this->conversation('semantic-external-cancel-inflight@example.com');
        $turn = null;
        $baselineTransactionLevel = DB::connection()->transactionLevel();
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->andReturnUsing(function (
                ConversationSession $actualSession,
                array $arguments,
                Carbon $hardDeadlineAt,
            ) use (&$turn, $token, $session, $baselineTransactionLevel): array {
                $this->assertTrue($actualSession->is($session));
                $this->assertSame('web', $arguments['kind']);
                $this->assertSame(
                    $baselineTransactionLevel,
                    DB::connection()->transactionLevel(),
                    'Provider I/O must run after the lifecycle transaction releases its row locks.',
                );
                $this->assertInstanceOf(VoiceTurn::class, $turn);
                $operation = $this->operationRun($turn->fresh(['runs']), 'lookup');
                $this->assertTrue($hardDeadlineAt->equalTo($operation->hard_deadline_at));
                $this->assertSame('running', $operation->status);

                $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
                    'session_id' => $session->id,
                    'turn_id' => $turn->turn_id,
                    'reason' => 'user_requested_while_provider_running',
                ])->assertOk()
                    ->assertJsonPath('data.cancellation.canceled', true)
                    ->assertJsonPath('data.cancellation.completed_before_cancellation', false)
                    ->assertJsonPath('data.turn.state', VoiceTurnState::Canceled->value);

                $this->assertSame('cancelled', $operation->fresh()->status);

                return [
                    'ok' => true,
                    'provider' => 'late_test_provider',
                    'text' => 'This late provider output must be discarded.',
                ];
            });
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('lookup', 'external.lookup', [
                    'kind' => 'web',
                    'query' => 'Return cancellation race evidence.',
                    'topic' => 'general',
                ])],
            ),
        ], [
            new HermesSemanticComposition('This late provider output must never be composed.', false, false),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-external-cancel-inflight-0001',
            'Look up cancellation race evidence.',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $operation = $this->operationRun($terminal, 'lookup');
        $this->assertSame(VoiceTurnState::Canceled, $terminal->state);
        $this->assertSame('cancelled', $operation->status);
        $this->assertNull(data_get($operation->metadata, 'semantic_operation_receipt'));
        $this->assertNull($terminal->finalAssistantMessage);
        $this->assertCount(0, $fake->compositionRequests);
        $this->assertSame(1, ConversationMessage::query()
            ->where('conversation_session_id', $session->id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'user')
            ->count());
        $this->assertSame(0, ConversationMessage::query()
            ->where('conversation_session_id', $session->id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'assistant')
            ->count());
    }

    public function test_external_provider_releases_lifecycle_locks_so_deadline_wins_and_discards_late_output(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00.250000', 'America/New_York'));
        [$token, $session] = $this->conversation('semantic-external-deadline-inflight@example.com');
        $turn = null;
        $baselineTransactionLevel = DB::connection()->transactionLevel();
        $lookup = Mockery::mock(LiveLookupService::class);
        $lookup->shouldReceive('lookupTyped')
            ->once()
            ->andReturnUsing(function (
                ConversationSession $actualSession,
                array $arguments,
                Carbon $hardDeadlineAt,
            ) use (&$turn, $session, $baselineTransactionLevel): array {
                $this->assertTrue($actualSession->is($session));
                $this->assertSame('general', $arguments['kind']);
                $this->assertSame(
                    $baselineTransactionLevel,
                    DB::connection()->transactionLevel(),
                    'Deadline enforcement must be able to acquire lifecycle locks while provider I/O is running.',
                );
                $this->assertInstanceOf(VoiceTurn::class, $turn);
                $operation = $this->operationRun($turn->fresh(['runs']), 'lookup');
                $this->assertTrue($hardDeadlineAt->equalTo($operation->hard_deadline_at));

                Carbon::setTestNow($hardDeadlineAt);
                $this->assertSame(
                    2,
                    app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id),
                    'The external operation and its required composition must terminalize while the provider is still in flight.',
                );
                $this->assertSame('failed', $operation->fresh()->status);
                $this->assertSame(VoiceTurnState::Failed, $turn->fresh()->state);

                return [
                    'ok' => true,
                    'provider' => 'late_test_provider',
                    'text' => 'This late provider output must be discarded.',
                ];
            });
        $this->app->instance(LiveLookupService::class, $lookup);
        $executor = app(HermesSemanticOperationExecutor::class);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('lookup', 'external.lookup', [
                    'kind' => 'general',
                    'query' => 'Return deadline race evidence.',
                    'topic' => 'general',
                ])],
            ),
        ], [
            new HermesSemanticComposition('This late provider output must never be composed.', false, false),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-external-deadline-inflight-0001',
            'Look up deadline race evidence.',
        );

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $operation = $this->operationRun($terminal, 'lookup');
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame('failed', $operation->status);
        $this->assertSame(
            'external_hard_deadline_timeout',
            data_get($operation->metadata, 'semantic_operation_receipt.data.category'),
        );
        $this->assertSame('failed', data_get($operation->metadata, 'semantic_operation_receipt.status'));
        $this->assertNotSame(
            'This late provider output must be discarded.',
            data_get($operation->metadata, 'semantic_operation_receipt.data.text'),
        );
        $this->assertCount(0, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        $this->processRun($operation, $fake, $executor);
        $this->assertExactlyOneAcceptedAndFinalMessage($turn->fresh());
        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'turn_failed')
            ->count());
    }

    public function test_typed_lane_deadlines_terminalize_a_mixed_plan_by_the_eight_second_bound(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00', 'America/New_York');
        [$token, $session] = $this->conversation('semantic-lane-deadlines@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('clock', 'system.clock.read', ['kind' => 'time']),
                    new HermesSemanticOperation('task', 'app.task.create', [
                        ...$this->taskCreateDefaults(),
                        'title' => 'Never created after lane deadline',
                        'type' => 'todo',
                    ]),
                    new HermesSemanticOperation('web', 'external.lookup', [
                        'kind' => 'web',
                        'query' => 'Bean deadline evidence',
                        'topic' => 'general',
                    ]),
                ],
            ),
        ], [
            new HermesSemanticComposition(
                'I could not finish the read, write, or lookup before their deadlines. Would you like me to try again?',
                false,
                false,
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-lane-deadlines-0001',
            'Tell me the time, create a deadline task, and look up Bean deadline evidence.',
        );
        $interpretation = $turn->runs()->sole();
        $origin = $interpretation->created_at?->copy();
        $this->assertNotNull($origin);
        $this->processRun($interpretation, $fake);

        $operationRuns = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)
            ->get()
            ->keyBy(fn (AssistantRun $run): string => (string) data_get($run->metadata, 'semantic_operation_id'));
        $read = $operationRuns->get('clock');
        $write = $operationRuns->get('task');
        $external = $operationRuns->get('web');
        $this->assertInstanceOf(AssistantRun::class, $read);
        $this->assertInstanceOf(AssistantRun::class, $write);
        $this->assertInstanceOf(AssistantRun::class, $external);
        $this->assertSame($origin->copy()->addSeconds(4)->timestamp, $read->hard_deadline_at?->timestamp);
        $this->assertSame($origin->copy()->addSeconds(6)->timestamp, $write->hard_deadline_at?->timestamp);
        $this->assertSame($origin->copy()->addSeconds(8)->timestamp, $external->hard_deadline_at?->timestamp);
        $composition = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $this->assertSame($origin->copy()->addSeconds(8)->timestamp, $composition->hard_deadline_at?->timestamp);
        $this->assertSame('external', data_get($composition->metadata, 'semantic_plan_deadline_lane'));

        foreach ([$read, $write, $external, $composition] as $operationRun) {
            Queue::assertPushed(
                EnforceBrowserVoiceTurnDeadline::class,
                fn (EnforceBrowserVoiceTurnDeadline $job): bool => $job->voiceTurnId === $turn->id
                    && Carbon::parse($job->deadlineAt)->timestamp === $operationRun->hard_deadline_at?->timestamp,
            );
        }

        $lifecycle = app(VoiceTurnLifecycleService::class);
        Carbon::setTestNow($origin->copy()->addSeconds(4));
        $this->assertSame(1, $lifecycle->enforceDeadlines($turn->id));
        $this->assertSame('failed', $read->fresh()->status);
        $this->assertSame(VoiceTurnState::Running, $turn->fresh()->state);

        Carbon::setTestNow($origin->copy()->addSeconds(6));
        $this->assertSame(1, $lifecycle->enforceDeadlines($turn->id));
        $this->assertSame('failed', $write->fresh()->status);
        $this->assertSame(VoiceTurnState::Running, $turn->fresh()->state);

        Carbon::setTestNow($origin->copy()->addSeconds(8));
        $this->assertSame(2, $lifecycle->enforceDeadlines($turn->id));
        $this->assertSame('failed', $external->fresh()->status);
        $this->assertSame('failed', $composition->fresh()->status);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame('required_jobs_failed', $terminal->failure_category);
        $this->assertSame(
            AssistantRunService::SYSTEM_FAILURE_FINAL,
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertCount(0, $fake->compositionRequests);
        $this->assertDatabaseMissing('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Never created after lane deadline',
        ]);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_completed_read_still_terminalizes_when_composition_stalls_past_the_four_second_final_bound(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00', 'America/New_York');
        [$token, $session] = $this->conversation('semantic-composition-deadline@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('clock', 'system.clock.read', ['kind' => 'time'])],
            ),
        ], [new HermesSemanticComposition('It is noon.', false, false)]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-composition-deadline-0001',
            'What time is it?',
        );
        $interpretation = $turn->runs()->sole();
        $origin = $interpretation->created_at?->copy();
        $this->assertNotNull($origin);
        $this->processRun($interpretation, $fake);

        $operation = $this->operationRun($turn->fresh(['runs']), 'clock');
        $composition = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $expectedDeadline = $origin->copy()->addSeconds(4);
        $this->assertSame($expectedDeadline->timestamp, $operation->hard_deadline_at?->timestamp);
        $this->assertSame($expectedDeadline->timestamp, $composition->hard_deadline_at?->timestamp);
        $this->assertSame('app_read', data_get($composition->metadata, 'semantic_plan_deadline_lane'));
        Queue::assertPushed(
            EnforceBrowserVoiceTurnDeadline::class,
            fn (EnforceBrowserVoiceTurnDeadline $job): bool => $job->voiceTurnId === $turn->id
                && Carbon::parse($job->deadlineAt)->timestamp === $expectedDeadline->timestamp,
        );

        $this->processRun($operation, $fake);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertSame('queued', $composition->fresh()->status);
        $this->assertSame(VoiceTurnState::Running, $turn->fresh()->state);

        Carbon::setTestNow($expectedDeadline);
        $this->assertSame(1, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame('failed', $composition->fresh()->status);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame('semantic_composition_deadline', $terminal->failure_category);
        $this->assertSame(
            AssistantRunService::SYSTEM_FAILURE_FINAL,
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertCount(0, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_typed_mutation_crossing_its_deadline_rolls_back_and_terminalizes_without_the_delayed_enforcer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00.250000', 'America/New_York'));
        [$token, $session] = $this->conversation('semantic-late-mutation@example.com');
        $fake = $this->singleTaskCreationInterpreter(
            'Never commit after deadline',
            'This late success must never be delivered.',
        );
        $turn = $this->admit(
            $token,
            $session,
            'semantic-late-mutation-0001',
            'Create a task called Never commit after deadline.',
        );
        $this->processRun($turn->runs()->sole(), $fake);
        $operation = $this->operationRun($turn->fresh(['runs']), 'create_task');
        $composition = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $deadline = $operation->hard_deadline_at?->copy();
        $this->assertNotNull($deadline);
        $this->assertSame($deadline?->timestamp, $composition->hard_deadline_at?->timestamp);

        $actions = Mockery::mock(StructuredHermesActionService::class);
        $actions->shouldReceive('applyCanonicalSemanticAction')
            ->once()
            ->withArgs(fn (ConversationSession $candidate, string $type, array $arguments): bool => $candidate->is($session)
                && $type === 'task.create'
                && ($arguments['title'] ?? null) === 'Never commit after deadline')
            ->andReturnUsing(function (ConversationSession $candidate, string $_type, array $arguments) use ($deadline) {
                $task = Task::create([
                    'user_id' => $candidate->user_id,
                    'workspace_id' => $candidate->workspace_id,
                    'created_by_user_id' => $candidate->user_id,
                    'conversation_session_id' => $candidate->id,
                    'title' => (string) $arguments['title'],
                    'type' => (string) ($arguments['type'] ?? 'todo'),
                    'status' => 'open',
                ]);
                $event = ActivityEvent::create([
                    'user_id' => $candidate->user_id,
                    'workspace_id' => $candidate->workspace_id,
                    'conversation_session_id' => $candidate->id,
                    'event_type' => 'assistant.action.executed',
                    'tool_name' => 'structured_action',
                    'status' => 'succeeded',
                    'payload' => ['task_id' => $task->id],
                ]);
                Carbon::setTestNow($deadline);

                return collect([$event]);
            });
        $executor = new HermesSemanticOperationExecutor(
            actions: $actions,
            lookups: app(LiveLookupService::class),
            planLimits: app(PlanLimitService::class),
            lifecycle: app(VoiceTurnLifecycleService::class),
            assistantRuns: app(AssistantRunService::class),
        );

        // The scheduled deadline job is intentionally not run. The worker's
        // atomic post-execution guard must roll back the write and ask the
        // lifecycle owner to close the elapsed deadline immediately.
        $this->processRun($operation, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $operation = $terminal->runs->firstWhere('id', $operation->id);
        $composition = $terminal->runs->firstWhere('id', $composition->id);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::NotCommitted, $terminal->side_effect_status);
        $this->assertSame('failed', $operation?->status);
        $this->assertSame(
            'app_write_hard_deadline_timeout',
            data_get($operation?->metadata, 'semantic_operation_receipt.data.category'),
        );
        $this->assertFalse((bool) data_get($operation?->metadata, 'semantic_operation_receipt.side_effect_committed', true));
        $this->assertSame('failed', $composition?->status);
        $this->assertSame('required_jobs_failed', $terminal->failure_category);
        $this->assertStringNotContainsString('late success', (string) $terminal->finalAssistantMessage?->content);
        $this->assertDatabaseMissing('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Never commit after deadline',
        ]);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        $this->processRun($operation, $fake, $executor);
        $this->processRun($composition, $fake, $executor);
        $this->assertSame(0, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));
        $this->assertExactlyOneAcceptedAndFinalMessage($turn->fresh());
        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'turn_failed')
            ->count());
    }

    public function test_composition_returning_at_the_deadline_cannot_publish_or_complete_before_a_delayed_enforcer(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14 12:00:00.250000', 'America/New_York'));
        [$token, $session] = $this->conversation('semantic-late-composition@example.com');
        $compositionDeadline = null;
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Committed before late composition',
                    'type' => 'todo',
                ])],
            ),
        ], [
            function (HermesSemanticCompositionRequest $_request) use (&$compositionDeadline): HermesSemanticComposition {
                if (! $compositionDeadline instanceof Carbon) {
                    throw new RuntimeException('The composition deadline was not captured before provider execution.');
                }
                Carbon::setTestNow($compositionDeadline);

                return new HermesSemanticComposition(
                    'This late composition success must never be delivered.',
                    true,
                    true,
                );
            },
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-late-composition-0001',
            'Create a task called Committed before late composition.',
        );
        $this->processRun($turn->runs()->sole(), $fake);
        $operation = $this->operationRun($turn->fresh(['runs']), 'create_task');
        $composition = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $compositionDeadline = $composition->hard_deadline_at?->copy();
        $this->assertNotNull($compositionDeadline);

        $this->processRun($operation, $fake);
        $this->assertSame('completed', $operation->fresh()->status);
        $this->assertTrue((bool) data_get($operation->fresh()->metadata, 'semantic_operation_receipt.side_effect_committed'));
        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Committed before late composition',
        ]);

        // Again, no scheduled enforcer runs first. A provider response that
        // returns on the cutoff must lose the lifecycle compare-and-set.
        $this->processRun($composition, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertSame('failed', $composition->fresh()->status);
        $this->assertSame('semantic_composition_deadline', $terminal->failure_category);
        $this->assertSame(
            AssistantRunService::SYSTEM_FAILURE_FINAL,
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Committed before late composition',
        ]);
        $this->assertNull(data_get($terminal->metadata, 'response_directives'));
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'semantic_response_directives_published')
            ->count());
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        $this->processRun($composition, $fake);
        $this->assertSame(0, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($turn->fresh());
        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'turn_failed')
            ->count());
    }

    public function test_retriable_composition_failure_returns_to_hermes_before_one_durable_final(): void
    {
        [$token, $session] = $this->conversation('semantic-composition-retry@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Composition retry task',
                    'type' => 'todo',
                ])],
            ),
        ], [
            new HermesSemanticProviderException(
                'transport',
                'The first composition call timed out.',
                true,
            ),
            new HermesSemanticComposition('I created “Composition retry task”.', false, false),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-composition-retry-0001',
            'Create a task called Composition retry task.',
        );

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(1, $terminal->retry_count);
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertCount(2, $fake->compositionRequests);
        $this->assertSame('I created “Composition retry task”.', $terminal->finalAssistantMessage?->content);
        $this->assertSame(1, Task::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('title', 'Composition retry task')
            ->count());
        $retryEvent = VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'retry_started')
            ->sole();
        $this->assertSame('semantic_composition', data_get($retryEvent->payload, 'phase'));
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_generic_run_endpoints_and_service_cannot_own_a_voice_run(): void
    {
        [$token, $session] = $this->conversation('semantic-generic-run-isolation@example.com');
        $fake = $this->singleTaskCreationInterpreter(
            'Voice lifecycle only',
            'I created the task.',
        );
        $turn = $this->admit(
            $token,
            $session,
            'semantic-generic-run-isolation-0001',
            'Create a task called Voice lifecycle only.',
        );
        $this->processRun($turn->runs()->sole(), $fake);
        $operation = $this->operationRun($turn->fresh(['runs']), 'create_task');

        $this->withToken($token)->getJson("/api/assistant/runs/{$operation->id}")
            ->assertNotFound();
        $this->withToken($token)->postJson("/api/assistant/runs/{$operation->id}/cancel")
            ->assertNotFound();
        $this->withToken($token)->postJson("/api/assistant/sessions/{$session->id}/cancel")
            ->assertAccepted();

        $genericRuns = app(AssistantRunService::class);
        foreach ([
            fn () => $genericRuns->prepareRunForBackgroundResponse($operation->fresh()),
            fn () => $genericRuns->cancelRun($operation->fresh()),
        ] as $genericMutation) {
            try {
                $genericMutation();
                $this->fail('Generic assistant-run lifecycle code must reject voice-owned runs.');
            } catch (VoiceTurnConflictException $exception) {
                $this->assertSame(
                    'Browser Voice runs are owned by VoiceTurnLifecycleService.',
                    $exception->getMessage(),
                );
            }
        }

        $this->assertSame('queued', $operation->fresh()->status);
        $this->assertSame(VoiceTurnState::Running, $turn->fresh()->state);
        $this->assertSame(0, ActivityEvent::query()
            ->where('event_type', 'runtime.run_cancel_requested')
            ->where('payload->run_id', $operation->id)
            ->count());

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $session->id,
            'turn_id' => $turn->turn_id,
        ])->assertOk()
            ->assertJsonPath('data.turn.state', VoiceTurnState::Canceled->value);
        $this->assertSame('cancelled', $operation->fresh()->status);
        $this->assertSame(VoiceTurnState::Canceled, $turn->fresh()->state);
    }

    public function test_job_deadline_after_a_committed_receipt_reconciles_before_composition(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00', 'America/New_York');
        [$token, $session] = $this->conversation('semantic-post-commit-deadline@example.com');
        $fake = $this->singleTaskCreationInterpreter(
            'Create the committed deadline task.',
            'The task was created successfully before the response deadline was checked.',
        );
        $turn = $this->admit(
            $token,
            $session,
            'semantic-post-commit-deadline-0001',
            'Create a task called Committed deadline task.',
        );
        $executor = app(HermesSemanticOperationExecutor::class);
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $this->processRun($turn->runs()->sole(), $fake, $executor);
        $operation = $this->operationRun($turn->fresh(['runs']), 'create_task');

        $this->assertTrue($lifecycle->claimJobExecution($operation));
        $receipt = $executor->executeRun($turn->fresh(), $operation->fresh());
        $this->assertTrue((bool) ($receipt['side_effect_committed'] ?? false));
        $operation->fresh()->update(['hard_deadline_at' => now()->subSecond()]);

        $this->assertSame(1, $lifecycle->enforceDeadlines(sessionId: $session->id));
        $reconciled = $operation->fresh();
        $this->assertSame('completed', $reconciled->status);
        $this->assertTrue((bool) data_get($reconciled->result, 'metadata.reconciled_at_job_deadline'));
        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Create the committed deadline task.',
        ]);

        $this->drainTurn($turn, $fake, $executor);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertStringContainsString('created successfully', (string) $terminal->finalAssistantMessage?->content);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_reload_and_duplicate_deliveries_reconstruct_one_request_one_write_and_one_final(): void
    {
        [$token, $session] = $this->conversation('semantic-reload-idempotency@example.com');
        $fake = $this->singleTaskCreationInterpreter(
            'Reload-safe task',
            'I created the reload-safe task.',
        );
        $payload = $this->payload(
            $session,
            'semantic-reload-idempotency-0001',
            'Create a task called Reload-safe task.',
        );
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertCreated();
        $turn = VoiceTurn::where('turn_id', $payload['turn_id'])->firstOrFail();
        $interpretationRun = $turn->runs()->sole();
        $this->processRun($interpretationRun, $fake);

        // Simulate a worker crash after the plan was sealed but before the
        // operation and composition queue writes were stamped. A browser
        // reload reconstructs and redispatches both durable jobs.
        $turn->fresh()->runs()
            ->where('status', 'queued')
            ->update(['dispatch_requested_at' => null]);
        Queue::fake();
        $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->assertJsonPath('data.turns.0.turn_id', $turn->turn_id)
            ->assertJsonCount(3, 'data.jobs');
        Queue::assertPushed(ProcessAssistantRun::class, 2);
        $this->assertSame(0, $turn->fresh()->runs()
            ->where('status', 'queued')
            ->whereNull('dispatch_requested_at')
            ->count());

        // Re-admission and duplicate queue delivery reuse durable identities.
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertOk()
            ->assertJsonCount(3, 'data.jobs');
        $this->processRun($interpretationRun, $fake);
        $this->assertCount(1, $fake->interpretationRequests);

        $this->drainTurn($turn, $fake);
        foreach ($turn->fresh()->runs()->get() as $run) {
            $this->processRun($run, $fake);
        }

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(3, $terminal->runs->count());
        $this->assertSame(1, Task::where('workspace_id', $session->workspace_id)->where('title', 'Reload-safe task')->count());
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        $state = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->assertJsonPath('data.turns.0.state', VoiceTurnState::Completed->value)
            ->json('data');
        $this->assertCount(1, collect($state['messages'])->where('role', 'assistant')->where('turn_id', $turn->turn_id));
    }

    public function test_out_of_order_delivery_cannot_suppress_the_real_final_after_reload(): void
    {
        [$token, $session] = $this->conversation('semantic-delivery-order@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Delivery-order task',
                    'type' => 'todo',
                ])],
            ),
        ], [new HermesSemanticComposition('I created the delivery-order task.', false, false)]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-delivery-order-0001',
            'Create a task called Delivery-order task.',
        );
        $deliveryUrl = "/api/assistant/voice/turns/{$turn->turn_id}/delivery";

        foreach ([
            ['event' => 'acknowledgement_started'],
            ['event' => 'playback_started', 'timing' => ['purpose' => 'acknowledgement']],
            ['event' => 'final_text_delivered'],
            ['event' => 'final_audio_started', 'timing' => ['purpose' => 'final']],
            ['event' => 'playback_started', 'timing' => ['purpose' => 'final']],
        ] as $prematureDelivery) {
            $this->withToken($token)->postJson($deliveryUrl, [
                'session_id' => $session->id,
                ...$prematureDelivery,
            ])->assertStatus(409);
        }
        foreach (['final', 'acknowledgement', 'clarification'] as $purpose) {
            foreach (['playback_finished', 'playback_stopped'] as $event) {
                $this->withToken($token)->postJson($deliveryUrl, [
                    'session_id' => $session->id,
                    'event' => $event,
                    'timing' => [
                        'purpose' => $purpose,
                        'speech_item_id' => "premature-{$purpose}",
                    ],
                ])->assertStatus(409);
            }
        }

        $this->assertNull($turn->fresh()->acknowledged_at);
        $this->assertNull($turn->fresh()->final_delivered_at);
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'acknowledgement_started')
            ->count());
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->finalAudioStarted()
            ->count());
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->whereIn('event_type', ['playback_finished', 'playback_stopped'])
            ->count());

        $this->processRun($turn->runs()->sole(), $fake);
        $staged = $turn->fresh();
        $this->assertTrue($staged->acknowledgement_required);
        $this->assertSame('I’ll create that task.', $staged->acknowledgement_text);

        foreach (['playback_finished', 'playback_stopped'] as $event) {
            $this->withToken($token)->postJson($deliveryUrl, [
                'session_id' => $session->id,
                'event' => $event,
                'timing' => [
                    'purpose' => 'acknowledgement',
                    'speech_item_id' => 'ack-delivery-order',
                ],
            ])->assertStatus(409);
        }
        $acknowledgement = [
            'session_id' => $session->id,
            'event' => 'acknowledgement_started',
            'timing' => ['purpose' => 'acknowledgement', 'speech_item_id' => 'ack-delivery-order'],
        ];
        $this->withToken($token)->postJson($deliveryUrl, $acknowledgement)->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, $acknowledgement)->assertOk();
        $this->assertNotNull($turn->fresh()->acknowledged_at);
        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'acknowledgement_started')
            ->count());
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_finished',
            'timing' => [
                'purpose' => 'acknowledgement',
                'speech_item_id' => 'ack-delivery-order',
            ],
        ])->assertOk();

        foreach (['final_audio_started', 'playback_started'] as $prematureFinalAudioEvent) {
            $this->withToken($token)->postJson($deliveryUrl, [
                'session_id' => $session->id,
                'event' => $prematureFinalAudioEvent,
                'timing' => ['purpose' => 'final'],
            ])->assertStatus(409);
        }
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->finalAudioStarted()
            ->count());

        $this->drainTurn($turn, $fake);
        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('I created the delivery-order task.', $terminal->finalAssistantMessage?->content);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        $beforePlaybackReload = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $beforePlaybackTurn = collect($beforePlaybackReload['turns'])->firstWhere('turn_id', $turn->turn_id);
        $this->assertIsArray($beforePlaybackTurn);
        $this->assertFalse($beforePlaybackTurn['final_audio_started']);
        $this->assertSame('I created the delivery-order task.', $beforePlaybackTurn['final_text']);
        foreach (['playback_finished', 'playback_stopped'] as $event) {
            $this->withToken($token)->postJson($deliveryUrl, [
                'session_id' => $session->id,
                'event' => $event,
                'timing' => [
                    'purpose' => 'final',
                    'speech_item_id' => 'final-delivery-order',
                ],
            ])->assertStatus(409);
        }

        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'final_text_delivered',
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_started',
            'timing' => ['purpose' => 'final', 'speech_item_id' => 'final-delivery-order'],
        ])->assertOk()
            ->assertJsonPath('data.turn.final_audio_started', true);
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_finished',
            'timing' => ['purpose' => 'final', 'speech_item_id' => 'final-delivery-order'],
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_stopped',
            'timing' => ['purpose' => 'final', 'speech_item_id' => 'final-delivery-order'],
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'final_audio_started',
            'timing' => ['purpose' => 'final', 'speech_item_id' => 'final-delivery-order'],
        ])->assertOk()
            ->assertJsonPath('data.turn.final_audio_started', true);

        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->finalAudioStarted()
            ->count());
        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->where('event_type', 'final_text_delivered')
            ->count());

        $afterPlaybackReload = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $afterPlaybackTurn = collect($afterPlaybackReload['turns'])->firstWhere('turn_id', $turn->turn_id);
        $this->assertIsArray($afterPlaybackTurn);
        $this->assertTrue($afterPlaybackTurn['final_audio_started']);
        $this->assertSame(1, collect($afterPlaybackReload['messages'])
            ->where('role', 'assistant')
            ->where('turn_id', $turn->turn_id)
            ->count());
        $this->assertSame(1, Task::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('title', 'Delivery-order task')
            ->count());
    }

    public function test_note_limit_is_enforced_before_acknowledgement_with_one_hermes_upgrade_final(): void
    {
        [$token, $session] = $this->conversation('semantic-note-entitlement@example.com');
        foreach (range(1, 10) as $index) {
            Note::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'created_by_user_id' => $session->user_id,
                'title' => "Existing note {$index}",
                'plain_text' => 'Existing plan-limited note.',
            ]);
        }
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_note', 'app.note.create', [
                    'title' => 'Trip ideas',
                    'plain_text' => 'Visit the science museum.',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'Your current plan includes up to 10 notes. Upgrade your plan to create and manage more notes.',
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-note-limit-0001',
            'Create a note called Trip ideas that says visit the science museum.',
        );

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertNull($terminal->failure_category);
        $this->assertNull($terminal->acknowledgement_text);
        $this->assertFalse($terminal->acknowledgement_required);
        $this->assertSame(0, $terminal->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertStringContainsString('current plan includes up to 10 notes', (string) $terminal->finalAssistantMessage?->content);
        $this->assertStringContainsString('Upgrade your plan', (string) $terminal->finalAssistantMessage?->content);
        $this->assertSame(10, Note::where('user_id', $session->user_id)->count());
        $this->assertCount(2, $fake->interpretationRequests);
        $this->assertCount(0, $fake->compositionRequests);
        $this->assertSame(1, $terminal->retry_count);
        $this->assertSame(0, VoiceTurnEvent::where('voice_turn_id', $turn->id)
            ->where('event_type', 'semantic_acknowledgement_published')
            ->count());
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_semantic_usage_preflight_limit_terminalizes_once_without_staging_application_work(): void
    {
        [$token, $session] = $this->conversation('semantic-usage-limit-journey@example.com');
        $limitText = 'You’ve reached today’s AI usage limit for your current plan. Upgrade for more voice usage, or try again tomorrow.';
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticUsageLimitException($limitText, [
                'budget' => ['tier' => 'base', 'daily_cost_limit' => 1.0],
                'allowed' => false,
            ]),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-usage-limit-0001',
            'Move the launch task to tomorrow.',
        );

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame('semantic_usage_limit', $terminal->failure_category);
        $this->assertSame($limitText, $terminal->finalAssistantMessage?->content);
        $this->assertSame(VoiceTurnSideEffectStatus::None, $terminal->side_effect_status);
        $this->assertSame(0, $terminal->retry_count);
        $this->assertSame(1, $terminal->runs->count());
        $this->assertSame(0, Task::where('workspace_id', $session->workspace_id)->count());
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertCount(0, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_hermes_final_is_preserved_literally_through_reload_speech_and_delivery(): void
    {
        [$token, $session] = $this->conversation('semantic-literal-final@example.com');
        $finalText = 'The provider said “server error.” Keep this literal JSON: {"message":"do not unwrap me"}';
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: $finalText,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-literal-final-0001',
            'Tell me the provider result exactly.',
        );

        $this->drainTurn($turn, $fake);
        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame($finalText, $terminal->finalAssistantMessage?->content);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        $projection = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $projectedTurn = collect($projection['turns'])->firstWhere('turn_id', $turn->turn_id);
        $projectedFinal = collect($projection['messages'])->first(
            fn (array $message): bool => ($message['turn_id'] ?? null) === $turn->turn_id
                && ($message['role'] ?? null) === 'assistant',
        );
        $this->assertSame($finalText, $projectedTurn['final_text'] ?? null);
        $this->assertSame($finalText, $projectedFinal['content'] ?? null);

        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.openai.speech_model', 'tts-1-test');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        Http::fake(fn () => Http::response('literal-final-audio', 200, ['Content-Type' => 'application/octet-stream']));
        $speech = $this->withToken($token)->postJson('/api/assistant/voice/speech', [
            'workspace_id' => $session->workspace_id,
            'turn_id' => $turn->turn_id,
            'speech_item_id' => $turn->turn_id.':final',
            'purpose' => 'final',
            'text' => $finalText,
        ])->assertOk();
        $this->assertSame('literal-final-audio', $speech->streamedContent());
        Http::assertSent(fn ($request): bool => $request['input'] === $finalText);

        $deliveryUrl = "/api/assistant/voice/turns/{$turn->turn_id}/delivery";
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'final_text_delivered',
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_started',
            'timing' => ['purpose' => 'final', 'speech_item_id' => $turn->turn_id.':final'],
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_finished',
            'timing' => ['purpose' => 'final', 'speech_item_id' => $turn->turn_id.':final'],
        ])->assertOk();

        $delivered = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame($finalText, $delivered->finalAssistantMessage?->content);
        $this->assertNotNull($delivered->final_delivered_at);
        $this->assertExactlyOneAcceptedAndFinalMessage($delivered);
    }

    public function test_explicit_memory_remember_then_search_read_crosses_the_canonical_semantic_path(): void
    {
        [$token, $session] = $this->conversation('semantic-memory-remember-read@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll remember that.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('remember_editor', 'app.memory.create', [
                    'type' => 'preference',
                    'title' => 'Preferred editor',
                    'content' => 'The user prefers Nova for editing code.',
                    'confidence' => 95,
                    'importance' => 80,
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('read_editor_memory', 'app.memory.search', [
                    'query' => 'Preferred editor',
                    'match_mode' => 'exact_title',
                    'require_unique' => true,
                    'type' => 'preference',
                ])],
            ),
        ], [
            new HermesSemanticComposition('I’ll remember that you prefer Nova for editing code.', false, false),
            new HermesSemanticComposition('You asked me to remember that you prefer Nova for editing code.', false, false),
        ]);

        $remember = $this->admit(
            $token,
            $session,
            'semantic-memory-remember-0001',
            'Remember that I prefer Nova for editing code.',
        );
        $this->drainTurn($remember, $fake);

        $item = MemoryItem::query()->sole();
        $this->assertSame('preference', $item->type);
        $this->assertSame('Preferred editor', $item->title);
        $this->assertSame('The user prefers Nova for editing code.', $item->content);
        $this->assertSame('browser_voice_semantic', $item->source_type);
        $this->assertSame('I’ll remember that you prefer Nova for editing code.', $remember->fresh()->finalAssistantMessage?->content);
        $this->assertExactlyOneAcceptedAndFinalMessage($remember->fresh());
        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $session->id,
            'event_type' => 'assistant.memory.created',
            'tool_name' => 'memory.create',
            'status' => 'succeeded',
        ]);

        $read = $this->admit(
            $token,
            $session,
            'semantic-memory-read-0001',
            'What do you remember about my preferred editor?',
        );
        $this->drainTurn($read, $fake);

        $terminal = $read->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('You asked me to remember that you prefer Nova for editing code.', $terminal->finalAssistantMessage?->content);
        $this->assertSame($item->id, data_get($fake->compositionRequests[1]->results, '0.data.unique_id'));
        $this->assertSame($item->content, data_get($fake->compositionRequests[1]->results, '0.data.items.0.content'));
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_explicit_memory_update_and_forget_use_only_trusted_ids_and_transactional_receipts(): void
    {
        [$token, $session] = $this->conversation('semantic-memory-update-delete@example.com');
        $preference = MemoryItem::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Preferred drink',
            'content' => 'The user prefers coffee.',
            'confidence' => 80,
            'importance' => 70,
        ]);
        $temporary = MemoryItem::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'type' => 'temporary_context',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Temporary hotel',
            'content' => 'The user is staying at the Harbor Hotel.',
            'confidence' => 90,
            'importance' => 40,
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll correct one memory and forget the other.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find_drink', 'app.memory.search', [
                        'query' => 'The user prefers coffee.',
                        'match_mode' => 'exact_content',
                        'require_unique' => true,
                        'type' => 'preference',
                    ]),
                    new HermesSemanticOperation('correct_drink', 'app.memory.update', [
                        'result_ref' => ['operation_id' => 'find_drink', 'path' => 'unique_id'],
                        'content' => 'The user prefers tea.',
                        'confidence' => 95,
                    ], ['find_drink']),
                    new HermesSemanticOperation('forget_hotel', 'app.memory.delete', [
                        'id' => $temporary->id,
                    ]),
                ],
            ),
        ], [new HermesSemanticComposition('I corrected your drink preference and forgot the hotel.', false, false)]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-memory-update-delete-0001',
            'Correct my drink preference to tea and forget the temporary hotel.',
        );

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertSame('The user prefers tea.', $preference->fresh()->content);
        $this->assertSame(95, $preference->fresh()->confidence);
        $forgotten = MemoryItem::withTrashed()->findOrFail($temporary->id);
        $this->assertSame('archived', $forgotten->status);
        $this->assertNotNull($forgotten->deleted_at);
        $this->assertSame(1, ActivityEvent::query()->where('event_type', 'assistant.memory.updated')->count());
        $this->assertSame(1, ActivityEvent::query()->where('event_type', 'assistant.memory.deleted')->count());
        $this->assertSame($preference->id, data_get(
            $this->operationRun($terminal, 'correct_drink')->metadata,
            'semantic_operation_receipt.data.events.0.data.memory_item_id',
        ));
        $this->assertSame($temporary->id, data_get(
            $this->operationRun($terminal, 'forget_hotel')->metadata,
            'semantic_operation_receipt.data.events.0.data.memory_item_id',
        ));
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_duplicate_memory_race_returns_only_structured_facts_to_hermes_composition(): void
    {
        [$token, $session] = $this->conversation('semantic-memory-duplicate-race@example.com');
        $target = MemoryItem::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Preferred drink',
            'content' => 'The user prefers coffee.',
        ]);
        $hermesQuestion = 'I found an existing tea preference, so I left both memories unchanged. Should I keep the coffee version or the tea version?';
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('correct_drink', 'app.memory.update', [
                    'id' => $target->id,
                    'content' => 'The user prefers tea.',
                ])],
            ),
        ], [function (HermesSemanticCompositionRequest $request) use ($hermesQuestion): HermesSemanticComposition {
            $this->assertCount(1, $request->results);
            $result = $request->results[0];
            $this->assertSame('correct_drink', $result->operationId);
            $this->assertSame('failed', $result->status);
            $this->assertSame('duplicate_memory_item', $result->data['category'] ?? null);
            $this->assertSame('operation', $result->data['failure_scope'] ?? null);
            $this->assertArrayNotHasKey('user_facing', $result->data);

            return new HermesSemanticComposition($hermesQuestion, false, true);
        }]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-memory-duplicate-race-0001',
            'Correct my preferred drink to tea.',
        );

        // The target was unique when Hermes interpreted and deterministic
        // authorization sealed the plan. A concurrent memory then creates the
        // duplicate-prevention race that only execution can observe.
        $this->processRun($turn->runs()->sole(), $fake);
        MemoryItem::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Preferred drink backup',
            'content' => 'The user prefers tea.',
        ]);

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $operation = $this->operationRun($terminal, 'correct_drink');
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::NotCommitted, $terminal->side_effect_status);
        $this->assertSame('completed', $operation->status);
        $this->assertSame('failed', data_get($operation->metadata, 'semantic_operation_receipt.status'));
        $this->assertSame('duplicate_memory_item', data_get(
            $operation->metadata,
            'semantic_operation_receipt.data.category',
        ));
        $this->assertArrayNotHasKey('user_facing', (array) data_get(
            $operation->metadata,
            'semantic_operation_receipt.data',
        ));
        $this->assertSame('The user prefers coffee.', $target->fresh()->content);
        $this->assertSame(0, ActivityEvent::query()->where('event_type', 'assistant.memory.updated')->count());
        $this->assertSame($hermesQuestion, $terminal->finalAssistantMessage?->content);
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_ambiguous_memory_target_returns_to_hermes_clarification_without_speculative_write(): void
    {
        [$token, $session] = $this->conversation('semantic-memory-ambiguity@example.com');
        $first = MemoryItem::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Preferred drink',
            'content' => 'The user prefers coffee in the morning.',
        ]);
        $second = MemoryItem::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Preferred drink',
            'content' => 'The user prefers sparkling water with dinner.',
        ]);
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find_drink', 'app.memory.search', [
                        'query' => 'Preferred drink',
                        'match_mode' => 'exact_title',
                        'require_unique' => true,
                    ]),
                    new HermesSemanticOperation('correct_drink', 'app.memory.update', [
                        'result_ref' => ['operation_id' => 'find_drink', 'path' => 'unique_id'],
                        'content' => 'The user prefers chamomile tea in the morning.',
                    ], ['find_drink']),
                ],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Do you mean your morning drink or your dinner drink?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('correct_drink', 'app.memory.update', [
                    'id' => $first->id,
                    'content' => 'The user prefers chamomile tea in the morning.',
                ])],
            ),
        ], [new HermesSemanticComposition('I corrected your morning drink preference.', false, false)]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-memory-ambiguity-0001',
            'Correct my preferred drink to chamomile tea.',
        );

        $this->drainTurn($turn, $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame('Do you mean your morning drink or your dinner drink?', data_get(
            $awaiting->metadata,
            'clarification_question',
        ));
        $this->assertSame('The user prefers coffee in the morning.', $first->fresh()->content);
        $this->assertSame('The user prefers sparkling water with dinner.', $second->fresh()->content);
        $this->assertSame(0, ActivityEvent::query()->where('event_type', 'like', 'assistant.memory.%')->count());
        $this->assertSame(0, $awaiting->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());

        $deliveryUrl = "/api/assistant/voice/turns/{$turn->turn_id}/delivery";
        $clarificationTiming = [
            'purpose' => 'clarification',
            'speech_item_id' => 'semantic-memory-ambiguity-clarification',
        ];
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_started',
            'timing' => $clarificationTiming,
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_finished',
            'timing' => $clarificationTiming,
        ])->assertOk();
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/clarifications", [
            'session_id' => $session->id,
            'answer' => 'My morning drink.',
            'clarification_id' => 'semantic-memory-ambiguity-answer-0001',
        ])->assertOk();

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('The user prefers chamomile tea in the morning.', $first->fresh()->content);
        $this->assertSame('The user prefers sparkling water with dinner.', $second->fresh()->content);
        $this->assertSame(1, ActivityEvent::query()->where('event_type', 'assistant.memory.updated')->count());
        $this->assertCount(3, $fake->interpretationRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_missing_memory_fields_return_to_hermes_without_defaulting_or_parsing_prose(): void
    {
        [$token, $session] = $this->conversation('semantic-memory-missing-fields@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('remember_atlas', 'app.memory.create', [
                    'content' => 'Atlas',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'What about Atlas should I remember?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('remember_atlas', 'app.memory.create', [
                    'type' => 'project',
                    'title' => 'Atlas',
                    'content' => 'Atlas is the user’s launch project.',
                ])],
            ),
        ], [new HermesSemanticComposition('I’ll remember that Atlas is your launch project.', false, false)]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-memory-missing-fields-0001',
            'Remember Atlas.',
        );

        $this->drainTurn($turn, $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame('What about Atlas should I remember?', data_get($awaiting->metadata, 'clarification_question'));
        $this->assertSame(0, MemoryItem::query()->count());
        $this->assertSame(0, $awaiting->runs->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertStringContainsString('requires type', (string) data_get(
            $fake->interpretationRequests[1]->context,
            'prior_interpretation_feedback.detail',
        ));

        $deliveryUrl = "/api/assistant/voice/turns/{$turn->turn_id}/delivery";
        $clarificationTiming = [
            'purpose' => 'clarification',
            'speech_item_id' => 'semantic-memory-missing-fields-clarification',
        ];
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_started',
            'timing' => $clarificationTiming,
        ])->assertOk();
        $this->withToken($token)->postJson($deliveryUrl, [
            'session_id' => $session->id,
            'event' => 'playback_finished',
            'timing' => $clarificationTiming,
        ])->assertOk();
        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/clarifications", [
            'session_id' => $session->id,
            'answer' => 'Atlas is my launch project.',
            'clarification_id' => 'semantic-memory-missing-fields-answer-0001',
        ])->assertOk();

        $this->drainTurn($turn, $fake);

        $item = MemoryItem::query()->sole();
        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame('project', $item->type);
        $this->assertSame('Atlas is the user’s launch project.', $item->content);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertCount(3, $fake->interpretationRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    public function test_memory_create_reload_and_duplicate_delivery_produce_one_item_event_and_final(): void
    {
        [$token, $session] = $this->conversation('semantic-memory-reload@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('remember_timezone', 'app.memory.create', [
                    'type' => 'preference',
                    'title' => 'Scheduling timezone',
                    'content' => 'The user prefers meetings scheduled in Eastern Time.',
                ])],
            ),
        ], [new HermesSemanticComposition('I’ll remember to schedule meetings in Eastern Time.', false, false)]);
        $payload = $this->payload(
            $session,
            'semantic-memory-reload-0001',
            'Remember that I prefer meetings scheduled in Eastern Time.',
        );
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertCreated();
        $turn = VoiceTurn::query()->where('turn_id', $payload['turn_id'])->firstOrFail();
        $interpretationRun = $turn->runs()->sole();
        $this->processRun($interpretationRun, $fake);

        $turn->fresh()->runs()
            ->where('status', 'queued')
            ->update(['dispatch_requested_at' => null]);
        Queue::fake();
        $this->withToken($token)->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->assertJsonPath('data.turns.0.turn_id', $turn->turn_id)
            ->assertJsonCount(3, 'data.jobs');
        Queue::assertPushed(ProcessAssistantRun::class, 2);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertOk()
            ->assertJsonCount(3, 'data.jobs');
        $this->processRun($interpretationRun, $fake);
        $this->assertCount(1, $fake->interpretationRequests);

        $this->drainTurn($turn, $fake);
        foreach ($turn->fresh()->runs()->get() as $run) {
            $this->processRun($run, $fake);
        }

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(1, MemoryItem::query()->count());
        $this->assertSame(1, ActivityEvent::query()->where('event_type', 'assistant.memory.created')->count());
        $this->assertSame(3, $terminal->runs->count());
        $this->assertCount(1, $fake->compositionRequests);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);

        $state = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $this->assertCount(1, collect($state['messages'])->where('role', 'assistant')->where('turn_id', $turn->turn_id));
    }

    public function test_ordinary_preference_disclosure_records_activity_but_never_auto_persists_memory(): void
    {
        [$token, $session] = $this->conversation('semantic-memory-no-inference@example.com');
        $fake = new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'Oat milk sounds good.',
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-memory-no-inference-0001',
            'I prefer oat milk in coffee.',
        );

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(0, MemoryItem::query()->count());
        $activity = MemoryEvent::query()->sole();
        $this->assertSame(BeanMemoryService::TURN_ACTIVITY_EVENT_TYPE, $activity->event_type);
        $this->assertSame('processed', $activity->status);
        $this->assertSame('Oat milk sounds good.', $terminal->finalAssistantMessage?->content);
        $this->assertExactlyOneAcceptedAndFinalMessage($terminal);
    }

    /** @return array{0:string,1:ConversationSession} */
    private function conversation(string $email): array
    {
        $token = $this->apiToken($email);
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        return [$token, ConversationSession::findOrFail($sessionId)];
    }

    /** @param array<string,mixed> $extra */
    private function admit(
        string $token,
        ConversationSession $session,
        string $turnId,
        string $transcript,
        array $extra = [],
    ): VoiceTurn {
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$this->payload($session, $turnId, $transcript),
            ...$extra,
        ])->assertCreated()
            ->assertJsonPath('data.turn.state', VoiceTurnState::Accepted->value);

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }

    /** @return array<string,mixed> */
    private function payload(ConversationSession $session, string $turnId, string $transcript): array
    {
        return [
            'turn_id' => $turnId,
            'session_id' => $session->id,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
            'client_context' => [
                'voice_mode_active' => true,
                'wake_detection_enabled' => true,
                'playback_state' => 'idle',
            ],
        ];
    }

    private function drainTurn(
        VoiceTurn $turn,
        CompleteJourneyHermesInterpreter $interpreter,
        ?HermesSemanticOperationExecutor $executor = null,
    ): void {
        $executor ??= app(HermesSemanticOperationExecutor::class);

        for ($pass = 0; $pass < 20; $pass++) {
            $freshTurn = $turn->fresh();
            if (! $freshTurn instanceof VoiceTurn || $freshTurn->state->isTerminal()) {
                return;
            }

            $queued = $freshTurn->runs()->where('status', 'queued')->orderBy('id')->get();
            if ($queued->isEmpty()) {
                return;
            }

            $progressed = false;
            foreach ($queued as $run) {
                $before = $run->status;
                $this->processRun($run, $interpreter, $executor);
                $progressed = $progressed || $run->fresh()?->status !== $before;
            }

            if (! $progressed) {
                return;
            }
        }

        $this->fail('The semantic journey did not reach a terminal or waiting state within 20 scheduler passes.');
    }

    private function processRun(
        AssistantRun $run,
        CompleteJourneyHermesInterpreter $interpreter,
        ?HermesSemanticOperationExecutor $executor = null,
    ): void {
        (new ProcessAssistantRun($run->id))->handle(
            runtime: app(HermesRuntimeService::class),
            runs: app(AssistantRunService::class),
            voiceTurns: app(VoiceTurnLifecycleService::class),
            semanticInterpreter: $interpreter,
            semanticOperations: $executor ?? app(HermesSemanticOperationExecutor::class),
        );
    }

    private function operationRun(VoiceTurn $turn, string $operationId): AssistantRun
    {
        return $turn->runs->first(
            fn (AssistantRun $run): bool => data_get($run->metadata, 'semantic_operation_id') === $operationId,
        ) ?? throw new RuntimeException("Semantic operation run {$operationId} was not staged.");
    }

    /** @return array<string,mixed> */
    private function taskCreateDefaults(): array
    {
        return [
            'type' => 'todo',
            'status' => 'open',
            'notes' => null,
            'category' => null,
            'color' => '#34C759',
            'is_critical' => false,
            'due_at' => null,
            'completed_at' => null,
            'recurrence' => 'none',
        ];
    }

    /** @return array<string,mixed> */
    private function reminderCreateDefaults(): array
    {
        return [
            'notes' => null,
            'status' => 'scheduled',
            'category' => null,
            'color' => '#34C759',
            'is_critical' => false,
            'recurrence' => 'none',
            'calendar_event_id' => null,
        ];
    }

    /** @return array<string,mixed> */
    private function calendarCreateDefaults(): array
    {
        return [
            'description' => null,
            'location' => null,
            'category' => null,
            'color' => '#34C759',
            'is_critical' => false,
            'recurrence' => 'none',
            'ends_at' => null,
            'status' => 'scheduled',
            'all_day' => false,
        ];
    }

    private function singleTaskCreationInterpreter(string $title, string $finalText): CompleteJourneyHermesInterpreter
    {
        return new CompleteJourneyHermesInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => $title,
                    'type' => 'todo',
                ])],
            ),
        ], [new HermesSemanticComposition($finalText, false, false)]);
    }

    private function assertExactlyOneAcceptedAndFinalMessage(VoiceTurn $turn): void
    {
        $this->assertSame(1, ConversationMessage::query()
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'user')
            ->count());
        $this->assertSame(1, ConversationMessage::query()
            ->where('conversation_session_id', $turn->conversation_session_id)
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'assistant')
            ->count());
    }
}

final class CompleteJourneyHermesInterpreter implements HermesSemanticInterpreter
{
    /** @var list<HermesSemanticInterpretationRequest> */
    public array $interpretationRequests = [];

    /** @var list<HermesSemanticCompositionRequest> */
    public array $compositionRequests = [];

    /**
     * @param  list<HermesSemanticInterpretation|\Throwable>  $interpretations
     * @param  list<HermesSemanticComposition|\Throwable|\Closure(HermesSemanticCompositionRequest):HermesSemanticComposition>  $compositions
     */
    public function __construct(
        private array $interpretations,
        private array $compositions = [],
    ) {}

    public function interpret(HermesSemanticInterpretationRequest $request): HermesSemanticInterpretation
    {
        $this->interpretationRequests[] = $request;
        $next = array_shift($this->interpretations);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (! $next instanceof HermesSemanticInterpretation) {
            throw new RuntimeException('No scripted complete-journey interpretation remains.');
        }

        return $next;
    }

    public function compose(HermesSemanticCompositionRequest $request): HermesSemanticComposition
    {
        $this->compositionRequests[] = $request;
        $next = array_shift($this->compositions);
        if ($next instanceof \Closure) {
            $next = $next($request);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (! $next instanceof HermesSemanticComposition) {
            throw new RuntimeException('No scripted complete-journey composition remains.');
        }

        return $next;
    }
}
