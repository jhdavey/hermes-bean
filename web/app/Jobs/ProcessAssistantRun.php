<?php

namespace App\Jobs;

use App\Data\AssistantRunExecutionClaim;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Data\HermesSemanticOperation;
use App\Data\HermesSemanticOperationResult;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\HermesSemanticOperationException;
use App\Exceptions\HermesSemanticProviderException;
use App\Exceptions\HermesSemanticUsageLimitException;
use App\Exceptions\VoiceTurnConflictException;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\BeanMemoryService;
use App\Services\HermesRuntimeService;
use App\Services\HermesSemanticContextService;
use App\Services\HermesSemanticInterpreter;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Log;

class ProcessAssistantRun implements ShouldQueue
{
    use Queueable;

    // Browser Voice v2 uses these attempts only for lifecycle-scheduler waits.
    // Nonvoice assistant runs still use queue middleware below.
    public int $tries = 120;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(
        public readonly int $assistantRunId,
        public readonly ?int $executionGeneration = null,
    ) {}

    public function middleware(): array
    {
        $run = AssistantRun::query()
            ->select(['id', 'workspace_id', 'conversation_session_id', 'voice_turn_id', 'resource_lock_key'])
            ->find($this->assistantRunId);

        // The transactional VoiceTurnLifecycleService scheduler is the sole
        // Browser Voice v2 execution-order authority. A second queue lock here
        // would race its capacity, dependency, priority, and resource decisions
        // while consuming attempts without recording a durable scheduler wait.
        if ($run?->voice_turn_id !== null) {
            return [];
        }

        $lockKey = $run?->conversation_session_id
            ? "assistant-session-{$run->conversation_session_id}"
            : "assistant-run-{$this->assistantRunId}";

        return [
            (new WithoutOverlapping($lockKey))
                ->releaseAfter(2)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(
        HermesRuntimeService $runtime,
        AssistantRunService $runs,
        ?VoiceTurnLifecycleService $voiceTurns = null,
        ?HermesSemanticInterpreter $semanticInterpreter = null,
        ?HermesSemanticContextService $semanticContext = null,
        ?HermesSemanticOperationExecutor $semanticOperations = null,
    ): void {
        $run = AssistantRun::with('session', 'userMessage')->find($this->assistantRunId);
        if (! $run || ! $run->session || ! $run->userMessage) {
            return;
        }

        if ($run->voice_turn_id !== null) {
            $this->handleBrowserVoiceV2(
                $run,
                $voiceTurns ?? app(VoiceTurnLifecycleService::class),
                $semanticInterpreter ?? app(HermesSemanticInterpreter::class),
                $semanticContext ?? app(HermesSemanticContextService::class),
                $semanticOperations ?? app(HermesSemanticOperationExecutor::class),
            );

            return;
        }

        if ($run->status === 'cancelled') {
            return;
        }

        if ($run->status === 'running') {
            $runs->prepareRunForBackgroundResponse($run);

            return;
        }

        if ($run->status !== 'queued') {
            return;
        }

        if ($this->executionGeneration === null) {
            return;
        }

        $run = $runs->prepareRunForBackgroundResponse($run)
            ->load(['session', 'userMessage']);
        if ($run->status !== 'queued' || ! $run->session || ! $run->userMessage) {
            return;
        }

        $claim = $runs->claimRunExecution($run, $this->executionGeneration);
        if (! $claim instanceof AssistantRunExecutionClaim) {
            return;
        }

        $run = AssistantRun::with(['session', 'userMessage'])->find($claim->runId);
        if (! $run instanceof AssistantRun || ! $run->session || ! $run->userMessage) {
            return;
        }

        try {
            $result = $runtime->sendExistingMessage($run->session->refresh(), $run->userMessage, $claim);
            $runs->finishRuntimeResult($claim, $result);
        } catch (\Throwable $exception) {
            $runs->finishRuntimeResult($claim, [
                'status' => 'failed',
                'error' => $exception->getMessage(),
                'events' => [],
            ]);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $run = AssistantRun::with('session')->find($this->assistantRunId);
        if (! $run) {
            return;
        }

        if ($run->voice_turn_id !== null) {
            $this->markBrowserVoiceV2Failed(
                $run,
                $exception,
                app(VoiceTurnLifecycleService::class),
                app(HermesSemanticOperationExecutor::class),
            );

            return;
        }

        if ($this->executionGeneration === null || (int) $run->user_message_id <= 0) {
            return;
        }

        $claim = new AssistantRunExecutionClaim(
            runId: $run->id,
            sessionId: $run->conversation_session_id,
            userMessageId: (int) $run->user_message_id,
            generation: $this->executionGeneration,
        );
        app(AssistantRunService::class)->finishRuntimeResult($claim, [
            'status' => 'failed',
            'error' => $exception->getMessage(),
            'events' => [],
        ]);
    }

    private function handleBrowserVoiceV2(
        AssistantRun $run,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticInterpreter $semanticInterpreter,
        HermesSemanticContextService $semanticContext,
        HermesSemanticOperationExecutor $semanticOperations,
    ): void {
        $turn = VoiceTurn::find($run->voice_turn_id);
        if (! $turn instanceof VoiceTurn) {
            return;
        }
        if ($run->status === 'cancelled' || $turn->state === VoiceTurnState::Canceled) {
            return;
        }

        match ($run->handler) {
            HermesSemanticOperationExecutor::INTERPRETATION_HANDLER => $this->handleBrowserVoiceSemanticRun(
                $run,
                $turn,
                $lifecycle,
                $semanticInterpreter,
                $semanticContext,
                $semanticOperations,
            ),
            HermesSemanticOperationExecutor::OPERATION_HANDLER => $this->handleBrowserVoiceSemanticOperationRun(
                $run,
                $turn,
                $lifecycle,
                $semanticOperations,
            ),
            HermesSemanticOperationExecutor::COMPOSITION_HANDLER => $this->handleBrowserVoiceSemanticCompositionRun(
                $run,
                $turn,
                $lifecycle,
                $semanticInterpreter,
                $semanticContext,
                $semanticOperations,
            ),
            default => $this->markBrowserVoiceV2Failed(
                $run,
                new \RuntimeException("Unsupported Browser Voice handler {$run->handler}."),
                $lifecycle,
                $semanticOperations,
            ),
        };
    }

    private function handleBrowserVoiceSemanticRun(
        AssistantRun $run,
        VoiceTurn $turn,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticInterpreter $interpreter,
        HermesSemanticContextService $contextService,
        HermesSemanticOperationExecutor $operations,
    ): void {
        if (! $this->claimBrowserVoiceRun($run, $turn, $lifecycle)) {
            return;
        }

        $interpretation = null;
        $semanticDeadlineAt = $this->semanticPhaseDeadlineTimestamp($run);
        try {
            $turn = $lifecycle->markProgress($turn, [
                'run_id' => $run->id,
                'phase' => 'semantic_interpretation',
                'model' => (string) config('services.hermes_runtime.semantic_interpretation_model', 'gpt-5.6-luna'),
            ], 'semantic_interpreter');
            $lifecycle->recordSemanticEvent($turn, 'semantic_interpretation_started', [
                'run_id' => $run->id,
                'semantic_sequence' => (int) data_get($run->metadata, 'semantic_sequence', 1),
                'model' => (string) config('services.hermes_runtime.semantic_interpretation_model', 'gpt-5.6-luna'),
                'phase_deadline_ms' => 2000,
            ]);

            $timezone = $this->semanticTimezone($turn);
            $baseContext = [
                ...$contextService->forVoiceTurn($turn),
                'logical_request' => [
                    'utterances' => data_get($turn->metadata, 'semantic_utterances', []),
                    'clarification_history' => data_get($turn->metadata, 'semantic_clarification_history', []),
                    'capture_origin' => data_get($turn->metadata, 'conversation_context.mode', 'new_conversation'),
                    'authorized_prior_turn_id' => data_get($turn->metadata, 'prior_turn_id'),
                ],
            ];
            $feedback = null;

            while (true) {
                $requestContext = $feedback === null
                    ? $baseContext
                    : [...$baseContext, 'prior_interpretation_feedback' => $feedback];
                $request = new HermesSemanticInterpretationRequest(
                    user: $turn->user()->firstOrFail(),
                    workspaceId: $turn->workspace_id,
                    stableTurnId: $turn->turn_id,
                    transcript: $turn->transcript,
                    currentTime: now('UTC')->toIso8601String(),
                    timezone: $timezone,
                    context: $requestContext,
                    locale: 'en-US',
                    conversationSessionId: $turn->conversation_session_id,
                    conversationMessageId: $turn->user_message_id,
                );

                try {
                    $this->assertSemanticInterpretationBudget($semanticDeadlineAt);
                    $interpretation = $interpreter->interpret($request);
                    $this->assertSemanticInterpretationCompletedInTime($semanticDeadlineAt);
                } catch (HermesSemanticProviderException $exception) {
                    if (! $exception->retriable || ! $this->beginSemanticRetry(
                        $turn,
                        $run,
                        $lifecycle,
                        $exception->category,
                        $semanticDeadlineAt,
                    )) {
                        throw $exception;
                    }

                    $turn = $turn->fresh();
                    $feedback = [
                        'kind' => 'provider_retry',
                        'instruction' => 'Interpret the same logical request again. Do not change its meaning.',
                    ];

                    continue;
                }

                $lifecycle->recordSemanticEvent($turn, 'semantic_interpretation_provider_response', [
                    'run_id' => $run->id,
                    'outcome' => $interpretation->outcome,
                    'operation_tools' => collect($interpretation->operations)->pluck('tool')->values()->all(),
                ]);

                if ($interpretation->outcome === HermesSemanticInterpretation::OUTCOME_CLARIFY) {
                    $awaiting = $lifecycle->withClaimedJobExecution(
                        $turn,
                        $run,
                        function (VoiceTurn $_lockedTurn, AssistantRun $lockedRun) use ($lifecycle, $interpretation): VoiceTurn {
                            $lifecycle->publishSemanticResponseDirectives(
                                $lockedRun,
                                $interpretation->closeAfterResponse,
                                $interpretation->responseExpected,
                            );

                            return $lifecycle->requestSemanticClarification(
                                $lockedRun,
                                (string) $interpretation->clarificationQuestion,
                                [
                                    'outcome' => $interpretation->outcome,
                                    'model' => (string) config('services.hermes_runtime.semantic_interpretation_model', 'gpt-5.6-luna'),
                                ],
                            );
                        },
                    );
                    $lifecycle->recordSemanticEvent($awaiting, 'semantic_interpretation_completed', [
                        'run_id' => $run->id,
                        'outcome' => $interpretation->outcome,
                        'operation_tools' => [],
                    ]);

                    return;
                }

                if ($interpretation->outcome === HermesSemanticInterpretation::OUTCOME_RESPOND) {
                    $terminal = $lifecycle->withClaimedJobExecution(
                        $turn,
                        $run,
                        function (VoiceTurn $_lockedTurn, AssistantRun $lockedRun) use ($lifecycle, $interpretation): VoiceTurn {
                            $lifecycle->publishSemanticResponseDirectives(
                                $lockedRun,
                                $interpretation->closeAfterResponse,
                                $interpretation->responseExpected,
                            );
                            $lifecycle->markJobFinalizing($lockedRun, [
                                'semantic_outcome' => $interpretation->outcome,
                                'semantic_operation_tools' => [],
                            ]);

                            return $lifecycle->finishJob(
                                $lockedRun,
                                'completed',
                                finalText: (string) $interpretation->responseText,
                                metadata: [
                                    'run_id' => $lockedRun->id,
                                    'semantic_outcome' => $interpretation->outcome,
                                ],
                            );
                        },
                    );
                    $lifecycle->recordSemanticEvent($terminal, 'semantic_interpretation_completed', [
                        'run_id' => $run->id,
                        'outcome' => $interpretation->outcome,
                        'operation_tools' => [],
                    ]);
                    $this->recordCompletedTurnActivity($run, $terminal);

                    return;
                }

                try {
                    $staged = $operations->stage($turn->fresh(), $run->fresh(), $interpretation, $baseContext);
                } catch (HermesSemanticOperationException $exception) {
                    $canReinterpret = $exception->category === 'invalid_semantic_operation'
                        && $this->beginSemanticRetry(
                            $turn,
                            $run,
                            $lifecycle,
                            $exception->category,
                            $semanticDeadlineAt,
                        );
                    if (! $canReinterpret) {
                        throw $exception;
                    }

                    $turn = $turn->fresh();
                    $feedback = [
                        'kind' => 'deterministic_validation_failure',
                        'detail' => mb_substr($exception->getMessage(), 0, 500),
                        'instruction' => 'Return a corrected schema-valid plan, or ask one specific clarification question if the target or required detail is unresolved.',
                    ];

                    continue;
                }

                $lifecycle->recordSemanticEvent($turn->fresh(), 'semantic_interpretation_completed', [
                    'run_id' => $run->id,
                    'outcome' => $interpretation->outcome,
                    'operation_tools' => collect($interpretation->operations)->pluck('tool')->values()->all(),
                ]);

                foreach ([...$staged['operation_runs'], $staged['composition_run']] as $stagedRun) {
                    try {
                        $this->dispatchBrowserVoiceRun($stagedRun, $lifecycle);
                    } catch (\Throwable $dispatchException) {
                        // The sealed job remains queued without a dispatch
                        // stamp. Reload recovery can safely enqueue it by its
                        // durable run identity without repeating interpretation.
                        $lifecycle->recordSemanticEvent($turn->fresh(), 'semantic_dispatch_deferred', [
                            'run_id' => $stagedRun->id,
                            'category' => 'queue_dispatch_failure',
                        ]);
                        Log::warning('Browser Voice semantic job dispatch deferred to recovery.', [
                            'run_id' => $stagedRun->id,
                            'voice_turn_id' => $turn->id,
                            'exception' => $dispatchException->getMessage(),
                        ]);
                    }
                }

                return;
            }
        } catch (\Throwable $exception) {
            $lifecycle->enforceDeadlines($turn->id);
            $freshTurn = $turn->fresh();
            if (! $freshTurn instanceof VoiceTurn || $freshTurn->state->isTerminal()) {
                return;
            }

            $category = match (true) {
                $exception instanceof HermesSemanticProviderException => 'semantic_'.$exception->category,
                $exception instanceof HermesSemanticOperationException => $exception->category,
                default => 'semantic_worker_failure',
            };
            $userFacing = $exception instanceof HermesSemanticUsageLimitException
                ? $exception->userFacingText
                : AssistantRunService::SYSTEM_FAILURE_FINAL;

            try {
                $lifecycle->recordSemanticEvent($freshTurn, 'semantic_interpretation_failed', [
                    'run_id' => $run->id,
                    'category' => $category,
                    'side_effect_status' => VoiceTurnSideEffectStatus::None->value,
                ]);
                $lifecycle->finishJob(
                    $run,
                    'failed',
                    failureCategory: $category,
                    internalDetail: $exception->getMessage(),
                    userFacingFailure: $userFacing,
                    sideEffectStatus: VoiceTurnSideEffectStatus::None,
                    metadata: [
                        'run_id' => $run->id,
                        'exception_class' => $exception::class,
                        'semantic_outcome' => $interpretation?->outcome,
                    ],
                );
            } catch (VoiceTurnConflictException) {
                // Cancellation or another finalizer already won the durable turn.
            }

            Log::error('Browser Voice semantic run failed.', [
                'run_id' => $run->id,
                'voice_turn_id' => $turn->id,
                'category' => $category,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function handleBrowserVoiceSemanticOperationRun(
        AssistantRun $run,
        VoiceTurn $turn,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticOperationExecutor $operations,
    ): void {
        if (! $this->claimBrowserVoiceRun($run, $turn, $lifecycle)) {
            return;
        }

        try {
            $turn = $lifecycle->markProgress($turn, [
                'run_id' => $run->id,
                'phase' => 'typed_operation',
                'operation_id' => data_get($run->metadata, 'semantic_operation_id'),
                'tool' => data_get($run->metadata, 'semantic_tool'),
            ], 'semantic_executor');
            $receipt = $operations->executeRun($turn, $run->fresh());
            $this->finishSemanticOperationReceipt($run, $receipt, $lifecycle, $operations);
        } catch (\Throwable $exception) {
            try {
                $receipt = $operations->recordFailureReceipt($run->fresh(), $exception);
                $this->finishSemanticOperationReceipt($run, $receipt, $lifecycle, $operations);
            } catch (VoiceTurnConflictException) {
                // A post-execution deadline guard rolls back the typed side
                // effect and receipt together. Close that elapsed deadline
                // here even when the scheduled enforcer has not run yet.
                $lifecycle->enforceDeadlines($turn->id);
            }

            Log::error('Browser Voice semantic operation failed.', [
                'run_id' => $run->id,
                'voice_turn_id' => $turn->id,
                'operation_id' => data_get($run->metadata, 'semantic_operation_id'),
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /** @param array<string,mixed> $receipt */
    private function finishSemanticOperationReceipt(
        AssistantRun $run,
        array $receipt,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticOperationExecutor $operations,
    ): void {
        $receiptStatus = (string) ($receipt['status'] ?? 'failed');
        $status = match ($receiptStatus) {
            'completed', 'skipped' => 'completed',
            'canceled' => 'cancelled',
            'failed' => data_get($receipt, 'data.failure_scope') === 'operation'
                ? 'completed'
                : 'failed',
            default => 'failed',
        };
        $category = trim((string) data_get($receipt, 'data.category')) ?: null;
        $lifecycle->finishJob(
            $run,
            $status,
            finalText: $this->semanticOperationReceiptText($run, $receipt),
            failureCategory: $status === 'failed' ? $category : null,
            internalDetail: $status === 'failed'
                ? trim((string) data_get($receipt, 'data.internal_detail'))
                : null,
            userFacingFailure: null,
            sideEffectStatus: $operations->receiptSideEffectStatus($receipt),
            metadata: [
                'run_id' => $run->id,
                'semantic_operation_receipt' => $receipt,
            ],
        );
    }

    private function handleBrowserVoiceSemanticCompositionRun(
        AssistantRun $run,
        VoiceTurn $turn,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticInterpreter $interpreter,
        HermesSemanticContextService $contextService,
        HermesSemanticOperationExecutor $operations,
    ): void {
        if (! $this->claimBrowserVoiceRun($run, $turn, $lifecycle)) {
            return;
        }

        $receipts = [];
        try {
            $turn = $lifecycle->markProgress($turn, [
                'run_id' => $run->id,
                'phase' => 'semantic_composition',
            ], 'semantic_interpreter');
            $interpretation = $this->semanticInterpretationFromRun($run);
            $receipts = $this->semanticCompositionReceipts($run, $interpretation, $operations);
            $timezone = $this->semanticTimezone($turn);
            $context = [
                ...$contextService->forVoiceTurn($turn),
                'logical_request' => [
                    'utterances' => data_get($turn->metadata, 'semantic_utterances', []),
                    'clarification_history' => data_get($turn->metadata, 'semantic_clarification_history', []),
                    'capture_origin' => data_get($turn->metadata, 'conversation_context.mode', 'new_conversation'),
                    'authorized_prior_turn_id' => data_get($turn->metadata, 'prior_turn_id'),
                ],
            ];
            $compositionRequest = new HermesSemanticCompositionRequest(
                user: $turn->user()->firstOrFail(),
                workspaceId: $turn->workspace_id,
                stableTurnId: $turn->turn_id,
                transcript: $turn->transcript,
                currentTime: now('UTC')->toIso8601String(),
                timezone: $timezone,
                interpretation: $interpretation,
                results: $receipts,
                context: $context,
                locale: 'en-US',
                conversationSessionId: $turn->conversation_session_id,
                conversationMessageId: $turn->user_message_id,
            );
            $compositionDeadlineAt = $this->semanticPhaseDeadlineTimestamp($run);
            while (true) {
                try {
                    $composition = $interpreter->compose($compositionRequest);
                    $this->assertSemanticCompositionCompletedInTime($compositionDeadlineAt);

                    break;
                } catch (HermesSemanticProviderException $exception) {
                    if (! $exception->retriable || ! $this->beginSemanticRetry(
                        $turn,
                        $run,
                        $lifecycle,
                        $exception->category,
                        $compositionDeadlineAt,
                        'semantic_composition',
                        (float) config('services.hermes_runtime.semantic_composition_timeout', 2),
                    )) {
                        throw $exception;
                    }

                    $turn = $turn->fresh() ?? $turn;
                }
            }
            $terminal = $lifecycle->withClaimedJobExecution(
                $turn,
                $run,
                function (VoiceTurn $_lockedTurn, AssistantRun $lockedRun) use ($lifecycle, $composition, $receipts): VoiceTurn {
                    $lifecycle->publishSemanticResponseDirectives(
                        $lockedRun,
                        $composition->closeAfterResponse,
                        $composition->responseExpected,
                    );
                    $lifecycle->markJobFinalizing($lockedRun, [
                        'semantic_outcome' => HermesSemanticInterpretation::OUTCOME_EXECUTE,
                        'semantic_operation_count' => count($receipts),
                    ]);

                    return $lifecycle->finishJob(
                        $lockedRun,
                        'completed',
                        finalText: $composition->responseText,
                        metadata: [
                            'run_id' => $lockedRun->id,
                            'operation_count' => count($receipts),
                            'operation_statuses' => collect($receipts)->mapWithKeys(
                                fn (HermesSemanticOperationResult $result): array => [$result->operationId => $result->status],
                            )->all(),
                        ],
                    );
                },
            );
            $this->recordCompletedTurnActivity($run, $terminal);
        } catch (\Throwable $exception) {
            $lifecycle->enforceDeadlines($turn->id);
            $freshTurn = $turn->fresh();
            if (! $freshTurn instanceof VoiceTurn || $freshTurn->state->isTerminal()) {
                return;
            }

            $committed = $this->compositionHasCommittedReceipt($run, $operations);
            $category = $exception instanceof HermesSemanticProviderException
                ? 'semantic_composition_'.$exception->category
                : 'semantic_composition_worker_failure';
            $userFacing = $exception instanceof HermesSemanticUsageLimitException
                ? $exception->userFacingText
                : AssistantRunService::SYSTEM_FAILURE_FINAL;
            try {
                $lifecycle->finishJob(
                    $run,
                    'failed',
                    failureCategory: $category,
                    internalDetail: $exception->getMessage(),
                    userFacingFailure: $userFacing,
                    sideEffectStatus: $committed
                        ? VoiceTurnSideEffectStatus::Committed
                        : VoiceTurnSideEffectStatus::NotCommitted,
                    metadata: [
                        'run_id' => $run->id,
                        'exception_class' => $exception::class,
                        'committed_operation_receipt_present' => $committed,
                    ],
                );
            } catch (VoiceTurnConflictException) {
                // Cancellation or another finalizer already won.
            }

            Log::error('Browser Voice semantic composition failed.', [
                'run_id' => $run->id,
                'voice_turn_id' => $turn->id,
                'category' => $category,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    private function beginSemanticRetry(
        VoiceTurn $turn,
        AssistantRun $run,
        VoiceTurnLifecycleService $lifecycle,
        string $reason,
        float $phaseDeadlineAt,
        string $phase = 'semantic_interpretation',
        ?float $providerTimeoutSeconds = null,
    ): bool {
        $fresh = $turn->fresh();
        $remainingMilliseconds = (int) floor(($phaseDeadlineAt - microtime(true)) * 1000);
        $providerTimeoutMilliseconds = (int) ceil(max(
            0.1,
            $providerTimeoutSeconds
                ?? (float) config('services.hermes_runtime.semantic_interpretation_timeout', 0.9),
        ) * 1000);
        $minimumRetryBudgetMilliseconds = $providerTimeoutMilliseconds + 100;
        if (! $fresh instanceof VoiceTurn
            || $fresh->retry_count >= 1
            || $remainingMilliseconds < $minimumRetryBudgetMilliseconds) {
            if ($fresh instanceof VoiceTurn) {
                $lifecycle->recordSemanticEvent($fresh, 'semantic_retry_denied', [
                    'run_id' => $run->id,
                    'phase' => $phase,
                    'reason' => $reason,
                    'remaining_phase_ms' => max(0, $remainingMilliseconds),
                    'minimum_retry_budget_ms' => $minimumRetryBudgetMilliseconds,
                ]);
            }

            return false;
        }

        $retried = $lifecycle->markRetryAttempt($fresh, [
            'run_id' => $run->id,
            'phase' => $phase,
            'reason' => $reason,
            'remaining_phase_ms' => $remainingMilliseconds,
        ], 'semantic_interpreter');

        return $retried->retry_count > $fresh->retry_count;
    }

    private function semanticPhaseDeadlineTimestamp(AssistantRun $run): float
    {
        $deadline = $run->hard_deadline_at;
        if ($deadline === null) {
            return microtime(true) + 2.0;
        }

        $remainingMilliseconds = now()->diffInMilliseconds($deadline, false);

        return microtime(true) + (max(0, $remainingMilliseconds) / 1000);
    }

    private function assertSemanticInterpretationBudget(float $deadlineAt): void
    {
        $providerTimeoutMilliseconds = (int) ceil(max(
            0.1,
            (float) config('services.hermes_runtime.semantic_interpretation_timeout', 0.9),
        ) * 1000);
        $remainingMilliseconds = (int) floor(($deadlineAt - microtime(true)) * 1000);
        if ($remainingMilliseconds >= $providerTimeoutMilliseconds + 100) {
            return;
        }

        throw new HermesSemanticProviderException(
            category: 'deadline',
            internalDetail: 'The semantic interpretation deadline did not have enough budget for another provider call.',
        );
    }

    private function assertSemanticInterpretationCompletedInTime(float $deadlineAt): void
    {
        if (microtime(true) <= $deadlineAt) {
            return;
        }

        throw new HermesSemanticProviderException(
            category: 'deadline',
            internalDetail: 'The semantic provider response arrived after the interpretation deadline.',
        );
    }

    private function assertSemanticCompositionCompletedInTime(float $deadlineAt): void
    {
        if (microtime(true) <= $deadlineAt) {
            return;
        }

        throw new HermesSemanticProviderException(
            category: 'deadline',
            internalDetail: 'The semantic composition provider response arrived after the final-response deadline.',
        );
    }

    private function semanticTimezone(VoiceTurn $turn): ?string
    {
        $timezone = trim((string) (
            data_get($turn->metadata, 'timezone')
            ?? data_get($turn->metadata, 'client_context.timezone')
            ?? data_get($turn->metadata, 'client_context.timezone_offset')
            ?? ''
        ));
        if ($timezone === '') {
            return null;
        }
        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return null;
        }
    }

    private function markBrowserVoiceV2Failed(
        AssistantRun $run,
        \Throwable $exception,
        VoiceTurnLifecycleService $lifecycle,
        HermesSemanticOperationExecutor $operations,
    ): void {
        $run->refresh();
        $turn = VoiceTurn::find($run->voice_turn_id);
        if (! $turn instanceof VoiceTurn) {
            return;
        }
        if ($turn->state->isTerminal()) {
            return;
        }

        if ($run->handler === HermesSemanticOperationExecutor::OPERATION_HANDLER) {
            try {
                $receipt = $operations->recordFailureReceipt($run, $exception);
                $this->finishSemanticOperationReceipt($run, $receipt, $lifecycle, $operations);

                return;
            } catch (VoiceTurnConflictException) {
                return;
            }
        }

        try {
            $compositionCommitted = $run->handler === HermesSemanticOperationExecutor::COMPOSITION_HANDLER
                && $this->compositionHasCommittedReceipt($run, $operations);
            $category = $exception instanceof HermesSemanticOperationException
                ? $exception->category
                : 'worker_failure';
            $lifecycle->finishJob(
                $run,
                'failed',
                failureCategory: $category,
                internalDetail: $exception->getMessage(),
                userFacingFailure: AssistantRunService::SYSTEM_FAILURE_FINAL,
                sideEffectStatus: $compositionCommitted
                    ? VoiceTurnSideEffectStatus::Committed
                    : VoiceTurnSideEffectStatus::NotCommitted,
                metadata: ['run_id' => $run->id, 'exception_class' => $exception::class],
            );
        } catch (VoiceTurnConflictException) {
            // Cancellation or another finalizer won the terminal compare-and-set.
        }

        Log::error('Browser Voice v2 run failed.', [
            'run_id' => $run->id,
            'voice_turn_id' => $turn->id,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function claimBrowserVoiceRun(
        AssistantRun $run,
        VoiceTurn $turn,
        VoiceTurnLifecycleService $lifecycle,
    ): bool {
        // Deadline enforcement is external and scheduled, but every worker
        // also closes the race locally before executing a provider call or
        // typed side effect.
        $lifecycle->enforceDeadlines($turn->id);
        $run = $run->fresh() ?? $run;
        $turn = $turn->fresh() ?? $turn;
        if ($turn->state->isTerminal()
            || in_array($run->status, ['completed', 'failed', 'cancelled'], true)) {
            return false;
        }

        $started = $lifecycle->claimJobExecution($run);
        if (! $started) {
            $currentRun = $run->fresh();
            $currentTurn = $turn->fresh();
            if ($currentRun instanceof AssistantRun
                && $currentTurn instanceof VoiceTurn
                && $currentRun->status === 'queued'
                && ! $currentTurn->state->isTerminal()) {
                $this->job?->release(1);
            }
        }

        return $started;
    }

    private function dispatchBrowserVoiceRun(AssistantRun $run, VoiceTurnLifecycleService $lifecycle): void
    {
        if (! $lifecycle->jobRequiresDispatch($run)) {
            return;
        }

        self::dispatch($run->id);
        $lifecycle->markJobDispatched($run);
    }

    private function semanticInterpretationFromRun(AssistantRun $run): HermesSemanticInterpretation
    {
        $payload = json_decode((string) $run->input, true);
        if (! is_array($payload) || ! is_array($payload['operations'] ?? null)) {
            throw new VoiceTurnConflictException('The semantic composition run has no sealed interpretation.');
        }
        $typedOperations = array_map(function (mixed $operation): HermesSemanticOperation {
            if (! is_array($operation)) {
                throw new VoiceTurnConflictException('The sealed semantic plan contains an invalid operation.');
            }

            return new HermesSemanticOperation(
                id: (string) ($operation['id'] ?? ''),
                tool: (string) ($operation['tool'] ?? ''),
                arguments: is_array($operation['arguments'] ?? null) ? $operation['arguments'] : [],
                dependencies: is_array($operation['dependencies'] ?? null) ? $operation['dependencies'] : [],
            );
        }, $payload['operations']);

        return new HermesSemanticInterpretation(
            outcome: (string) ($payload['outcome'] ?? ''),
            responseText: is_string($payload['response_text'] ?? null) ? $payload['response_text'] : null,
            clarificationQuestion: is_string($payload['clarification_question'] ?? null) ? $payload['clarification_question'] : null,
            acknowledgementText: is_string($payload['acknowledgement_text'] ?? null) ? $payload['acknowledgement_text'] : null,
            closeAfterResponse: (bool) ($payload['close_after_response'] ?? false),
            responseExpected: (bool) ($payload['response_expected'] ?? false),
            operations: $typedOperations,
        );
    }

    /** @return list<HermesSemanticOperationResult> */
    private function semanticCompositionReceipts(
        AssistantRun $run,
        HermesSemanticInterpretation $interpretation,
        HermesSemanticOperationExecutor $operations,
    ): array {
        $runMap = data_get($run->metadata, 'operation_run_map');
        $runMap = is_array($runMap) ? $runMap : [];

        return array_map(function (HermesSemanticOperation $operation) use ($run, $runMap, $operations): HermesSemanticOperationResult {
            $operationRunId = (int) ($runMap[$operation->id] ?? 0);
            $operationRun = $operationRunId > 0
                ? AssistantRun::query()
                    ->whereKey($operationRunId)
                    ->where('voice_turn_id', $run->voice_turn_id)
                    ->first()
                : null;
            $receipt = $operationRun instanceof AssistantRun
                ? $operations->receiptForRun($operationRun)
                : null;
            if (! $operationRun instanceof AssistantRun
                || ! in_array($operationRun->status, ['completed', 'failed', 'cancelled'], true)
                || ! is_array($receipt)
                || ($receipt['operation_id'] ?? null) !== $operation->id
                || ($receipt['tool'] ?? null) !== $operation->tool) {
                throw new VoiceTurnConflictException("Semantic operation {$operation->id} has no matching terminal receipt.");
            }
            $data = is_array($receipt['data'] ?? null) ? $receipt['data'] : [];
            $data['side_effect_committed'] = ($receipt['side_effect_committed'] ?? false) === true;

            return new HermesSemanticOperationResult(
                operationId: $operation->id,
                tool: $operation->tool,
                status: (string) ($receipt['status'] ?? 'failed'),
                data: $data,
            );
        }, $interpretation->operations);
    }

    private function compositionHasCommittedReceipt(
        AssistantRun $compositionRun,
        HermesSemanticOperationExecutor $operations,
    ): bool {
        $runIds = data_get($compositionRun->metadata, 'operation_run_ids');
        if (! is_array($runIds)) {
            return false;
        }

        return AssistantRun::query()
            ->where('voice_turn_id', $compositionRun->voice_turn_id)
            ->whereIn('id', array_map('intval', $runIds))
            ->get()
            ->contains(function (AssistantRun $operationRun) use ($operations): bool {
                $receipt = $operations->receiptForRun($operationRun);

                return is_array($receipt) && ($receipt['side_effect_committed'] ?? false) === true;
            });
    }

    /** @param array<string,mixed> $receipt */
    private function semanticOperationReceiptText(AssistantRun $run, array $receipt): string
    {
        $label = trim((string) $run->label) ?: 'Operation';

        return match ((string) ($receipt['status'] ?? 'failed')) {
            'completed' => "{$label} completed.",
            'skipped' => "{$label} was skipped because a dependency did not complete.",
            'canceled' => "{$label} was canceled.",
            default => "{$label} failed.",
        };
    }

    private function recordCompletedTurnActivity(AssistantRun $run, VoiceTurn $turn): void
    {
        if ($turn->state === VoiceTurnState::Completed
            && $turn->finalAssistantMessage instanceof ConversationMessage
            && $turn->userMessage instanceof ConversationMessage) {
            app(BeanMemoryService::class)->recordTurnActivity(
                $run->session->refresh(),
                $turn->userMessage,
                $turn->finalAssistantMessage,
            );
        }
    }
}
