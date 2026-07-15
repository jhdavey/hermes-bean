<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnState;
use App\Models\AssistantRun;
use App\Models\CalendarEvent;
use App\Models\MemoryItem;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\VoiceTurn;
use App\Services\HermesSemanticContextService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrowserVoiceSemanticContextTest extends TestCase
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

    public function test_only_same_epoch_follow_up_history_is_authorized_for_conversational_references(): void
    {
        $token = $this->apiToken('semantic-context-scope@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $first = $this->admit($token, $sessionId, 'context-scope-first-0001', 'Show my launch tasks.', [
            'mode' => 'new_conversation',
            'epoch' => 9,
        ]);
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $first = $lifecycle->complete($first, 'I found Plan the launch.');
        $task = Task::create([
            'user_id' => $first->user_id,
            'workspace_id' => $first->workspace_id,
            'created_by_user_id' => $first->user_id,
            'conversation_session_id' => $sessionId,
            'title' => 'Plan the launch',
            'type' => 'todo',
            'status' => 'open',
        ]);
        AssistantRun::create([
            'voice_turn_id' => $first->id,
            'user_id' => $first->user_id,
            'workspace_id' => $first->workspace_id,
            'conversation_session_id' => $sessionId,
            'source' => 'browser_voice_v2',
            'lane' => 'app_write',
            'handler' => 'semantic.operation',
            'label' => 'Update task',
            'idempotency_key' => $first->turn_id.':context-work-fact',
            'status' => 'completed',
            'input' => json_encode([
                'id' => 'move',
                'tool' => 'app.task.update',
                'arguments' => [
                    'id' => $task->id,
                    'due_at' => '2026-07-16T15:00:00-04:00',
                    'query' => 'prose that must not leak',
                ],
                'dependencies' => [],
            ], JSON_THROW_ON_ERROR),
            'metadata' => [
                'semantic_operation_id' => 'move',
                'semantic_tool' => 'app.task.update',
                'semantic_operation_receipt' => [
                    'data' => ['events' => [['data' => [
                        'task_id' => $task->id,
                        'title' => 'Plan the launch',
                    ]]]],
                ],
            ],
            'completed_at' => now(),
        ]);
        $lifecycle->markFinalAudioStarted($first, 'final_audio_started', [
            'speech_item_id' => 'context-scope-first-final',
            'controller_generation' => 4,
            'purpose' => 'final',
        ]);
        $lifecycle->recordBrowserEvent($first, 'playback_finished', [
            'speech_item_id' => 'context-scope-first-final',
            'controller_generation' => 4,
            'purpose' => 'final',
        ]);
        $followUp = $this->admit($token, $sessionId, 'context-scope-follow-0001', 'Move the first one.', [
            'mode' => 'contextual_follow_up',
            'epoch' => 9,
        ]);

        $context = app(HermesSemanticContextService::class)->forVoiceTurn($followUp);
        $this->assertTrue(data_get($context, 'conversation_reference_scope.authorized'));
        $this->assertSame($first->turn_id, data_get($context, 'conversation_reference_scope.authorized_prior_turn_id'));
        $this->assertSame(
            [$first->turn_id, $first->turn_id, $followUp->turn_id],
            collect($context['authorized_conversation'])->pluck('stable_turn_id')->all(),
        );

        $stale = $this->admit($token, $sessionId, 'context-scope-stale-0001', 'Move it again.', [
            'mode' => 'new_conversation',
            'epoch' => 10,
        ]);
        $staleContext = app(HermesSemanticContextService::class)->forVoiceTurn($stale);
        $this->assertFalse(data_get($staleContext, 'conversation_reference_scope.authorized'));
        $this->assertNull(data_get($staleContext, 'conversation_reference_scope.authorized_prior_turn_id'));
        $this->assertSame(
            [$stale->turn_id],
            collect($staleContext['authorized_conversation'])->pluck('stable_turn_id')->all(),
        );
        $this->assertNotEmpty($staleContext['recent_voice_turns']);
        foreach ($staleContext['recent_voice_turns'] as $workFact) {
            $this->assertArrayNotHasKey('transcript', $workFact);
            $this->assertArrayNotHasKey('final_text', $workFact);
        }
        $operation = collect($staleContext['recent_voice_turns'])
            ->firstWhere('stable_turn_id', $first->turn_id)['operations'][0];
        $this->assertSame('app.task.update', $operation['tool']);
        $this->assertSame('completed', $operation['run_status']);
        $this->assertSame($task->id, $operation['resource_id']);
        $this->assertSame('Plan the launch', $operation['resource_title']);
        $this->assertArrayNotHasKey('query', $operation);
        $this->assertArrayNotHasKey('arguments', $operation);

        $oldBranch = $this->admit($token, $sessionId, 'context-scope-old-branch-0001', 'Move the first one.', [
            'mode' => 'contextual_follow_up',
            'epoch' => 9,
        ]);
        $oldBranchContext = app(HermesSemanticContextService::class)->forVoiceTurn($oldBranch);
        $this->assertFalse(data_get($oldBranchContext, 'conversation_reference_scope.authorized'));
        $this->assertSame(
            [$oldBranch->turn_id],
            collect($oldBranchContext['authorized_conversation'])->pluck('stable_turn_id')->all(),
        );
    }

    public function test_server_follow_up_window_expires_and_a_duplicate_playback_event_cannot_extend_it(): void
    {
        Carbon::setTestNow('2026-07-14 14:30:00', 'UTC');
        $token = $this->apiToken('semantic-context-window@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $first = $this->admit($token, $sessionId, 'context-window-first-0001', 'Show my tasks.', [
            'mode' => 'new_conversation',
            'epoch' => 3,
        ]);
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $first = $lifecycle->complete($first, 'Here are your tasks.');
        $eventPayload = [
            'speech_item_id' => 'context-window-first-final',
            'controller_generation' => 4,
            'purpose' => 'final',
        ];
        $lifecycle->markFinalAudioStarted($first, 'final_audio_started', $eventPayload);
        $lifecycle->recordBrowserEvent($first, 'playback_finished', $eventPayload);

        Carbon::setTestNow('2026-07-14 14:30:16', 'UTC');
        $lifecycle->recordBrowserEvent($first, 'playback_finished', $eventPayload);
        $followUp = $this->admit($token, $sessionId, 'context-window-follow-0001', 'Move the first one.', [
            'mode' => 'contextual_follow_up',
            'epoch' => 3,
        ]);

        $context = app(HermesSemanticContextService::class)->forVoiceTurn($followUp);
        $this->assertFalse(data_get($context, 'conversation_reference_scope.authorized'));
        $this->assertNull(data_get($context, 'conversation_reference_scope.authorized_prior_turn_id'));
        $this->assertSame(
            [$followUp->turn_id],
            collect($context['authorized_conversation'])->pluck('stable_turn_id')->all(),
        );

        $followUp = $lifecycle->complete($followUp, 'I need a fresh task reference.');
        $followUpFinalPlayback = [
            'speech_item_id' => 'context-window-follow-final',
            'controller_generation' => 4,
            'purpose' => 'final',
        ];
        $lifecycle->markFinalAudioStarted($followUp, 'final_audio_started', $followUpFinalPlayback);
        $lifecycle->recordBrowserEvent($followUp, 'playback_finished', $followUpFinalPlayback);
        $newChain = $this->admit($token, $sessionId, 'context-window-new-chain-0001', 'Show those tasks.', [
            'mode' => 'contextual_follow_up',
            'epoch' => 3,
        ]);
        $newChainContext = app(HermesSemanticContextService::class)->forVoiceTurn($newChain);
        $this->assertTrue(data_get($newChainContext, 'conversation_reference_scope.authorized'));
        $this->assertSame($followUp->turn_id, data_get(
            $newChainContext,
            'conversation_reference_scope.authorized_prior_turn_id',
        ));
        $authorizedTurnIds = collect($newChainContext['authorized_conversation'])->pluck('stable_turn_id')->unique()->values();
        $this->assertEqualsCanonicalizing([$followUp->turn_id, $newChain->turn_id], $authorizedTurnIds->all());
        $this->assertNotContains($first->turn_id, $authorizedTurnIds->all());
    }

    public function test_acknowledgement_and_clarification_playback_never_open_general_follow_up_context(): void
    {
        $token = $this->apiToken('semantic-nonfinal-context-window@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $acknowledgementTurn = $this->admit(
            $token,
            $sessionId,
            'context-ack-window-0001',
            'Create a task.',
            ['mode' => 'new_conversation', 'epoch' => 14],
        );
        $acknowledgementTurn->forceFill([
            'acknowledgement_required' => true,
            'acknowledgement_text' => 'I’ll create that task.',
        ])->saveQuietly();
        $acknowledgementPlayback = [
            'speech_item_id' => 'context-ack-window-audio',
            'controller_generation' => 4,
            'purpose' => 'acknowledgement',
        ];
        $lifecycle->markAcknowledged($acknowledgementTurn->fresh(), $acknowledgementPlayback);
        $lifecycle->recordBrowserEvent($acknowledgementTurn->fresh(), 'playback_finished', $acknowledgementPlayback);

        $afterAcknowledgement = $this->admit(
            $token,
            $sessionId,
            'context-after-ack-0001',
            'Move it.',
            ['mode' => 'contextual_follow_up', 'epoch' => 14],
        );
        $acknowledgementContext = app(HermesSemanticContextService::class)->forVoiceTurn($afterAcknowledgement);
        $this->assertFalse(data_get($acknowledgementContext, 'conversation_reference_scope.authorized'));

        $clarificationTurn = $this->admit(
            $token,
            $sessionId,
            'context-clarification-window-0001',
            'Move a task.',
            ['mode' => 'new_conversation', 'epoch' => 15],
        );
        $clarificationTurn->forceFill([
            'state' => VoiceTurnState::AwaitingClarification,
            'metadata' => [
                ...$clarificationTurn->metadata,
                'clarification_question' => 'Which task?',
                'clarification_sequence' => 1,
            ],
        ])->saveQuietly();
        $clarificationPlayback = [
            'speech_item_id' => 'context-clarification-window-audio',
            'controller_generation' => 4,
            'purpose' => 'clarification',
        ];
        $lifecycle->recordBrowserEvent($clarificationTurn->fresh(), 'playback_started', $clarificationPlayback);
        $lifecycle->recordBrowserEvent($clarificationTurn->fresh(), 'playback_finished', $clarificationPlayback);

        $afterClarification = $this->admit(
            $token,
            $sessionId,
            'context-after-clarification-0001',
            'Move it.',
            ['mode' => 'contextual_follow_up', 'epoch' => 15],
        );
        $clarificationContext = app(HermesSemanticContextService::class)->forVoiceTurn($afterClarification);
        $this->assertFalse(data_get($clarificationContext, 'conversation_reference_scope.authorized'));
    }

    public function test_authorized_note_folders_are_exposed_to_hermes_with_stable_ids(): void
    {
        $token = $this->apiToken('semantic-note-folder-context@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $turn = $this->admit($token, $sessionId, 'context-note-folder-0001', 'Create a note in Work.', [
            'mode' => 'new_conversation',
            'epoch' => 1,
        ]);
        $folder = NoteFolder::create([
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'name' => 'Work',
        ]);

        $context = app(HermesSemanticContextService::class)->forVoiceTurn($turn);

        $this->assertSame([
            'id' => $folder->id,
            'name' => 'Work',
            'updated_at' => $folder->updated_at?->toIso8601String(),
        ], $context['resources']['note_folders'][0]);
    }

    public function test_authoritative_browser_voice_state_is_preserved_as_execution_context(): void
    {
        $token = $this->apiToken('semantic-client-state@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $turn = $this->admit(
            $token,
            $sessionId,
            'context-client-state-0001',
            'Are you listening?',
            ['mode' => 'new_conversation', 'epoch' => 1],
            [
                'voice_mode_active' => true,
                'wake_detection_enabled' => true,
                'playback_state' => 'playing_final',
            ],
        );

        $this->assertSame([
            'voice_mode_active' => true,
            'wake_detection_enabled' => true,
            'playback_state' => 'playing_final',
        ], data_get($turn->metadata, 'client_context'));
    }

    public function test_trusted_location_is_exposed_to_hermes_as_read_only_semantic_context(): void
    {
        $token = $this->apiToken('semantic-location-context@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $turn = $this->admit(
            $token,
            $sessionId,
            'context-location-0001',
            'What is the weather here?',
            ['mode' => 'new_conversation', 'epoch' => 1],
            [],
            [
                'label' => 'Orlando, Florida',
                'latitude' => 28.5383,
                'longitude' => -81.3792,
                'is_local' => true,
                'source' => 'browser_geolocation',
            ],
        );

        $context = app(HermesSemanticContextService::class)->forVoiceTurn($turn);

        $this->assertSame([
            'label' => 'Orlando, Florida',
            'latitude' => 28.5383,
            'longitude' => -81.3792,
            'is_local' => true,
        ], $context['trusted_location']);
    }

    public function test_resource_context_exposes_recurrence_and_all_day_exactly_as_stored(): void
    {
        Carbon::setTestNow('2026-07-14 14:00:00', 'America/New_York');
        $token = $this->apiToken('semantic-canonical-schedule-context@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $turn = $this->admit(
            $token,
            $sessionId,
            'canonical-schedule-context-0001',
            'What recurring work do I have?',
            ['mode' => 'new_conversation', 'epoch' => 22],
        );
        $task = Task::create([
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'conversation_session_id' => $sessionId,
            'title' => 'Daily inventory',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => '2026-07-15 04:00:00',
            'metadata' => ['recurrence' => 'daily'],
        ]);
        $reminder = Reminder::create([
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'conversation_session_id' => $sessionId,
            'title' => 'Weekly report',
            'status' => 'scheduled',
            'remind_at' => '2026-07-15 04:00:00',
            'metadata' => ['recurrence' => 'weekly'],
        ]);
        $calendar = CalendarEvent::create([
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'conversation_session_id' => $sessionId,
            'title' => 'Midnight monthly review',
            'status' => 'scheduled',
            'recurrence' => 'monthly',
            'starts_at' => '2026-07-15 04:00:00',
            'ends_at' => '2026-07-16 04:00:00',
            'metadata' => ['all_day' => false],
        ]);

        $resources = app(HermesSemanticContextService::class)->forVoiceTurn($turn)['resources'];
        $taskFact = collect($resources['tasks'])->firstWhere('id', $task->id);
        $reminderFact = collect($resources['reminders'])->firstWhere('id', $reminder->id);
        $calendarFact = collect($resources['calendar_events'])->firstWhere('id', $calendar->id);

        $this->assertSame('daily', $taskFact['recurrence']);
        $this->assertSame('weekly', $reminderFact['recurrence']);
        $this->assertSame('monthly', $calendarFact['recurrence']);
        $this->assertFalse($calendarFact['all_day']);
        $this->assertSame('2026-07-15T04:00:00+00:00', $calendarFact['starts_at']);
        $this->assertSame('2026-07-16T04:00:00+00:00', $calendarFact['ends_at']);
    }

    /** @param array{mode:string,epoch:int} $conversationContext */
    public function test_only_active_unexpired_memory_items_are_exposed_with_stable_ids(): void
    {
        $token = $this->apiToken('semantic-memory-context@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $turn = $this->admit($token, $sessionId, 'memory-context-turn-0001', 'What do you remember?', [
            'mode' => 'new_conversation',
            'epoch' => 31,
        ]);
        $active = MemoryItem::query()->create([
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'type' => 'preference',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Preferred editor',
            'content' => 'The user prefers Nova.',
            'summary' => 'Nova is preferred.',
        ]);
        MemoryItem::query()->create([
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'type' => 'temporary_context',
            'status' => 'active',
            'visibility' => 'workspace',
            'title' => 'Expired context',
            'content' => 'This must not be exposed.',
            'expires_at' => now()->subMinute(),
        ]);
        MemoryItem::query()->create([
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'type' => 'fact',
            'status' => 'archived',
            'visibility' => 'workspace',
            'title' => 'Archived fact',
            'content' => 'This must not be exposed either.',
        ]);

        $context = app(HermesSemanticContextService::class)->forVoiceTurn($turn);

        $this->assertCount(1, data_get($context, 'resources.memory_items'));
        $this->assertSame($active->id, data_get($context, 'resources.memory_items.0.id'));
        $this->assertSame('preference', data_get($context, 'resources.memory_items.0.type'));
        $this->assertSame('The user prefers Nova.', data_get($context, 'resources.memory_items.0.content'));
    }

    private function admit(
        string $token,
        int $sessionId,
        string $turnId,
        string $transcript,
        array $conversationContext,
        array $clientContext = [],
        ?array $locationContext = null,
    ): VoiceTurn {
        $this->withToken($token)->postJson('/api/assistant/voice/turns', [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 4,
            'provider_connection_generation' => 2,
            'conversation_context' => $conversationContext,
            'client_context' => $clientContext,
            'location_context' => $locationContext,
        ])->assertCreated();

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }
}
