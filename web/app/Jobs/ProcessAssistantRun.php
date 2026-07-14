<?php

namespace App\Jobs;

use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\BrowserVoiceHandlerException;
use App\Exceptions\VoiceTurnConflictException;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use App\Services\BeanMemoryService;
use App\Services\FastCalendarReadService;
use App\Services\FastDomainReadService;
use App\Services\FastDomainWriteService;
use App\Services\FastWeatherReadService;
use App\Services\HermesRuntimeService;
use App\Services\PlanLimitService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ProcessAssistantRun implements ShouldQueue
{
    use Queueable;

    // Browser Voice v2 uses these attempts only for lifecycle-scheduler waits.
    // Legacy runs still use queue middleware below.
    public int $tries = 120;

    public int $maxExceptions = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public readonly int $assistantRunId) {}

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
        ?FastCalendarReadService $calendarReads = null,
        ?FastWeatherReadService $weatherReads = null,
        ?FastDomainReadService $domainReads = null,
        ?FastDomainWriteService $domainWrites = null,
        ?PlanLimitService $planLimits = null,
    ): void {
        $run = AssistantRun::with('session', 'userMessage')->find($this->assistantRunId);
        if (! $run || ! $run->session || ! $run->userMessage) {
            return;
        }

        if ($run->voice_turn_id !== null) {
            $this->handleBrowserVoiceV2(
                $run,
                $runtime,
                $voiceTurns ?? app(VoiceTurnLifecycleService::class),
                $calendarReads ?? app(FastCalendarReadService::class),
                $weatherReads ?? app(FastWeatherReadService::class),
                $domainReads ?? app(FastDomainReadService::class),
                $domainWrites ?? app(FastDomainWriteService::class),
                $planLimits ?? app(PlanLimitService::class),
            );

            return;
        }

        $supersedesRunId = (int) data_get($run->metadata, 'supersedes_run_id', 0);
        if ($supersedesRunId > 0) {
            $predecessor = AssistantRun::query()
                ->whereKey($supersedesRunId)
                ->where('user_id', $run->user_id)
                ->where('conversation_session_id', $run->conversation_session_id)
                ->first();
            if ($predecessor instanceof AssistantRun && $runs->runHasCompletedMutatingWork($predecessor)) {
                $this->markSupersessionConflict($run, $predecessor);

                return;
            }
        }

        if ($run->status === 'cancelled') {
            $this->markCancelled($run);

            return;
        }

        $started = DB::transaction(function () use ($run): bool {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun || ! in_array($lockedRun->status, ['queued', 'running'], true)) {
                return false;
            }

            $lockedRun->update([
                'status' => 'running',
                'started_at' => $lockedRun->started_at ?? now(),
            ]);
            $this->updateVoiceTurnState($lockedRun, 'running');
            $session->update([
                'status' => 'running',
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_started', [
                'run_id' => $lockedRun->id,
                'source' => $lockedRun->source,
                'message_id' => $lockedRun->user_message_id,
                'queue_wait_ms' => $this->elapsedMilliseconds($lockedRun->created_at),
            ], 'hermes.runs', 'started');

            return true;
        }, 3);

        if (! $started) {
            return;
        }

        $run->refresh()->load(['session', 'userMessage']);

        $userMessageMetadata = is_array($run->userMessage->metadata) ? $run->userMessage->metadata : [];
        $run->userMessage->setAttribute('metadata', array_merge($userMessageMetadata, [
            'assistant_run_id' => $run->id,
            'defer_memory_candidate' => true,
        ]));

        try {
            $result = $runtime->sendExistingMessage($run->session->refresh(), $run->userMessage);
            $assistantMessage = $result['assistant_message'] ?? null;
            $completionWon = DB::transaction(function () use ($run, $result, $assistantMessage): bool {
                $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
                $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
                if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun || $lockedRun->status !== 'running') {
                    $alreadyReconciled = $assistantMessage instanceof ConversationMessage
                        && $lockedRun instanceof AssistantRun
                        && $lockedRun->status === 'completed'
                        && (int) $lockedRun->assistant_message_id === (int) $assistantMessage->id;
                    if ($assistantMessage instanceof ConversationMessage && ! $alreadyReconciled) {
                        $assistantMessage->delete();
                    }
                    if ($session instanceof ConversationSession && $lockedRun instanceof AssistantRun) {
                        $session->update([
                            'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                            'last_activity_at' => now(),
                        ]);
                    }

                    return false;
                }

                $status = ($result['status'] ?? null) === 'cancelled' ? 'cancelled' : 'completed';
                $lockedRun->update([
                    'status' => $status,
                    'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                    'result' => [
                        'status' => $result['status'] ?? null,
                        'assistant_message_id' => $assistantMessage instanceof ConversationMessage ? $assistantMessage->id : null,
                        'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                    ],
                    'cancelled_at' => $status === 'cancelled' ? now() : null,
                    'completed_at' => now(),
                ]);
                $this->updateVoiceTurnState($lockedRun, $status === 'completed' ? 'completed' : 'cancelled', terminal: true);
                $session->update([
                    'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
                $this->recordEvent($lockedRun, 'runtime.run_completed', [
                    'run_id' => $lockedRun->id,
                    'status' => $status,
                    'assistant_message_id' => $lockedRun->assistant_message_id,
                    'run_duration_ms' => $this->elapsedMilliseconds($lockedRun->started_at),
                ], 'hermes.runs', $status === 'completed' ? 'succeeded' : 'cancelled');

                return $status === 'completed';
            }, 3);

            if ($completionWon && $assistantMessage instanceof ConversationMessage) {
                $persistedUserMessage = ConversationMessage::find($run->user_message_id);
                if ($persistedUserMessage instanceof ConversationMessage) {
                    app(BeanMemoryService::class)->recordTurnCandidate($run->session->refresh(), $persistedUserMessage, $assistantMessage);
                }
            }
        } catch (\Throwable $exception) {
            $this->markFailed($run, $exception->getMessage());
        }
    }

    public function failed(\Throwable $exception): void
    {
        $run = AssistantRun::with('session')->find($this->assistantRunId);
        if (! $run) {
            return;
        }

        if ($run->voice_turn_id !== null) {
            $this->markBrowserVoiceV2Failed($run, $exception, app(VoiceTurnLifecycleService::class));

            return;
        }

        $this->markFailed($run, $exception->getMessage());
    }

    private function handleBrowserVoiceV2(
        AssistantRun $run,
        HermesRuntimeService $runtime,
        VoiceTurnLifecycleService $lifecycle,
        FastCalendarReadService $calendarReads,
        FastWeatherReadService $weatherReads,
        FastDomainReadService $domainReads,
        FastDomainWriteService $domainWrites,
        PlanLimitService $planLimits,
    ): void {
        $turn = VoiceTurn::find($run->voice_turn_id);
        if (! $turn instanceof VoiceTurn) {
            return;
        }
        if ($run->status === 'cancelled' || $turn->state === VoiceTurnState::Canceled) {
            return;
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

            return;
        }

        $provisional = null;
        try {
            $turn = $lifecycle->markProgress($turn, [
                'run_id' => $run->id,
                'label' => $run->label,
            ], 'worker');
            $runtimeUserMessage = $run->userMessage->refresh();
            $runtimeUserMessage->setAttribute('metadata', [
                ...(is_array($runtimeUserMessage->metadata) ? $runtimeUserMessage->metadata : []),
                'assistant_run_id' => $run->id,
                'defer_memory_candidate' => true,
                'defer_response_persistence' => true,
            ]);
            $typedFinalText = $this->executeBrowserVoiceTypedHandler(
                $run,
                $turn,
                $lifecycle,
                $calendarReads,
                $weatherReads,
                $domainReads,
                $domainWrites,
            );
            $runLane = $this->browserVoiceRunLane($run, $turn);
            $runHandler = trim((string) ($run->handler ?: $turn->handler));
            if ($runHandler === 'agent.generate_note') {
                $message = $planLimits->noteCreationUpgradeMessage($turn->user()->firstOrFail());
                if ($message !== null) {
                    throw new BrowserVoiceHandlerException(
                        'subscription_limit_reached',
                        $message,
                        $message,
                    );
                }
            }
            $typedHandler = in_array($runHandler, [
                'app.calendar.read',
                'app.reminder.read',
                'app.task.read',
                'app.note.read',
                'external.weather',
            ], true);
            $typedHandler = $typedHandler || $runLane === VoiceTurnLane::AppWrite;
            if ($typedHandler && $typedFinalText === null) {
                throw new BrowserVoiceHandlerException(
                    'direct_request_incomplete',
                    "The persisted typed handler {$runHandler} could not resolve the required fields or one unambiguous target.",
                    'I’m missing a required detail or a clear target for that change. Please tell me exactly which item and what to change.',
                );
            }
            $result = $typedHandler
                ? [
                    'status' => 'completed',
                    'assistant_message' => null,
                    'events' => collect(),
                ]
                : $runtime->sendExistingMessage($run->session->refresh(), $runtimeUserMessage);
            $provisional = $result['assistant_message'] ?? null;
            $finalText = $typedHandler
                ? trim((string) $typedFinalText)
                : trim((string) ($result['assistant_content']
                    ?? ($provisional instanceof ConversationMessage ? $provisional->content : '')));
            $runtimeStatus = $this->browserVoiceRuntimeStatus($result);
            $runtimeCanceled = in_array($runtimeStatus, ['cancelled', 'canceled'], true);
            $runtimeFailed = in_array($runtimeStatus, ['failed', 'blocked', 'error'], true);
            if (! $runtimeCanceled && ! $runtimeFailed && $runHandler === 'agent.generate_note') {
                $saved = $domainWrites->createGeneratedNote($turn, $run, $finalText);
                if ($saved === null) {
                    throw new BrowserVoiceHandlerException(
                        'generated_note_not_committed',
                        'The generated note did not cross the typed write receipt boundary.',
                        'I drafted that, but I couldn’t save the note. Would you like me to try again?',
                    );
                }
                $finalText = $saved;
            }

            $mayFinalize = DB::transaction(function () use ($run, $provisional): bool {
                $lockedRun = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->first();
                if (! $lockedRun instanceof AssistantRun || $lockedRun->status !== 'running') {
                    if ($provisional instanceof ConversationMessage) {
                        $provisional->delete();
                    }

                    return false;
                }

                $lockedRun->update([
                    'status' => 'finalizing',
                    'last_progress_at' => now(),
                ]);
                if ($provisional instanceof ConversationMessage) {
                    $provisional->delete();
                }

                return true;
            }, 3);
            if (! $mayFinalize) {
                return;
            }

            if ($runtimeCanceled) {
                $terminal = $lifecycle->finishJob(
                    $run,
                    'cancelled',
                    metadata: [
                        'run_id' => $run->id,
                        'reason' => 'runtime_canceled',
                        'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                    ],
                );
            } elseif ($runtimeFailed) {
                $terminal = $lifecycle->finishJob(
                    $run,
                    'failed',
                    failureCategory: 'runtime_'.$runtimeStatus,
                    internalDetail: trim((string) data_get($result, 'blocker.reason'))
                        ?: "The runtime returned {$runtimeStatus}.",
                    userFacingFailure: $this->browserVoiceRuntimeFailureText($finalText),
                    sideEffectStatus: $this->failureSideEffectStatus($turn, $run),
                    metadata: [
                        'run_id' => $run->id,
                        'runtime_status' => $runtimeStatus,
                        'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                    ],
                );
            } elseif ($finalText === '') {
                $terminal = $lifecycle->finishJob(
                    $run,
                    'failed',
                    failureCategory: 'empty_runtime_result',
                    internalDetail: 'The runtime completed without a usable final response.',
                    userFacingFailure: 'I couldn’t finish that request. Would you like me to try again?',
                    sideEffectStatus: $this->failureSideEffectStatus($turn, $run),
                    metadata: [
                        'run_id' => $run->id,
                        'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                    ],
                );
            } else {
                $terminal = $lifecycle->finishJob(
                    $run,
                    'completed',
                    finalText: $finalText,
                    sideEffectStatus: $this->isReceiptBackedWriteRun($run, $turn)
                        ? VoiceTurnSideEffectStatus::Committed
                        : VoiceTurnSideEffectStatus::None,
                    metadata: [
                        'run_id' => $run->id,
                        'event_ids' => collect($result['events'] ?? [])->pluck('id')->filter()->values()->all(),
                    ],
                );
            }

            if ($terminal->state === VoiceTurnState::Completed
                && $terminal->finalAssistantMessage instanceof ConversationMessage
                && $terminal->userMessage instanceof ConversationMessage) {
                app(BeanMemoryService::class)->recordTurnCandidate(
                    $run->session->refresh(),
                    $terminal->userMessage,
                    $terminal->finalAssistantMessage,
                );
            }
        } catch (\Throwable $exception) {
            if ($provisional instanceof ConversationMessage) {
                $freshTurn = $turn->fresh();
                if (! $freshTurn instanceof VoiceTurn
                    || (int) $freshTurn->final_assistant_message_id !== (int) $provisional->id) {
                    $provisional->delete();
                }
            }
            if ($this->isReceiptBackedWriteRun($run, $turn)
                && ($reconciled = $domainWrites->reconcile($turn->fresh(), $run->fresh())) !== null) {
                try {
                    $lifecycle->finishJob(
                        $run,
                        'completed',
                        finalText: $reconciled,
                        sideEffectStatus: VoiceTurnSideEffectStatus::Committed,
                        metadata: ['reconciled_after_worker_exception' => true, 'run_id' => $run->id],
                    );

                    return;
                } catch (VoiceTurnConflictException) {
                    // A competing finalizer already recorded the authoritative outcome.
                }
            }
            $this->markBrowserVoiceV2Failed($run, $exception, $lifecycle);
        }
    }

    private function executeBrowserVoiceTypedHandler(
        AssistantRun $run,
        VoiceTurn $turn,
        VoiceTurnLifecycleService $lifecycle,
        FastCalendarReadService $calendarReads,
        FastWeatherReadService $weatherReads,
        FastDomainReadService $domainReads,
        FastDomainWriteService $domainWrites,
    ): ?string {
        $lane = $this->browserVoiceRunLane($run, $turn);
        $handler = trim((string) ($run->handler ?: $turn->handler));
        $input = trim((string) ($run->input ?: $turn->transcript));
        $typed = in_array($lane, [VoiceTurnLane::AppRead, VoiceTurnLane::AppWrite, VoiceTurnLane::External], true);
        if (! $typed) {
            return null;
        }

        $attempt = 0;
        while (true) {
            try {
                return match ($handler) {
                    'app.calendar.read' => $calendarReads->resolve($run->session->refresh(), $input, [
                        'client_timezone' => data_get($turn->metadata, 'timezone'),
                        'allow_prior_context' => data_get($turn->metadata, 'prior_context_authorized') === true,
                        'prior_transcript' => data_get($turn->metadata, 'prior_transcript'),
                    ]),
                    'external.weather' => $weatherReads->resolve($run->session->refresh(), $input, [
                        'client_timezone' => data_get($turn->metadata, 'timezone'),
                        'location_context' => data_get($turn->metadata, 'location_context'),
                        'allow_prior_context' => data_get($turn->metadata, 'prior_context_authorized') === true,
                        'prior_transcript' => data_get($turn->metadata, 'prior_transcript'),
                    ]),
                    'app.reminder.read', 'app.task.read', 'app.note.read' => $domainReads->resolve(
                        $run->session->refresh(),
                        $handler,
                        $input,
                        ['client_timezone' => data_get($turn->metadata, 'timezone')],
                    ),
                    default => $lane === VoiceTurnLane::AppWrite
                        ? $domainWrites->execute($turn, $run)
                        : null,
                };
            } catch (\Throwable $exception) {
                $retriable = in_array($lane, [VoiceTurnLane::AppRead, VoiceTurnLane::External], true)
                    && (! $exception instanceof BrowserVoiceHandlerException || $exception->retriable)
                    && $attempt === 0
                    && (($run->hard_deadline_at ?? $turn->hard_deadline_at) === null
                        || now()->diffInMilliseconds($run->hard_deadline_at ?? $turn->hard_deadline_at, false) >= ($lane === VoiceTurnLane::External ? 3500 : 250));
                if (! $retriable) {
                    throw $exception;
                }

                $attempt++;
                $turn = $lifecycle->markRetryAttempt($turn->fresh(), [
                    'run_id' => $run->id,
                    'attempt' => $attempt,
                    'exception_class' => $exception::class,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function markBrowserVoiceV2Failed(
        AssistantRun $run,
        \Throwable $exception,
        VoiceTurnLifecycleService $lifecycle,
    ): void {
        $run->refresh();
        $turn = VoiceTurn::find($run->voice_turn_id);
        if (! $turn instanceof VoiceTurn) {
            return;
        }
        if ($turn->state->isTerminal()) {
            $this->reconcileBrowserVoiceV2RunTerminal($run, $turn);

            return;
        }
        if ($this->isReceiptBackedWriteRun($run, $turn)
            && ($reconciled = app(FastDomainWriteService::class)->reconcile($turn, $run)) !== null) {
            try {
                $lifecycle->finishJob(
                    $run,
                    'completed',
                    finalText: $reconciled,
                    sideEffectStatus: VoiceTurnSideEffectStatus::Committed,
                    metadata: ['reconciled_during_worker_failure' => true, 'run_id' => $run->id],
                );

                return;
            } catch (VoiceTurnConflictException) {
                return;
            }
        }

        try {
            $category = $exception instanceof BrowserVoiceHandlerException
                ? $exception->category
                : 'worker_failure';
            $userFacing = $exception instanceof BrowserVoiceHandlerException
                ? $exception->userFacingText
                : 'I couldn’t finish that request. Would you like me to try again?';
            $lifecycle->finishJob(
                $run,
                'failed',
                failureCategory: $category,
                internalDetail: $exception->getMessage(),
                userFacingFailure: $userFacing,
                sideEffectStatus: $this->failureSideEffectStatus($turn, $run),
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

    private function failureSideEffectStatus(VoiceTurn $turn, ?AssistantRun $run = null): VoiceTurnSideEffectStatus
    {
        if ($run instanceof AssistantRun && ! $this->isReceiptBackedWriteRun($run, $turn)) {
            return VoiceTurnSideEffectStatus::NotCommitted;
        }
        if (! $run instanceof AssistantRun && $turn->lane !== VoiceTurnLane::AppWrite) {
            return VoiceTurnSideEffectStatus::NotCommitted;
        }

        return app(FastDomainWriteService::class)->reconcile($turn->fresh(), $run?->fresh()) !== null
            ? VoiceTurnSideEffectStatus::Committed
            : VoiceTurnSideEffectStatus::NotCommitted;
    }

    private function browserVoiceRunLane(AssistantRun $run, VoiceTurn $turn): VoiceTurnLane
    {
        return VoiceTurnLane::tryFrom((string) $run->lane) ?? $turn->lane;
    }

    /** @param array<string, mixed> $result */
    private function browserVoiceRuntimeStatus(array $result): string
    {
        $status = mb_strtolower(trim((string) ($result['status'] ?? 'completed')));
        if (in_array($status, ['cancelled', 'canceled', 'failed', 'blocked', 'error'], true)) {
            return $status;
        }

        $events = collect($result['events'] ?? []);
        if ($events->contains(fn (mixed $event): bool => in_array(
            (string) data_get($event, 'event_type'),
            ['runtime.fast_response_failed_terminal', 'runtime.tool_model_failed'],
            true,
        ))) {
            return 'failed';
        }
        if ($events->contains(fn (mixed $event): bool => in_array(
            (string) data_get($event, 'event_type'),
            ['runtime.usage_blocked', 'runtime.tool_model_blocked'],
            true,
        ))) {
            return 'blocked';
        }
        $assistant = $result['assistant_message'] ?? null;
        if ($assistant instanceof ConversationMessage
            && (filled(data_get($assistant->metadata, 'failure_reason')) || is_array(data_get($assistant->metadata, 'failure')))) {
            return 'failed';
        }

        return $status;
    }

    private function browserVoiceRuntimeFailureText(string $candidate): string
    {
        $candidate = trim($candidate);
        if ($candidate !== '' && preg_match('/\b(?:usage limit|current plan|upgrade|available on (?:premium|pro))\b/iu', $candidate) === 1) {
            return $candidate;
        }
        if ($candidate !== '' && preg_match('/would you like me to try again\??$/iu', $candidate) === 1) {
            return $candidate;
        }
        if ($candidate !== '' && preg_match('/\b(?:couldn\'t|cannot|can\'t|unavailable|timed out|didn\'t answer)\b/iu', $candidate) === 1) {
            return rtrim($candidate, " \t\n\r\0\x0B.?!").'. Would you like me to try again?';
        }

        return 'I couldn’t finish that request because the service didn’t respond as expected. Would you like me to try again?';
    }

    private function isReceiptBackedWriteRun(AssistantRun $run, VoiceTurn $turn): bool
    {
        return $this->browserVoiceRunLane($run, $turn) === VoiceTurnLane::AppWrite
            || ($run->handler ?: $turn->handler) === 'agent.generate_note';
    }

    private function reconcileBrowserVoiceV2RunTerminal(AssistantRun $run, VoiceTurn $turn): void
    {
        DB::transaction(function () use ($run, $turn): void {
            $locked = AssistantRun::query()->whereKey($run->id)->lockForUpdate()->first();
            if (! $locked instanceof AssistantRun || $locked->status === 'cancelled') {
                return;
            }

            $status = match ($turn->state) {
                VoiceTurnState::Completed => 'completed',
                VoiceTurnState::Canceled => 'cancelled',
                VoiceTurnState::Failed => 'failed',
                default => $locked->status,
            };
            $locked->update([
                'status' => $status,
                'assistant_message_id' => $turn->final_assistant_message_id,
                'error' => $status === 'failed' ? $turn->internal_failure_detail : $locked->error,
                'result' => [
                    'status' => $status,
                    'voice_turn_id' => $turn->id,
                    'assistant_message_id' => $turn->final_assistant_message_id,
                    'reconciled_from_terminal_turn' => true,
                ],
                'cancelled_at' => $status === 'cancelled' ? ($locked->cancelled_at ?? now()) : $locked->cancelled_at,
                'completed_at' => $locked->completed_at ?? now(),
                'last_progress_at' => now(),
            ]);
        }, 3);
    }

    private function markCancelled(AssistantRun $run): void
    {
        DB::transaction(function () use ($run): void {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $lockedRun instanceof AssistantRun || in_array($lockedRun->status, ['completed', 'failed'], true)) {
                return;
            }

            $transitioned = $lockedRun->status !== 'cancelled';
            $lockedRun->update([
                'status' => 'cancelled',
                'cancelled_at' => $lockedRun->cancelled_at ?? now(),
                'completed_at' => $lockedRun->completed_at ?? now(),
            ]);
            $this->updateVoiceTurnState($lockedRun, 'cancelled', terminal: true);
            $this->deleteOrphanAssistants($lockedRun);
            if ($session instanceof ConversationSession) {
                $session->update([
                    'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                    'last_activity_at' => now(),
                ]);
            }
            if ($transitioned) {
                $this->recordEvent($lockedRun, 'runtime.run_cancelled', ['run_id' => $lockedRun->id], 'hermes.runs', 'cancelled');
            }
        }, 3);
    }

    private function markSupersessionConflict(AssistantRun $run, AssistantRun $predecessor): void
    {
        DB::transaction(function () use ($run, $predecessor): void {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun || ! in_array($lockedRun->status, ['queued', 'running'], true)) {
                return;
            }

            $assistantMessage = ConversationMessage::create([
                'user_id' => $lockedRun->user_id,
                'conversation_session_id' => $lockedRun->conversation_session_id,
                'role' => 'assistant',
                'content' => 'That first change completed before I could safely replace it, so I did not make a second change. Tell me whether you want me to update or undo the first one.',
                'metadata' => [
                    'runtime' => 'supersession_conflict',
                    'supersedes_run_id' => $predecessor->id,
                ],
            ]);
            $metadata = is_array($lockedRun->metadata) ? $lockedRun->metadata : [];
            $lockedRun->update([
                'status' => 'failed',
                'assistant_message_id' => $assistantMessage->id,
                'error' => 'The superseded run committed mutating work before cancellation.',
                'result' => [
                    'status' => 'supersession_conflict',
                    'assistant_message_id' => $assistantMessage->id,
                    'supersedes_run_id' => $predecessor->id,
                ],
                'metadata' => array_merge($metadata, [
                    'supersession_conflict' => true,
                    'supersession_conflict_detected_at' => now()->toIso8601String(),
                ]),
                'completed_at' => now(),
            ]);
            $this->updateVoiceTurnState($lockedRun, 'failed', terminal: true, reason: 'supersession_conflict');
            $session->update([
                'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_supersession_conflict', [
                'run_id' => $lockedRun->id,
                'supersedes_run_id' => $predecessor->id,
                'assistant_message_id' => $assistantMessage->id,
            ], 'hermes.runs', 'failed');
        }, 3);
    }

    private function markFailed(AssistantRun $run, string $reason): void
    {
        $failed = DB::transaction(function () use ($run, $reason): bool {
            $session = ConversationSession::query()->lockForUpdate()->find($run->conversation_session_id);
            $lockedRun = AssistantRun::query()->lockForUpdate()->find($run->id);
            if (! $session instanceof ConversationSession || ! $lockedRun instanceof AssistantRun) {
                return false;
            }

            if (! in_array($lockedRun->status, ['queued', 'running'], true)) {
                // A cancel/reconcile CAS may have won after the runtime committed its
                // assistant but before the exception reached this worker. Under the same
                // session/run locks, remove only output that no terminal run owns.
                // Failed + unlinked remains an intentionally recoverable ambiguity until
                // polling reconciles it or Stop transitions it to cancelled.
                if ($lockedRun->status !== 'failed' || $lockedRun->assistant_message_id !== null) {
                    $this->deleteOrphanAssistants($lockedRun, preserveLinkedTerminalAssistant: true);
                }

                return false;
            }

            $lockedRun->update([
                'status' => 'failed',
                'error' => $reason,
                'completed_at' => now(),
            ]);
            $this->updateVoiceTurnState($lockedRun, 'failed', terminal: true, reason: $reason);
            $session->update([
                'status' => $this->sessionStatusForActiveRuns($session->id, $lockedRun->id),
                'last_activity_at' => now(),
            ]);
            $this->recordEvent($lockedRun, 'runtime.run_failed', [
                'run_id' => $lockedRun->id,
                'reason' => $reason,
            ], 'hermes.runs', 'failed');

            return true;
        }, 3);

        if ($failed) {
            Log::error('Assistant run failed.', [
                'run_id' => $run->id,
                'session_id' => $run->conversation_session_id,
                'exception' => $reason,
            ]);
        }
    }

    private function sessionStatusForActiveRuns(int $sessionId, int $excludingRunId): string
    {
        $statuses = AssistantRun::query()
            ->where('conversation_session_id', $sessionId)
            ->where('id', '!=', $excludingRunId)
            ->whereIn('status', ['queued', 'running'])
            ->pluck('status');

        if ($statuses->contains('running')) {
            return 'running';
        }

        return $statuses->contains('queued') ? 'queued' : 'active';
    }

    private function updateVoiceTurnState(
        AssistantRun $run,
        string $state,
        bool $terminal = false,
        ?string $reason = null,
    ): void {
        $message = ConversationMessage::query()->lockForUpdate()->find($run->user_message_id);
        if (! $message instanceof ConversationMessage || ! (bool) data_get($message->metadata, 'voice_request', false)) {
            return;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $lifecycle = is_array(data_get($metadata, 'voice_turn_outcome')) ? data_get($metadata, 'voice_turn_outcome') : [];
        $now = now()->toIso8601String();
        $metadata['voice_turn_state'] = $state;
        $metadata['voice_turn_outcome'] = array_merge($lifecycle, [
            'status' => $state,
            'updated_at' => $now,
            ...($terminal ? ['terminal_at' => $lifecycle['terminal_at'] ?? $now] : []),
            ...($reason ? ['reason' => $reason] : []),
        ]);
        $message->update(['metadata' => $metadata]);
    }

    private function deleteOrphanAssistants(AssistantRun $run, bool $preserveLinkedTerminalAssistant = false): void
    {
        $assistants = ConversationMessage::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where('role', 'assistant')
            ->where('metadata->assistant_run_id', $run->id);
        if ($preserveLinkedTerminalAssistant
            && in_array($run->status, ['completed', 'failed'], true)
            && $run->assistant_message_id !== null) {
            $assistants->where('id', '!=', $run->assistant_message_id);
        }
        $assistantIds = $assistants->pluck('id');
        if ($assistantIds->isEmpty()) {
            return;
        }

        MemoryEvent::query()->whereIn('assistant_message_id', $assistantIds)->delete();
        ConversationMessage::query()->whereIn('id', $assistantIds)->delete();
    }

    private function recordEvent(AssistantRun $run, string $eventType, array $payload = [], ?string $toolName = null, string $status = 'recorded'): ActivityEvent
    {
        return ActivityEvent::create([
            'user_id' => $run->user_id,
            'workspace_id' => $run->workspace_id,
            'conversation_session_id' => $run->conversation_session_id,
            'event_type' => $eventType,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }

    private function elapsedMilliseconds(mixed $startedAt): ?int
    {
        if (! $startedAt) {
            return null;
        }

        return max(0, (int) $startedAt->diffInMilliseconds(now(), true));
    }
}
