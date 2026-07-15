<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticOperation;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Task;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BrowserVoiceV2MultiRunFinalizerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    public function test_failed_operation_skips_dependents_and_the_composition_barrier_waits_for_independent_work(): void
    {
        [$token, $session] = $this->voiceSession('semantic-operation-barrier@example.com');
        $doomedTask = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Doomed task',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-operation-barrier-0001',
            'Update the missing task, save a dependent note, and independently create a reminder.',
        );
        $compositionRequest = null;
        $interpreter = Mockery::mock(HermesSemanticInterpreter::class);
        $interpreter->shouldReceive('interpret')->once()->andReturn(
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll work through those requests.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('missing_task_update', 'app.task.update', [
                        'id' => $doomedTask->id,
                        'title' => 'Updated missing task',
                    ]),
                    new HermesSemanticOperation('dependent_note', 'app.note.create', [
                        'title' => 'Dependent note',
                        'plain_text' => 'This must not be written after its dependency fails.',
                    ], ['missing_task_update']),
                    new HermesSemanticOperation('independent_reminder', 'app.reminder.create', [
                        'title' => 'Review the independent result',
                        'notes' => null,
                        'status' => 'scheduled',
                        'category' => null,
                        'color' => '#34C759',
                        'is_critical' => false,
                        'remind_at' => '2026-07-20T09:00:00-04:00',
                        'recurrence' => 'none',
                        'calendar_event_id' => null,
                    ]),
                ],
            ),
        );
        $interpreter->shouldReceive('compose')
            ->once()
            ->andReturnUsing(function (HermesSemanticCompositionRequest $request) use (&$compositionRequest): HermesSemanticComposition {
                $compositionRequest = $request;

                return new HermesSemanticComposition(
                    'I created the reminder, but I could not update the missing task, so I did not create its dependent note.',
                    false,
                    false,
                );
            });

        $this->process($turn->runs()->sole(), $interpreter);
        $runs = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)
            ->get()
            ->keyBy(fn (AssistantRun $run): string => (string) data_get($run->metadata, 'semantic_operation_id'));
        $failed = $runs->get('missing_task_update');
        $dependent = $runs->get('dependent_note');
        $independent = $runs->get('independent_reminder');
        $composition = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $this->assertInstanceOf(AssistantRun::class, $failed);
        $this->assertInstanceOf(AssistantRun::class, $dependent);
        $this->assertInstanceOf(AssistantRun::class, $independent);

        $doomedTask->delete();
        $this->process($failed, $interpreter);
        $this->assertSame('completed', $failed->fresh()->status);
        $this->assertSame('failed', data_get($failed->fresh()->metadata, 'semantic_operation_receipt.status'));
        $this->assertSame('stale_target', data_get(
            $failed->fresh()->metadata,
            'semantic_operation_receipt.data.category',
        ));
        $this->assertSame('operation', data_get(
            $failed->fresh()->metadata,
            'semantic_operation_receipt.data.failure_scope',
        ));
        $this->assertArrayNotHasKey(
            'message',
            (array) data_get($failed->fresh()->metadata, 'semantic_operation_receipt.data'),
        );
        $this->assertSame(VoiceTurnState::Running, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->final_assistant_message_id);

        $this->process($dependent, $interpreter);
        $this->assertSame('completed', $dependent->fresh()->status);
        $this->assertSame('skipped', data_get($dependent->fresh()->metadata, 'semantic_operation_receipt.status'));
        $this->assertSame('dependency_not_completed', data_get($dependent->fresh()->metadata, 'semantic_operation_receipt.data.reason'));
        $this->assertDatabaseMissing('notes', ['workspace_id' => $session->workspace_id, 'title' => 'Dependent note']);
        $this->assertSame('queued', $independent->fresh()->status);
        $this->assertNull($turn->fresh()->final_assistant_message_id);

        // Composition is already durable, but it cannot run until every
        // operation receipt is terminal.
        $this->process($composition, $interpreter);
        $this->assertSame('queued', $composition->fresh()->status);
        $this->assertNotNull(data_get($composition->fresh()->metadata, 'dependency_wait_started_at'));
        $this->assertNull($compositionRequest);

        $this->process($independent, $interpreter);
        $this->assertSame('completed', $independent->fresh()->status);
        $this->assertDatabaseHas('reminders', [
            'workspace_id' => $session->workspace_id,
            'title' => 'Review the independent result',
        ]);
        $this->process($composition->fresh(), $interpreter);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertSame(
            'I created the reminder, but I could not update the missing task, so I did not create its dependent note.',
            $terminal->finalAssistantMessage?->content,
        );
        $this->assertInstanceOf(HermesSemanticCompositionRequest::class, $compositionRequest);
        $this->assertSame(
            ['failed', 'skipped', 'completed'],
            collect($compositionRequest->results)->map(fn ($result): string => $result->status)->all(),
        );
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());

        $this->process($composition->fresh(), $interpreter);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
        $this->assertSame(1, $turn->events()->where('event_type', 'turn_completed')->count());
    }

    /** @return array{string, ConversationSession} */
    private function voiceSession(string $email): array
    {
        $token = $this->apiToken($email);
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        return [$token, ConversationSession::findOrFail($sessionId)];
    }

    private function admit(
        string $token,
        ConversationSession $session,
        string $turnId,
        string $transcript,
    ): VoiceTurn {
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
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
        ])->assertCreated()
            ->assertJsonPath('data.turn.state', VoiceTurnState::Accepted->value);

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }

    private function process(AssistantRun $run, HermesSemanticInterpreter $interpreter): void
    {
        (new ProcessAssistantRun($run->id))->handle(
            runtime: app(HermesRuntimeService::class),
            runs: app(AssistantRunService::class),
            voiceTurns: app(VoiceTurnLifecycleService::class),
            semanticInterpreter: $interpreter,
        );
    }
}
