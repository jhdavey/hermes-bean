<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
use App\Models\User;
use App\Services\HermesSemanticInterpreter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyAssistantFlowTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_signed_in_user_can_plan_today_and_get_user_scoped_today_summary(): void
    {
        Carbon::setTestNow('2026-05-12T12:00:00Z');

        $aliceToken = $this->apiToken('alice@example.com');
        $bobToken = $this->apiToken('bob@example.com');

        $sessionId = $this->withToken($aliceToken)->postJson('/api/assistant/sessions', [
            'title' => 'Today',
            'metadata' => ['intent' => 'daily_planning'],
        ])->assertCreated()->json('data.id');

        $interpreter = new DailyAssistantSemanticInterpreter(
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll plan that now.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('create-task', 'app.task.create', [
                        'title' => 'Review launch notes',
                        'type' => 'todo',
                        'status' => 'open',
                        'notes' => null,
                        'category' => null,
                        'color' => '#34C759',
                        'is_critical' => false,
                        'due_at' => null,
                        'completed_at' => null,
                        'recurrence' => 'none',
                    ]),
                    new HermesSemanticOperation('create-reminder', 'app.reminder.create', [
                        'title' => 'pack laptop',
                        'notes' => null,
                        'status' => 'scheduled',
                        'category' => null,
                        'color' => '#34C759',
                        'is_critical' => false,
                        'remind_at' => '2026-05-13T09:00:00Z',
                        'recurrence' => 'none',
                        'calendar_event_id' => null,
                    ]),
                    new HermesSemanticOperation('create-event', 'app.calendar.create', [
                        'title' => 'Focus block',
                        'description' => null,
                        'location' => null,
                        'category' => null,
                        'color' => '#34C759',
                        'is_critical' => false,
                        'recurrence' => 'none',
                        'starts_at' => '2026-05-13T09:00:00Z',
                        'ends_at' => '2026-05-13T10:00:00Z',
                        'status' => 'scheduled',
                        'all_day' => false,
                    ]),
                ],
            ),
            new HermesSemanticComposition('Planned your day.', false, false),
        );
        $this->app->instance(HermesSemanticInterpreter::class, $interpreter);

        $this->withToken($aliceToken)->postJson("/api/assistant/sessions/{$sessionId}/runs", [
            'content' => 'Plan my day: add task Review launch notes; remind me tomorrow to pack laptop; schedule Focus block tomorrow at 9am.',
            'metadata' => ['client_request_id' => 'daily-plan-1'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        foreach (['assistant.task.created', 'assistant.reminder.created', 'assistant.calendar_event.created'] as $eventType) {
            $this->assertDatabaseHas('activity_events', ['event_type' => $eventType]);
        }

        $summary = $this->withToken($aliceToken)->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'alice@example.com')
            ->assertJsonPath('data.counts.tasks', 1)
            ->assertJsonPath('data.counts.reminders', 1)
            ->assertJsonPath('data.counts.calendar_events', 1)
            ->assertJsonPath('data.counts.activity_events', 12)
            ->assertJsonFragment(['title' => 'Review launch notes'])
            ->assertJsonFragment(['title' => 'pack laptop'])
            ->assertJsonFragment(['title' => 'Focus block'])
            ->json('data');

        $this->assertSame($sessionId, $summary['session']['id']);

        $this->withToken($bobToken)->getJson('/api/today')
            ->assertOk()
            ->assertJsonPath('data.user.email', 'bob@example.com')
            ->assertJsonPath('data.counts.tasks', 0)
            ->assertJsonMissing(['title' => 'Review launch notes']);

        $this->assertCount(1, $interpreter->interpretationRequests);
        $this->assertCount(1, $interpreter->compositionRequests);
    }

    public function test_live_resource_list_endpoints_are_user_scoped(): void
    {
        $aliceToken = $this->apiToken('alice@example.com');
        $bobToken = $this->apiToken('bob@example.com');
        $bobId = User::where('email', 'bob@example.com')->value('id');

        $this->withToken($aliceToken)->postJson('/api/tasks', [
            'title' => 'Alice private task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($bobToken)->postJson('/api/tasks', [
            'title' => 'Bob private task',
            'type' => 'todo',
        ])->assertCreated();

        $this->withToken($bobToken)->getJson('/api/tasks')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.user_id', $bobId)
            ->assertJsonPath('data.0.title', 'Bob private task')
            ->assertJsonMissing(['title' => 'Alice private task']);
    }
}

final class DailyAssistantSemanticInterpreter implements HermesSemanticInterpreter
{
    /** @var list<HermesSemanticInterpretationRequest> */
    public array $interpretationRequests = [];

    /** @var list<HermesSemanticCompositionRequest> */
    public array $compositionRequests = [];

    public function __construct(
        private readonly HermesSemanticInterpretation $interpretation,
        private readonly HermesSemanticComposition $composition,
    ) {}

    public function interpret(HermesSemanticInterpretationRequest $request): HermesSemanticInterpretation
    {
        $this->interpretationRequests[] = $request;

        return $this->interpretation;
    }

    public function compose(HermesSemanticCompositionRequest $request): HermesSemanticComposition
    {
        $this->compositionRequests[] = $request;

        return $this->composition;
    }
}
