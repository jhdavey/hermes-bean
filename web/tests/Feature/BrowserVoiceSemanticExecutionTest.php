<?php

namespace Tests\Feature;

use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\HermesSemanticProviderException;
use App\Jobs\EnforceBrowserVoiceTurnDeadline;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticInterpreter;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use RuntimeException;
use Tests\TestCase;

class BrowserVoiceSemanticExecutionTest extends TestCase
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

    public function test_time_date_and_voice_state_cross_hermes_before_deterministic_reads(): void
    {
        Carbon::setTestNow('2026-07-14 14:30:00', 'UTC');
        [$token, $session] = $this->conversation('semantic-system@example.com');
        $turn = $this->admit(
            $token,
            $session,
            'semantic-system-0001',
            'What time and date is it, and are you listening?',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('clock', 'system.clock.read', ['kind' => 'datetime']),
                    new HermesSemanticOperation('voice', 'system.voice_state.read', []),
                ],
            ),
        ], [
            new HermesSemanticComposition(
                'It’s Tuesday, July 14th at 10:30 a.m., and I’m listening.',
                false,
                false,
            ),
        ]);

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $interpretationRun = $terminal->runs->firstWhere('handler', 'agent.semantic');
        $this->assertNotNull($interpretationRun);
        $this->assertSame(VoiceTurnLane::Semantic->value, $interpretationRun->lane);
        $this->assertSame('It’s Tuesday, July 14th at 10:30 a.m., and I’m listening.', $terminal->finalAssistantMessage->content);
        $this->assertSame(VoiceTurnSideEffectStatus::None, $terminal->side_effect_status);
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertCount(1, $fake->compositionRequests);
        $results = $fake->compositionRequests[0]->results;
        $this->assertSame('2026-07-14T10:30:00-04:00', $results[0]->data['current_time']);
        $this->assertTrue($results[1]->data['voice_mode_active']);
        $operationRuns = $terminal->runs->filter(
            fn (AssistantRun $run): bool => data_get($run->metadata, 'role') === 'semantic_operation',
        );
        $this->assertCount(2, $operationRuns);
        $this->assertSame(
            'completed',
            data_get($operationRuns->firstWhere('metadata.semantic_operation_id', 'clock')?->metadata, 'semantic_operation_receipt.status'),
        );
        $this->assertSame(
            'completed',
            data_get($operationRuns->firstWhere('metadata.semantic_operation_id', 'voice')?->metadata, 'semantic_operation_receipt.status'),
        );
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_named_resource_reference_executes_once_from_a_typed_receipt(): void
    {
        [$token, $session] = $this->conversation('semantic-reference@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-reference-0001',
            'Move the first task, Plan the launch, to Thursday at three.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll move that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('find', 'app.task.search', [
                        'query' => 'Plan the launch',
                        'match_mode' => 'exact_title',
                        'require_unique' => true,
                        'limit' => 5,
                    ]),
                    new HermesSemanticOperation('move', 'app.task.update', [
                        'result_ref' => ['operation_id' => 'find', 'path' => 'unique_id'],
                        'due_at' => '2026-07-16T15:00:00-04:00',
                    ], ['find']),
                ],
            ),
        ], [
            new HermesSemanticComposition('I moved “Plan the launch” to Thursday at 3 p.m.', false, false),
        ]);
        $run = $turn->runs()->sole();

        $this->drainTurn($turn, $fake);
        // A duplicated queue delivery cannot interpret, mutate, or finalize a
        // second time because the run and operation receipts are durable.
        $this->process($run, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('2026-07-16T19:00:00+00:00', $task->fresh()->due_at->toIso8601String());
        $this->assertTrue($terminal->acknowledgement_required);
        $this->assertSame('I’ll move that task.', $terminal->acknowledgement_text);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertCount(1, $fake->compositionRequests);
        $moveRun = $terminal->runs->first(
            fn (AssistantRun $candidate): bool => data_get($candidate->metadata, 'semantic_operation_id') === 'move',
        );
        $this->assertInstanceOf(AssistantRun::class, $moveRun);
        $this->assertSame($task->id, data_get($moveRun->metadata, 'semantic_operation_receipt.data.events.0.data.task_id'));
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_completed_task_create_persists_only_the_explicit_hermes_completion_timestamp(): void
    {
        [$token, $session] = $this->conversation('semantic-completed-create@example.com');
        $turn = $this->admit(
            $token,
            $session,
            'semantic-completed-create-0001',
            'Add the already completed filing task.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'File receipts',
                    'type' => 'todo',
                    'status' => 'completed',
                    'completed_at' => '2026-07-14T09:15:00-04:00',
                ])],
            ),
        ], [new HermesSemanticComposition('I added File receipts as completed.', false, false)]);

        $this->drainTurn($turn, $fake);

        $task = Task::query()->where('user_id', $session->user_id)->where('title', 'File receipts')->sole();
        $this->assertSame('completed', $task->status);
        $this->assertSame('2026-07-14T13:15:00+00:00', $task->completed_at?->toIso8601String());
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
    }

    public function test_incidental_create_fields_use_application_defaults_without_a_semantic_retry(): void
    {
        [$token, $session] = $this->conversation('semantic-explicit-create-fields@example.com');
        $turn = $this->admit(
            $token,
            $session,
            'semantic-explicit-create-fields-0001',
            'Add a task called Water the plants.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll add that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create', 'app.task.create', [
                    'title' => 'Water the plants',
                ])],
            ),
        ], [new HermesSemanticComposition('I added Water the plants.', false, false)]);

        $this->drainTurn($turn, $fake);

        $task = Task::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('title', 'Water the plants')
            ->sole();
        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(0, $terminal->retry_count);
        $this->assertSame('todo', $task->type);
        $this->assertSame('open', $task->status);
        $this->assertNull($task->notes);
        $this->assertNull($task->category);
        $this->assertSame('#34C759', $task->color);
        $this->assertFalse($task->is_critical);
        $this->assertNull($task->due_at);
        $this->assertNull($task->completed_at);
        $this->assertNull(data_get($task->metadata, 'recurrence'));
        $this->assertCount(1, $fake->interpretationRequests);
        $this->assertSame('I’ll add that task.', $terminal->acknowledgement_text);
        $this->assertSame('I added Water the plants.', $terminal->finalAssistantMessage?->content);
        $this->assertSame(1, Task::query()->where('workspace_id', $session->workspace_id)->count());
        $this->assertSame(1, ConversationMessage::query()
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'user')
            ->count());
        $this->assertSame(1, ConversationMessage::query()
            ->where('client_turn_id', $turn->turn_id)
            ->where('role', 'assistant')
            ->count());
    }

    public function test_missing_trusted_voice_state_stays_unknown_instead_of_becoming_false(): void
    {
        [$token, $session] = $this->conversation('semantic-unknown-voice-state@example.com');
        $turn = $this->admit(
            $token,
            $session,
            'semantic-unknown-voice-state-0001',
            'Are voice mode and wake detection active?',
        );
        $metadata = is_array($turn->metadata) ? $turn->metadata : [];
        unset($metadata['client_context']);
        $turn->update(['metadata' => $metadata]);
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('voice', 'system.voice_state.read', [])],
            ),
        ], [new HermesSemanticComposition('I do not have trusted voice-state values for that turn.', false, false)]);

        $this->drainTurn($turn, $fake);

        $result = $fake->compositionRequests[0]->results[0]->data;
        $this->assertNull($result['voice_mode_active']);
        $this->assertNull($result['wake_detection_enabled']);
        $this->assertSame('unknown', $result['playback_state']);
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
    }

    public function test_calendar_writes_require_explicit_all_day_and_never_parse_the_title(): void
    {
        [$token, $session] = $this->conversation('semantic-calendar-all-day@example.com');
        $existing = CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Team lunch',
            'starts_at' => '2026-07-18T00:00:00Z',
            'ends_at' => '2026-07-18T23:59:00Z',
            'status' => 'scheduled',
            'metadata' => ['all_day' => true],
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-calendar-all-day-0001',
            'Create a timed event titled “All day: Board review” from nine to ten, add an all-day retreat, and make Team lunch a timed event titled “All day: Team lunch.”',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('timed', 'app.calendar.create', [
                        ...$this->calendarCreateDefaults(),
                        'title' => 'All day: Board review',
                        'starts_at' => '2026-07-15T13:00:00Z',
                        'ends_at' => '2026-07-15T14:00:00Z',
                        'metadata' => ['all_day' => false],
                    ]),
                    new HermesSemanticOperation('retreat', 'app.calendar.create', [
                        ...$this->calendarCreateDefaults(),
                        'title' => 'Company retreat',
                        'starts_at' => '2026-07-16T00:00:00Z',
                        'ends_at' => '2026-07-17T00:00:00Z',
                        'all_day' => true,
                    ]),
                    new HermesSemanticOperation('lunch', 'app.calendar.update', [
                        'id' => $existing->id,
                        'title' => 'All day: Team lunch',
                        'starts_at' => '2026-07-18T16:00:00Z',
                        'ends_at' => '2026-07-18T17:00:00Z',
                        'all_day' => false,
                    ]),
                ],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('timed', 'app.calendar.create', [
                        ...$this->calendarCreateDefaults(),
                        'title' => 'All day: Board review',
                        'starts_at' => '2026-07-15T13:00:00Z',
                        'ends_at' => '2026-07-15T14:00:00Z',
                        'all_day' => false,
                    ]),
                    new HermesSemanticOperation('retreat', 'app.calendar.create', [
                        ...$this->calendarCreateDefaults(),
                        'title' => 'Company retreat',
                        'starts_at' => '2026-07-16T00:00:00Z',
                        'ends_at' => '2026-07-17T00:00:00Z',
                        'all_day' => true,
                    ]),
                    new HermesSemanticOperation('offset_retreat', 'app.calendar.create', [
                        ...$this->calendarCreateDefaults(),
                        'title' => 'East Coast retreat',
                        'starts_at' => '2026-07-18T00:00:00-04:00',
                        'ends_at' => '2026-07-19T00:00:00-04:00',
                        'all_day' => true,
                    ]),
                    new HermesSemanticOperation('lunch', 'app.calendar.update', [
                        'id' => $existing->id,
                        'title' => 'All day: Team lunch',
                        'starts_at' => '2026-07-18T16:00:00Z',
                        'ends_at' => '2026-07-18T17:00:00Z',
                        'all_day' => false,
                    ]),
                ],
            ),
        ], [
            new HermesSemanticComposition('I created the events and made Team lunch a timed event.', false, false),
        ]);
        $interpretationRun = $turn->runs()->sole();

        $this->process($interpretationRun, $fake);

        $this->assertDatabaseCount('calendar_events', 1);
        $this->assertSame('Team lunch', $existing->fresh()->title);
        $this->assertTrue($existing->fresh()->metadata['all_day']);
        $this->assertCount(2, $fake->interpretationRequests);
        $this->assertStringContainsString(
            'canonical top-level semantic fields',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );

        $this->drainTurn($turn, $fake);

        $timed = CalendarEvent::query()->where('title', 'All day: Board review')->sole();
        $retreat = CalendarEvent::query()->where('title', 'Company retreat')->sole();
        $offsetRetreat = CalendarEvent::query()->where('title', 'East Coast retreat')->sole();
        $updated = $existing->fresh();
        $this->assertDatabaseCount('calendar_events', 4);
        $this->assertFalse($timed->metadata['all_day']);
        $this->assertSame('2026-07-15T13:00:00+00:00', $timed->starts_at->toIso8601String());
        $this->assertSame('2026-07-15T14:00:00+00:00', $timed->ends_at->toIso8601String());
        $this->assertTrue($retreat->metadata['all_day']);
        $this->assertSame('2026-07-17T00:00:00+00:00', $retreat->ends_at->toIso8601String());
        $this->assertTrue($offsetRetreat->metadata['all_day']);
        $this->assertSame('2026-07-18T04:00:00+00:00', $offsetRetreat->starts_at->toIso8601String());
        $this->assertSame('2026-07-19T04:00:00+00:00', $offsetRetreat->ends_at->toIso8601String());
        $this->assertSame(1440.0, $offsetRetreat->starts_at->diffInMinutes($offsetRetreat->ends_at));
        $this->assertSame('All day: Team lunch', $updated->title);
        $this->assertFalse($updated->metadata['all_day']);
        $this->assertSame('2026-07-18T16:00:00+00:00', $updated->starts_at->toIso8601String());
        $this->assertSame('2026-07-18T17:00:00+00:00', $updated->ends_at->toIso8601String());
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $turn->fresh()->side_effect_status);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_note_folder_name_is_rejected_without_a_hidden_folder_side_effect(): void
    {
        [$token, $session] = $this->conversation('semantic-note-folder@example.com');
        $workFolder = NoteFolder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'name' => 'Work',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-note-folder-0001',
            'Create a sprint plan note in a folder called Ideas.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_note', 'app.note.create', [
                    'title' => 'Sprint plan',
                    'plain_text' => 'Plan the next sprint.',
                    'folder_name' => 'Ideas',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Which existing note folder should I use?',
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
                operations: [new HermesSemanticOperation('create_note', 'app.note.create', [
                    'title' => 'Sprint plan',
                    'plain_text' => 'Plan the next sprint.',
                    'note_folder_id' => $workFolder->id,
                ])],
            ),
        ], [
            new HermesSemanticComposition('I created “Sprint plan” in Work.', false, false),
        ]);

        $this->process($turn->runs()->sole(), $fake);

        $awaiting = $turn->fresh();
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertDatabaseCount('notes', 0);
        $this->assertDatabaseCount('note_folders', 1);
        $this->assertDatabaseMissing('note_folders', [
            'workspace_id' => $session->workspace_id,
            'name' => 'Ideas',
        ]);
        $this->assertCount(2, $fake->interpretationRequests);
        $this->assertStringContainsString(
            'folder_name',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/clarifications", [
            'session_id' => $session->id,
            'answer' => 'Use Work instead.',
            'clarification_id' => 'semantic-note-folder-answer-0001',
        ])->assertOk();

        $this->drainTurn($turn, $fake);

        $note = Note::query()->where('workspace_id', $session->workspace_id)->sole();
        $this->assertSame('Sprint plan', $note->title);
        $this->assertSame($workFolder->id, $note->note_folder_id);
        $this->assertDatabaseCount('note_folders', 1);
        $this->assertDatabaseMissing('note_folders', [
            'workspace_id' => $session->workspace_id,
            'name' => 'Ideas',
        ]);
        $this->assertCount(3, $fake->interpretationRequests);
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $turn->fresh()->side_effect_status);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_canonical_semantic_writes_do_not_reinterpret_timestamps_or_omitted_fields(): void
    {
        [$token, $session] = $this->conversation('semantic-canonical-write@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Submit report',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $completedTask = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Reopen without rescheduling',
            'type' => 'todo',
            'status' => 'completed',
            'due_at' => '2026-07-01T12:00:00Z',
            'completed_at' => '2026-07-02T12:00:00Z',
        ]);
        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Call Alex',
            'status' => 'scheduled',
            'remind_at' => '2026-07-19T12:00:00Z',
        ]);
        $calendar = CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Planning block',
            'starts_at' => '2026-07-20T08:00:00Z',
            'ends_at' => '2026-07-20T12:00:00Z',
            'status' => 'scheduled',
            'metadata' => ['all_day' => false],
        ]);
        $allDayCalendar = CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Existing all-day block',
            'starts_at' => '2026-07-21T00:00:00Z',
            'ends_at' => '2026-07-21T23:59:00Z',
            'status' => 'scheduled',
            'metadata' => ['all_day' => true],
        ]);
        $note = Note::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'title' => 'Stable note title',
            'body_html' => '<p>Old body.</p>',
            'plain_text' => 'Old body.',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-canonical-write-0001',
            'Move the report and reminder to the exact times I gave you, reopen the completed task without rescheduling it, move only the starts of both calendar blocks, and replace the note body.',
        );
        $message = ConversationMessage::findOrFail($turn->user_message_id);
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $metadata['client_context']['timezone_offset'] = '-04:00';
        $message->update(['metadata' => $metadata]);
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('task_time', 'app.task.update', [
                        'id' => $task->id,
                        'due_at' => '2026-07-20T15:00:00Z',
                    ]),
                    new HermesSemanticOperation('reminder_time', 'app.reminder.update', [
                        'id' => $reminder->id,
                        'remind_at' => '2026-07-20T16:00:00Z',
                    ]),
                    new HermesSemanticOperation('reopen_task', 'app.task.update', [
                        'id' => $completedTask->id,
                        'status' => 'open',
                        'completed_at' => null,
                    ]),
                    new HermesSemanticOperation('calendar_start', 'app.calendar.update', [
                        'id' => $calendar->id,
                        'starts_at' => '2026-07-20T09:00:00Z',
                    ]),
                    new HermesSemanticOperation('all_day_calendar_start', 'app.calendar.update', [
                        'id' => $allDayCalendar->id,
                        'starts_at' => '2026-07-21T01:00:00Z',
                        'ends_at' => '2026-07-21T23:59:00Z',
                    ]),
                    new HermesSemanticOperation('note_body', 'app.note.update', [
                        'id' => $note->id,
                        'plain_text' => 'This first line must remain body text, not become a title.',
                    ]),
                ],
            ),
        ], [
            new HermesSemanticComposition('I updated the six items using exactly those fields.', false, false),
        ]);

        $this->drainTurn($turn, $fake);

        $this->assertSame('2026-07-20T15:00:00+00:00', $task->fresh()->due_at->toIso8601String());
        $this->assertSame('2026-07-20T16:00:00+00:00', $reminder->fresh()->remind_at->toIso8601String());
        $this->assertSame('open', $completedTask->fresh()->status);
        $this->assertSame('2026-07-01T12:00:00+00:00', $completedTask->fresh()->due_at->toIso8601String());
        $this->assertNull($completedTask->fresh()->completed_at);
        $this->assertSame('2026-07-20T09:00:00+00:00', $calendar->fresh()->starts_at->toIso8601String());
        $this->assertSame('2026-07-20T12:00:00+00:00', $calendar->fresh()->ends_at->toIso8601String());
        $this->assertSame('2026-07-21T01:00:00+00:00', $allDayCalendar->fresh()->starts_at->toIso8601String());
        $this->assertSame('2026-07-21T23:59:00+00:00', $allDayCalendar->fresh()->ends_at->toIso8601String());
        $this->assertTrue($allDayCalendar->fresh()->metadata['all_day']);
        $this->assertSame('Stable note title', $note->fresh()->title);
        $this->assertSame('This first line must remain body text, not become a title.', $note->fresh()->plain_text);
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $turn->fresh()->side_effect_status);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_status_alias_is_rejected_then_hermes_supplies_one_canonical_recurring_write(): void
    {
        [$token, $session] = $this->conversation('semantic-canonical-status@example.com');
        User::findOrFail($session->user_id)
            ->forceFill(['subscription_tier' => 'premium'])
            ->save();
        $turn = $this->admit(
            $token,
            $session,
            'semantic-canonical-status-0001',
            'Create a daily task called Review metrics.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Review metrics',
                    'type' => 'todo',
                    'status' => 'done',
                    'recurrence' => 'daily',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create that daily task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Review metrics',
                    'type' => 'todo',
                    'status' => 'open',
                    'recurrence' => 'daily',
                ])],
            ),
        ], [
            new HermesSemanticComposition('I created the daily Review metrics task.', false, false),
        ]);

        $this->drainTurn($turn, $fake);

        $task = Task::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('title', 'Review metrics')
            ->sole();
        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame('open', $task->status);
        $this->assertSame('daily', data_get($task->metadata, 'recurrence'));
        $this->assertSame(1, $terminal->retry_count);
        $this->assertSame('I’ll create that daily task.', $terminal->acknowledgement_text);
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $turn->id)
            ->where('event_type', 'semantic_acknowledgement_published')
            ->count());
        $this->assertStringContainsString(
            'non-canonical status',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_recurrence_alias_is_rejected_then_hermes_supplies_the_canonical_scalar(): void
    {
        [$token, $session] = $this->conversation('semantic-canonical-recurrence@example.com');
        User::findOrFail($session->user_id)
            ->forceFill(['subscription_tier' => 'premium'])
            ->save();
        $turn = $this->admit(
            $token,
            $session,
            'semantic-canonical-recurrence-0001',
            'Create a daily task called Check inventory.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Check inventory',
                    'type' => 'todo',
                    'status' => 'open',
                    'recurrence' => 'every day',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_task', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Check inventory',
                    'type' => 'todo',
                    'status' => 'open',
                    'recurrence' => 'daily',
                ])],
            ),
        ], [
            new HermesSemanticComposition('I created the daily Check inventory task.', false, false),
        ]);

        $this->drainTurn($turn, $fake);

        $task = Task::query()
            ->where('workspace_id', $session->workspace_id)
            ->where('title', 'Check inventory')
            ->sole();
        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame('daily', data_get($task->metadata, 'recurrence'));
        $this->assertSame(1, $terminal->retry_count);
        $this->assertStringContainsString(
            'recurrence must be none, daily, weekly, monthly, or yearly',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_canonical_recurrence_is_persisted_in_each_authoritative_domain_shape(): void
    {
        [$token, $session] = $this->conversation('semantic-recurrence-persistence@example.com');
        User::findOrFail($session->user_id)
            ->forceFill(['subscription_tier' => 'premium'])
            ->save();
        $turn = $this->admit(
            $token,
            $session,
            'semantic-recurrence-persistence-0001',
            'Create a daily task, a weekly reminder, and a monthly calendar event.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create those recurring items.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('create_task', 'app.task.create', [
                        ...$this->taskCreateDefaults(),
                        'title' => 'Review metrics',
                        'type' => 'todo',
                        'status' => 'open',
                        'recurrence' => 'daily',
                    ]),
                    new HermesSemanticOperation('create_reminder', 'app.reminder.create', [
                        ...$this->reminderCreateDefaults(),
                        'title' => 'Send weekly report',
                        'status' => 'scheduled',
                        'remind_at' => '2026-07-20T14:00:00-04:00',
                        'recurrence' => 'weekly',
                    ]),
                    new HermesSemanticOperation('create_calendar', 'app.calendar.create', [
                        ...$this->calendarCreateDefaults(),
                        'title' => 'Monthly review',
                        'status' => 'scheduled',
                        'starts_at' => '2026-07-21T10:00:00-04:00',
                        'ends_at' => '2026-07-21T11:00:00-04:00',
                        'all_day' => false,
                        'recurrence' => 'monthly',
                    ]),
                ],
            ),
        ], [
            new HermesSemanticComposition('I created all three recurring items.', false, false),
        ]);

        $this->drainTurn($turn, $fake);

        $task = Task::query()->where('workspace_id', $session->workspace_id)->where('title', 'Review metrics')->sole();
        $reminder = Reminder::query()->where('workspace_id', $session->workspace_id)->where('title', 'Send weekly report')->sole();
        $calendar = CalendarEvent::query()->where('workspace_id', $session->workspace_id)->where('title', 'Monthly review')->sole();
        $terminal = $turn->fresh(['finalAssistantMessage']);

        $this->assertSame('daily', data_get($task->metadata, 'recurrence'));
        $this->assertSame('weekly', data_get($reminder->metadata, 'recurrence'));
        $this->assertSame('monthly', $calendar->recurrence);
        $this->assertArrayNotHasKey('recurrence', $calendar->metadata ?? []);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $terminal->side_effect_status);
        $this->assertSame('I created all three recurring items.', $terminal->finalAssistantMessage?->content);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_typed_search_results_expose_stored_recurrence_and_all_day_without_timestamp_inference(): void
    {
        [$token, $session] = $this->conversation('semantic-schedule-search-result@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Daily inventory',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => '2026-07-15 04:00:00',
            'metadata' => ['recurrence' => 'daily'],
        ]);
        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Weekly report',
            'status' => 'scheduled',
            'remind_at' => '2026-07-15 04:00:00',
            'metadata' => ['recurrence' => 'weekly'],
        ]);
        $calendar = CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Midnight monthly review',
            'status' => 'scheduled',
            'recurrence' => 'monthly',
            'starts_at' => '2026-07-15 04:00:00',
            'ends_at' => '2026-07-16 04:00:00',
            'metadata' => ['all_day' => false],
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-schedule-search-result-0001',
            'Check those three recurring items.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [
                    new HermesSemanticOperation('task', 'app.task.search', ['ids' => [$task->id]]),
                    new HermesSemanticOperation('reminder', 'app.reminder.search', ['ids' => [$reminder->id]]),
                    new HermesSemanticOperation('calendar', 'app.calendar.search', ['ids' => [$calendar->id]]),
                ],
            ),
        ], [new HermesSemanticComposition('I checked all three recurring items.', false, false)]);

        $this->drainTurn($turn, $fake);

        $results = collect($fake->compositionRequests[0]->results)->keyBy('operationId');
        $this->assertSame('daily', data_get($results->get('task')?->data, 'items.0.recurrence'));
        $this->assertSame('weekly', data_get($results->get('reminder')?->data, 'items.0.recurrence'));
        $this->assertSame('monthly', data_get($results->get('calendar')?->data, 'items.0.recurrence'));
        $this->assertFalse(data_get($results->get('calendar')?->data, 'items.0.all_day'));
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
    }

    public function test_model_clarification_and_correction_resume_one_stable_turn_without_speculative_write(): void
    {
        [$token, $session] = $this->conversation('semantic-clarify-execute@example.com');
        $turn = $this->admit($token, $session, 'semantic-correction-0001', 'Create a reminder to file taxes.');
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'When should I remind you?',
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
                operations: [
                    new HermesSemanticOperation('create_task', 'app.task.create', [
                        ...$this->taskCreateDefaults(),
                        'title' => 'File taxes',
                        'due_at' => '2026-07-15T17:00:00-04:00',
                    ]),
                ],
            ),
        ], [
            new HermesSemanticComposition('Got it—I made it a task, due tomorrow at 5 p.m.', false, false),
        ]);

        $this->process($turn->runs()->sole(), $fake);
        $awaiting = $turn->fresh();
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame(0, Reminder::where('workspace_id', $session->workspace_id)->count());
        $this->assertSame(0, Task::where('workspace_id', $session->workspace_id)->count());

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$turn->turn_id}/clarifications", [
            'session_id' => $session->id,
            'answer' => 'Actually, make it a task due tomorrow at five.',
            'clarification_id' => 'semantic-correction-answer-0001',
        ])->assertOk()
            ->assertJsonPath('data.turn.turn_id', $turn->turn_id)
            ->assertJsonCount(2, 'data.jobs');

        $secondRun = $turn->fresh()->runs()->where('status', 'queued')->sole();
        $this->assertSame('semantic_interpretation', data_get($secondRun->metadata, 'role'));
        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame(0, Reminder::where('workspace_id', $session->workspace_id)->count());
        $this->assertDatabaseHas('tasks', [
            'workspace_id' => $session->workspace_id,
            'title' => 'File taxes',
        ]);
        $this->assertCount(2, $fake->interpretationRequests);
        $this->assertStringContainsString("\nActually, make it a task", $fake->interpretationRequests[1]->transcript);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'user')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_spoken_stop_preserves_background_work_and_semantic_cancel_targets_it_explicitly(): void
    {
        [$token, $session] = $this->conversation('semantic-stop-cancel@example.com');
        $background = $this->admit(
            $token,
            $session,
            'semantic-background-0001',
            'Create a detailed launch plan note.',
        );

        $stop = $this->admit($token, $session, 'semantic-stop-0001', 'Stop.');
        $stopFake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('stop_speech', 'voice.playback.stop', [])],
            ),
        ], [new HermesSemanticComposition('Stopped.', true, false)]);
        $this->drainTurn($stop, $stopFake);

        $this->assertSame(VoiceTurnState::Accepted, $background->fresh()->state);
        $this->assertSame('queued', $background->runs()->sole()->status);
        $this->assertNotEmpty(data_get($stop->fresh()->metadata, 'playback_stop_directive.id'));
        $this->assertArrayNotHasKey(
            'suppress_final_audio',
            (array) data_get($stop->fresh()->metadata, 'response_directives', []),
        );
        $this->assertTrue((bool) data_get($stop->fresh()->metadata, 'response_directives.close_after_response'));
        $this->assertSame('Stopped.', $stop->fresh(['finalAssistantMessage'])->finalAssistantMessage?->content);

        $cancel = $this->admit($token, $session, 'semantic-cancel-0001', 'Cancel that launch-plan request.');
        $cancelFake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('cancel_work', 'voice.work.cancel', [
                    'target_turn_id' => $background->turn_id,
                ])],
            ),
        ], [new HermesSemanticComposition('Canceled the launch-plan request.', false, false)]);
        $this->drainTurn($cancel, $cancelFake);

        $this->assertSame(VoiceTurnState::Canceled, $background->fresh()->state);
        $this->assertSame('cancelled', $background->runs()->sole()->status);
        $this->assertSame(VoiceTurnState::Completed, $cancel->fresh()->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $cancel->fresh()->side_effect_status);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $stop->turn_id)->where('role', 'assistant')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $cancel->turn_id)->where('role', 'assistant')->count());
    }

    public function test_spoken_stop_directive_requires_the_exact_ack_and_stays_cleared_after_reload(): void
    {
        [$token, $session] = $this->conversation('semantic-stop-directive@example.com');
        $background = $this->admit(
            $token,
            $session,
            'semantic-stop-background-0001',
            'Create a detailed launch plan note.',
        );
        $backgroundRunId = $background->runs()->sole()->id;
        $stop = $this->admit($token, $session, 'semantic-stop-directive-0001', 'Stop.');
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('stop_speech', 'voice.playback.stop', [])],
            ),
        ], [new HermesSemanticComposition('Stopped.', true, false)]);

        $this->drainTurn($stop, $fake);

        $firstReload = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $firstProjection = collect($firstReload['turns'])->firstWhere('turn_id', $stop->turn_id);
        $this->assertIsArray($firstProjection);
        $this->assertTrue($firstProjection['stop_playback']);
        $this->assertArrayNotHasKey('suppress_final_audio', $firstProjection);
        $directiveId = $firstProjection['stop_playback_directive_id'];
        $this->assertIsString($directiveId);
        $this->assertNotSame('', $directiveId);

        $repeatedReload = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $repeatedProjection = collect($repeatedReload['turns'])->firstWhere('turn_id', $stop->turn_id);
        $this->assertTrue($repeatedProjection['stop_playback']);
        $this->assertSame($directiveId, $repeatedProjection['stop_playback_directive_id']);

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$stop->turn_id}/delivery", [
            'session_id' => $session->id,
            'event' => 'playback_stopped',
            'timing' => [
                'directive_id' => $directiveId.':stale',
                'reason' => 'semantic_spoken_stop',
            ],
        ])->assertStatus(409);
        $this->assertSame(0, VoiceTurnEvent::query()
            ->where('voice_turn_id', $stop->id)
            ->where('event_type', 'playback_stopped')
            ->count());

        $afterWrongAck = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $wrongAckProjection = collect($afterWrongAck['turns'])->firstWhere('turn_id', $stop->turn_id);
        $this->assertTrue($wrongAckProjection['stop_playback']);
        $this->assertSame($directiveId, $wrongAckProjection['stop_playback_directive_id']);

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$stop->turn_id}/delivery", [
            'session_id' => $session->id,
            'event' => 'playback_stopped',
            'timing' => [
                'directive_id' => $directiveId,
                'reason' => 'semantic_spoken_stop',
            ],
        ])->assertOk()
            ->assertJsonPath('data.turn.stop_playback', false)
            ->assertJsonPath('data.turn.stop_playback_directive_id', null)
            ->assertJsonMissingPath('data.turn.suppress_final_audio');
        $this->assertSame(1, VoiceTurnEvent::query()
            ->where('voice_turn_id', $stop->id)
            ->where('event_type', 'playback_stopped')
            ->count());

        // A fresh state projection models a browser reload. The durable ack
        // prevents the old directive from stopping unrelated future audio.
        $afterAckReload = $this->withToken($token)
            ->getJson("/api/assistant/voice/state?session_id={$session->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $ackedProjection = collect($afterAckReload['turns'])->firstWhere('turn_id', $stop->turn_id);
        $this->assertFalse($ackedProjection['stop_playback']);
        $this->assertNull($ackedProjection['stop_playback_directive_id']);
        $this->assertArrayNotHasKey('suppress_final_audio', $ackedProjection);
        $this->assertSame('Stopped.', $ackedProjection['final_text']);
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $stop->id)
            ->where('event_type', 'playback_stop_directive_acknowledged')
            ->count());

        $this->withToken($token)->postJson("/api/assistant/voice/turns/{$stop->turn_id}/delivery", [
            'session_id' => $session->id,
            'event' => 'final_audio_started',
            'timing' => [
                'speech_item_id' => $stop->turn_id.':final',
                'purpose' => 'final',
            ],
        ])->assertOk()
            ->assertJsonPath('data.turn.final_audio_started', true);
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $stop->id)
            ->where('event_type', 'final_audio_started')
            ->count());

        $this->assertSame(VoiceTurnState::Accepted, $background->fresh()->state);
        $this->assertSame('queued', AssistantRun::findOrFail($backgroundRunId)->status);
        $this->assertSame(1, $background->fresh()->runs()->count());
    }

    public function test_untrusted_direct_mutation_id_is_rejected_before_acknowledgement_or_job_staging(): void
    {
        [$token, $session] = $this->conversation('semantic-untrusted-target@example.com');
        [, $otherSession] = $this->conversation('semantic-untrusted-target-owner@example.com');
        $otherTask = Task::create([
            'user_id' => $otherSession->user_id,
            'workspace_id' => $otherSession->workspace_id,
            'created_by_user_id' => $otherSession->user_id,
            'conversation_session_id' => $otherSession->id,
            'title' => 'Private task',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-untrusted-target-0001',
            'Move that task to tomorrow.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll move that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('move', 'app.task.update', [
                    'id' => $otherTask->id,
                    'due_at' => '2026-07-15T09:00:00-04:00',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Which task should I move?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
        ]);

        $this->process($turn->runs()->sole(), $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertNull($awaiting->acknowledgement_text);
        $this->assertFalse($awaiting->acknowledgement_required);
        $this->assertNull($otherTask->fresh()->due_at);
        $this->assertSame(0, $awaiting->runs->where('handler', 'semantic.operation')->count());
        $this->assertSame(0, VoiceTurnEvent::where('voice_turn_id', $turn->id)
            ->where('event_type', 'semantic_acknowledgement_published')
            ->count());
        $this->assertStringContainsString(
            'not exposed in trusted tasks context',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );
    }

    public function test_subscription_recurrence_is_rejected_before_acknowledgement_and_hermes_owns_the_final_response(): void
    {
        [$token, $session] = $this->conversation('semantic-recurrence-entitlement@example.com');
        $turn = $this->admit(
            $token,
            $session,
            'semantic-recurrence-entitlement-0001',
            'Create a daily task called Review metrics.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create that daily task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Review metrics',
                    'type' => 'todo',
                    'status' => 'open',
                    'recurrence' => 'daily',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'Daily recurring tasks require a plan that includes recurrence.',
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
        ]);

        $this->drainTurn($turn, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('Daily recurring tasks require a plan that includes recurrence.', $terminal->finalAssistantMessage?->content);
        $this->assertNull($terminal->acknowledgement_text);
        $this->assertFalse($terminal->acknowledgement_required);
        $this->assertSame(0, Task::where('user_id', $session->user_id)->count());
        $this->assertSame(0, $terminal->runs->where('handler', 'semantic.operation')->count());
        $this->assertSame(0, VoiceTurnEvent::where('voice_turn_id', $turn->id)
            ->where('event_type', 'semantic_acknowledgement_published')
            ->count());
        $this->assertStringContainsString(
            'does not authorize daily recurrence',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );
    }

    public function test_untrusted_calendar_link_is_rejected_before_reminder_mutation_or_acknowledgement(): void
    {
        [$token, $session] = $this->conversation('semantic-untrusted-link@example.com');
        [, $otherSession] = $this->conversation('semantic-untrusted-link-owner@example.com');
        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Prepare slides',
            'status' => 'scheduled',
            'remind_at' => '2026-07-15T09:00:00-04:00',
        ]);
        $otherEvent = CalendarEvent::create([
            'user_id' => $otherSession->user_id,
            'workspace_id' => $otherSession->workspace_id,
            'created_by_user_id' => $otherSession->user_id,
            'conversation_session_id' => $otherSession->id,
            'title' => 'Private meeting',
            'starts_at' => '2026-07-15T10:00:00-04:00',
            'ends_at' => '2026-07-15T11:00:00-04:00',
            'status' => 'scheduled',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-untrusted-link-0001',
            'Link the slides reminder to that meeting.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll link that reminder.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('link', 'app.reminder.update', [
                    'id' => $reminder->id,
                    'calendar_event_id' => $otherEvent->id,
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Which authorized calendar event should I link?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
        ]);

        $this->process($turn->runs()->sole(), $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertNull($reminder->fresh()->calendar_event_id);
        $this->assertNull($awaiting->acknowledgement_text);
        $this->assertSame(0, $awaiting->runs->where('handler', 'semantic.operation')->count());
        $this->assertStringContainsString(
            'calendar event id was not exposed',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );
    }

    public function test_completed_at_cannot_silently_change_an_open_task_state(): void
    {
        [$token, $session] = $this->conversation('semantic-completed-at-state@example.com');
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => 'Review metrics',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $turn = $this->admit(
            $token,
            $session,
            'semantic-completed-at-state-0001',
            'Mark the Review metrics task complete.',
        );
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll complete that task.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('complete', 'app.task.update', [
                    'id' => $task->id,
                    'completed_at' => '2026-07-14T14:30:00-04:00',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                responseText: null,
                clarificationQuestion: 'Should I also set the task status to completed?',
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: true,
                operations: [],
            ),
        ]);

        $this->process($turn->runs()->sole(), $fake);

        $awaiting = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state);
        $this->assertSame('open', $task->fresh()->status);
        $this->assertNull($task->fresh()->completed_at);
        $this->assertNull($awaiting->acknowledgement_text);
        $this->assertSame(0, $awaiting->runs->where('handler', 'semantic.operation')->count());
        $this->assertStringContainsString(
            'completed_at cannot imply a status change',
            (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );
    }

    public function test_noncanonical_semantic_shapes_return_to_hermes_before_acknowledgement_or_jobs(): void
    {
        $cases = [
            'cancel-conflict' => [
                new HermesSemanticOperation('invalid', 'voice.work.cancel', [
                    'target_turn_id' => 'some-turn',
                    'all' => true,
                ]),
                'exactly one selector',
            ],
            'status-conflict' => [
                new HermesSemanticOperation('invalid', 'voice.work.status', [
                    'target_turn_id' => 'some-turn',
                    'scope' => 'latest',
                ]),
                'exactly one selector',
            ],
            'clock-kind-required' => [
                new HermesSemanticOperation('invalid', 'system.clock.read', []),
                'requires kind',
            ],
            'recurrence-null' => [
                new HermesSemanticOperation('invalid', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Review metrics',
                    'recurrence' => null,
                ]),
                'recurrence must be none',
            ],
            'reminder-create-missing-semantic-time' => [
                new HermesSemanticOperation('invalid', 'app.reminder.create', [
                    'title' => 'Call Alex',
                ]),
                'requires remind_at',
            ],
            'calendar-create-missing-semantic-start' => [
                new HermesSemanticOperation('invalid', 'app.calendar.create', [
                    'title' => 'Planning block',
                ]),
                'requires starts_at',
            ],
            'id-only-update' => [
                new HermesSemanticOperation('invalid', 'app.task.update', ['id' => 1]),
                'at least one explicit mutable field',
            ],
            'reminder-create-status-alias' => [
                new HermesSemanticOperation('invalid', 'app.reminder.create', [
                    ...$this->reminderCreateDefaults(),
                    'title' => 'Call Alex',
                    'status' => 'pending',
                    'remind_at' => '2026-07-14T18:00:00-04:00',
                ]),
                'non-canonical status',
            ],
            'reminder-update-status-alias' => [
                new HermesSemanticOperation('invalid', 'app.reminder.update', [
                    'id' => 1,
                    'status' => 'done',
                ]),
                'non-canonical status',
            ],
            'reminder-search-status-alias' => [
                new HermesSemanticOperation('invalid', 'app.reminder.search', [
                    'status' => 'complete',
                ]),
                'non-canonical status',
            ],
            'calendar-create-status-alias' => [
                new HermesSemanticOperation('invalid', 'app.calendar.create', [
                    ...$this->calendarCreateDefaults(),
                    'title' => 'Planning block',
                    'status' => 'confirmed',
                    'starts_at' => '2026-07-14T18:00:00-04:00',
                ]),
                'non-canonical status',
            ],
            'calendar-update-status-alias' => [
                new HermesSemanticOperation('invalid', 'app.calendar.update', [
                    'id' => 1,
                    'status' => 'tentative',
                ]),
                'non-canonical status',
            ],
            'calendar-search-status-alias' => [
                new HermesSemanticOperation('invalid', 'app.calendar.search', [
                    'status' => 'confirmed',
                ]),
                'non-canonical status',
            ],
            'open-create-completed-at-conflict' => [
                new HermesSemanticOperation('invalid', 'app.task.create', [
                    ...$this->taskCreateDefaults(),
                    'title' => 'Already complete',
                    'status' => 'open',
                    'completed_at' => '2026-07-14T14:30:00Z',
                ]),
                'status=open cannot include a non-null completed_at',
            ],
            'completed-status-needs-timestamp' => [
                new HermesSemanticOperation('invalid', 'app.task.update', [
                    'id' => 1,
                    'status' => 'completed',
                ]),
                'requires an explicit non-null completed_at',
            ],
            'open-status-conflicts-with-completion' => [
                new HermesSemanticOperation('invalid', 'app.task.update', [
                    'id' => 1,
                    'status' => 'open',
                    'completed_at' => '2026-07-14T14:30:00Z',
                ]),
                'status=open requires explicit completed_at=null',
            ],
            'all-day-bounds-required' => [
                new HermesSemanticOperation('invalid', 'app.calendar.create', [
                    ...$this->calendarCreateDefaults(),
                    'title' => 'Retreat',
                    'starts_at' => '2026-07-15T00:00:00-04:00',
                    'all_day' => true,
                ]),
                'all_day=true requires explicit starts_at and ends_at bounds',
            ],
            'note-body-representation-conflict' => [
                new HermesSemanticOperation('invalid', 'app.note.create', [
                    'title' => 'Trip plan',
                    'plain_text' => 'Visit the museum.',
                    'body_html' => '<p>Visit the museum.</p>',
                ]),
                'exactly one note body representation',
            ],
            'weather-location-conflict' => [
                new HermesSemanticOperation('invalid', 'external.lookup', [
                    'query' => 'Current weather',
                    'kind' => 'weather',
                    'location' => 'Orlando, Florida',
                    'latitude' => 28.5383,
                    'longitude' => -81.3792,
                    'units' => 'imperial',
                ]),
                'exactly one location representation',
            ],
            'weather-units-required' => [
                new HermesSemanticOperation('invalid', 'external.lookup', [
                    'query' => 'Current weather',
                    'kind' => 'weather',
                    'location' => 'Orlando, Florida',
                ]),
                'requires units',
            ],
            'forecast-kind-fields' => [
                new HermesSemanticOperation('invalid', 'external.lookup', [
                    'query' => 'Tomorrow weather',
                    'kind' => 'forecast',
                    'location' => 'Orlando, Florida',
                    'date' => '2026-07-15',
                    'units' => 'imperial',
                    'topic' => 'general',
                ]),
                'inapplicable field(s): topic',
            ],
        ];

        foreach ($cases as $slug => [$operation, $expectedFeedback]) {
            [$token, $session] = $this->conversation("semantic-schema-{$slug}@example.com");
            $turn = $this->admit(
                $token,
                $session,
                'semantic-schema-'.substr(hash('sha256', $slug), 0, 18),
                'Apply the requested operation.',
            );
            $fake = new ScriptedHermesSemanticInterpreter([
                new HermesSemanticInterpretation(
                    outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                    responseText: null,
                    clarificationQuestion: null,
                    acknowledgementText: 'I’ll do that.',
                    closeAfterResponse: false,
                    responseExpected: false,
                    operations: [$operation],
                ),
                new HermesSemanticInterpretation(
                    outcome: HermesSemanticInterpretation::OUTCOME_CLARIFY,
                    responseText: null,
                    clarificationQuestion: 'Could you clarify that request?',
                    acknowledgementText: null,
                    closeAfterResponse: false,
                    responseExpected: true,
                    operations: [],
                ),
            ]);

            $this->process($turn->runs()->sole(), $fake);

            $awaiting = $turn->fresh(['runs']);
            $this->assertSame(VoiceTurnState::AwaitingClarification, $awaiting->state, $slug);
            $this->assertNull($awaiting->acknowledgement_text, $slug);
            $this->assertFalse($awaiting->acknowledgement_required, $slug);
            $this->assertSame(0, $awaiting->runs->where('handler', 'semantic.operation')->count(), $slug);
            $this->assertSame(0, VoiceTurnEvent::where('voice_turn_id', $turn->id)
                ->where('event_type', 'semantic_acknowledgement_published')
                ->count(), $slug);
            $this->assertStringContainsString(
                $expectedFeedback,
                (string) data_get($fake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
                $slug,
            );
        }
    }

    public function test_note_limit_reserves_staged_creates_before_acknowledging_a_competing_turn(): void
    {
        [$token, $session] = $this->conversation('semantic-note-reservation@example.com');
        foreach (range(1, 9) as $index) {
            Note::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'created_by_user_id' => $session->user_id,
                'title' => "Existing note {$index}",
                'plain_text' => 'Existing note.',
            ]);
        }
        $first = $this->admit($token, $session, 'semantic-note-reservation-first', 'Create a note called First.');
        $firstFake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create First.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_first', 'app.note.create', [
                    'title' => 'First',
                    'plain_text' => 'First note.',
                ])],
            ),
        ], [new HermesSemanticComposition('I created First.', false, false)]);
        $this->process($first->runs()->sole(), $firstFake);

        $second = $this->admit($token, $session, 'semantic-note-reservation-second', 'Create a note called Second.');
        $secondFake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create Second.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_second', 'app.note.create', [
                    'title' => 'Second',
                    'plain_text' => 'Second note.',
                ])],
            ),
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_RESPOND,
                responseText: 'Your current plan has room for only one of those notes.',
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [],
            ),
        ]);

        $this->drainTurn($second, $secondFake);

        $rejected = $second->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $rejected->state);
        $this->assertSame('Your current plan has room for only one of those notes.', $rejected->finalAssistantMessage?->content);
        $this->assertNull($rejected->acknowledgement_text);
        $this->assertSame(0, $rejected->runs->where('handler', 'semantic.operation')->count());
        $this->assertSame(9, Note::where('user_id', $session->user_id)->count());
        $this->assertStringContainsString(
            'up to 10 notes',
            (string) data_get($secondFake->interpretationRequests[1]->context, 'prior_interpretation_feedback.detail'),
        );

        $this->drainTurn($first, $firstFake);
        $this->assertSame(10, Note::where('user_id', $session->user_id)->count());
        $this->assertSame(VoiceTurnState::Completed, $first->fresh()->state);
    }

    public function test_cross_epoch_work_status_uses_typed_note_descriptor_without_prior_prose(): void
    {
        [$token, $session] = $this->conversation('semantic-cross-epoch-work@example.com');
        $create = $this->admit(
            $token,
            $session,
            'semantic-cross-epoch-note-create',
            'Create a note called Launch checklist.',
        );
        $createFake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: 'I’ll create that note.',
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('create_note', 'app.note.create', [
                    'title' => 'Launch checklist',
                    'plain_text' => 'Confirm launch readiness.',
                ])],
            ),
        ], [new HermesSemanticComposition('I created Launch checklist.', false, false)]);
        $this->drainTurn($create, $createFake);

        $status = $this->admit(
            $token,
            $session,
            'semantic-cross-epoch-note-status',
            'Did you finish the note?',
        );
        $statusFake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticInterpretation(
                outcome: HermesSemanticInterpretation::OUTCOME_EXECUTE,
                responseText: null,
                clarificationQuestion: null,
                acknowledgementText: null,
                closeAfterResponse: false,
                responseExpected: false,
                operations: [new HermesSemanticOperation('status', 'voice.work.status', [
                    'target_turn_id' => $create->turn_id,
                ])],
            ),
        ], [new HermesSemanticComposition('Yes, I finished creating Launch checklist.', false, false)]);
        $this->drainTurn($status, $statusFake);

        $context = $statusFake->interpretationRequests[0]->context;
        $this->assertSame(
            [$status->turn_id],
            collect($context['authorized_conversation'])->pluck('stable_turn_id')->unique()->values()->all(),
        );
        $work = collect($context['recent_voice_turns'])->firstWhere('stable_turn_id', $create->turn_id);
        $this->assertIsArray($work);
        $this->assertArrayNotHasKey('transcript', $work);
        $this->assertArrayNotHasKey('final_text', $work);
        $descriptor = collect($work['operations'])->firstWhere('operation_id', 'create_note');
        $this->assertSame('app.note.create', $descriptor['tool']);
        $this->assertSame('Launch checklist', $descriptor['resource_title']);
        $this->assertSame('completed', $descriptor['run_status']);
        $this->assertArrayNotHasKey('plain_text', $descriptor);
        $this->assertSame(VoiceTurnState::Completed, $status->fresh()->state);
        $this->assertSame(
            'Yes, I finished creating Launch checklist.',
            $status->fresh(['finalAssistantMessage'])->finalAssistantMessage?->content,
        );
    }

    public function test_semantic_provider_failure_retries_once_and_never_falls_back_to_the_legacy_runtime(): void
    {
        [$token, $session] = $this->conversation('semantic-failure@example.com');
        $turn = $this->admit($token, $session, 'semantic-failure-0001', 'Delete that task.');
        $fake = new ScriptedHermesSemanticInterpreter([
            new HermesSemanticProviderException('transport', 'First timeout.', true),
            new HermesSemanticProviderException('transport', 'Second timeout.', true),
        ]);
        $runtime = \Mockery::mock(HermesRuntimeService::class);

        $this->drainTurn($turn, $fake, $runtime);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame('semantic_transport', $terminal->failure_category);
        $this->assertSame(1, $terminal->retry_count);
        $this->assertSame(AssistantRunService::SYSTEM_FAILURE_FINAL, $terminal->finalAssistantMessage->content);
        $this->assertCount(2, $fake->interpretationRequests);
        $this->assertSame(0, Task::where('workspace_id', $session->workspace_id)->count());
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $turn->id)->where('event_type', 'semantic_interpretation_failed')->count());
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
    }

    public function test_queued_semantic_interpretation_terminalizes_at_its_absolute_two_second_deadline(): void
    {
        Carbon::setTestNow('2026-07-14 12:00:00', 'America/New_York');
        [$token, $session] = $this->conversation('semantic-queue-deadline@example.com');
        $turn = $this->admit(
            $token,
            $session,
            'semantic-queue-deadline-0001',
            'Move the launch task to tomorrow.',
        );
        $run = $turn->runs()->sole();
        $this->assertSame(
            now()->addSeconds(2)->timestamp,
            $run->hard_deadline_at?->timestamp,
        );
        Queue::assertPushed(
            EnforceBrowserVoiceTurnDeadline::class,
            fn (EnforceBrowserVoiceTurnDeadline $job): bool => $job->voiceTurnId === $turn->id
                && Carbon::parse($job->deadlineAt)->timestamp === $run->hard_deadline_at?->timestamp,
        );

        Carbon::setTestNow(now()->addSeconds(3));
        $fake = new ScriptedHermesSemanticInterpreter([]);
        $this->assertSame(1, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));
        $this->process($run, $fake);

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame('semantic_deadline', $terminal->failure_category);
        $this->assertSame('failed', $run->fresh()->status);
        $this->assertSame(AssistantRunService::SYSTEM_FAILURE_FINAL, $terminal->finalAssistantMessage?->content);
        $this->assertCount(0, $fake->interpretationRequests);
        $this->assertSame(1, ConversationMessage::where('client_turn_id', $turn->turn_id)->where('role', 'assistant')->count());
        $this->assertSame(1, VoiceTurnEvent::where('voice_turn_id', $turn->id)
            ->where('event_type', 'job_failed')
            ->count());
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

    /** @return array{0:string,1:ConversationSession} */
    private function conversation(string $email): array
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

    private function process(
        AssistantRun $run,
        HermesSemanticInterpreter $interpreter,
        ?HermesRuntimeService $runtime = null,
    ): void {
        (new ProcessAssistantRun($run->id))->handle(
            runtime: $runtime ?? app(HermesRuntimeService::class),
            runs: app(AssistantRunService::class),
            voiceTurns: app(VoiceTurnLifecycleService::class),
            semanticInterpreter: $interpreter,
        );
    }

    private function drainTurn(
        VoiceTurn $turn,
        HermesSemanticInterpreter $interpreter,
        ?HermesRuntimeService $runtime = null,
    ): void {
        for ($pass = 0; $pass < 20; $pass++) {
            $fresh = $turn->fresh();
            if (! $fresh instanceof VoiceTurn || $fresh->state->isTerminal()) {
                return;
            }

            $queued = $fresh->runs()->where('status', 'queued')->orderBy('id')->get();
            if ($queued->isEmpty()) {
                return;
            }

            $progressed = false;
            foreach ($queued as $run) {
                $before = $run->status;
                $this->process($run, $interpreter, $runtime);
                $progressed = $progressed || $run->fresh()?->status !== $before;
            }
            if (! $progressed) {
                return;
            }
        }

        $this->fail('The durable semantic jobs did not settle within 20 scheduler passes.');
    }
}

final class ScriptedHermesSemanticInterpreter implements HermesSemanticInterpreter
{
    /** @var list<HermesSemanticInterpretationRequest> */
    public array $interpretationRequests = [];

    /** @var list<HermesSemanticCompositionRequest> */
    public array $compositionRequests = [];

    /**
     * @param  list<HermesSemanticInterpretation|\Throwable>  $interpretations
     * @param  list<HermesSemanticComposition|\Throwable>  $compositions
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
            throw new RuntimeException('No scripted semantic interpretation remains.');
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
            throw new RuntimeException('No scripted semantic composition remains.');
        }

        return $next;
    }
}
