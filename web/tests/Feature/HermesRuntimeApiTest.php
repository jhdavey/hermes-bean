<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentProfileService;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticRuntimeService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class HermesRuntimeApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-13 12:00:00'));
        Queue::fake();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_runtime_can_start_resume_send_a_model_interpreted_message_and_poll_progress(): void
    {
        $this->bindSemanticFake([$this->respond('Planning complete.')]);
        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Kitchen remodel',
            'metadata' => ['source' => 'feature-test'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.session_kind', 'onboarding')
            ->json('data.id');

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.status', 'active');

        $run = $this->queueAndRun($token, $sessionId, 'What should I do first?');
        $this->assertSame('Planning complete.', $run->assistantMessage?->content);

        $this->withToken($token)->getJson("/api/assistant/runs/{$run->id}")
            ->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.assistant_message.content', 'Planning complete.');

        $events = $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->json('data');
        $this->assertSame([
            'runtime.session_started',
            'runtime.session_resumed',
            'runtime.run_queued',
            'runtime.run_started',
            'runtime.semantic_interpretation_started',
            'runtime.semantic_interpretation_completed',
            'runtime.run_completed',
        ], collect($events)->pluck('event_type')->all());
    }

    public function test_session_kind_is_derived_by_the_server_and_never_selects_a_runtime(): void
    {
        $token = $this->apiToken('server-owned-session-kind@example.com');
        $user = User::query()->where('email', 'server-owned-session-kind@example.com')->firstOrFail();
        $profiles = app(AgentProfileService::class);
        $profiles->applyOnboarding($profiles->ensureForUser($user), [
            'agent_personality' => 'balanced',
            'onboarding_priorities' => ['Planning'],
            'onboarding_context' => 'Session-kind contract test.',
        ], 'test');
        $user->forceFill(['onboard_complete' => true])->save();

        $session = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Ordinary conversation',
            'session_kind' => 'onboarding',
        ])->assertCreated()
            ->assertJsonPath('data.session_kind', 'conversation')
            ->json('data');

        $this->assertDatabaseHas('conversation_sessions', [
            'id' => $session['id'],
            'session_kind' => 'conversation',
        ]);
        $this->assertInstanceOf(HermesSemanticRuntimeService::class, app(HermesRuntimeService::class));
    }

    public function test_web_queued_chat_returns_a_background_run_without_inline_model_work(): void
    {
        Queue::fake();
        $fake = $this->bindSemanticFake([$this->respond('Should not run inline.')]);
        $token = $this->apiToken('web-queued-chat@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Add a task to take out trash tonight.',
            'metadata' => [
                'client_request_id' => 'web-chat-test-1',
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.assistant_message', null)
            ->assertJsonPath('data.run.status', 'queued');

        Queue::assertPushed(ProcessAssistantRun::class);
        $this->assertCount(0, $fake->interpretationRequests);
    }

    public function test_rapid_submits_are_both_durably_admitted_in_order_and_exposed_for_reload_recovery(): void
    {
        Queue::fake();
        $token = $this->apiToken('rapid-durable-chat@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $first = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'First durable request.',
            'metadata' => [
                'client_request_id' => 'rapid-durable-chat-0001',
            ],
        ])->assertCreated();
        $second = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Second durable request.',
            'metadata' => [
                'client_request_id' => 'rapid-durable-chat-0002',
            ],
        ])->assertCreated();

        $firstRunId = (int) $first->json('data.run.id');
        $secondRunId = (int) $second->json('data.run.id');
        $this->assertGreaterThan($firstRunId, $secondRunId);
        $this->assertSame(2, AssistantRun::query()
            ->where('conversation_session_id', $sessionId)
            ->whereNull('voice_turn_id')
            ->count());
        $this->assertSame(
            ['First durable request.', 'Second durable request.'],
            ConversationMessage::query()
                ->where('conversation_session_id', $sessionId)
                ->where('role', 'user')
                ->orderBy('id')
                ->pluck('content')
                ->all(),
        );

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.assistant_runs.0.id', $firstRunId)
            ->assertJsonPath('data.assistant_runs.0.status', 'queued')
            ->assertJsonPath('data.assistant_runs.1.id', $secondRunId)
            ->assertJsonPath('data.assistant_runs.1.status', 'queued')
            ->assertJsonPath('data.assistant_runs.0.user_message.content', 'First durable request.')
            ->assertJsonPath('data.assistant_runs.1.user_message.content', 'Second durable request.');

        $secondRun = AssistantRun::query()->findOrFail($secondRunId);
        $secondAssistant = ConversationMessage::query()->create([
            'user_id' => $secondRun->user_id,
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Second final arrived first.',
        ]);
        $secondRun->forceFill([
            'assistant_message_id' => $secondAssistant->id,
            'status' => 'completed',
            'completed_at' => now(),
        ])->saveQuietly();

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/runs/lookup?client_request_id=rapid-durable-chat-0001")
            ->assertStatus(202)
            ->assertJsonPath('data.run.id', $firstRunId)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.assistant_message', null);
        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/runs/lookup?client_request_id=rapid-durable-chat-0002")
            ->assertOk()
            ->assertJsonPath('data.run.id', $secondRunId)
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Second final arrived first.');

        Queue::assertPushed(ProcessAssistantRun::class, 2);
    }

    public function test_message_branch_preserves_immutable_history_and_excludes_the_replaced_turn_from_hermes_context(): void
    {
        $fake = $this->bindSemanticFake([
            $this->respond('Old answer.'),
            $this->respond('Updated answer.'),
        ]);
        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $originalRun = $this->queueAndRun($token, $sessionId, 'Plan today');
        $originalMessageId = $originalRun->user_message_id;
        $originalAssistantMessageId = $originalRun->assistant_message_id;

        $branchPayload = [
            'content' => 'Plan tomorrow',
            'metadata' => [
                'source' => 'web',
                'client_request_id' => 'branch-plan-tomorrow',
            ],
        ];
        $response = $this->withToken($token)->postJson(
            "/api/assistant/sessions/{$sessionId}/messages/{$originalMessageId}/branch",
            $branchPayload,
        )->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.user_message.content', 'Plan tomorrow')
            ->assertJsonPath('data.user_message.metadata.edited_from_message_id', $originalMessageId);
        $updatedRunId = (int) $response->json('data.run.id');
        $updatedRun = $this->executeRun($updatedRunId);
        $this->assertSame('Updated answer.', $updatedRun->assistantMessage?->content);

        $this->withToken($token)->postJson(
            "/api/assistant/sessions/{$sessionId}/messages/{$originalMessageId}/branch",
            $branchPayload,
        )->assertOk()
            ->assertJsonPath('data.run.id', $updatedRunId)
            ->assertJsonPath('data.assistant_message.content', 'Updated answer.');

        $this->assertDatabaseHas('conversation_messages', [
            'id' => $originalMessageId,
            'content' => 'Plan today',
        ]);
        $this->assertDatabaseHas('conversation_messages', [
            'id' => $originalAssistantMessageId,
            'content' => 'Old answer.',
        ]);
        $originalRun->refresh();
        $this->assertSame($originalMessageId, $originalRun->user_message_id);
        $this->assertSame($originalAssistantMessageId, $originalRun->assistant_message_id);
        $this->assertSame(1, AssistantRun::where('metadata->client_request_id', 'branch-plan-tomorrow')->count());

        $branchRequestMessages = collect(data_get($fake->interpretationRequests[1]->context, 'authorized_conversation', []))
            ->pluck('content')
            ->all();
        $this->assertSame(['Plan tomorrow'], $branchRequestMessages);

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonFragment(['content' => 'Plan today'])
            ->assertJsonFragment(['content' => 'Old answer.'])
            ->assertJsonFragment(['content' => 'Plan tomorrow'])
            ->assertJsonFragment(['content' => 'Updated answer.']);
    }

    public function test_branching_from_a_completed_voice_turn_preserves_voice_messages_and_every_lifecycle_pointer(): void
    {
        $this->bindSemanticFake([$this->respond('Updated voice request.')]);
        $token = $this->apiToken('voice-history-branch@example.com');
        $user = User::where('email', 'voice-history-branch@example.com')->firstOrFail();
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $session = ConversationSession::findOrFail($sessionId);
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $turn = $lifecycle->admit($user, $session, [
            'turn_id' => 'voice-history-branch-turn-0001',
            'transcript' => 'Plan today by voice.',
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
        ]);
        $turn = $lifecycle->complete($turn, 'The original literal voice final.');
        $voiceUserMessageId = $turn->user_message_id;
        $voiceFinalMessageId = $turn->final_assistant_message_id;
        $voiceRunIds = $turn->runs()->pluck('id')->all();

        $response = $this->withToken($token)->postJson(
            "/api/assistant/sessions/{$sessionId}/messages/{$voiceUserMessageId}/branch",
            [
                'content' => 'Plan tomorrow by chat instead.',
                'metadata' => [
                    'source' => 'web',
                    'client_request_id' => 'voice-history-branch-edit-0001',
                    'edited_from_message_id' => 999999,
                    'edited_message_id' => 999999,
                ],
            ],
        )->assertCreated()
            ->assertJsonPath('data.user_message.metadata.edited_from_message_id', $voiceUserMessageId)
            ->assertJsonMissingPath('data.user_message.metadata.edited_message_id');
        $branchRun = $this->executeRun((int) $response->json('data.run.id'));

        $turn->refresh();
        $this->assertSame($voiceUserMessageId, $turn->user_message_id);
        $this->assertSame($voiceFinalMessageId, $turn->final_assistant_message_id);
        $this->assertSame($voiceRunIds, $turn->runs()->pluck('id')->all());
        foreach ($turn->runs as $voiceRun) {
            $this->assertSame($voiceUserMessageId, $voiceRun->user_message_id);
            $this->assertSame($voiceFinalMessageId, $voiceRun->assistant_message_id);
        }
        $this->assertSame($voiceUserMessageId, $branchRun->metadata['edited_from_message_id'] ?? null);

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonFragment(['content' => 'Plan today by voice.'])
            ->assertJsonFragment(['content' => 'The original literal voice final.'])
            ->assertJsonFragment(['content' => 'Plan tomorrow by chat instead.'])
            ->assertJsonFragment(['content' => 'Updated voice request.']);
    }

    public function test_hermes_assistant_copy_is_preserved_when_serialized(): void
    {
        $token = $this->apiToken('copy-preserved@example.com');
        $user = User::where('email', 'copy-preserved@example.com')->firstOrFail();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        foreach ([
            'Bean could not finish that request.',
            'Bean hit a snag while trying to handle that request.',
            'HermesApiException(statusCode: 502)',
        ] as $content) {
            ConversationMessage::create([
                'user_id' => $user->id,
                'conversation_session_id' => $sessionId,
                'role' => 'assistant',
                'content' => $content,
            ]);
        }

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.messages.0.content', 'Bean could not finish that request.')
            ->assertJsonPath('data.messages.1.content', 'Bean hit a snag while trying to handle that request.')
            ->assertJsonPath('data.messages.2.content', 'HermesApiException(statusCode: 502)');
    }

    public function test_model_selected_canonical_tools_persist_resources_and_literal_times(): void
    {
        $this->bindSemanticFake([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('call_task', 'app.task.create', [
                        'title' => 'Persist DB task',
                        'due_at' => '2026-05-13T17:00:00Z',
                    ]),
                    new HermesSemanticOperation('call_event', 'app.calendar.create', [
                        'title' => 'Retreat',
                        'starts_at' => '2026-05-18T13:00:00-04:00',
                        'ends_at' => '2026-05-21T20:00:00-04:00',
                    ]),
                ],
            ),
        ], [new HermesSemanticComposition('Saved the task and retreat.', false, false)]);

        $token = $this->apiToken();
        $userId = User::where('email', 'test@example.com')->value('id');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $run = $this->queueAndRun($token, $sessionId, 'Save a task and the retreat.', [
            'client_context' => [
                'timezone' => 'America/New_York',
                'current_local_time' => '2026-05-13T08:00:00-04:00',
            ],
        ]);
        $this->assertSame('Saved the task and retreat.', $run->assistantMessage?->content);
        $this->assertDatabaseHas('activity_events', ['event_type' => 'assistant.task.created']);
        $this->assertDatabaseHas('activity_events', ['event_type' => 'assistant.calendar_event.created']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $userId,
            'conversation_session_id' => $sessionId,
            'title' => 'Persist DB task',
        ]);
        $event = CalendarEvent::where('conversation_session_id', $sessionId)->where('title', 'Retreat')->firstOrFail();
        $this->assertSame('2026-05-18T17:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-22T00:00:00+00:00', $event->ends_at->utc()->toIso8601String());
        $this->assertSame(1, Task::where('conversation_session_id', $sessionId)->count());
    }

    private function queueAndRun(string $token, int $sessionId, string $content, array $metadata = []): AssistantRun
    {
        $metadata = array_merge([
            'client_request_id' => 'test-'.str()->uuid(),
        ], $metadata);
        $response = $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => $content,
            'metadata' => $metadata,
        ])->assertCreated()
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.assistant_message', null);

        return $this->executeRun((int) $response->json('data.run.id'));
    }

    private function executeRun(int $runId): AssistantRun
    {
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

    /**
     * @param  list<HermesSemanticInterpretation|\Throwable>  $interpretations
     * @param  list<HermesSemanticComposition|\Throwable>  $compositions
     */
    private function bindSemanticFake(array $interpretations, array $compositions = []): HermesRuntimeApiSemanticFake
    {
        $fake = new HermesRuntimeApiSemanticFake($interpretations, $compositions);
        $this->app->instance(HermesSemanticInterpreter::class, $fake);

        return $fake;
    }

    private function respond(string $content): HermesSemanticInterpretation
    {
        return new HermesSemanticInterpretation(
            outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
            responseText: $content,
            clarificationQuestion: null,
            acknowledgementText: null,
            closeAfterResponse: false,
            responseExpected: false,
            operations: [],
        );
    }
}

final class HermesRuntimeApiSemanticFake implements HermesSemanticInterpreter
{
    /** @var list<HermesSemanticInterpretationRequest> */
    public array $interpretationRequests = [];

    /** @var list<HermesSemanticCompositionRequest> */
    public array $compositionRequests = [];

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
            throw new RuntimeException('No Hermes runtime API interpretation remains.');
        }

        return $next;
    }

    public function compose(HermesSemanticCompositionRequest $request): HermesSemanticComposition
    {
        $this->compositionRequests[] = $request;
        $next = array_shift($this->compositions);
        if ($next instanceof \Throwable) {
            throw $next;
        }
        if (! $next instanceof HermesSemanticComposition) {
            throw new RuntimeException('No Hermes runtime API composition remains.');
        }

        return $next;
    }
}
