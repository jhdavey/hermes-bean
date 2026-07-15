<?php

namespace Tests\Feature;

use App\Enums\VoiceRealtimeCommandStatus;
use App\Enums\VoiceRealtimeCommandType;
use App\Enums\VoiceTurnState;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeEvent;
use App\Models\VoiceRealtimeSession;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\RealtimeVoiceApplicationEventHandler;
use App\Services\RealtimeVoiceCommandService;
use App\Services\RealtimeVoiceEventService;
use App\Services\RealtimeVoiceSessionService;
use App\Services\VoiceTurnLifecycleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RealtimeVoiceApplicationEventHandlerTest extends TestCase
{
    use RefreshDatabase;

    private int $eventSequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        config()->set('services.voice_realtime.playback_authorization_grace_ms', 250);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_direct_semantic_response_returns_function_result_then_one_native_audio_authorization(): void
    {
        $fixture = $this->admittedTurn('handler-direct@example.com', 'handler-direct-0001');
        $plan = $this->requestAndAcknowledgePlan($fixture, 'input_direct', 'resp_plan_direct');

        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_direct',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_direct',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user is checking whether Bean can hear them.',
                    'interpretation' => $this->interpretation(
                        outcome: 'respond',
                        responseText: 'Yes, I can hear you.',
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $turn = $fixture['turn']->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $turn->state);
        $this->assertSame('Yes, I can hear you.', $turn->finalAssistantMessage?->content);
        $this->assertSame(1, $turn->session->messages()->where('role', 'assistant')->count());

        $output = $this->command($fixture['session'], 'function-output:call_plan_direct');
        $final = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->sole();
        $this->assertLessThan($final->id, $output->id);
        $this->assertSame(VoiceRealtimeCommandType::ConversationItemCreate, $output->command_type);
        $this->assertSame(['audio'], data_get($final->payload, 'response.output_modalities'));
        $this->assertSame(hash('sha256', 'Yes, I can hear you.'), $final->approved_text_hash);
        $this->assertSame('none', data_get($final->payload, 'response.tool_choice'));
        $this->assertSame('resp_plan_direct', $plan->fresh()->provider_response_id);

        $commandCount = VoiceRealtimeCommand::query()->where('voice_turn_id', $turn->id)->count();
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_direct',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_direct',
                'name' => 'bean_turn_plan',
                'arguments' => '{}',
            ],
        ]);
        $this->assertSame($commandCount, VoiceRealtimeCommand::query()->where('voice_turn_id', $turn->id)->count());
        $this->assertSame(1, $turn->session->messages()->where('role', 'assistant')->count());
    }

    public function test_clarification_pauses_the_turn_and_divergent_audio_is_cancelled_and_cleared(): void
    {
        $fixture = $this->admittedTurn('handler-clarify@example.com', 'handler-clarify-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_clarify', 'resp_plan_clarify');

        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_clarify',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_clarify',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user wants a task changed but did not identify which task.',
                    'interpretation' => $this->interpretation(
                        outcome: 'clarify',
                        clarificationQuestion: 'Which task should I change?',
                        responseExpected: true,
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $turn = $fixture['turn']->fresh();
        $this->assertSame(VoiceTurnState::AwaitingClarification, $turn->state);
        $clarification = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'clarification')
            ->sole();
        $this->assertSame(hash('sha256', 'Which task should I change?'), $clarification->approved_text_hash);

        $this->sendConversationOutputThenAcknowledgeResponse(
            $fixture['session'],
            'handler-daemon',
            $clarification,
            'resp_clarification_audio',
        );
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_audio_transcript.done',
            'response_id' => 'resp_clarification_audio',
            'transcript' => 'I changed the first task already.',
        ]);

        $this->assertSame(VoiceTurnState::Failed, $turn->fresh()->state);
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('command_type', VoiceRealtimeCommandType::ResponseCancel->value)
            ->where('purpose', 'divergent_output')
            ->count());
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('command_type', VoiceRealtimeCommandType::OutputAudioBufferClear->value)
            ->where('purpose', 'divergent_output')
            ->count());
    }

    public function test_clarification_answer_reuses_the_stable_turn_and_finishes_once(): void
    {
        $fixture = $this->admittedTurn('handler-clarify-complete@example.com', 'handler-clarify-complete-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_clarify_first', 'resp_plan_clarify_first');
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_clarify_first',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_clarify_first',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The requested task is ambiguous.',
                    'interpretation' => $this->interpretation(
                        outcome: 'clarify',
                        clarificationQuestion: 'Which task should I change?',
                        responseExpected: true,
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $clarification = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $fixture['turn']->id)
            ->where('purpose', 'clarification')
            ->sole();
        $this->sendConversationOutputThenAcknowledgeResponse(
            $fixture['session'],
            'handler-daemon',
            $clarification,
            'resp_clarification_complete_audio',
        );

        $continued = app(VoiceTurnLifecycleService::class)->preAdmitRealtime(
            $fixture['user'],
            $fixture['conversation'],
            $fixture['session'],
            [
                'turn_id' => $fixture['turn']->turn_id,
                'controller_generation' => 1,
                'provider_connection_generation' => 1,
                'input_generation' => 2,
                'conversation_context' => ['mode' => 'contextual_follow_up', 'epoch' => 1],
                'client_milestones' => [],
            ],
        );
        $this->assertSame($fixture['turn']->id, $continued->id);

        $this->requestAndAcknowledgePlan(
            [...$fixture, 'turn' => $continued],
            'input_clarify_answer',
            'resp_plan_clarify_answer',
        );
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_clarify_answer',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_clarify_answer',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user identified the first task and asked for its status.',
                    'interpretation' => $this->interpretation(
                        outcome: 'respond',
                        responseText: 'The first task is still open.',
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $terminal = $continued->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('The first task is still open.', $terminal->finalAssistantMessage?->content);
        $this->assertSame(2, data_get($terminal->metadata, 'semantic_sequence'));
        $this->assertSame(1, $terminal->session->messages()->where('role', 'user')->count());
        $this->assertSame(1, $terminal->session->messages()->where('role', 'assistant')->count());
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $terminal->id)
            ->where('purpose', 'final')
            ->count());
    }

    public function test_out_of_order_function_output_is_recovered_after_response_binding_without_duplicate_work(): void
    {
        $fixture = $this->admittedTurn('handler-out-of-order@example.com', 'handler-out-of-order-0001');
        $this->providerEvent($fixture['session'], [
            'type' => 'input_audio_buffer.committed',
            'item_id' => 'input_out_of_order',
        ]);
        $plan = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $fixture['turn']->id)
            ->where('purpose', 'semantic_plan')
            ->latest('id')
            ->firstOrFail();
        $commands = app(RealtimeVoiceCommandService::class);
        $commands->markSent($commands->claimNext($fixture['session'], 'handler-daemon'), 'handler-daemon');

        $functionEvent = [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_out_of_order',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_out_of_order',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user asked a direct conversational question.',
                    'interpretation' => $this->interpretation(
                        outcome: 'respond',
                        responseText: 'I am ready to help.',
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ];
        $this->providerEvent($fixture['session'], $functionEvent);
        $this->assertSame(VoiceTurnState::Accepted, $fixture['turn']->fresh()->state);

        $this->providerEvent($fixture['session'], [
            'type' => 'response.created',
            'response' => [
                'id' => 'resp_out_of_order',
                'metadata' => ['bean_command_id' => $plan->command_id],
            ],
        ]);

        $terminal = $fixture['turn']->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('I am ready to help.', $terminal->finalAssistantMessage?->content);
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('command_id', 'function-output:call_plan_out_of_order')
            ->count());
        $this->assertSame(1, $terminal->session->messages()->where('role', 'assistant')->count());
    }

    public function test_invalid_structured_plan_retries_once_then_terminalizes_with_one_neutral_final(): void
    {
        $fixture = $this->admittedTurn('handler-retry@example.com', 'handler-retry-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_retry', 'resp_plan_retry_1');

        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_retry_1',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_retry_1',
                'name' => 'bean_turn_plan',
                'arguments' => '{}',
            ],
        ]);
        $retry = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $fixture['turn']->id)
            ->where('purpose', 'semantic_plan')
            ->latest('id')
            ->firstOrFail();
        $this->assertStringEndsWith(':2', $retry->command_id);
        $this->sendConversationOutputThenAcknowledgeResponse(
            $fixture['session'],
            'handler-daemon',
            $retry,
            'resp_plan_retry_2',
        );

        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_retry_2',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_retry_2',
                'name' => 'bean_turn_plan',
                'arguments' => '{}',
            ],
        ]);

        $terminal = $fixture['turn']->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertSame(1, $terminal->retry_count);
        $this->assertSame('I’m sorry, I couldn’t finish that voice request. Please try again.', $terminal->finalAssistantMessage?->content);
        $this->assertSame(2, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $terminal->id)
            ->where('purpose', 'semantic_plan')
            ->count());
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $terminal->id)
            ->where('purpose', 'final')
            ->count());
        $this->assertSame(1, $terminal->session->messages()->where('role', 'assistant')->count());
    }

    public function test_execute_plan_runs_on_voice_high_seals_receipt_and_authorizes_one_deterministic_final(): void
    {
        $fixture = $this->admittedTurn('handler-execute@example.com', 'handler-execute-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_execute', 'resp_plan_execute');

        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_execute',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_execute',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'Create a task named Buy milk.',
                    'interpretation' => $this->interpretation(
                        outcome: 'execute',
                        acknowledgementText: null,
                        operations: [[
                            'id' => 'create_task',
                            'tool' => 'app.task.create',
                            'arguments_json' => json_encode([
                                ...$this->taskCreateDefaults(),
                                'title' => 'Buy milk',
                            ], JSON_THROW_ON_ERROR),
                            'dependencies' => [],
                        ]],
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);

        $turn = $fixture['turn']->fresh(['runs']);
        $operation = $turn->runs->firstWhere('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER);
        $this->assertInstanceOf(AssistantRun::class, $operation);
        Queue::assertPushed(ProcessAssistantRun::class, function (ProcessAssistantRun $job) use ($operation): bool {
            return $job->assistantRunId === $operation->id && $job->queue === 'voice-high';
        });

        app()->call([new ProcessAssistantRun($operation->id), 'handle']);

        $terminal = $turn->fresh(['finalAssistantMessage', 'runs']);
        $receipt = app(HermesSemanticOperationExecutor::class)->receiptForRun($operation->fresh());
        $this->assertSame(VoiceTurnState::Completed, $terminal->state);
        $this->assertSame('completed', $receipt['status'] ?? null);
        $this->assertTrue($receipt['side_effect_committed'] ?? false);
        $this->assertSame(1, Task::query()->where('title', 'Buy milk')->count());
        $this->assertNotSame('', trim((string) $terminal->finalAssistantMessage?->content));
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->count());
        $composition = $terminal->runs->firstWhere('handler', HermesSemanticOperationExecutor::COMPOSITION_HANDLER);
        $this->assertSame('completed', $composition?->status);
        $this->assertSame('receipt_template', data_get($composition?->result, 'metadata.composition_source'));
    }

    public function test_provider_error_terminalizes_the_active_turn_and_authorizes_neutral_native_audio(): void
    {
        $fixture = $this->admittedTurn('handler-error@example.com', 'handler-error-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_error', 'resp_plan_error');

        $this->providerEvent($fixture['session'], [
            'type' => 'error',
            'error' => [
                'code' => 'server_error',
                'message' => 'Provider failed with sk-secret-that-must-not-persist-123456.',
            ],
        ]);

        $turn = $fixture['turn']->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('realtime_server_error', $turn->failure_category);
        $this->assertSame('I’m sorry, I couldn’t finish that voice request. Please try again.', $turn->finalAssistantMessage?->content);
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->count());
        $event = VoiceRealtimeEvent::query()->where('event_type', 'error')->sole();
        $this->assertStringNotContainsString('sk-secret-that', json_encode($event->payload, JSON_THROW_ON_ERROR));
    }

    public function test_exhausted_durable_provider_event_reconciliation_terminalizes_its_bound_turn_once(): void
    {
        $fixture = $this->admittedTurn('handler-event-exhausted@example.com', 'handler-event-exhausted-0001');
        $record = app(RealtimeVoiceEventService::class)->record($fixture['session'], [
            'event_id' => 'evt_exhausted_bound_turn',
            'type' => 'response.created',
            'response' => [
                'id' => 'resp_exhausted_bound_turn',
                'metadata' => ['turn_id' => $fixture['turn']->turn_id],
            ],
        ]);
        $event = $record['event'];
        $event->forceFill([
            'processing_attempts' => 3,
            'failed_at' => now(),
            'error' => 'Synthetic exhausted provider event.',
        ])->save();

        $handler = app(RealtimeVoiceApplicationEventHandler::class);
        $handler->handleEventFailure($event->fresh());
        $handler->handleEventFailure($event->fresh());

        $turn = $fixture['turn']->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $turn->state);
        $this->assertSame('realtime_event_processing_failed', $turn->failure_category);
        $this->assertSame('I’m sorry, I couldn’t finish that voice request. Please try again.', $turn->finalAssistantMessage?->content);
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->count());
        $this->assertSame(1, $turn->session->messages()->where('role', 'assistant')->count());
    }

    public function test_reload_rebinds_an_unplayed_durable_final_to_the_newest_ready_session_exactly_once(): void
    {
        $fixture = $this->admittedTurn('handler-reload@example.com', 'handler-reload-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_reload', 'resp_plan_reload');
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_reload',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_reload',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user asked for a direct answer before reloading.',
                    'interpretation' => $this->interpretation(
                        outcome: 'respond',
                        responseText: 'Your answer is ready after reload.',
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        $oldFinal = VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $fixture['session']->id)
            ->where('voice_turn_id', $fixture['turn']->id)
            ->where('purpose', 'final')
            ->sole();

        $sessions = app(RealtimeVoiceSessionService::class);
        $replacement = $sessions->createPending(
            $fixture['user'],
            $fixture['conversation'],
            'gpt-realtime-2.1-mini',
            'marin',
            2,
            [
                'timezone' => 'America/New_York',
                'provider_connection_generation' => 2,
                'playback_capability' => 'replacement-capability',
            ],
        );
        $replacement = $sessions->bindProviderCall($replacement, 'rtc_replacement_reload');
        $replacement = $sessions->markReady(
            $sessions->acquireLease($replacement, 'replacement-daemon'),
            'replacement-daemon',
        );

        $handler = app(RealtimeVoiceApplicationEventHandler::class);
        $handler->handleSessionReady($replacement);
        $handler->handleSessionReady($replacement);

        $turn = $fixture['turn']->fresh();
        $this->assertSame($replacement->id, $turn->realtime_session_id);
        $this->assertSame(2, data_get($turn->metadata, 'controller_generation'));
        $this->assertSame(2, data_get($turn->metadata, 'provider_connection_generation'));
        $this->assertSame(VoiceRealtimeCommandStatus::Failed, $oldFinal->fresh()->status);
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $replacement->id)
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->count());
        $replacementFinal = VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $replacement->id)
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->sole();
        $this->assertSame('replacement-capability', data_get(
            $replacementFinal->payload,
            'response.metadata.playback_capability',
        ));
        $this->assertSame('2', data_get(
            $replacementFinal->payload,
            'response.metadata.provider_connection_generation',
        ));
        $this->assertNotSame($oldFinal->speech_item_id, $replacementFinal->speech_item_id);
        $this->assertStringEndsWith(':delivery:1', (string) $oldFinal->speech_item_id);
        $this->assertStringEndsWith(':delivery:2', (string) $replacementFinal->speech_item_id);
    }

    public function test_client_playback_failure_reauthorizes_the_same_final_once_then_records_exhaustion(): void
    {
        $fixture = $this->admittedTurn('handler-playback-recovery@example.com', 'handler-playback-recovery-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_playback_recovery', 'resp_plan_playback_recovery');
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_playback_recovery',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_playback_recovery',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user asked for a direct answer.',
                    'interpretation' => $this->interpretation(
                        outcome: 'respond',
                        responseText: 'This final remains authoritative.',
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        $turn = $fixture['turn']->fresh();
        $original = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->sole();
        $this->sendConversationOutputThenAcknowledgeResponse(
            $fixture['session'],
            'handler-daemon',
            $original,
            'resp_original_final_playback_recovery',
        );

        $failure = [
            'failure_id' => 'playback-recovery-failure-0001',
            'stage' => 'playback',
            'code' => 'playback_authorization_mismatch',
            'message' => 'Raw browser detail must not control recovery.',
            'cause_chain' => [],
            'session_id' => $fixture['conversation']->id,
            'turn_id' => $turn->turn_id,
        ];
        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/client-failures', $failure)
            ->assertOk()
            ->assertJsonPath('data.playback_recovery', 'reauthorized');
        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/client-failures', $failure)
            ->assertOk()
            ->assertJsonPath('data.duplicate', true)
            ->assertJsonPath('data.playback_recovery', 'already_reauthorized');

        $finals = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $finals);
        $this->assertNotSame($finals[0]->speech_item_id, $finals[1]->speech_item_id);
        $this->assertStringEndsWith(':delivery:1', (string) $finals[0]->speech_item_id);
        $this->assertStringEndsWith(':delivery:2', (string) $finals[1]->speech_item_id);

        $retry = $finals[1];
        $retry->forceFill([
            'status' => VoiceRealtimeCommandStatus::Failed,
            'failed_at' => now(),
            'error' => 'delivery_failed: synthetic second playback failure',
        ])->save();
        $handler = app(RealtimeVoiceApplicationEventHandler::class);
        $handler->handleCommandFailure($retry->fresh());
        $handler->handleCommandFailure($retry->fresh());

        $this->assertSame(2, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->count());
        $this->assertSame(1, $turn->events()
            ->where('event_type', 'final_speech_delivery_exhausted')
            ->count());
        $this->assertSame(1, $turn->session->messages()->where('role', 'assistant')->count());
    }

    public function test_failed_final_sideband_command_gets_one_distinct_delivery_identity(): void
    {
        $fixture = $this->admittedTurn('handler-final-command-recovery@example.com', 'handler-final-command-recovery-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_final_command_recovery', 'resp_plan_final_command_recovery');
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_final_command_recovery',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_final_command_recovery',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user requested a direct answer.',
                    'interpretation' => $this->interpretation(
                        outcome: 'respond',
                        responseText: 'The durable final is unchanged.',
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        $turn = $fixture['turn']->fresh();
        $original = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->sole();
        $original->forceFill([
            'status' => VoiceRealtimeCommandStatus::Failed,
            'failed_at' => now(),
            'error' => 'delivery_failed: synthetic socket rejection',
        ])->save();

        $handler = app(RealtimeVoiceApplicationEventHandler::class);
        $handler->handleCommandFailure($original->fresh());
        $handler->handleCommandFailure($original->fresh());

        $finals = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->orderBy('id')
            ->get();
        $this->assertCount(2, $finals);
        $this->assertSame(VoiceRealtimeCommandStatus::Queued, $finals[1]->status);
        $this->assertNotSame($finals[0]->speech_item_id, $finals[1]->speech_item_id);
        $this->assertStringEndsWith(':delivery:2', (string) $finals[1]->speech_item_id);
        $this->assertSame('The durable final is unchanged.', $turn->finalAssistantMessage?->content);
        $this->assertSame(1, $turn->session->messages()->where('role', 'assistant')->count());
    }

    public function test_playback_failure_after_final_audio_started_never_reauthorizes(): void
    {
        $fixture = $this->admittedTurn('handler-final-started@example.com', 'handler-final-started-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_final_started', 'resp_plan_final_started');
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_final_started',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_final_started',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The user requested a direct answer.',
                    'interpretation' => $this->interpretation(
                        outcome: 'respond',
                        responseText: 'This audio has already started.',
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        $turn = $fixture['turn']->fresh();
        $final = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->sole();
        app(VoiceTurnLifecycleService::class)->markFinalAudioStarted(
            $turn,
            'playback_started',
            ['purpose' => 'final', 'speech_item_id' => $final->speech_item_id],
        );

        $this->assertSame(
            'already_started',
            app(RealtimeVoiceApplicationEventHandler::class)->handleClientPlaybackFailure($turn),
        );
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->count());
    }

    public function test_acknowledgement_playback_failure_does_not_hold_the_eventual_final(): void
    {
        $fixture = $this->admittedTurn('handler-ack-playback-failure@example.com', 'handler-ack-playback-failure-0001');
        $this->requestAndAcknowledgePlan($fixture, 'input_ack_playback_failure', 'resp_plan_ack_playback_failure');
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_ack_playback_failure',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_ack_playback_failure',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'Create a task named Call Alex.',
                    'interpretation' => $this->interpretation(
                        outcome: 'execute',
                        acknowledgementText: 'I’ll create that task.',
                        operations: [[
                            'id' => 'create_task',
                            'tool' => 'app.task.create',
                            'arguments_json' => json_encode([
                                ...$this->taskCreateDefaults(),
                                'title' => 'Call Alex',
                            ], JSON_THROW_ON_ERROR),
                            'dependencies' => [],
                        ]],
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        $turn = $fixture['turn']->fresh(['runs']);
        $acknowledgement = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'acknowledgement')
            ->sole();
        $this->sendConversationOutputThenAcknowledgeResponse(
            $fixture['session'],
            'handler-daemon',
            $acknowledgement,
            'resp_ack_playback_failure',
        );

        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/client-failures', [
                'failure_id' => 'ack-playback-failure-0001',
                'stage' => 'playback',
                'code' => 'audio_play_rejected',
                'message' => 'Browser playback failed.',
                'cause_chain' => [],
                'session_id' => $fixture['conversation']->id,
                'turn_id' => $turn->turn_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.playback_recovery', 'acknowledgement_suppressed');
        $this->assertSame(VoiceRealtimeCommandStatus::Failed, $acknowledgement->fresh()->status);
        $this->assertStringStartsWith('reconciled: playback_failed:', (string) $acknowledgement->fresh()->error);

        $operation = $turn->runs->firstWhere('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER);
        $this->assertInstanceOf(AssistantRun::class, $operation);
        app()->call([new ProcessAssistantRun($operation->id), 'handle']);

        $final = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->sole();
        $this->assertTrue($final->available_at->lte(now()->addSecond()));
        $this->assertSame(VoiceTurnState::Completed, $turn->fresh()->state);
    }

    public function test_clarification_playback_retries_once_then_terminalizes_instead_of_hanging(): void
    {
        $fixture = $this->admittedTurn('handler-clarification-playback-failure@example.com', 'handler-clarification-playback-failure-0001');
        $this->requestAndAcknowledgePlan(
            $fixture,
            'input_clarification_playback_failure',
            'resp_plan_clarification_playback_failure',
        );
        $this->providerEvent($fixture['session'], [
            'type' => 'response.output_item.done',
            'response_id' => 'resp_plan_clarification_playback_failure',
            'item' => [
                'type' => 'function_call',
                'call_id' => 'call_plan_clarification_playback_failure',
                'name' => 'bean_turn_plan',
                'arguments' => json_encode([
                    'semantic_input' => 'The task target is ambiguous.',
                    'interpretation' => $this->interpretation(
                        outcome: 'clarify',
                        clarificationQuestion: 'Which task should I change?',
                        responseExpected: true,
                    ),
                ], JSON_THROW_ON_ERROR),
            ],
        ]);
        $turn = $fixture['turn']->fresh();
        $original = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'clarification')
            ->sole();
        $this->sendConversationOutputThenAcknowledgeResponse(
            $fixture['session'],
            'handler-daemon',
            $original,
            'resp_clarification_playback_failure_1',
        );
        $failure = [
            'stage' => 'playback',
            'code' => 'audio_play_rejected',
            'message' => 'Browser playback failed.',
            'cause_chain' => [],
            'session_id' => $fixture['conversation']->id,
            'turn_id' => $turn->turn_id,
        ];

        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/client-failures', [
                ...$failure,
                'failure_id' => 'clarification-playback-failure-0001',
            ])
            ->assertOk()
            ->assertJsonPath('data.playback_recovery', 'reauthorized');
        $retry = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'clarification')
            ->latest('id')
            ->firstOrFail();
        $this->assertNotSame($original->speech_item_id, $retry->speech_item_id);
        $this->assertStringEndsWith(':delivery:2', (string) $retry->speech_item_id);
        $this->sendAndAcknowledgeResponse(
            $fixture['session'],
            'handler-daemon',
            $retry,
            'resp_clarification_playback_failure_2',
        );

        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/client-failures', [
                ...$failure,
                'failure_id' => 'clarification-playback-failure-0002',
            ])
            ->assertOk()
            ->assertJsonPath('data.playback_recovery', 'clarification_failed');

        $terminal = $turn->fresh(['finalAssistantMessage']);
        $this->assertSame(VoiceTurnState::Failed, $terminal->state);
        $this->assertNotNull($terminal->finalAssistantMessage);
        $this->assertSame(1, $terminal->events()
            ->where('event_type', 'clarification_speech_delivery_exhausted')
            ->count());
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('purpose', 'final')
            ->count());
    }

    public function test_ready_recovery_filters_started_finals_before_selecting_only_the_newest_unplayed_turn(): void
    {
        $fixture = $this->admittedTurn('handler-ready-window@example.com', 'handler-ready-window-admission');
        $terminalTurns = collect();
        foreach (range(1, 26) as $index) {
            $turnId = sprintf('handler-ready-window-%04d', $index);
            $message = ConversationMessage::query()->create([
                'user_id' => $fixture['user']->id,
                'conversation_session_id' => $fixture['conversation']->id,
                'client_turn_id' => $turnId.':assistant',
                'role' => 'assistant',
                'origin' => 'spoken_voice',
                'display_mode' => 'voice_only',
                'content' => "Recovered final {$index}.",
                'metadata' => ['voice_turn_id' => $turnId],
            ]);
            $turn = VoiceTurn::query()->create([
                'turn_id' => $turnId,
                'user_id' => $fixture['user']->id,
                'workspace_id' => $fixture['conversation']->workspace_id,
                'conversation_session_id' => $fixture['conversation']->id,
                'realtime_session_id' => $fixture['session']->id,
                'final_assistant_message_id' => $message->id,
                'source' => 'browser_voice_realtime',
                'client_kind' => 'browser_voice',
                'display_mode' => 'voice_only',
                'state' => VoiceTurnState::Completed,
                'version' => 1,
                'idempotency_key' => $turnId,
                'acknowledgement_required' => false,
                'accepted_at' => now(),
                'terminal_at' => now(),
                'side_effect_status' => 'none',
                'retry_count' => 0,
                'metadata' => [
                    'controller_generation' => 1,
                    'provider_connection_generation' => 1,
                    'semantic_sequence' => 1,
                ],
            ]);
            if ($index <= 24) {
                VoiceTurnEvent::query()->create([
                    'voice_turn_id' => $turn->id,
                    'user_id' => $turn->user_id,
                    'workspace_id' => $turn->workspace_id,
                    'conversation_session_id' => $turn->conversation_session_id,
                    'sequence' => 1,
                    'event_type' => 'playback_started',
                    'from_state' => VoiceTurnState::Completed->value,
                    'to_state' => VoiceTurnState::Completed->value,
                    'version' => 1,
                    'source' => 'browser',
                    'payload' => ['purpose' => 'final', 'speech_item_id' => $turnId.':final'],
                ]);
            }
            $terminalTurns->push($turn);
        }

        $sessions = app(RealtimeVoiceSessionService::class);
        $replacement = $sessions->createPending(
            $fixture['user'],
            $fixture['conversation'],
            'gpt-realtime-2.1-mini',
            'marin',
            2,
            [
                'provider_connection_generation' => 2,
                'playback_capability' => 'ready-window-replacement-capability',
            ],
        );
        $replacement = $sessions->bindProviderCall($replacement, 'rtc_ready_window_replacement');
        $replacement = $sessions->markReady(
            $sessions->acquireLease($replacement, 'ready-window-daemon'),
            'ready-window-daemon',
        );

        app(RealtimeVoiceApplicationEventHandler::class)->handleSessionReady($replacement);

        $newest = $terminalTurns->last();
        $olderUnplayed = $terminalTurns->get(24);
        $this->assertSame($replacement->id, $newest->fresh()->realtime_session_id);
        $this->assertSame($fixture['session']->id, $olderUnplayed->fresh()->realtime_session_id);
        $this->assertSame(1, VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $replacement->id)
            ->where('purpose', 'final')
            ->count());
        $this->assertDatabaseHas('voice_realtime_commands', [
            'voice_realtime_session_id' => $replacement->id,
            'voice_turn_id' => $newest->id,
            'purpose' => 'final',
        ]);
    }

    /**
     * @return array{token:string,user:User,conversation:ConversationSession,session:VoiceRealtimeSession,turn:VoiceTurn}
     */
    private function admittedTurn(string $email, string $turnId): array
    {
        $token = $this->apiToken($email);
        $user = User::query()->where('email', $email)->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $sessions = app(RealtimeVoiceSessionService::class);
        $session = $sessions->createPending(
            $user,
            $conversation,
            'gpt-realtime-2.1',
            'alloy',
            1,
            [
                'timezone' => 'America/New_York',
                'playback_capability' => 'capability-test',
            ],
        );
        $session = $sessions->bindProviderCall($session, 'rtc_'.$session->public_id);
        $session = $sessions->markReady(
            $sessions->acquireLease($session, 'handler-daemon'),
            'handler-daemon',
        );
        $turn = app(VoiceTurnLifecycleService::class)->preAdmitRealtime(
            $user,
            $conversation,
            $session,
            [
                'turn_id' => $turnId,
                'controller_generation' => 1,
                'provider_connection_generation' => 1,
                'input_generation' => 1,
                'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
                'client_milestones' => [],
            ],
        );

        return compact('token', 'user', 'conversation', 'session', 'turn');
    }

    /** @param array{session:VoiceRealtimeSession,turn:VoiceTurn} $fixture */
    private function requestAndAcknowledgePlan(array $fixture, string $inputItemId, string $responseId): VoiceRealtimeCommand
    {
        $this->providerEvent($fixture['session'], [
            'type' => 'input_audio_buffer.committed',
            'item_id' => $inputItemId,
        ]);
        $plan = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $fixture['turn']->id)
            ->where('purpose', 'semantic_plan')
            ->latest('id')
            ->firstOrFail();
        $this->sendAndAcknowledgeResponse($fixture['session'], 'handler-daemon', $plan, $responseId);

        return $plan->fresh();
    }

    private function sendConversationOutputThenAcknowledgeResponse(
        VoiceRealtimeSession $session,
        string $leaseOwner,
        VoiceRealtimeCommand $response,
        string $responseId,
    ): void {
        $commands = app(RealtimeVoiceCommandService::class);
        $functionOutput = $commands->claimNext($session, $leaseOwner);
        $this->assertSame(VoiceRealtimeCommandType::ConversationItemCreate, $functionOutput?->command_type);
        $commands->markSent($functionOutput, $leaseOwner);
        $this->sendAndAcknowledgeResponse($session, $leaseOwner, $response, $responseId);
    }

    private function sendAndAcknowledgeResponse(
        VoiceRealtimeSession $session,
        string $leaseOwner,
        VoiceRealtimeCommand $expected,
        string $responseId,
    ): void {
        $commands = app(RealtimeVoiceCommandService::class);
        $claimed = $commands->claimNext($session, $leaseOwner);
        if ($claimed === null && $expected->available_at?->isFuture()) {
            Carbon::setTestNow($expected->available_at->copy()->addMicrosecond());
            $claimed = $commands->claimNext($session, $leaseOwner);
        }
        $this->assertSame($expected->id, $claimed?->id);
        $commands->markSent($claimed, $leaseOwner);
        $this->providerEvent($session, [
            'type' => 'response.created',
            'response' => [
                'id' => $responseId,
                'metadata' => ['bean_command_id' => $expected->command_id],
            ],
        ]);
        $this->assertSame(VoiceRealtimeCommandStatus::Acknowledged, $expected->fresh()->status);
    }

    /** @param array<string,mixed> $payload */
    private function providerEvent(VoiceRealtimeSession $session, array $payload): VoiceRealtimeEvent
    {
        $payload['event_id'] ??= sprintf('evt_handler_%04d', ++$this->eventSequence);
        $record = app(RealtimeVoiceEventService::class)->record($session, $payload);
        app(RealtimeVoiceApplicationEventHandler::class)->handle($record['event']);
        app(RealtimeVoiceEventService::class)->markProcessed($record['event']);

        return $record['event']->refresh();
    }

    private function command(VoiceRealtimeSession $session, string $commandId): VoiceRealtimeCommand
    {
        return VoiceRealtimeCommand::query()
            ->where('voice_realtime_session_id', $session->id)
            ->where('command_id', $commandId)
            ->sole();
    }

    /** @param list<array<string,mixed>> $operations */
    private function interpretation(
        string $outcome,
        ?string $responseText = null,
        ?string $clarificationQuestion = null,
        ?string $acknowledgementText = null,
        bool $closeAfterResponse = false,
        bool $responseExpected = false,
        array $operations = [],
    ): array {
        return [
            'outcome' => $outcome,
            'response_text' => $responseText,
            'clarification_question' => $clarificationQuestion,
            'acknowledgement_text' => $acknowledgementText,
            'close_after_response' => $closeAfterResponse,
            'response_expected' => $responseExpected,
            'operations' => $operations,
        ];
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
}
