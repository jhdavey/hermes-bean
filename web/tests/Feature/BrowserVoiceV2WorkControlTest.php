<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticOperation;
use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Models\Task;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class BrowserVoiceV2WorkControlTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    public function test_semantic_jobs_enforce_three_write_capacity_while_a_staged_read_bypasses_background_work(): void
    {
        [$token, $session] = $this->voiceSession('semantic-scheduler-capacity@example.com');
        $writes = $this->admit(
            $token,
            $session,
            'semantic-capacity-writes-0001',
            'Create four independent launch tasks.',
        );
        $interpreter = Mockery::mock(HermesSemanticInterpreter::class);
        $interpreter->shouldReceive('interpret')->twice()->ordered()->andReturn(
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create those tasks.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('task_1', 'app.task.create', $this->taskCreateArguments('Launch task one')),
                    new HermesSemanticOperation('task_2', 'app.task.create', $this->taskCreateArguments('Launch task two')),
                    new HermesSemanticOperation('task_3', 'app.task.create', $this->taskCreateArguments('Launch task three')),
                    new HermesSemanticOperation('task_4', 'app.task.create', $this->taskCreateArguments('Launch task four')),
                ],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('clock', 'system.clock.read', ['kind' => 'time'])],
            ),
        );
        $interpreter->shouldReceive('compose')->twice()->andReturn(
            new HermesSemanticComposition('It is 2:00 p.m.', false, false),
            new HermesSemanticComposition('I created all four launch tasks.', false, false),
        );

        $this->process($writes->runs()->sole(), $interpreter);
        $writeRuns = $this->operationRuns($writes);
        $this->assertCount(4, $writeRuns);
        $this->assertTrue($writeRuns->every(fn (AssistantRun $run): bool => $run->lane === VoiceTurnLane::AppWrite->value));
        $this->assertTrue($writeRuns->every(fn (AssistantRun $run): bool => $run->resource_lock_key === null));

        $lifecycle = app(VoiceTurnLifecycleService::class);
        $executor = app(HermesSemanticOperationExecutor::class);
        $this->assertTrue($lifecycle->claimJobExecution($writeRuns[0]));
        $this->assertTrue($lifecycle->claimJobExecution($writeRuns[1]));
        $this->assertTrue($lifecycle->claimJobExecution($writeRuns[2]));
        $this->assertFalse($lifecycle->claimJobExecution($writeRuns[3]));
        $this->assertNotNull(data_get($writeRuns[3]->fresh()->metadata, 'capacity_wait_started_at'));

        // A new request admitted while all three write slots are occupied must
        // still cross Hermes and publish its read final before those writes end.
        $read = $this->admit(
            $token,
            $session,
            'semantic-capacity-read-0002',
            'What time is it?',
        );
        $this->process($read->runs()->sole(), $interpreter);
        $readRun = $this->operationRuns($read)->sole();
        $this->assertSame(VoiceTurnLane::AppRead->value, $readRun->lane);
        $this->process($readRun, $interpreter);
        $readComposition = $read->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $this->process($readComposition, $interpreter);

        $readTerminal = $read->fresh('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Completed, $readTerminal->state);
        $this->assertSame('It is 2:00 p.m.', $readTerminal->finalAssistantMessage?->content);
        $this->assertSame(3, AssistantRun::where('conversation_session_id', $session->id)
            ->where('lane', VoiceTurnLane::AppWrite->value)
            ->where('status', 'running')
            ->count());
        $this->assertSame('queued', $writeRuns[3]->fresh()->status);

        $this->finishClaimedOperation($writes, $writeRuns[0], $executor, $lifecycle);
        $this->assertTrue($lifecycle->claimJobExecution($writeRuns[3]));
        $this->finishClaimedOperation($writes, $writeRuns[3], $executor, $lifecycle);
        $this->finishClaimedOperation($writes, $writeRuns[1], $executor, $lifecycle);
        $this->finishClaimedOperation($writes, $writeRuns[2], $executor, $lifecycle);
        $writeComposition = $writes->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $this->process($writeComposition, $interpreter);

        $writeTerminal = $writes->fresh('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Completed, $writeTerminal->state);
        $this->assertSame('I created all four launch tasks.', $writeTerminal->finalAssistantMessage?->content);
        $this->assertSame(4, Task::where('workspace_id', $session->workspace_id)
            ->where('title', 'like', 'Launch task %')
            ->count());
    }

    /** @return array<string,mixed> */
    private function taskCreateArguments(string $title): array
    {
        return [
            'title' => $title,
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

    public function test_semantic_mutations_on_one_resolved_resource_serialize_and_deletion_has_priority(): void
    {
        [$token, $session] = $this->voiceSession('semantic-scheduler-resource@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Launch checklist',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-resource-priority-0001',
            'Rename the resolved launch task, then delete it.',
        );
        $interpreter = Mockery::mock(HermesSemanticInterpreter::class);
        $interpreter->shouldReceive('interpret')->once()->andReturn(
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('rename', 'app.task.update', [
                        'id' => $task->id,
                        'title' => 'Updated launch checklist',
                    ]),
                    new HermesSemanticOperation('delete', 'app.task.delete', ['id' => $task->id]),
                ],
            ),
        );
        $interpreter->shouldReceive('compose')->once()->andReturn(new HermesSemanticComposition(
            'I deleted the launch checklist; the queued rename could not be applied afterward.',
            false,
            false,
        ));
        $this->process($turn->runs()->sole(), $interpreter);

        $runs = $this->operationRuns($turn)->keyBy(
            fn (AssistantRun $run): string => (string) data_get($run->metadata, 'semantic_operation_id'),
        );
        $rename = $runs->get('rename');
        $delete = $runs->get('delete');
        $this->assertInstanceOf(AssistantRun::class, $rename);
        $this->assertInstanceOf(AssistantRun::class, $delete);
        $this->assertSame($rename->resource_lock_key, $delete->resource_lock_key);
        $this->assertNotNull($rename->resource_lock_key);
        $this->assertSame(50, $rename->priority);
        $this->assertSame(100, $delete->priority);

        $lifecycle = app(VoiceTurnLifecycleService::class);
        $executor = app(HermesSemanticOperationExecutor::class);
        $this->assertFalse($lifecycle->claimJobExecution($rename));
        $this->assertNotNull(data_get($rename->fresh()->metadata, 'priority_wait_started_at'));
        $this->assertTrue($lifecycle->claimJobExecution($delete));
        $this->assertFalse($lifecycle->claimJobExecution($rename));
        $this->assertNotNull(data_get($rename->fresh()->metadata, 'resource_wait_started_at'));

        $receipt = $executor->executeRun($turn->fresh(), $delete->fresh());
        $lifecycle->finishJob(
            $delete,
            'completed',
            finalText: 'Deleted the launch checklist.',
            sideEffectStatus: $executor->receiptSideEffectStatus($receipt),
            metadata: ['semantic_operation_receipt' => $receipt],
        );

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertTrue($lifecycle->claimJobExecution($rename));
        $this->assertSame('running', $rename->fresh()->status);
        try {
            $executor->executeRun($turn->fresh(), $rename->fresh());
            $this->fail('The queued rename must not claim success after the resolved task was deleted.');
        } catch (\Throwable $exception) {
            $failedReceipt = $executor->recordFailureReceipt($rename->fresh(), $exception);
            $this->assertSame('failed', $failedReceipt['status']);
            $this->assertSame('stale_target', data_get($failedReceipt, 'data.category'));
            $this->assertSame('target_changed_after_staging', data_get($failedReceipt, 'data.internal_detail'));
            $this->assertSame('operation', data_get($failedReceipt, 'data.failure_scope'));
            $this->assertFalse((bool) ($failedReceipt['side_effect_committed'] ?? true));
            $this->assertArrayNotHasKey('message', (array) data_get($failedReceipt, 'data'));
            $lifecycle->finishJob(
                $rename,
                'completed',
                finalText: 'The rename could not be applied.',
                sideEffectStatus: $executor->receiptSideEffectStatus($failedReceipt),
                metadata: ['semantic_operation_receipt' => $failedReceipt],
            );
        }
        $composition = $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)
            ->sole();
        $this->process($composition, $interpreter);

        $terminal = $turn->fresh('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertSame('completed', $rename->fresh()->status);
        $this->assertSame('failed', data_get($rename->fresh()->metadata, 'semantic_operation_receipt.status'));
        $this->assertSame(
            'I deleted the launch checklist; the queued rename could not be applied afterward.',
            $terminal->finalAssistantMessage?->content,
        );
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

    /** @return Collection<int, AssistantRun> */
    private function operationRuns(VoiceTurn $turn): Collection
    {
        return $turn->fresh()->runs()
            ->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)
            ->orderBy('id')
            ->get();
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

    private function finishClaimedOperation(
        VoiceTurn $turn,
        AssistantRun $run,
        HermesSemanticOperationExecutor $executor,
        VoiceTurnLifecycleService $lifecycle,
    ): void {
        $receipt = $executor->executeRun($turn->fresh(), $run->fresh());
        $lifecycle->finishJob(
            $run,
            'completed',
            finalText: 'Typed operation completed.',
            sideEffectStatus: $executor->receiptSideEffectStatus($receipt),
            metadata: ['semantic_operation_receipt' => $receipt],
        );
    }
}
