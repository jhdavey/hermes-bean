<?php

namespace Tests\Feature;

use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Reminder;
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

class BrowserVoiceV2ConversationContextTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    public function test_stale_pronoun_reaches_hermes_without_authorized_prior_conversation_and_clarifies_without_writing(): void
    {
        [$token, $session] = $this->voiceSession('semantic-context-stale@example.com');
        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Keep this old reminder',
            'remind_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
        $source = $this->admit(
            $token,
            $session,
            'semantic-context-old-0001',
            'What reminders do I have?',
            'new_conversation',
            4,
        );
        $stale = $this->admit(
            $token,
            $session,
            'semantic-context-stale-0002',
            'Delete that reminder.',
            'new_conversation',
            5,
        );

        $requests = [];
        $interpretations = [
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'You have one reminder.',
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Which reminder should I delete?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
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

        $this->process($source->runs()->sole(), $interpreter);
        $this->process($stale->runs()->sole(), $interpreter);

        $stale->refresh();
        $this->assertSame(VoiceTurnState::AwaitingClarification, $stale->state);
        $this->assertSame('Which reminder should I delete?', data_get($stale->metadata, 'clarification_question'));
        $this->assertFalse((bool) data_get($requests[1]->context, 'conversation_reference_scope.authorized'));
        $this->assertFalse(collect(data_get($requests[1]->context, 'authorized_conversation', []))->contains(
            fn (array $message): bool => ($message['stable_turn_id'] ?? null) === $source->turn_id,
        ));
        $this->assertSame(1, $stale->runs()->count());
        $this->assertSame(0, $stale->runs()->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)->count());
        $this->assertDatabaseHas('reminders', ['id' => $reminder->id]);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $stale->turn_id)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('client_turn_id', $stale->turn_id)->where('role', 'assistant')->count());
    }

    public function test_conversation_scope_is_part_of_the_stable_semantic_turn_fingerprint(): void
    {
        [$token, $session] = $this->voiceSession('semantic-context-fingerprint@example.com');
        $payload = $this->payload(
            $session,
            'semantic-context-fingerprint-0001',
            'What time is it?',
            'new_conversation',
            3,
        );

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertCreated();
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)
            ->assertOk()
            ->assertJsonCount(1, 'data.jobs');
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            ...$payload,
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 4],
        ])->assertConflict();

        $turn = VoiceTurn::where('turn_id', 'semantic-context-fingerprint-0001')->sole();
        $this->assertSame(3, data_get($turn->metadata, 'conversation_context.epoch'));
        $this->assertSame(1, $turn->runs()->count());
        $this->assertSame(HermesSemanticOperationExecutor::INTERPRETATION_HANDLER, $turn->runs()->sole()->handler);
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
        string $mode,
        int $epoch,
    ): VoiceTurn {
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $session,
            $turnId,
            $transcript,
            $mode,
            $epoch,
        ))->assertCreated()
            ->assertJsonPath('data.turn.state', VoiceTurnState::Accepted->value);

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(
        ConversationSession $session,
        string $turnId,
        string $transcript,
        string $mode,
        int $epoch,
    ): array {
        return [
            'turn_id' => $turnId,
            'session_id' => $session->id,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
            'conversation_context' => ['mode' => $mode, 'epoch' => $epoch],
            'client_context' => [
                'voice_mode_active' => true,
                'wake_detection_enabled' => true,
                'playback_state' => 'idle',
            ],
        ];
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
