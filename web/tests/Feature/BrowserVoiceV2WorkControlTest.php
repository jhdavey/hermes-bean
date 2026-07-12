<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
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

    public function test_spoken_stop_never_routes_or_executes_as_backend_cancellation(): void
    {
        $token = $this->apiToken('voice-v2-stop-safety@example.com');
        $sessionId = $this->sessionId($token);
        $this->admit($token, $sessionId, 'stop-safety-work-0001', 'Create a detailed seven-day travel plan.');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'stop-safety-command-0001',
            'Stop.',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'agent.complex');

        $this->assertSame(
            VoiceTurnState::Accepted,
            VoiceTurn::where('turn_id', 'stop-safety-work-0001')->value('state'),
        );
        $this->assertSame(
            'queued',
            VoiceTurn::where('turn_id', 'stop-safety-work-0001')->firstOrFail()->runs()->value('status'),
        );
    }

    public function test_multi_domain_requests_route_once_to_complex_work_instead_of_dropping_a_subrequest(): void
    {
        $token = $this->apiToken('voice-v2-multi-domain-route@example.com');
        $sessionId = $this->sessionId($token);

        foreach ([
            ['multi-domain-route-0001', 'Check my calendar and reminders for tomorrow.', ['app.calendar.read', 'app.reminder.read']],
            ['multi-domain-route-0002', 'Check the weather in Orlando tonight and create a note titled Forecast.', ['external.weather', 'app.note.create']],
        ] as [$turnId, $transcript, $handlers]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
                $sessionId,
                $turnId,
                $transcript,
            ))->assertCreated()
                ->assertJsonPath('data.turn.lane', 'complex_agent')
                ->assertJsonPath('data.turn.handler', 'agent.complex')
                ->assertJsonPath('data.turn.acknowledgement_required', true)
                ->assertJsonCount(2, 'data.jobs');

            $this->assertSame(
                $handlers,
                VoiceTurn::where('turn_id', $turnId)->firstOrFail()->runs()->orderBy('id')->pluck('handler')->all(),
            );
        }
    }

    public function test_same_domain_read_write_and_repeated_writes_are_planned_as_separate_subtasks(): void
    {
        $token = $this->apiToken('voice-v2-same-domain-plan@example.com');
        $sessionId = $this->sessionId($token);

        foreach ([
            [
                'same-domain-plan-0001',
                'Check my reminders and create a reminder titled Call Mom for tomorrow at noon.',
                ['app.reminder.read', 'app.reminder.create'],
            ],
            [
                'same-domain-plan-0002',
                'Create a reminder titled Call Mom for tomorrow at noon and create a reminder titled Send RSVP for tomorrow at 1 p.m.',
                ['app.reminder.create', 'app.reminder.create'],
            ],
            [
                'same-domain-plan-0003',
                'Create a reminder titled Call Mom for tomorrow at noon and another reminder titled Send RSVP for tomorrow at 1 p.m.',
                ['app.reminder.create', 'app.reminder.create'],
            ],
        ] as [$turnId, $transcript, $handlers]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
                $sessionId,
                $turnId,
                $transcript,
            ))->assertCreated()
                ->assertJsonPath('data.turn.lane', 'complex_agent')
                ->assertJsonCount(2, 'data.jobs');

            $this->assertSame(
                $handlers,
                VoiceTurn::where('turn_id', $turnId)->firstOrFail()->runs()->orderBy('id')->pluck('handler')->all(),
            );
        }
    }

    public function test_every_typed_subtask_is_complete_before_any_part_of_the_parent_is_admitted(): void
    {
        $token = $this->apiToken('voice-v2-subtask-completeness@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'subtask-completeness-0001',
            'Create a reminder titled Call Mom tomorrow at noon and create a reminder titled RSVP.',
        ))->assertUnprocessable()
            ->assertJsonPath('code', 'voice_request_incomplete')
            ->assertJsonPath('question', 'What time should I remind you?');

        $this->assertSame(0, VoiceTurn::where('turn_id', 'subtask-completeness-0001')->count());
        $this->assertSame(0, Reminder::count());
    }

    public function test_conjunctions_inside_entity_titles_never_create_guessed_subtasks(): void
    {
        $token = $this->apiToken('voice-v2-title-conjunction@example.com');
        $sessionId = $this->sessionId($token);

        foreach ([
            ['title-conjunction-0001', 'Create a note titled reminders and calendar ideas.', 'reminders and calendar ideas'],
            ['title-conjunction-0002', 'Create a note titled “tasks and reminders”.', 'tasks and reminders'],
            ['title-conjunction-0003', 'Create a note titled Meal Plan.', 'Meal Plan'],
        ] as [$turnId, $transcript, $expectedTitle]) {
            $response = $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
                $sessionId,
                $turnId,
                $transcript,
            ))->assertCreated()
                ->assertJsonPath('data.turn.lane', 'app_write')
                ->assertJsonPath('data.turn.handler', 'app.note.create')
                ->assertJsonCount(1, 'data.jobs');

            $runId = (int) $response->json('data.jobs.0.id');
            (new ProcessAssistantRun($runId))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
            );
            $this->assertSame(1, Note::where('title', $expectedTitle)->count());
        }
    }

    public function test_payload_verbs_never_override_the_sealed_create_operation(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-payload-operation@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Existing reminder',
            'remind_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);

        foreach ([
            ['payload-operation-0001', 'Create a reminder titled Delete invoices for tomorrow at noon.', 'Delete invoices'],
            ['payload-operation-0002', 'Remind me to delete old invoices tomorrow at 1 p.m.', 'delete old invoices'],
        ] as [$turnId, $transcript, $expectedTitle]) {
            $response = $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
                $sessionId,
                $turnId,
                $transcript,
            ))->assertCreated()
                ->assertJsonPath('data.turn.handler', 'app.reminder.create');

            (new ProcessAssistantRun((int) $response->json('data.jobs.0.id')))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
            );
            $this->assertSame(1, Reminder::where('title', $expectedTitle)->count());
        }

        $this->assertSame(1, Reminder::where('title', 'Existing reminder')->count());
    }

    public function test_calendar_read_language_takes_precedence_over_schedule_as_a_noun(): void
    {
        $token = $this->apiToken('voice-v2-schedule-read@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'schedule-read-0001',
            'What’s on my schedule tomorrow?',
        ))->assertCreated()
            ->assertJsonPath('data.turn.lane', 'app_read')
            ->assertJsonPath('data.turn.handler', 'app.calendar.read');
    }

    public function test_questions_and_negated_commands_never_authorize_a_write(): void
    {
        $token = $this->apiToken('voice-v2-nonmutating-grammar@example.com');
        $sessionId = $this->sessionId($token);

        foreach ([
            ['nonmutating-grammar-0001', 'Did I create a reminder?', 'app.reminder.read'],
            ['nonmutating-grammar-0002', 'Are my tasks marked complete?', 'app.task.read'],
            ['nonmutating-grammar-0003', 'Can I create a reminder?', 'instant.capability'],
            ['nonmutating-grammar-0004', 'Don’t delete that reminder.', 'app.voice_work.cancel'],
            ['nonmutating-grammar-0005', 'Please don’t delete that reminder.', 'app.voice_work.cancel'],
            ['nonmutating-grammar-0006', 'No, don’t delete that reminder.', 'app.voice_work.cancel'],
            ['nonmutating-grammar-0007', 'Should you delete that reminder?', 'instant.confirmation_required'],
        ] as [$turnId, $transcript, $handler]) {
            $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
                $sessionId,
                $turnId,
                $transcript,
            ))->assertCreated()
                ->assertJsonPath('data.turn.handler', $handler);
        }

        $this->assertSame(0, Reminder::count());
        $this->assertSame(0, Task::count());
    }

    public function test_generated_note_is_sealed_as_reasoning_plus_typed_receipt_handler(): void
    {
        $token = $this->apiToken('voice-v2-generated-note-route@example.com');
        $sessionId = $this->sessionId($token);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'generated-note-route-0001',
            'Create a three-meal dinner plan and save it as a note. Pick three random meals.',
        ))->assertCreated()
            ->assertJsonPath('data.turn.lane', 'complex_agent')
            ->assertJsonPath('data.turn.handler', 'agent.generate_note')
            ->assertJsonPath('data.jobs.0.handler', 'agent.generate_note')
            ->assertJsonPath('data.jobs.0.label', 'Create meal plan note')
            ->assertJsonCount(1, 'data.jobs');
    }

    public function test_a_deterministic_multi_read_plan_executes_real_subtasks_and_writes_one_combined_final(): void
    {
        $token = $this->apiToken('voice-v2-multi-read-execution@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'multi-read-execution-0001',
            'Check my calendar and reminders for tomorrow.',
        );

        $this->assertCount(2, $turn->runs);
        foreach ($turn->runs()->orderBy('id')->get() as $run) {
            (new ProcessAssistantRun($run->id))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
            );
        }

        $turn = $turn->fresh(['runs', 'finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame(['completed', 'completed'], $turn->runs->pluck('status')->all());
        $this->assertNotNull($turn->finalAssistantMessage);
        $this->assertStringContainsString('Check calendar:', $turn->finalAssistantMessage->content);
        $this->assertStringContainsString('Check reminders:', $turn->finalAssistantMessage->content);
        $this->assertSame(1, ConversationMessage::whereKey($turn->final_assistant_message_id)->count());
    }

    public function test_a_deterministic_multi_write_plan_commits_each_typed_subtask_once_and_combines_the_final(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-multi-write-execution@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'multi-write-execution-0001',
            'Create a reminder titled Call Mom for tomorrow at noon and create a note titled Packing.',
        );

        $this->assertSame(
            ['app.reminder.create', 'app.note.create'],
            $turn->runs()->orderBy('id')->pluck('handler')->all(),
        );
        foreach ($turn->runs()->orderBy('id')->get() as $run) {
            (new ProcessAssistantRun($run->id))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
            );
        }

        $turn = $turn->fresh(['runs', 'finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame(1, Reminder::where('title', 'Call Mom')->count());
        $this->assertSame(1, Note::where('title', 'Packing')->count());
        $this->assertSame('committed', $turn->side_effect_status->value);
        $this->assertCount(2, data_get($turn->metadata, 'write_receipts', []));
        $this->assertStringContainsString('Update reminders:', $turn->finalAssistantMessage->content);
        $this->assertStringContainsString('Update notes:', $turn->finalAssistantMessage->content);

        foreach ($turn->runs as $run) {
            (new ProcessAssistantRun($run->id))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
            );
        }
        $this->assertSame(1, Reminder::where('title', 'Call Mom')->count());
        $this->assertSame(1, Note::where('title', 'Packing')->count());
        $this->assertSame(1, ConversationMessage::whereKey($turn->final_assistant_message_id)->count());
    }

    public function test_repeated_same_domain_writes_each_commit_once(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-same-domain-writes@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'same-domain-write-execution-0001',
            'Create a reminder titled Call Mom for tomorrow at noon and create a reminder titled Send RSVP for tomorrow at 1 p.m.',
        );

        foreach ($turn->runs()->orderBy('id')->get() as $run) {
            (new ProcessAssistantRun($run->id))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
            );
        }

        $turn = $turn->fresh(['runs', 'finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame(1, Reminder::where('title', 'Call Mom')->count());
        $this->assertSame(1, Reminder::where('title', 'Send RSVP')->count());
        $this->assertCount(2, data_get($turn->metadata, 'write_receipts', []));
        $this->assertSame(1, ConversationMessage::whereKey($turn->final_assistant_message_id)->count());
    }

    public function test_each_planned_subtask_deadline_terminalizes_without_waiting_for_the_parent_deadline(): void
    {
        $token = $this->apiToken('voice-v2-subtask-deadline@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'subtask-deadline-0001',
            'Check my calendar and reminders for tomorrow.',
        );
        $turn->runs()->update(['hard_deadline_at' => now()->subSecond()]);

        $this->assertSame(2, app(VoiceTurnLifecycleService::class)->enforceDeadlines($turn->id));

        $turn = $turn->fresh(['runs', 'finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame(['failed', 'failed'], $turn->runs->pluck('status')->all());
        $this->assertSame('required_jobs_failed', $turn->failure_category);
        $this->assertSame(1, ConversationMessage::whereKey($turn->final_assistant_message_id)->count());
    }

    public function test_canceling_a_multi_write_turn_preserves_and_explains_an_already_committed_subtask(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-partial-cancel@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'partial-cancel-0001',
            'Create a reminder titled Call Mom for tomorrow at noon and create a note titled Packing.',
        );
        $firstRun = $turn->runs()->where('handler', 'app.reminder.create')->firstOrFail();
        (new ProcessAssistantRun($firstRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        $this->assertSame('committed', $turn->fresh()->side_effect_status->value);

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $sessionId,
            'turn_id' => $turn->turn_id,
        ])->assertOk()
            ->assertJsonPath(
                'data.confirmation_text',
                'I canceled the remaining work, but part of that request had already finished and couldn’t be undone.',
            );

        $turn = $turn->fresh(['runs']);
        $this->assertSame(VoiceTurnState::Canceled, $turn->state);
        $this->assertSame('committed', $turn->side_effect_status->value);
        $this->assertSame(1, Reminder::where('title', 'Call Mom')->count());
        $this->assertSame(0, Note::where('title', 'Packing')->count());
        $this->assertSame(
            ['completed', 'cancelled'],
            $turn->runs()->orderBy('id')->pluck('status')->all(),
        );
    }

    public function test_canceling_an_already_completed_job_never_claims_that_it_was_canceled(): void
    {
        $token = $this->apiToken('voice-v2-completed-job-cancel@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'completed-job-cancel-0001',
            'Create a note titled Packing.',
        );
        $run = $turn->runs()->firstOrFail();
        (new ProcessAssistantRun($run->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $sessionId,
            'job_id' => $run->id,
        ])->assertOk()
            ->assertJsonPath('data.canceled_turn_ids', [])
            ->assertJsonPath('data.confirmation_text', 'That work had already finished, so I couldn’t cancel it.');

        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame(1, Note::where('title', 'Packing')->count());
    }

    public function test_contextual_cancel_targets_the_named_domain_instead_of_the_newest_work(): void
    {
        $token = $this->apiToken('voice-v2-targeted-cancel@example.com');
        $sessionId = $this->sessionId($token);
        $this->admit(
            $token,
            $sessionId,
            'targeted-cancel-reminder-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
        );
        $this->admit(
            $token,
            $sessionId,
            'targeted-cancel-note-0001',
            'Create a note called Grocery ideas.',
        );

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'targeted-cancel-command-0001',
            'Cancel that reminder request.',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.cancel')
            ->assertJsonPath('data.turn.final_text', 'Canceled.');

        $this->assertSame(
            VoiceTurnState::Canceled,
            VoiceTurn::where('turn_id', 'targeted-cancel-reminder-0001')->value('state'),
        );
        $this->assertSame(
            VoiceTurnState::Accepted,
            VoiceTurn::where('turn_id', 'targeted-cancel-note-0001')->value('state'),
        );

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'targeted-cancel-command-0002',
            'Don’t create the note.',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.cancel')
            ->assertJsonPath('data.turn.final_text', 'Canceled.');

        $this->assertSame(
            VoiceTurnState::Canceled,
            VoiceTurn::where('turn_id', 'targeted-cancel-note-0001')->value('state'),
        );
    }

    public function test_work_status_reports_running_work_without_creating_an_agent_job(): void
    {
        $token = $this->apiToken('voice-v2-running-status@example.com');
        $sessionId = $this->sessionId($token);
        $target = $this->admit(
            $token,
            $sessionId,
            'running-status-work-0001',
            'Create a detailed seven-day travel plan.',
        );
        app(VoiceTurnLifecycleService::class)->markProgress($target, ['phase' => 'planning']);

        $response = $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'running-status-question-0001',
            'Did you finish that?',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.status')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.jobs', []);

        $this->assertSame('I’m still working on the request.', $response->json('data.turn.final_text'));
        $this->assertSame(1, ConversationMessage::where('client_turn_id', 'running-status-question-0001')->where('role', 'assistant')->count());
    }

    public function test_work_status_reports_completed_and_canceled_domain_requests_accurately(): void
    {
        $token = $this->apiToken('voice-v2-terminal-status@example.com');

        $completedSessionId = $this->sessionId($token);
        $completed = $this->admit(
            $token,
            $completedSessionId,
            'completed-status-reminder-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
        );
        app(VoiceTurnLifecycleService::class)->complete($completed, 'Done—I created the reminder.');
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $completedSessionId,
            'completed-status-question-0001',
            'Did you finish that reminder request?',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.status')
            ->assertJsonPath('data.turn.final_text', 'Yes—I finished the reminder request.');

        $canceledSessionId = $this->sessionId($token);
        $canceled = $this->admit(
            $token,
            $canceledSessionId,
            'canceled-status-note-0001',
            'Create a note called Grocery ideas.',
        );
        app(VoiceTurnLifecycleService::class)->cancel($canceled);
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $canceledSessionId,
            'canceled-status-question-0001',
            'Did you finish the note?',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.status')
            ->assertJsonPath('data.turn.final_text', 'The note request was canceled. Would you like me to restart it?');
    }

    public function test_unrelated_creates_have_distinct_resource_reference_locks_and_can_run_together(): void
    {
        $token = $this->apiToken('voice-v2-independent-writes@example.com');
        $sessionId = $this->sessionId($token);
        $first = $this->admit(
            $token,
            $sessionId,
            'independent-write-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
        )->runs()->firstOrFail();
        $second = $this->admit(
            $token,
            $sessionId,
            'independent-write-0002',
            'Create a reminder titled Send RSVP for tomorrow at 4 p.m.',
        )->runs()->firstOrFail();
        $third = $this->admit(
            $token,
            $sessionId,
            'independent-write-0003',
            'Schedule dentist tomorrow at 5 p.m.',
        )->runs()->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $this->assertNotNull($first->resource_lock_key);
        $this->assertNotNull($second->resource_lock_key);
        $this->assertNotNull($third->resource_lock_key);
        $this->assertNotSame($first->resource_lock_key, $second->resource_lock_key);
        $this->assertNotSame($second->resource_lock_key, $third->resource_lock_key);
        $this->assertTrue($lifecycle->claimJobExecution($first));
        $this->assertTrue($lifecycle->claimJobExecution($second));
        $this->assertTrue($lifecycle->claimJobExecution($third));
    }

    public function test_immediate_contextual_delete_waits_for_its_active_create_then_both_finish_without_sticking(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-create-delete-order@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Keep this reminder',
            'remind_at' => now()->addDays(2),
            'status' => 'scheduled',
        ]);

        $createTurn = $this->admit(
            $token,
            $sessionId,
            'create-delete-order-create-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
        );
        $deleteTurn = $this->admit(
            $token,
            $sessionId,
            'create-delete-order-delete-0001',
            'Delete that reminder.',
        );
        $createRun = $createTurn->runs()->firstOrFail();
        $deleteRun = $deleteTurn->runs()->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $this->assertNotNull($createRun->resource_lock_key);
        $this->assertSame($createRun->resource_lock_key, $deleteRun->resource_lock_key);
        $this->assertSame(0, $createRun->priority);
        $this->assertLessThan($createRun->priority, $deleteRun->priority);

        // Simulate the dependent worker winning the queue race. It must wait,
        // not fail target resolution or claim the shared resource first, even
        // in the outbox window before the create dispatch marker is durable.
        $createRun->update(['dispatch_requested_at' => null]);
        $this->assertFalse($lifecycle->claimJobExecution($deleteRun));
        $this->assertNotNull(data_get($deleteRun->fresh()->metadata, 'dependency_wait_started_at'));

        (new ProcessAssistantRun($createRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        $this->assertSame('completed', $createRun->fresh()->status);
        $this->assertSame(1, Reminder::where('title', 'Call Mom')->count());

        (new ProcessAssistantRun($deleteRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $createTurn = $createTurn->fresh(['finalAssistantMessage', 'runs']);
        $deleteTurn = $deleteTurn->fresh(['finalAssistantMessage', 'runs']);
        $this->assertSame(VoiceTurnState::Completed, $createTurn->state);
        $this->assertSame(VoiceTurnState::Completed, $deleteTurn->state);
        $this->assertSame('completed', $deleteRun->fresh()->status);
        $this->assertNull($createTurn->failure_category);
        $this->assertNull($deleteTurn->failure_category);
        $this->assertNotNull($createTurn->finalAssistantMessage);
        $this->assertNotNull($deleteTurn->finalAssistantMessage);
        $this->assertSame(0, Reminder::where('title', 'Call Mom')->count());
        $this->assertSame(1, Reminder::where('title', 'Keep this reminder')->count());
    }

    public function test_contextual_reschedule_and_completion_share_the_active_create_lock_at_lower_priority(): void
    {
        $token = $this->apiToken('voice-v2-contextual-mutation-policy@example.com');

        foreach ([
            [
                'Create a reminder titled Call Mom for tomorrow at noon.',
                'Move that reminder to tomorrow at 4 p.m.',
                'app.reminder.reschedule',
            ],
            [
                'Create a task titled File taxes.',
                'Mark that task complete.',
                'app.task.complete',
            ],
        ] as $index => [$createTranscript, $mutationTranscript, $expectedHandler]) {
            $sessionId = $this->sessionId($token);
            $createTurn = $this->admit(
                $token,
                $sessionId,
                "contextual-mutation-create-{$index}-0001",
                $createTranscript,
            );
            if ($index === 0) {
                // A read-only bypass does not replace the still-active write
                // target that the later contextual mutation refers to.
                $this->admit(
                    $token,
                    $sessionId,
                    'contextual-mutation-read-bypass-0001',
                    'What time is it?',
                );
            }
            $mutationTurn = $this->admit(
                $token,
                $sessionId,
                "contextual-mutation-dependent-{$index}-0001",
                $mutationTranscript,
            );
            $createRun = $createTurn->runs()->firstOrFail();
            $mutationRun = $mutationTurn->runs()->firstOrFail();

            $this->assertSame($expectedHandler, $mutationRun->handler);
            $this->assertNotNull($createRun->resource_lock_key);
            $this->assertSame($createRun->resource_lock_key, $mutationRun->resource_lock_key);
            $this->assertLessThan($createRun->priority, $mutationRun->priority);
        }
    }

    public function test_contextual_delete_fails_closed_when_its_create_fails_and_never_deletes_an_unrelated_item(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-create-delete-fail-closed@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        $unrelated = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Keep this reminder',
            'remind_at' => now()->addDays(2),
            'status' => 'scheduled',
        ]);
        $createTurn = $this->admit(
            $token,
            $sessionId,
            'create-delete-fail-create-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
        );
        $deleteTurn = $this->admit(
            $token,
            $sessionId,
            'create-delete-fail-delete-0001',
            'Delete that reminder.',
        );
        $createRun = $createTurn->runs()->firstOrFail();
        $deleteRun = $deleteTurn->runs()->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $lifecycle->finishJob(
            $createRun,
            'failed',
            failureCategory: 'simulated_create_failure',
            internalDetail: 'The simulated create did not commit.',
            userFacingFailure: 'I couldn’t create that reminder. Would you like me to try again?',
        );
        (new ProcessAssistantRun($deleteRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $this->assertSame(VoiceTurnState::Failed, $createTurn->fresh()->state);
        $this->assertSame(VoiceTurnState::Failed, $deleteTurn->fresh()->state);
        $this->assertSame('failed', $deleteRun->fresh()->status);
        $this->assertDatabaseHas('reminders', [
            'id' => $unrelated->id,
            'title' => 'Keep this reminder',
        ]);
        $this->assertSame(0, Reminder::where('title', 'Call Mom')->count());
    }

    public function test_same_turn_contextual_delete_waits_for_its_create_and_uses_that_exact_receipt(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-same-turn-create-delete@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        $unrelated = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Keep this reminder',
            'remind_at' => now()->addDays(2),
            'status' => 'scheduled',
        ]);
        $turn = $this->admit(
            $token,
            $sessionId,
            'same-turn-create-delete-0001',
            'Create a reminder titled Call Mom for tomorrow at noon and then delete that reminder.',
        );
        $runs = $turn->runs()->orderBy('id')->get();
        $createRun = $runs->get(0);
        $deleteRun = $runs->get(1);

        $this->assertCount(2, $runs);
        $this->assertSame('app.reminder.create', $createRun?->handler);
        $this->assertSame('app.reminder.delete', $deleteRun?->handler);
        $this->assertNotNull($createRun?->resource_lock_key);
        $this->assertSame($createRun?->resource_lock_key, $deleteRun?->resource_lock_key);
        $this->assertLessThan((int) $createRun?->priority, (int) $deleteRun?->priority);
        $this->assertSame('same_turn', data_get($deleteRun?->metadata, 'contextual_create_dependency.scope'));
        $this->assertSame(
            $createRun?->idempotency_key,
            data_get($deleteRun?->metadata, 'contextual_create_dependency.predecessor_idempotency_key'),
        );

        $lifecycle = app(VoiceTurnLifecycleService::class);
        $createRun->update(['dispatch_requested_at' => null]);
        $this->assertFalse($lifecycle->claimJobExecution($deleteRun));
        $this->assertNotNull(data_get($deleteRun->fresh()->metadata, 'dependency_wait_started_at'));
        (new ProcessAssistantRun($createRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        (new ProcessAssistantRun($deleteRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $turn = $turn->fresh(['runs', 'finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame(['completed', 'completed'], $turn->runs->sortBy('id')->pluck('status')->all());
        $this->assertCount(2, data_get($turn->metadata, 'write_receipts', []));
        $this->assertNotNull($turn->finalAssistantMessage);
        $this->assertSame(0, Reminder::where('title', 'Call Mom')->count());
        $this->assertDatabaseHas('reminders', ['id' => $unrelated->id]);
    }

    public function test_same_turn_contextual_delete_fails_closed_when_its_create_fails(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-same-turn-create-delete-failure@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        $unrelated = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Keep this reminder',
            'remind_at' => now()->addDays(2),
            'status' => 'scheduled',
        ]);
        $turn = $this->admit(
            $token,
            $sessionId,
            'same-turn-create-delete-failure-0001',
            'Create a reminder titled Call Mom for tomorrow at noon and then delete that reminder.',
        );
        $runs = $turn->runs()->orderBy('id')->get();
        $createRun = $runs->get(0);
        $deleteRun = $runs->get(1);
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $lifecycle->finishJob(
            $createRun,
            'failed',
            failureCategory: 'simulated_create_failure',
            internalDetail: 'The simulated create did not commit.',
            userFacingFailure: 'I couldn’t create that reminder. Would you like me to try again?',
        );
        (new ProcessAssistantRun($deleteRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $turn = $turn->fresh(['runs', 'finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame(['failed', 'failed'], $turn->runs->sortBy('id')->pluck('status')->all());
        $this->assertNotNull($turn->finalAssistantMessage);
        $this->assertDatabaseHas('reminders', ['id' => $unrelated->id]);
        $this->assertSame(0, Reminder::where('title', 'Call Mom')->count());
    }

    public function test_contextual_delete_after_completed_create_still_binds_to_the_exact_create_receipt(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-completed-create-follow-up@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        $unrelated = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Keep this reminder',
            'remind_at' => now()->addDays(2),
            'status' => 'scheduled',
        ]);
        $createTurn = $this->admit(
            $token,
            $sessionId,
            'completed-create-follow-up-create-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
            ['mode' => 'new_conversation', 'epoch' => 1],
        );
        $createRun = $createTurn->runs()->firstOrFail();
        (new ProcessAssistantRun($createRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        $this->assertSame(VoiceTurnState::Completed, $createTurn->fresh()->state);

        $deleteTurn = $this->admit(
            $token,
            $sessionId,
            'completed-create-follow-up-delete-0001',
            'Delete that reminder.',
            ['mode' => 'contextual_follow_up', 'epoch' => 1],
        );
        $deleteRun = $deleteTurn->runs()->firstOrFail();
        $this->assertSame($createRun->resource_lock_key, $deleteRun->resource_lock_key);
        $this->assertSame(100, $deleteRun->priority);
        $dependencies = data_get($deleteTurn->metadata, 'contextual_create_dependencies', []);
        $this->assertSame(
            $createTurn->id,
            data_get($dependencies[$deleteRun->resource_lock_key] ?? null, 'voice_turn_id'),
        );

        (new ProcessAssistantRun($deleteRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );
        $this->assertSame(VoiceTurnState::Completed, $deleteTurn->fresh()->state);
        $this->assertSame(0, Reminder::where('title', 'Call Mom')->count());
        $this->assertDatabaseHas('reminders', ['id' => $unrelated->id]);
    }

    public function test_explicit_titles_containing_pronouns_never_bind_to_a_contextual_create(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-explicit-pronoun-title@example.com');

        foreach ([
            ['Delete the reminder called Submit it.', false],
            ['Create a reminder titled Call Mom for tomorrow at noon and then delete the reminder called Submit it.', true],
        ] as $index => [$transcript, $sameTurn]) {
            $sessionId = $this->sessionId($token);
            $session = ConversationSession::findOrFail($sessionId);
            $explicitTarget = Reminder::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'title' => 'Submit it',
                'remind_at' => now()->addDays(2),
                'status' => 'scheduled',
            ]);
            if (! $sameTurn) {
                $createTurn = $this->admit(
                    $token,
                    $sessionId,
                    "explicit-pronoun-create-{$index}-0001",
                    'Create a reminder titled Call Mom for tomorrow at noon.',
                );
                $createLock = $createTurn->runs()->firstOrFail()->resource_lock_key;
            }
            $mutationTurn = $this->admit(
                $token,
                $sessionId,
                "explicit-pronoun-mutation-{$index}-0001",
                $transcript,
            );
            $deleteRun = $mutationTurn->runs()->where('handler', 'app.reminder.delete')->firstOrFail();
            if ($sameTurn) {
                $createLock = $mutationTurn->runs()->where('handler', 'app.reminder.create')->firstOrFail()->resource_lock_key;
            }

            $this->assertStringStartsWith('app.reminder.', $deleteRun->resource_lock_key);
            $this->assertSame(100, $deleteRun->priority);
            $this->assertNull(data_get($deleteRun->metadata, 'contextual_create_dependency'));
            $this->assertNotSame($createLock, $deleteRun->resource_lock_key);

            (new ProcessAssistantRun($deleteRun->id))->handle(
                app(HermesRuntimeService::class),
                app(AssistantRunService::class),
            );
            $this->assertDatabaseMissing('reminders', ['id' => $explicitTarget->id]);
        }
    }

    public function test_explicit_delete_and_reschedule_matching_an_active_create_share_its_dependency_lock(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-explicit-active-create-dependency@example.com');
        $sessionId = $this->sessionId($token);
        $createTurn = $this->admit(
            $token,
            $sessionId,
            'explicit-active-create-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
        );
        $deleteTurn = $this->admit(
            $token,
            $sessionId,
            'explicit-active-delete-0001',
            'Delete the reminder called Call Mom.',
        );
        $rescheduleTurn = $this->admit(
            $token,
            $sessionId,
            'explicit-active-reschedule-0001',
            'Move the reminder called Call Mom to tomorrow at 4 p.m.',
        );
        $createRun = $createTurn->runs()->firstOrFail();
        $deleteRun = $deleteTurn->runs()->firstOrFail();
        $rescheduleRun = $rescheduleTurn->runs()->firstOrFail();

        $this->assertSame($createRun->resource_lock_key, $deleteRun->resource_lock_key);
        $this->assertSame($createRun->resource_lock_key, $rescheduleRun->resource_lock_key);
        $this->assertLessThan($createRun->priority, $deleteRun->priority);
        $this->assertLessThan($createRun->priority, $rescheduleRun->priority);
        $this->assertFalse(app(VoiceTurnLifecycleService::class)->claimJobExecution($deleteRun));
        $this->assertFalse(app(VoiceTurnLifecycleService::class)->claimJobExecution($rescheduleRun));
        $this->assertNotNull(data_get($deleteRun->fresh()->metadata, 'dependency_wait_started_at'));
        $this->assertNotNull(data_get($rescheduleRun->fresh()->metadata, 'dependency_wait_started_at'));

        $sameTurnSessionId = $this->sessionId($token);
        $sameTurn = $this->admit(
            $token,
            $sameTurnSessionId,
            'explicit-same-turn-create-delete-0001',
            'Create a reminder titled Call Mom for tomorrow at noon and then delete the reminder called Call Mom.',
        );
        $sameTurnCreate = $sameTurn->runs()->where('handler', 'app.reminder.create')->firstOrFail();
        $sameTurnDelete = $sameTurn->runs()->where('handler', 'app.reminder.delete')->firstOrFail();
        $this->assertSame($sameTurnCreate->resource_lock_key, $sameTurnDelete->resource_lock_key);
        $this->assertSame('same_turn', data_get($sameTurnDelete->metadata, 'contextual_create_dependency.scope'));
        $this->assertFalse(app(VoiceTurnLifecycleService::class)->claimJobExecution($sameTurnDelete));
    }

    public function test_completed_create_contextual_and_explicit_mutations_converge_on_one_lock(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-completed-create-lock-convergence@example.com');
        $sessionId = $this->sessionId($token);
        $createTurn = $this->admit(
            $token,
            $sessionId,
            'completed-lock-create-0001',
            'Create a reminder titled Call Mom for tomorrow at noon.',
            ['mode' => 'new_conversation', 'epoch' => 1],
        );
        $createRun = $createTurn->runs()->firstOrFail();
        (new ProcessAssistantRun($createRun->id))->handle(
            app(HermesRuntimeService::class),
            app(AssistantRunService::class),
        );

        $contextualTurn = $this->admit(
            $token,
            $sessionId,
            'completed-lock-contextual-delete-0001',
            'Delete that reminder.',
            ['mode' => 'contextual_follow_up', 'epoch' => 1],
        );
        $explicitTurn = $this->admit(
            $token,
            $sessionId,
            'completed-lock-explicit-reschedule-0001',
            'Move the reminder called Call Mom to tomorrow at 4 p.m.',
        );
        $contextualRun = $contextualTurn->runs()->firstOrFail();
        $explicitRun = $explicitTurn->runs()->firstOrFail();

        $this->assertSame($createRun->resource_lock_key, $contextualRun->resource_lock_key);
        $this->assertSame($contextualRun->resource_lock_key, $explicitRun->resource_lock_key);
        $this->assertTrue(app(VoiceTurnLifecycleService::class)->claimJobExecution($contextualRun));
        $this->assertFalse(app(VoiceTurnLifecycleService::class)->claimJobExecution($explicitRun));
        $this->assertNotNull(data_get($explicitRun->fresh()->metadata, 'resource_wait_started_at'));
    }

    public function test_a_write_added_while_background_work_is_active_gets_an_immediate_queue_acknowledgement(): void
    {
        $token = $this->apiToken('voice-v2-queued-write-ack@example.com');
        $sessionId = $this->sessionId($token);
        $this->admit(
            $token,
            $sessionId,
            'queued-write-ack-complex-0001',
            'Create a detailed seven-day travel plan.',
        );

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'queued-write-ack-note-0001',
            'Create a note called Packing list.',
        ))->assertCreated()
            ->assertJsonPath('data.turn.lane', 'app_write')
            ->assertJsonPath('data.turn.acknowledgement_required', true)
            ->assertJsonPath('data.turn.acknowledgement_text', 'Got it—I added that.')
            ->assertJsonCount(1, 'data.jobs');
    }

    public function test_same_target_mutations_serialize_and_deletions_take_queue_priority(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-write-policy@example.com');
        $sessionId = $this->sessionId($token);
        $session = ConversationSession::findOrFail($sessionId);
        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'title' => 'Universal',
            'remind_at' => now()->addDay(),
            'status' => 'scheduled',
        ]);
        $lowPriority = $this->admit(
            $token,
            $sessionId,
            'priority-create-0001',
            'Create a note called Packing list.',
        )->runs()->firstOrFail();
        $deletion = $this->admit(
            $token,
            $sessionId,
            'priority-delete-0001',
            'Delete the reminder called Universal.',
        )->runs()->firstOrFail();
        $correction = $this->admit(
            $token,
            $sessionId,
            'priority-correction-0001',
            'Move the reminder called Universal to tomorrow at 4 p.m.',
        )->runs()->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $expectedKey = $deletion->resource_lock_key;
        $this->assertStringStartsWith('app.reminder.reference.', $expectedKey);
        $this->assertSame($expectedKey, $deletion->resource_lock_key);
        $this->assertSame($expectedKey, $correction->resource_lock_key);
        $this->assertSame(100, $deletion->priority);
        $this->assertSame(80, $correction->priority);

        $this->assertFalse($lifecycle->claimJobExecution($lowPriority));
        $this->assertNotNull(data_get($lowPriority->fresh()->metadata, 'priority_wait_started_at'));
        $this->assertTrue($lifecycle->claimJobExecution($deletion));
        $this->assertFalse($lifecycle->claimJobExecution($correction));
        $this->assertNotNull(data_get($correction->fresh()->metadata, 'resource_wait_started_at'));

        // Once the higher-priority job owns its target, the unrelated create can
        // use another one of the three background slots.
        $this->assertTrue($lifecycle->claimJobExecution($lowPriority->fresh()));
    }

    /** @param array{mode:string,epoch:int}|null $conversationContext */
    private function admit(
        string $token,
        int $sessionId,
        string $turnId,
        string $transcript,
        ?array $conversationContext = null,
    ): VoiceTurn {
        $payload = $this->payload(
            $sessionId,
            $turnId,
            $transcript,
        );
        if ($conversationContext !== null) {
            $payload['conversation_context'] = $conversationContext;
        }
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $payload)->assertCreated();

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(int $sessionId, string $turnId, string $transcript): array
    {
        return [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
        ];
    }

    private function sessionId(string $token): int
    {
        return (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
    }
}
