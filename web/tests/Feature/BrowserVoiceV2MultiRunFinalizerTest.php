<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\VoiceTurnConflictException;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
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

    public function test_process_worker_marks_each_job_complete_and_only_the_last_required_job_creates_one_named_final(): void
    {
        [$turn, $primary] = $this->complexTurn('multi-run-process@example.com', 'multi-run-process-0001');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $secondary = $lifecycle->createJob(
            $turn,
            'Save meal plan note',
            'save-note',
            metadata: ['required' => true],
        )['run'];
        try {
            $lifecycle->complete($turn, 'A premature result from only one job.');
            $this->fail('Multi-run completion must be owned by the required-job barrier.');
        } catch (VoiceTurnConflictException) {
            $this->assertNull($turn->fresh()->final_assistant_message_id);
        }
        $runtime = Mockery::mock(HermesRuntimeService::class);
        $runtime->shouldReceive('sendExistingMessage')
            ->twice()
            ->andReturnUsing(function (ConversationSession $session, ConversationMessage $message) use ($primary): array {
                $runId = (int) data_get($message->metadata, 'assistant_run_id');
                $content = $runId === $primary->id
                    ? 'I drafted the three-day meal plan.'
                    : 'I saved the meal plan note.';
                $provisional = ConversationMessage::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'role' => 'assistant',
                    'content' => $content,
                    'metadata' => ['assistant_run_id' => $runId],
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

        $this->process($primary, $runtime);

        $turn->refresh();
        $this->assertSame(VoiceTurnState::Running, $turn->state);
        $this->assertSame('completed', $primary->fresh()->status);
        $this->assertSame('queued', $secondary->fresh()->status);
        $this->assertNull($turn->final_assistant_message_id);
        $this->assertSame(0, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());

        $this->process($secondary, $runtime);

        $turn->refresh()->load(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
        $this->assertStringContainsString('Work on request: I drafted the three-day meal plan.', $turn->finalAssistantMessage->content);
        $this->assertStringContainsString('Save meal plan note: I saved the meal plan note.', $turn->finalAssistantMessage->content);
        $this->assertSame(
            [$turn->final_assistant_message_id],
            $turn->runs->pluck('assistant_message_id')->unique()->values()->all(),
        );

        // A duplicate queue delivery cannot replay work or create another final.
        $this->process($secondary->fresh(), $runtime);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
        $this->assertSame(1, $turn->events()->where('event_type', 'turn_completed')->count());
    }

    public function test_failed_required_job_waits_for_the_barrier_then_produces_one_coherent_failure_without_completing_siblings(): void
    {
        [$turn, $primary] = $this->complexTurn('multi-run-failure@example.com', 'multi-run-failure-0001');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $failed = $lifecycle->createJob($turn, 'Save note', 'save-note')['run'];
        $last = $lifecycle->createJob($turn, 'Create reminder', 'create-reminder')['run'];

        $lifecycle->finishJob($primary, 'completed', 'I drafted the plan.');
        $lifecycle->finishJob(
            $failed,
            'failed',
            failureCategory: 'note_write_failed',
            internalDetail: 'The note provider rejected the write.',
            userFacingFailure: 'I couldn’t save the note. Would you like me to try again?',
            sideEffectStatus: VoiceTurnSideEffectStatus::NotCommitted,
        );

        $this->assertSame(VoiceTurnState::Running, $turn->fresh()->state);
        $this->assertSame('queued', $last->fresh()->status);
        $this->assertNull($turn->fresh()->final_assistant_message_id);

        $terminal = $lifecycle->finishJob($last, 'completed', 'I created the reminder.');

        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame('completed', $primary->fresh()->status);
        $this->assertSame('failed', $failed->fresh()->status);
        $this->assertSame('completed', $last->fresh()->status);
        $this->assertStringContainsString('I finished Work on request, Create reminder', $terminal->finalAssistantMessage->content);
        $this->assertStringContainsString('Failed: Save note.', $terminal->finalAssistantMessage->content);
        $this->assertStringContainsString('Would you like me to try the failed work again?', $terminal->finalAssistantMessage->content);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());

        $deduplicated = $lifecycle->finishJob($last->fresh(), 'completed', 'A duplicate result.');
        $this->assertSame($terminal->final_assistant_message_id, $deduplicated->final_assistant_message_id);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_canceling_one_required_job_preserves_completed_siblings_and_terminalizes_once_after_reconciliation(): void
    {
        [$turn, $primary] = $this->complexTurn('multi-run-cancel@example.com', 'multi-run-cancel-0001');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $secondary = $lifecycle->createJob($turn, 'Save note', 'save-note')['run'];

        $lifecycle->finishJob($primary, 'completed', 'I drafted the plan.');
        $terminal = $lifecycle->cancelJob($secondary, 'user_requested_subtask_cancel');

        $this->assertSame(VoiceTurnState::Canceled, $terminal->state);
        $this->assertSame('completed', $primary->fresh()->status);
        $this->assertSame('cancelled', $secondary->fresh()->status);
        $this->assertNull($terminal->final_assistant_message_id);
        $this->assertSame(0, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
        $this->assertSame(1, $turn->events()->where('event_type', 'turn_canceled')->count());

        $deduplicated = $lifecycle->cancelJob($secondary->fresh(), 'duplicate_cancel');
        $this->assertSame(VoiceTurnState::Canceled, $deduplicated->state);
        $this->assertSame(1, $turn->events()->where('event_type', 'turn_canceled')->count());
    }

    /** @return array{VoiceTurn, AssistantRun} */
    private function complexTurn(string $email, string $turnId): array
    {
        $token = $this->apiToken($email);
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => 'Create a detailed three-day meal plan.',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
        ])->assertCreated();
        $turn = VoiceTurn::where('turn_id', $turnId)->firstOrFail();

        return [$turn, $turn->runs()->firstOrFail()];
    }

    private function process(AssistantRun $run, HermesRuntimeService $runtime): void
    {
        (new ProcessAssistantRun($run->id))->handle(
            $runtime,
            app(AssistantRunService::class),
            app(VoiceTurnLifecycleService::class),
        );
    }
}
