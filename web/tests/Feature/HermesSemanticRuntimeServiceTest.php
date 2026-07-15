<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
use App\Jobs\ProcessAssistantRun;
use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Task;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class HermesSemanticRuntimeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_every_generic_request_shape_reaches_the_same_semantic_interpreter_before_execution(): void
    {
        $cases = [
            'time_date' => "What time is it, and what's today's date?",
            'conversation' => 'Thanks, that was helpful.',
            'named_resource' => 'Move the first one to the afternoon.',
            'mutation' => 'Create a task for the launch.',
            'correction' => 'Actually, make that a reminder instead.',
            'multiple_clauses' => 'Move the meeting, delete its reminder, and add a follow-up task.',
            'temporal' => 'Schedule the review for next Friday after lunch.',
            'lookup' => 'What will the weather be in Boston tomorrow?',
        ];
        $fake = new GenericSemanticInterpreterFake(array_map(
            fn (string $prompt): HermesSemanticInterpretation => $this->respond('Hermes interpreted: '.$prompt),
            array_values($cases),
        ));
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        $token = $this->apiToken('single-semantic-path@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        foreach ($cases as $prompt) {
            $run = $this->queueAndRun($token, $sessionId, $prompt, [
                'source' => 'web',
                'client_context' => [
                    'current_local_time' => '2026-07-14T10:30:00-04:00',
                    'timezone' => 'America/New_York',
                ],
            ]);
            $this->assertSame('Hermes interpreted: '.$prompt, $run->assistantMessage?->content);
        }

        $this->assertSame(array_values($cases), array_map(
            static fn (HermesSemanticInterpretationRequest $request): string => $request->transcript,
            $fake->interpretationRequests,
        ));
        $this->assertSame(0, Task::count());
        $this->assertSame(count($cases), ActivityEvent::where('event_type', 'runtime.semantic_interpretation_started')->count());
        $this->assertSame(count($cases), ActivityEvent::where('event_type', 'runtime.semantic_interpretation_completed')->count());
        $this->assertDatabaseMissing('activity_events', ['event_type' => 'runtime.intent_routed']);
        $this->assertDatabaseMissing('activity_events', ['event_type' => 'runtime.fast_response_started']);
    }

    public function test_hermes_clarification_is_the_literal_durable_final_and_is_context_for_the_next_turn(): void
    {
        $question = 'Which launch task do you want me to move?';
        $fake = new GenericSemanticInterpreterFake([
            $this->clarify($question),
            $this->respond('Got it—the product launch task.'),
        ]);
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        $token = $this->apiToken('semantic-follow-up@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $first = $this->queueAndRun($token, $sessionId, 'Move the launch task to Friday.');
        $this->assertSame($question, $first->assistantMessage?->content);
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $first->id)->where('role', 'assistant')->count());
        $this->assertSame(0, Task::count());

        $this->queueAndRun($token, $sessionId, 'The product launch one.');
        $conversation = data_get($fake->interpretationRequests[1]->context, 'authorized_conversation', []);
        $this->assertTrue(collect($conversation)->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'assistant'
                && ($message['content'] ?? null) === $question,
        ));
        $this->assertTrue(collect($conversation)->contains(
            fn (array $message): bool => ($message['role'] ?? null) === 'user'
                && ($message['content'] ?? null) === 'Move the launch task to Friday.',
        ));
    }

    public function test_schema_failure_returns_structured_feedback_to_hermes_for_its_own_clarification(): void
    {
        $fake = new GenericSemanticInterpreterFake([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create', 'app.task.create', [])],
            ),
            $this->clarify('What should I call the task?'),
        ]);
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        $token = $this->apiToken('semantic-validation-feedback@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $run = $this->queueAndRun($token, $sessionId, 'Add that as a task.');

        $this->assertSame('What should I call the task?', $run->assistantMessage?->content);
        $this->assertSame(0, Task::count());
        $this->assertCount(2, $fake->interpretationRequests);
        $feedback = data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback');
        $this->assertSame('deterministic_validation_failure', data_get($feedback, 'kind'));
        $this->assertStringContainsString('requires title', (string) data_get($feedback, 'detail'));
        $this->assertStringNotContainsString('What should I call', (string) data_get($feedback, 'detail'));
    }

    public function test_opaque_profile_mutation_is_rejected_and_hermes_owns_the_follow_up(): void
    {
        $question = 'Would you like to change my display name instead?';
        $fake = new GenericSemanticInterpreterFake([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('change_status', 'app.agent_profile.update', [
                    'status' => 'disabled',
                ])],
            ),
            $this->clarify($question),
        ]);
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        $token = $this->apiToken('semantic-profile-status-feedback@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);
        $before = AgentProfile::query()->where('workspace_id', $session->workspace_id)->sole();
        $originalStatus = $before->status;

        $run = $this->queueAndRun($token, $sessionId, 'Disable your profile.');

        $this->assertSame($question, $run->assistantMessage?->content);
        $this->assertSame($originalStatus, $before->fresh()->status);
        $this->assertCount(2, $fake->interpretationRequests);
        $feedback = data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback');
        $this->assertSame('deterministic_validation_failure', data_get($feedback, 'kind'));
        $this->assertStringContainsString('unsupported argument', (string) data_get($feedback, 'detail'));
        $this->assertStringNotContainsString($question, (string) data_get($feedback, 'detail'));
    }

    public function test_generic_mutation_uses_application_defaults_and_one_durable_receipt_and_final(): void
    {
        $fake = new GenericSemanticInterpreterFake([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    'title' => 'Buy milk',
                ])],
            ),
        ], [new HermesSemanticComposition('I added Buy milk.', false, false)]);
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        $token = $this->apiToken('semantic-generic-write@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $run = $this->queueAndRun($token, $sessionId, 'Add a task to buy milk.');

        $task = Task::where('title', 'Buy milk')->sole();
        $this->assertSame('todo', $task->type);
        $this->assertSame('open', $task->status);
        $this->assertSame('#34C759', $task->color);
        $this->assertSame('I added Buy milk.', $run->assistantMessage?->content);
        $this->assertSame(1, ActivityEvent::where('event_type', 'assistant.semantic_operation.receipt')
            ->where('payload->assistant_run_id', $run->id)
            ->count());
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());

        (new ProcessAssistantRun($run->id, (int) $run->execution_generation))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        $this->assertSame(1, Task::where('title', 'Buy milk')->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'assistant.semantic_operation.receipt')
            ->where('payload->assistant_run_id', $run->id)
            ->count());
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
    }

    public function test_retained_application_surface_executes_through_the_same_typed_plan(): void
    {
        $fake = new GenericSemanticInterpreterFake([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('folder', 'app.note_folder.create', ['name' => 'Launch']),
                    new HermesSemanticOperation('category', 'app.event_category.create', ['name' => 'Planning']),
                    new HermesSemanticOperation('blocker', 'app.blocker.create', ['reason' => 'Waiting for approval']),
                    new HermesSemanticOperation('profile', 'app.agent_profile.update', ['display_name' => 'Bean']),
                    new HermesSemanticOperation('session', 'app.conversation.update', ['title' => 'Launch plan']),
                ],
            ),
        ], [new HermesSemanticComposition('I updated the launch workspace.', false, false)]);
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        $token = $this->premiumApiToken('semantic-retained-surface@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $run = $this->queueAndRun($token, $sessionId, 'Set up the launch workspace.');

        $this->assertDatabaseHas('note_folders', ['workspace_id' => $run->workspace_id, 'name' => 'Launch', 'sort_order' => 0]);
        $this->assertDatabaseHas('event_categories', ['workspace_id' => $run->workspace_id, 'name' => 'Planning', 'color' => '#34C759']);
        $this->assertDatabaseHas('blockers', ['workspace_id' => $run->workspace_id, 'reason' => 'Waiting for approval', 'status' => 'open']);
        $this->assertSame('Bean', AgentProfile::where('workspace_id', $run->workspace_id)->sole()->display_name);
        $this->assertSame('Launch plan', ConversationSession::findOrFail($sessionId)->title);
        $this->assertSame(5, ActivityEvent::where('event_type', 'assistant.semantic_operation.receipt')
            ->where('payload->assistant_run_id', $run->id)
            ->count());
    }

    public function test_stale_generation_cannot_write_or_finalize_after_recovery_claim_wins(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-14T10:00:00-04:00'));
        config()->set('services.hermes_runtime.assistant_run_stale_seconds', 2);
        config()->set('services.hermes_runtime.assistant_run_stale_recovery_attempts', 1);
        config()->set('services.hermes_runtime.assistant_run_recovery_window_seconds', 900);

        $token = $this->apiToken('semantic-claim-generation@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);
        $runs = app(AssistantRunService::class);
        $run = $runs->queueRun($session, 'Create exactly one task after recovery.', [
            'client_request_id' => 'semantic-slow-attempt-replacement',
            'source' => 'web_chat',
        ], 'web_chat')['run'];

        $execute = new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
            responseText: null,
            clarificationQuestion: null,
            acknowledgementText: null,
            closeAfterResponse: false,
            responseExpected: false,
            operations: [new HermesSemanticOperation('create', 'app.task.create', [
                'title' => 'Recovered exactly once',
            ])],
        );
        $fake = new GenericSemanticInterpreterFake([
            function () use ($run, $runs, $execute): HermesSemanticInterpretation {
                Carbon::setTestNow(now()->addSeconds(3));
                $requeued = $runs->prepareRunForBackgroundResponse($run->fresh());
                $this->assertSame('queued', $requeued->status);
                $this->assertSame(1, (int) $requeued->execution_generation);

                (new ProcessAssistantRun($run->id, 2))->handle(
                    app(HermesRuntimeService::class),
                    $runs,
                );
                $this->assertSame('completed', $run->fresh()->status);

                return $execute;
            },
            $execute,
        ], [new HermesSemanticComposition('I created the task exactly once.', false, false)]);
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        (new ProcessAssistantRun($run->id, 1))->handle(app(HermesRuntimeService::class), $runs);

        $completed = $run->fresh(['assistantMessage']);
        $this->assertSame('completed', $completed->status);
        $this->assertSame(2, (int) $completed->execution_generation);
        $this->assertSame('I created the task exactly once.', $completed->assistantMessage?->content);
        $this->assertSame(1, Task::where('title', 'Recovered exactly once')->count());
        $this->assertSame(1, ActivityEvent::where('event_type', 'assistant.semantic_operation.receipt')
            ->where('payload->assistant_run_id', $run->id)
            ->count());
        $this->assertSame(1, ConversationMessage::where('metadata->assistant_run_id', $run->id)->where('role', 'assistant')->count());
    }

    private function queueAndRun(string $token, int $sessionId, string $content, array $metadata = []): AssistantRun
    {
        $metadata = array_merge(['client_request_id' => 'test-'.str()->uuid()], $metadata);
        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => $content,
            'metadata' => $metadata,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.assistant_message', null);
        $runId = (int) $response->json('data.run.id');
        $queuedRun = AssistantRun::findOrFail($runId);

        (new ProcessAssistantRun($runId, (int) $queuedRun->execution_generation + 1))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $run = AssistantRun::with(['assistantMessage', 'userMessage'])->findOrFail($runId);
        $this->assertSame('completed', $run->status);
        $this->assertNotNull($run->assistantMessage);

        return $run;
    }

    private function respond(string $text): HermesSemanticInterpretation
    {
        return new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
            responseText: $text,
            clarificationQuestion: null,
            acknowledgementText: null,
            closeAfterResponse: false,
            responseExpected: false,
            operations: [],
        );
    }

    private function clarify(string $question): HermesSemanticInterpretation
    {
        return new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
            responseText: null,
            clarificationQuestion: $question,
            acknowledgementText: null,
            closeAfterResponse: false,
            responseExpected: true,
            operations: [],
        );
    }
}

final class GenericSemanticInterpreterFake implements HermesSemanticInterpreter
{
    /** @var list<HermesSemanticInterpretationRequest> */
    public array $interpretationRequests = [];

    /** @var list<HermesSemanticCompositionRequest> */
    public array $compositionRequests = [];

    /**
     * @param  list<HermesSemanticInterpretation|\Closure(HermesSemanticInterpretationRequest):HermesSemanticInterpretation|\Throwable>  $interpretations
     * @param  list<HermesSemanticComposition|\Closure(HermesSemanticCompositionRequest):HermesSemanticComposition|\Throwable>  $compositions
     */
    public function __construct(
        private array $interpretations,
        private array $compositions = [],
    ) {}

    public function interpret(HermesSemanticInterpretationRequest $request): HermesSemanticInterpretation
    {
        $this->interpretationRequests[] = $request;
        $next = array_shift($this->interpretations);
        if ($next instanceof \Closure) {
            $next = $next($request);
        }
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (! $next instanceof HermesSemanticInterpretation) {
            throw new RuntimeException('No generic semantic interpretation remains.');
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
            throw new RuntimeException('No generic semantic composition remains.');
        }

        return $next;
    }
}
