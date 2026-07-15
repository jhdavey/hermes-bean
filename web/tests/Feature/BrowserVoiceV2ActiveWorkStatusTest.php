<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticOperation;
use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
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

class BrowserVoiceV2ActiveWorkStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    public function test_hermes_resolves_status_to_the_intended_background_turn_instead_of_the_latest_turn(): void
    {
        [$token, $session] = $this->voiceSession('voice-semantic-active-status@example.com');
        $background = $this->admit(
            $token,
            $session,
            'semantic-status-background-0001',
            'Create a detailed seven-day travel plan.',
        );
        $interjected = $this->admit(
            $token,
            $session,
            'semantic-status-time-0002',
            'What time is it?',
        );
        $status = $this->admit(
            $token,
            $session,
            'semantic-status-question-0003',
            'Did you finish that?',
        );

        $compositionRequest = null;
        $interpreter = Mockery::mock(HermesSemanticInterpreter::class);
        $interpreter->shouldReceive('interpret')->twice()->ordered()->andReturn(
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'It is 2:00 p.m.',
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
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('status', 'voice.work.status', [
                    'target_turn_id' => $background->turn_id,
                ])],
            ),
        );
        $interpreter->shouldReceive('compose')
            ->once()
            ->andReturnUsing(function (HermesSemanticCompositionRequest $request) use (&$compositionRequest): HermesSemanticComposition {
                $compositionRequest = $request;

                return new HermesSemanticComposition('I’m still working on the travel-plan request.', false, false);
            });

        $this->process($interjected->runs()->sole(), $interpreter);
        $this->drainTurn($status, $interpreter);

        $background->refresh();
        $status->refresh()->load('finalAssistantMessage');
        $this->assertSame(VoiceTurnState::Accepted, $background->state);
        $this->assertSame('queued', $background->runs()->sole()->status);
        $this->assertSame(VoiceTurnState::Completed, $interjected->fresh()->state);
        $this->assertSame(VoiceTurnState::Completed, $status->state);
        $this->assertSame('I’m still working on the travel-plan request.', $status->finalAssistantMessage?->content);
        $this->assertInstanceOf(HermesSemanticCompositionRequest::class, $compositionRequest);
        $this->assertCount(1, $compositionRequest->results);
        $this->assertSame($background->turn_id, $compositionRequest->results[0]->data['stable_turn_id']);
        $this->assertSame(VoiceTurnState::Accepted->value, $compositionRequest->results[0]->data['state']);
        $this->assertSame(1, $status->runs()->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertSame(1, $status->runs()->where('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER)->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $status->turn_id)
            ->where('role', 'assistant')
            ->count());
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
            'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 12],
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

    private function drainTurn(VoiceTurn $turn, HermesSemanticInterpreter $interpreter): void
    {
        for ($pass = 0; $pass < 10; $pass++) {
            $fresh = $turn->fresh();
            if (! $fresh instanceof VoiceTurn || $fresh->state->isTerminal()) {
                return;
            }
            $queued = $fresh->runs()->where('status', 'queued')->orderBy('id')->get();
            if ($queued->isEmpty()) {
                return;
            }
            foreach ($queued as $run) {
                $this->process($run, $interpreter);
            }
        }

        $this->fail('The semantic status journey did not settle.');
    }
}
