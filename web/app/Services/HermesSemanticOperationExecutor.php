<?php

namespace App\Services;

use App\Data\AssistantRunExecutionClaim;
use App\Data\HermesSemanticExecutionContext;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticOperation;
use App\Data\HermesSemanticOperationResult;
use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\AssistantRunClaimLostException;
use App\Exceptions\HermesSemanticOperationException;
use App\Exceptions\VoiceTurnConflictException;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\MemoryItem;
use App\Models\Note;
use App\Models\NoteFolder;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceTurn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Executes only schema-validated semantic operations. It never inspects the
 * user's prose to infer a tool, target, date, or correction.
 */
class HermesSemanticOperationExecutor
{
    public const INTERPRETATION_HANDLER = 'agent.semantic';

    public const OPERATION_HANDLER = 'semantic.operation';

    public const COMPOSITION_HANDLER = 'semantic.compose';

    private const MUTATING_TOOLS = [
        'app.task.create', 'app.task.update', 'app.task.delete',
        'app.reminder.create', 'app.reminder.update', 'app.reminder.delete',
        'app.calendar.create', 'app.calendar.update', 'app.calendar.delete',
        'app.note.create', 'app.note.update', 'app.note.delete',
        'app.note_folder.create', 'app.note_folder.update', 'app.note_folder.delete',
        'app.event_category.create', 'app.event_category.update', 'app.event_category.delete',
        'app.blocker.create', 'app.blocker.update', 'app.blocker.resolve', 'app.blocker.delete',
        'app.agent_profile.update', 'app.conversation.update',
        'app.memory.create', 'app.memory.update', 'app.memory.delete',
        'voice.work.cancel',
    ];

    private const TASK_TYPES = ['todo', 'chore', 'maintenance'];

    private const TASK_STATUSES = ['open', 'completed'];

    private const REMINDER_STATUSES = ['scheduled', 'completed'];

    private const CALENDAR_STATUSES = ['scheduled', 'cancelled'];

    private const RECURRENCES = ['none', 'daily', 'weekly', 'monthly', 'yearly'];

    private const MEMORY_TYPES = [
        'fact', 'preference', 'identity', 'relationship', 'project',
        'routine', 'constraint', 'decision', 'instruction', 'temporary_context',
    ];

    public function __construct(
        private readonly StructuredHermesActionService $actions,
        private readonly LiveLookupService $lookups,
        private readonly PlanLimitService $planLimits,
        private readonly VoiceTurnLifecycleService $lifecycle,
        private readonly AssistantRunService $assistantRuns,
    ) {}

    /**
     * Validate one complete Hermes plan before any durable operation job is
     * created, then hand lifecycle-only scheduling facts to the turn owner.
     *
     * @return array{operation_runs:list<AssistantRun>,composition_run:AssistantRun,created_runs:list<AssistantRun>}
     */
    public function stage(
        VoiceTurn $turn,
        AssistantRun $interpretationRun,
        HermesSemanticInterpretation $interpretation,
        array $trustedContext,
    ): array {
        return DB::transaction(function () use ($turn, $interpretationRun, $interpretation, $trustedContext): array {
            // Serialize entitlement reservations for this user. The lifecycle
            // staging transaction is nested, so validation, durable jobs, and
            // acknowledgement eligibility commit as one unit.
            User::query()->whereKey($turn->user_id)->lockForUpdate()->firstOrFail();
            $normalizedPlan = $this->normalizePlan($turn, $interpretation->operations);
            $normalized = $normalizedPlan['operations'];
            $this->preflightPlan(
                $turn,
                $normalized,
                $normalizedPlan['sealed_reference_operation_ids'],
                $trustedContext,
            );
            $specs = array_map(
                fn (array $operation): array => $this->schedulerSpec($turn, $operation),
                $normalized,
            );

            return $this->lifecycle->stageSemanticExecution(
                $turn,
                $interpretationRun,
                $specs,
                $interpretation->toArray(),
            );
        }, 3);
    }

    /** @return array<string,mixed> */
    public function executeRun(VoiceTurn $turn, AssistantRun $run): array
    {
        if ($run->handler !== self::OPERATION_HANDLER) {
            throw new VoiceTurnConflictException('Only semantic operation runs may use the typed executor.');
        }
        if (($existing = $this->receiptForRun($run)) !== null) {
            return $existing;
        }

        $operation = $this->operationFromRun($run);
        if ($operation['tool'] === 'external.lookup') {
            return $this->executeExternalLookupRun($turn, $run, $operation);
        }

        return $this->lifecycle->withClaimedJobExecution(
            $turn,
            $run,
            function (VoiceTurn $lockedTurn, AssistantRun $lockedRun) use ($operation): array {
                if (($existing = $this->receiptForRun($lockedRun)) !== null) {
                    return $existing;
                }

                $dependencyReceipts = $this->dependencyReceipts($lockedRun, $operation);
                $failedDependency = collect($dependencyReceipts)->first(
                    fn (array $receipt): bool => ($receipt['status'] ?? null) !== 'completed',
                );
                if (is_array($failedDependency)) {
                    return $this->persistReceipt($lockedRun, $this->makeReceipt(
                        $operation,
                        'skipped',
                        [
                            'reason' => 'dependency_not_completed',
                            'dependency_operation_id' => $failedDependency['operation_id'] ?? null,
                            'dependency_status' => $failedDependency['status'] ?? null,
                        ],
                        false,
                    ));
                }

                $data = $this->executeOperation($lockedTurn, $lockedRun, $operation);
                $committed = in_array($operation['tool'], self::MUTATING_TOOLS, true)
                    && ($data['changed'] ?? true) !== false;

                return $this->persistReceipt(
                    $lockedRun,
                    $this->makeReceipt($operation, 'completed', $data, $committed),
                );
            },
        );
    }

    /**
     * Validate and seal the canonical operation plan for a generic chat run.
     * No operation executes until this whole plan passes schema, authorization,
     * entitlement, and exact-target validation.
     *
     * @return list<array{id:string,tool:string,arguments:array<string,mixed>,dependencies:list<string>}>
     */
    public function prepareGenericPlan(
        HermesSemanticExecutionContext $context,
        HermesSemanticInterpretation $interpretation,
        array $trustedContext,
    ): array {
        return DB::transaction(function () use ($context, $interpretation, $trustedContext): array {
            User::query()->whereKey($context->user_id)->lockForUpdate()->firstOrFail();
            $normalized = $this->normalizePlan($context, $interpretation->operations);
            $this->preflightPlan(
                $context,
                $normalized['operations'],
                $normalized['sealed_reference_operation_ids'],
                $trustedContext,
            );

            return $normalized['operations'];
        }, 3);
    }

    /**
     * @param  list<array{id:string,tool:string,arguments:array<string,mixed>,dependencies:list<string>}>  $operations
     * @return list<HermesSemanticOperationResult>
     */
    public function executeGenericPlan(
        HermesSemanticExecutionContext $context,
        AssistantRun $run,
        AssistantRunExecutionClaim $claim,
        array $operations,
    ): array {
        if ((int) $run->id !== $claim->runId
            || (int) $run->conversation_session_id !== $claim->sessionId
            || (int) $run->user_message_id !== $claim->userMessageId) {
            throw new AssistantRunClaimLostException;
        }

        $receipts = [];
        foreach ($operations as $operation) {
            $failedDependencyId = null;
            $failedDependency = null;
            foreach ($operation['dependencies'] as $dependencyId) {
                $candidate = $receipts[$dependencyId] ?? null;
                if (! is_array($candidate) || ($candidate['status'] ?? null) !== 'completed') {
                    $failedDependencyId = $dependencyId;
                    $failedDependency = $candidate;
                    break;
                }
            }
            if ($failedDependencyId !== null) {
                $receipt = $this->genericOperationReceipt(
                    $claim,
                    $operation,
                    'skipped',
                    [
                        'reason' => 'dependency_not_completed',
                        'dependency_operation_id' => $failedDependencyId,
                        'dependency_status' => is_array($failedDependency)
                            ? ($failedDependency['status'] ?? null)
                            : 'missing',
                    ],
                    false,
                );
            } else {
                try {
                    $receipt = $operation['tool'] === 'external.lookup'
                        ? $this->executeGenericExternalOperation($context, $claim, $operation)
                        : $this->assistantRuns->withExecutionClaim(
                            $claim,
                            function (ConversationSession $_session, AssistantRun $lockedRun) use ($context, $claim, $operation): array {
                                if (($existing = $this->genericReceiptForOperation($lockedRun, $operation)) !== null) {
                                    return $existing;
                                }

                                $data = $this->executeOperation($context, $lockedRun, $operation);
                                $committed = in_array($operation['tool'], self::MUTATING_TOOLS, true)
                                    && ($data['changed'] ?? true) !== false;

                                return $this->persistGenericReceipt(
                                    $lockedRun,
                                    $claim,
                                    $operation,
                                    $this->makeReceipt($operation, 'completed', $data, $committed),
                                );
                            },
                        );
                } catch (AssistantRunClaimLostException $exception) {
                    throw $exception;
                } catch (Throwable $exception) {
                    $category = $exception instanceof HermesSemanticOperationException
                        ? $exception->category
                        : 'semantic_operation_worker_failure';
                    $receipt = $this->genericOperationReceipt(
                        $claim,
                        $operation,
                        'failed',
                        [
                            'category' => $category,
                            'internal_detail' => $exception->getMessage(),
                            'failure_scope' => $exception instanceof HermesSemanticOperationException
                                ? 'operation'
                                : 'system',
                        ],
                        false,
                    );
                }
            }

            $receipts[$operation['id']] = $receipt;
        }

        return array_map(
            static function (array $operation) use ($receipts): HermesSemanticOperationResult {
                $receipt = $receipts[$operation['id']];
                $data = is_array($receipt['data'] ?? null) ? $receipt['data'] : [];
                $data['side_effect_committed'] = ($receipt['side_effect_committed'] ?? false) === true;

                return new HermesSemanticOperationResult(
                    operationId: $operation['id'],
                    tool: $operation['tool'],
                    status: (string) $receipt['status'],
                    data: $data,
                );
            },
            $operations,
        );
    }

    /**
     * Authorize a claimed external job while holding the lifecycle locks, then
     * release those locks before provider I/O. Cancellation and deadline
     * enforcement can therefore win while the provider is in flight. A late
     * result is accepted only through a second lifecycle-owned transaction.
     *
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @return array<string,mixed>
     */
    private function executeExternalLookupRun(HermesSemanticExecutionContext|VoiceTurn $turn, AssistantRun $run, array $operation): array
    {
        $authorization = $this->lifecycle->withClaimedJobExecution(
            $turn,
            $run,
            function (VoiceTurn $lockedTurn, AssistantRun $lockedRun) use ($operation): array {
                if (($existing = $this->receiptForRun($lockedRun)) !== null) {
                    return ['receipt' => $existing, 'hard_deadline_at' => null];
                }

                $dependencyReceipts = $this->dependencyReceipts($lockedRun, $operation);
                $failedDependency = collect($dependencyReceipts)->first(
                    fn (array $receipt): bool => ($receipt['status'] ?? null) !== 'completed',
                );
                if (is_array($failedDependency)) {
                    return [
                        'receipt' => $this->persistReceipt($lockedRun, $this->makeReceipt(
                            $operation,
                            'skipped',
                            [
                                'reason' => 'dependency_not_completed',
                                'dependency_operation_id' => $failedDependency['operation_id'] ?? null,
                                'dependency_status' => $failedDependency['status'] ?? null,
                            ],
                            false,
                        )),
                        'hard_deadline_at' => null,
                    ];
                }

                $deadlines = collect([$lockedTurn->hard_deadline_at, $lockedRun->hard_deadline_at])
                    ->filter(fn (mixed $deadline): bool => $deadline instanceof Carbon)
                    ->sortBy(fn (Carbon $deadline): string => $deadline->format('Y-m-d H:i:s.u'))
                    ->values();
                $hardDeadlineAt = $deadlines->first();
                if (! $hardDeadlineAt instanceof Carbon) {
                    throw new VoiceTurnConflictException('An external voice job requires a lifecycle-owned hard deadline.');
                }

                return ['receipt' => null, 'hard_deadline_at' => $hardDeadlineAt->copy()];
            },
        );

        if (is_array($authorization['receipt'] ?? null)) {
            return $authorization['receipt'];
        }
        $hardDeadlineAt = $authorization['hard_deadline_at'] ?? null;
        if (! $hardDeadlineAt instanceof Carbon) {
            throw new VoiceTurnConflictException('An external voice job has no active execution deadline.');
        }

        $session = ConversationSession::query()->findOrFail($turn->conversation_session_id);
        $data = $this->externalLookup(
            $session,
            $operation['arguments'],
            $hardDeadlineAt,
            $this->timezone($turn),
        );
        $receipt = $this->makeReceipt($operation, 'completed', $data, false);

        return $this->lifecycle->withClaimedJobExecution(
            $turn,
            $run,
            function (VoiceTurn $_lockedTurn, AssistantRun $lockedRun) use ($receipt): array {
                if (($existing = $this->receiptForRun($lockedRun)) !== null) {
                    return $existing;
                }

                return $this->persistReceipt($lockedRun, $receipt);
            },
        );
    }

    /** @return array<string,mixed>|null */
    public function receiptForRun(AssistantRun $run): ?array
    {
        $fresh = $run->exists ? $run->fresh() : $run;
        $receipt = data_get($fresh?->metadata, 'semantic_operation_receipt');
        if (! is_array($receipt)) {
            $receipt = data_get($fresh?->result, 'metadata.semantic_operation_receipt');
        }

        return is_array($receipt) ? $receipt : null;
    }

    /** @return array<string,mixed> */
    public function recordFailureReceipt(AssistantRun $run, Throwable $exception): array
    {
        $turn = VoiceTurn::query()->findOrFail($run->voice_turn_id);

        return $this->lifecycle->withClaimedJobExecution(
            $turn,
            $run,
            function (VoiceTurn $_turn, AssistantRun $locked) use ($exception): array {
                if (($existing = $this->receiptForRun($locked)) !== null) {
                    return $existing;
                }
                $operation = $this->operationFromRun($locked);
                $category = $exception instanceof HermesSemanticOperationException
                    ? $exception->category
                    : 'semantic_operation_worker_failure';

                return $this->persistReceipt($locked, $this->makeReceipt(
                    $operation,
                    'failed',
                    [
                        'category' => $category,
                        'internal_detail' => $exception->getMessage(),
                        'failure_scope' => $exception instanceof HermesSemanticOperationException
                            ? 'operation'
                            : 'system',
                    ],
                    false,
                ));
            },
        );
    }

    /** @param array<string,mixed> $receipt */
    public function receiptSideEffectStatus(array $receipt): VoiceTurnSideEffectStatus
    {
        return ($receipt['side_effect_committed'] ?? false) === true
            ? VoiceTurnSideEffectStatus::Committed
            : (($receipt['status'] ?? null) === 'failed'
                ? VoiceTurnSideEffectStatus::NotCommitted
                : VoiceTurnSideEffectStatus::None);
    }

    /**
     * @param  array<int, object|array<string, mixed>>  $operations
     * @return array{
     *     operations:array<int,array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}>,
     *     sealed_reference_operation_ids:list<string>
     * }
     */
    private function normalizePlan(HermesSemanticExecutionContext|VoiceTurn $turn, array $operations): array
    {
        if ($operations === [] || count($operations) > 12) {
            throw $this->invalid('An executable semantic plan must contain between one and twelve operations.');
        }

        $normalized = [];
        $knownOperations = [];
        $sealedReferenceOperationIds = [];
        foreach ($operations as $index => $operation) {
            $id = trim((string) $this->value($operation, 'id'));
            $tool = trim((string) $this->value($operation, 'tool'));
            $arguments = $this->value($operation, 'arguments');
            $dependencies = $this->value($operation, 'dependencies', []);

            if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $id) !== 1 || isset($knownOperations[$id])) {
                throw $this->invalid("Semantic operation at index {$index} has an invalid or duplicate id.");
            }
            if (! in_array($tool, HermesSemanticOperation::TOOLS, true)) {
                throw $this->invalid("Semantic operation {$id} selected unsupported tool {$tool}.");
            }
            // JSON objects decode to associative arrays, while an empty JSON
            // object necessarily becomes [] in PHP. Permit only that empty
            // case; non-empty positional arrays are never valid arguments.
            if (! is_array($arguments) || ($arguments !== [] && array_is_list($arguments))) {
                throw $this->invalid("Semantic operation {$id} arguments must be an object.");
            }
            if (! is_array($dependencies) || ! array_is_list($dependencies)) {
                throw $this->invalid("Semantic operation {$id} dependencies must be a list.");
            }
            $dependencies = array_values(array_unique(array_map(
                static fn (mixed $dependency): string => trim((string) $dependency),
                $dependencies,
            )));
            foreach ($dependencies as $dependency) {
                if (! isset($knownOperations[$dependency])) {
                    throw $this->invalid("Semantic operation {$id} depends on a missing or later operation {$dependency}.");
                }
            }

            $this->validateArguments($tool, $arguments);
            $resultReferenceId = trim((string) data_get($arguments, 'result_ref.operation_id', ''));
            if ($resultReferenceId !== '' && ! in_array($resultReferenceId, $dependencies, true)) {
                throw $this->invalid("Semantic operation {$id} must declare its result_ref as a dependency.");
            }
            $hasResultReference = is_array($arguments['result_ref'] ?? null);
            $arguments = $this->sealResultReferenceTarget(
                $turn,
                $id,
                $tool,
                $arguments,
                $knownOperations,
            );
            if ($hasResultReference && isset($arguments['id'])) {
                $sealedReferenceOperationIds[] = $id;
            }
            $this->validateArguments($tool, $arguments);

            $normalizedOperation = compact('id', 'tool', 'arguments', 'dependencies');
            $knownOperations[$id] = $normalizedOperation;
            $normalized[] = $normalizedOperation;
        }

        return [
            'operations' => $normalized,
            'sealed_reference_operation_ids' => $sealedReferenceOperationIds,
        ];
    }

    /**
     * Deterministically authorize the model's already-interpreted plan. This
     * layer may reject a target or entitlement, but it never chooses another
     * resource, resolves prose, or changes the requested operation.
     *
     * @param  array<int,array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}>  $operations
     * @param  list<string>  $sealedReferenceOperationIds
     */
    private function preflightPlan(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        array $operations,
        array $sealedReferenceOperationIds,
        array $trustedContext,
    ): void {
        $user = User::query()->findOrFail($turn->user_id);
        $resourceIds = collect((array) ($trustedContext['resources'] ?? []))
            ->map(fn (mixed $resources): array => collect(is_array($resources) ? $resources : [])
                ->pluck('id')
                ->filter(fn (mixed $id): bool => filter_var($id, FILTER_VALIDATE_INT) !== false && (int) $id > 0)
                ->map(fn (mixed $id): int => (int) $id)
                ->values()
                ->all())
            ->all();
        $trustedVoiceTurnIds = collect((array) ($trustedContext['recent_voice_turns'] ?? []))
            ->pluck('stable_turn_id')
            ->filter(fn (mixed $id): bool => is_string($id) && trim($id) !== '')
            ->map(fn (mixed $id): string => trim((string) $id))
            ->values()
            ->all();
        $sealed = array_fill_keys($sealedReferenceOperationIds, true);

        $noteOperations = collect($operations)->filter(
            fn (array $operation): bool => str_starts_with($operation['tool'], 'app.note.'),
        );
        if ($noteOperations->isNotEmpty() && ! $this->planLimits->canUseNotes($user)) {
            throw $this->invalid('The current subscription does not authorize note operations.');
        }
        $noteCreates = $noteOperations->where('tool', 'app.note.create')->count();
        $reservedNoteCreatesQuery = AssistantRun::query()
            ->where('user_id', $turn->user_id)
            ->where('handler', self::OPERATION_HANDLER)
            ->whereIn('status', ['queued', 'running', 'finalizing']);
        $voiceTurnId = $turn instanceof VoiceTurn
            ? (int) $turn->id
            : (int) ($turn->voiceTurn?->id ?? 0);
        if ($voiceTurnId > 0) {
            $reservedNoteCreatesQuery->where('voice_turn_id', '!=', $voiceTurnId);
        }
        $reservedNoteCreates = $reservedNoteCreatesQuery
            ->get(['metadata'])
            ->filter(fn (AssistantRun $run): bool => data_get($run->metadata, 'semantic_tool') === 'app.note.create')
            ->count();
        if ($noteCreates > 0
            && ($limitMessage = $this->planLimits->noteCreationUpgradeMessage(
                $user,
                $noteCreates + $reservedNoteCreates,
            )) !== null) {
            throw $this->invalid($limitMessage);
        }

        foreach ($operations as $operation) {
            $tool = $operation['tool'];
            $arguments = $operation['arguments'];
            if (in_array($tool, ['system.clock.read', 'app.day.read'], true)
                && $this->timezone($turn) === null) {
                throw $this->missingTimezone($tool);
            }
            if ($tool === 'voice.playback.stop'
                && ! $turn instanceof VoiceTurn
                && ! $turn->voiceTurn instanceof VoiceTurn) {
                throw $this->invalid('voice.playback.stop is available only for an active voice turn.');
            }
            if (array_key_exists('recurrence', $arguments) && $arguments['recurrence'] !== 'none') {
                $recurrence = $arguments['recurrence'];
                $recurrenceAllowed = match (true) {
                    str_starts_with($tool, 'app.task.') => $this->planLimits->canUseRecurringTasks($user),
                    str_starts_with($tool, 'app.reminder.') => $this->planLimits->canUseRecurringReminders($user),
                    str_starts_with($tool, 'app.calendar.') => $this->planLimits->canUseRecurringCalendar($user),
                    default => true,
                };
                if (! $recurrenceAllowed) {
                    throw $this->invalid("The current subscription does not authorize {$recurrence} recurrence for {$tool}.");
                }
            }

            if ($this->isIdTargetedMutation($tool)) {
                $this->authorizeMutationTarget(
                    $turn,
                    $operation['id'],
                    $tool,
                    (int) $arguments['id'],
                    isset($sealed[$operation['id']]),
                    $resourceIds,
                );
                if (str_ends_with($tool, '.update')) {
                    $this->validateExistingResourceState($turn, $tool, $arguments);
                }
            }

            if (array_key_exists('note_folder_id', $arguments) && $arguments['note_folder_id'] !== null) {
                $this->authorizeLinkedResource(
                    $turn,
                    $tool,
                    'note folder',
                    NoteFolder::class,
                    (int) $arguments['note_folder_id'],
                    (array) ($resourceIds['note_folders'] ?? []),
                );
            }
            if (array_key_exists('calendar_event_id', $arguments) && $arguments['calendar_event_id'] !== null) {
                $this->authorizeLinkedResource(
                    $turn,
                    $tool,
                    'calendar event',
                    CalendarEvent::class,
                    (int) $arguments['calendar_event_id'],
                    (array) ($resourceIds['calendar_events'] ?? []),
                );
            }

            if (in_array($tool, ['voice.work.status', 'voice.work.cancel'], true)) {
                $targetTurnId = trim((string) ($arguments['target_turn_id'] ?? ''));
                if ($targetTurnId !== '' && ! in_array($targetTurnId, $trustedVoiceTurnIds, true)) {
                    throw $this->invalid("{$tool} target_turn_id was not exposed in trusted recent work context.");
                }
            }
        }
    }

    /**
     * @param  array<string,list<int>>  $resourceIds
     */
    private function authorizeMutationTarget(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        string $operationId,
        string $tool,
        int $id,
        bool $sealedReference,
        array $resourceIds,
    ): void {
        [$contextKey, $model] = match (true) {
            str_starts_with($tool, 'app.task.') => ['tasks', Task::class],
            str_starts_with($tool, 'app.reminder.') => ['reminders', Reminder::class],
            str_starts_with($tool, 'app.calendar.') => ['calendar_events', CalendarEvent::class],
            str_starts_with($tool, 'app.note_folder.') => ['note_folders', NoteFolder::class],
            str_starts_with($tool, 'app.event_category.') => ['event_categories', EventCategory::class],
            str_starts_with($tool, 'app.blocker.') => ['blockers', Blocker::class],
            str_starts_with($tool, 'app.note.') => ['notes', Note::class],
            str_starts_with($tool, 'app.memory.') => ['memory_items', MemoryItem::class],
            default => throw $this->invalid("{$tool} does not support a direct application resource target."),
        };

        if (! $sealedReference && ! in_array($id, (array) ($resourceIds[$contextKey] ?? []), true)) {
            throw $this->invalid("Operation {$operationId} target id was not exposed in trusted {$contextKey} context.");
        }
        if (! $this->ownedResourceExists($turn, $model, $id)) {
            throw $this->invalid("Operation {$operationId} target id is not authorized for this user and workspace.");
        }
    }

    /** @param class-string<Model> $model */
    private function authorizeLinkedResource(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        string $tool,
        string $label,
        string $model,
        int $id,
        array $exposedIds,
    ): void {
        if (! in_array($id, $exposedIds, true)) {
            throw $this->invalid("{$tool} {$label} id was not exposed in trusted semantic context.");
        }
        if (! $this->ownedResourceExists($turn, $model, $id)) {
            throw $this->invalid("{$tool} {$label} id is not authorized for this user and workspace.");
        }
    }

    /** @param class-string<Model> $model */
    private function ownedResourceExists(HermesSemanticExecutionContext|VoiceTurn $turn, string $model, int $id): bool
    {
        return $model::query()
            ->where('user_id', $turn->user_id)
            ->where('workspace_id', $turn->workspace_id)
            ->whereKey($id)
            ->exists();
    }

    /** @param array<string,mixed> $arguments */
    private function validateExistingResourceState(HermesSemanticExecutionContext|VoiceTurn $turn, string $tool, array $arguments): void
    {
        if ($tool === 'app.task.update') {
            $task = Task::query()
                ->where('user_id', $turn->user_id)
                ->where('workspace_id', $turn->workspace_id)
                ->findOrFail((int) $arguments['id']);
            $statusSupplied = array_key_exists('status', $arguments);
            $completedAtSupplied = array_key_exists('completed_at', $arguments);
            $effectiveStatus = $statusSupplied ? $arguments['status'] : $task->status;
            $completedAt = $arguments['completed_at'] ?? null;

            if ($effectiveStatus === 'completed'
                && (($statusSupplied && ! $completedAtSupplied)
                    || ($completedAtSupplied && (! is_string($completedAt) || trim($completedAt) === '')))) {
                throw $this->invalid(
                    'app.task.update status=completed requires an explicit non-null completed_at timestamp.',
                );
            }
            if ($effectiveStatus === 'open'
                && (($statusSupplied && (! $completedAtSupplied || $completedAt !== null))
                    || (! $statusSupplied && $completedAtSupplied))) {
                throw $this->invalid(
                    'app.task.update for an open task requires status=open and completed_at=null together; completed_at cannot imply a status change.',
                );
            }
        }

        if ($tool === 'app.calendar.update') {
            $event = CalendarEvent::query()
                ->where('user_id', $turn->user_id)
                ->where('workspace_id', $turn->workspace_id)
                ->findOrFail((int) $arguments['id']);
            $allDaySupplied = array_key_exists('all_day', $arguments);
            $effectiveAllDay = $allDaySupplied
                ? $arguments['all_day'] === true
                : data_get($event->metadata, 'all_day') === true;
            $boundsTouched = array_key_exists('starts_at', $arguments) || array_key_exists('ends_at', $arguments);
            if (($allDaySupplied || ($effectiveAllDay && $boundsTouched))
                && ! $this->hasCompleteCalendarBounds($arguments)) {
                throw $this->invalid(
                    'app.calendar.update must supply both starts_at and ends_at when changing all-day convention or all-day bounds.',
                );
            }
        }
    }

    /** @param object|array<string,mixed> $operation */
    private function value(object|array $operation, string $key, mixed $default = null): mixed
    {
        if (is_array($operation)) {
            return $operation[$key] ?? $default;
        }

        return $operation->{$key} ?? $default;
    }

    /**
     * Resolve a model-selected search reference before any operation job is
     * staged. The source search must express exact matching and a unique-result
     * requirement in structured arguments. Once exactly one authorized target
     * exists, its concrete id replaces result_ref in the sealed operation.
     *
     * @param  array<string,mixed>  $arguments
     * @param  array<string,array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}>  $knownOperations
     * @return array<string,mixed>
     */
    private function sealResultReferenceTarget(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        string $operationId,
        string $tool,
        array $arguments,
        array $knownOperations,
    ): array {
        $reference = $arguments['result_ref'] ?? null;
        if (! is_array($reference)) {
            return $arguments;
        }

        $sourceOperationId = trim((string) ($reference['operation_id'] ?? ''));
        $source = $knownOperations[$sourceOperationId] ?? null;
        $expectedSearchTool = match (true) {
            str_starts_with($tool, 'app.task.') => 'app.task.search',
            str_starts_with($tool, 'app.reminder.') => 'app.reminder.search',
            str_starts_with($tool, 'app.calendar.') => 'app.calendar.search',
            str_starts_with($tool, 'app.note.') => 'app.note.search',
            str_starts_with($tool, 'app.memory.') => 'app.memory.search',
            default => null,
        };
        if (! is_array($source) || $expectedSearchTool === null || ($source['tool'] ?? null) !== $expectedSearchTool) {
            throw $this->invalid("Operation {$operationId} result_ref must target an earlier {$expectedSearchTool} operation.");
        }

        $sourceArguments = $source['arguments'];
        $matchMode = $sourceArguments['match_mode'] ?? null;
        $allowedMatchModes = $source['tool'] === 'app.memory.search'
            ? ['exact_title', 'exact_content']
            : ['exact_title'];
        if (! in_array($matchMode, $allowedMatchModes, true)
            || ($sourceArguments['require_unique'] ?? null) !== true) {
            throw $this->invalid(
                "Operation {$operationId} result_ref requires an exact source search with require_unique=true.",
            );
        }

        $targetIds = $this->exactSearchTargetIds($turn, $source['tool'], $sourceArguments);
        if (count($targetIds) !== 1) {
            $count = count($targetIds) > 1 ? 'more than one' : 'no';
            throw $this->invalid(
                "Operation {$operationId} source search {$sourceOperationId} matched {$count} resources. Ask one specific clarification or use a trusted concrete id.",
            );
        }

        $arguments['id'] = $targetIds[0];
        unset($arguments['result_ref']);

        return $arguments;
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @return list<int>
     */
    private function exactSearchTargetIds(HermesSemanticExecutionContext|VoiceTurn $turn, string $tool, array $arguments): array
    {
        $query = match ($tool) {
            'app.task.search' => Task::query(),
            'app.reminder.search' => Reminder::query(),
            'app.calendar.search' => CalendarEvent::query(),
            'app.note.search' => Note::query(),
            'app.memory.search' => MemoryItem::query(),
            default => throw $this->invalid("{$tool} cannot provide a mutation result_ref."),
        };
        $query->where('user_id', $turn->user_id)->where('workspace_id', $turn->workspace_id);
        $ids = $this->ids($arguments);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $exactField = $tool === 'app.memory.search'
            && ($arguments['match_mode'] ?? null) === 'exact_content'
                ? 'content'
                : 'title';
        $query->where($exactField, trim((string) ($arguments['query'] ?? '')));

        if ($tool === 'app.memory.search') {
            $query
                ->where('status', 'active')
                ->where(fn (Builder $candidate): Builder => $candidate
                    ->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now()));
            if (array_key_exists('type', $arguments)) {
                $query->where('type', $arguments['type']);
            }
        }

        if (! in_array($tool, ['app.note.search', 'app.memory.search'], true)) {
            $this->applyStatus($query, $arguments);
            $rangeField = match ($tool) {
                'app.task.search' => 'due_at',
                'app.reminder.search' => 'remind_at',
                'app.calendar.search' => 'starts_at',
            };
            $this->applyRange($query, $arguments, $rangeField);
        }

        return $query->orderBy('id')->limit(2)->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /** @param array<string,mixed> $arguments */
    private function validateArguments(string $tool, array $arguments): void
    {
        if (array_key_exists('metadata', $arguments)) {
            throw $this->invalid("{$tool} may not supply metadata; use only canonical top-level semantic fields.");
        }

        $allowed = $this->allowedArgumentKeys($tool);
        $unknown = array_values(array_diff(array_keys($arguments), $allowed));
        if ($unknown !== []) {
            throw $this->invalid("{$tool} received unsupported argument field(s): ".implode(', ', $unknown).'.');
        }

        foreach (['user_id', 'workspace_id', 'conversation_session_id', 'idempotency_key', 'lifecycle', 'state', 'deadline'] as $forbidden) {
            if (array_key_exists($forbidden, $arguments)) {
                throw $this->invalid("The semantic model may not supply {$forbidden}.");
            }
        }

        if (str_ends_with($tool, '.create')) {
            if ($tool === 'app.memory.create') {
                $this->requireText($arguments, 'type', $tool);
                $this->requireText($arguments, 'content', $tool);
            } elseif (in_array($tool, ['app.note_folder.create', 'app.event_category.create'], true)) {
                $this->requireText($arguments, 'name', $tool);
            } elseif ($tool === 'app.blocker.create') {
                $this->requireText($arguments, 'reason', $tool);
            } else {
                $this->requireText($arguments, 'title', $tool);
            }
            if (array_key_exists('id', $arguments)) {
                throw $this->invalid("{$tool} may not choose an application-owned resource id.");
            }
        }
        if ($this->isIdTargetedMutation($tool)) {
            if (array_key_exists('id', $arguments) && array_key_exists('result_ref', $arguments)) {
                throw $this->invalid("{$tool} may not supply both id and result_ref.");
            }
            $hasId = isset($arguments['id'])
                && is_int($arguments['id'])
                && $arguments['id'] > 0;
            $reference = $arguments['result_ref'] ?? null;
            $hasReference = is_array($reference)
                && ! array_is_list($reference)
                && array_values(array_diff(array_keys($reference), ['operation_id', 'path'])) === []
                && trim((string) ($reference['operation_id'] ?? '')) !== ''
                && trim((string) ($reference['path'] ?? '')) === 'unique_id';
            if (! $hasId && ! $hasReference) {
                throw $this->invalid("{$tool} requires a concrete positive resource id or a validated result_ref.");
            }
            if (str_ends_with($tool, '.update')) {
                $mutableFields = array_values(array_diff($this->allowedArgumentKeys($tool), ['id', 'result_ref']));
                if (array_intersect(array_keys($arguments), $mutableFields) === []) {
                    throw $this->invalid("{$tool} requires at least one explicit mutable field.");
                }
                if (array_key_exists('title', $arguments)
                    && $tool !== 'app.memory.update') {
                    $this->requireText($arguments, 'title', $tool);
                }
            }
        }
        if (in_array($tool, [
            'app.task.search',
            'app.reminder.search',
            'app.calendar.search',
            'app.note.search',
            'app.memory.search',
        ], true)) {
            $matchMode = trim((string) ($arguments['match_mode'] ?? ''));
            $allowedMatchModes = $tool === 'app.memory.search'
                ? ['exact_title', 'exact_content']
                : ['exact_title'];
            if ($matchMode !== '' && ! in_array($matchMode, $allowedMatchModes, true)) {
                throw $this->invalid("{$tool} received a non-canonical match_mode.");
            }
            if (in_array($matchMode, ['exact_title', 'exact_content'], true)) {
                $this->requireText($arguments, 'query', $tool);
            }
            if (($arguments['require_unique'] ?? false) === true
                && ! in_array($matchMode, $allowedMatchModes, true)) {
                throw $this->invalid("{$tool} require_unique=true requires an exact match_mode.");
            }
        }
        if (in_array($tool, ['app.agent_profile.update', 'app.conversation.update'], true)
            && array_intersect(array_keys($arguments), $this->allowedArgumentKeys($tool)) === []) {
            throw $this->invalid("{$tool} requires at least one explicit mutable field.");
        }
        if ($tool === 'app.blocker.resolve'
            && (! isset($arguments['id']) || ! is_int($arguments['id']) || $arguments['id'] <= 0)) {
            throw $this->invalid('app.blocker.resolve requires a concrete positive blocker id.');
        }
        if ($tool === 'app.memory.update'
            && array_key_exists('content', $arguments)) {
            $this->requireText($arguments, 'content', $tool);
        }
        if (str_starts_with($tool, 'app.memory.')) {
            foreach (['title', 'summary'] as $field) {
                if (array_key_exists($field, $arguments) && $arguments[$field] !== null) {
                    $this->requireText($arguments, $field, $tool);
                }
            }
            if (array_key_exists('expires_at', $arguments)
                && $arguments['expires_at'] !== null
                && ! is_string($arguments['expires_at'])) {
                throw $this->invalid("{$tool} expires_at must be an absolute ISO-8601 string or null.");
            }
        }
        if (str_starts_with($tool, 'app.memory.')
            && array_key_exists('type', $arguments)
            && ! in_array($arguments['type'], self::MEMORY_TYPES, true)) {
            throw $this->invalid("{$tool} received a non-canonical memory type.");
        }
        if ($tool === 'app.reminder.create') {
            $this->requireIsoTimestamp($arguments, 'remind_at', $tool);
        }
        if ($tool === 'app.reminder.update' && array_key_exists('remind_at', $arguments)) {
            $this->requireIsoTimestamp($arguments, 'remind_at', $tool);
        }
        if ($tool === 'app.calendar.create') {
            $this->requireIsoTimestamp($arguments, 'starts_at', $tool);
            if (($arguments['all_day'] ?? false) === true && ! $this->hasCompleteCalendarBounds($arguments)) {
                throw $this->invalid('app.calendar.create all_day=true requires explicit starts_at and ends_at bounds.');
            }
        }
        if ($tool === 'app.calendar.update' && array_key_exists('starts_at', $arguments)) {
            $this->requireIsoTimestamp($arguments, 'starts_at', $tool);
        }
        if ($tool === 'app.calendar.update'
            && array_key_exists('all_day', $arguments)
            && ! $this->hasCompleteCalendarBounds($arguments)) {
            throw $this->invalid(
                'app.calendar.update must supply both starts_at and ends_at when changing all-day convention.',
            );
        }
        if ($tool === 'app.task.create') {
            $status = $arguments['status'] ?? 'open';
            if ($status === 'completed'
                && (! is_string($arguments['completed_at'] ?? null)
                    || trim($arguments['completed_at']) === '')) {
                throw $this->invalid(
                    'app.task.create status=completed requires an explicit non-null completed_at timestamp.',
                );
            }
            if ($status === 'open' && ($arguments['completed_at'] ?? null) !== null) {
                throw $this->invalid('app.task.create status=open cannot include a non-null completed_at.');
            }
        }
        if ($tool === 'app.task.update' && array_key_exists('status', $arguments)) {
            $completedAtSupplied = array_key_exists('completed_at', $arguments);
            if ($arguments['status'] === 'completed'
                && (! $completedAtSupplied
                    || ! is_string($arguments['completed_at'])
                    || trim($arguments['completed_at']) === '')) {
                throw $this->invalid(
                    'app.task.update status=completed requires an explicit non-null completed_at timestamp.',
                );
            }
            if ($arguments['status'] === 'open'
                && (! $completedAtSupplied || $arguments['completed_at'] !== null)) {
                throw $this->invalid(
                    'app.task.update status=open requires explicit completed_at=null.',
                );
            }
        }
        if (in_array($tool, ['app.note.create', 'app.note.update'], true)) {
            $bodyRepresentations = collect(['plain_text', 'body_html', 'body_delta'])
                ->filter(fn (string $field): bool => array_key_exists($field, $arguments));
            if ($bodyRepresentations->count() > 1) {
                throw $this->invalid(
                    "{$tool} requires exactly one note body representation: plain_text, body_html, or body_delta.",
                );
            }
        }
        foreach (['due_at', 'remind_at', 'starts_at', 'ends_at', 'completed_at', 'from', 'to', 'expires_at'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && $arguments[$field] !== '') {
                $this->requireIsoTimestamp($arguments, $field, $tool);
            }
        }
        if (isset($arguments['starts_at'], $arguments['ends_at'])
            && Carbon::parse((string) $arguments['ends_at'])->lt(Carbon::parse((string) $arguments['starts_at']))) {
            throw $this->invalid("{$tool} ends_at may not be earlier than starts_at.");
        }

        $this->validateCanonicalDomainValues($tool, $arguments);

        if (isset($arguments['result_ref'])
            && ! $this->isIdTargetedMutation($tool)) {
            throw $this->invalid("{$tool} may not use result_ref.");
        }
        if ($tool === 'system.clock.read') {
            $this->requireText($arguments, 'kind', $tool);
            if (! in_array($arguments['kind'], ['time', 'date', 'datetime'], true)) {
                throw $this->invalid('system.clock.read kind must be time, date, or datetime.');
            }
        }
        if ($tool === 'app.day.read' && $this->absoluteDateArgument($arguments) === null) {
            throw $this->invalid('app.day.read requires one absolute valid YYYY-MM-DD date.');
        }
        if (in_array($tool, ['app.history.search', 'app.activity.search'], true)) {
            $hasFrom = array_key_exists('from', $arguments) && $arguments['from'] !== null;
            $hasTo = array_key_exists('to', $arguments) && $arguments['to'] !== null;
            if ($hasFrom !== $hasTo) {
                throw $this->invalid("{$tool} requires both from and to when filtering by time.");
            }
        }
        if ($tool === 'external.lookup') {
            $this->requireText($arguments, 'query', $tool);
            $this->requireText($arguments, 'kind', $tool);
            $kind = trim((string) $arguments['kind']);
            if (! in_array($kind, ['weather', 'forecast', 'places', 'web', 'general'], true)) {
                throw $this->invalid('external.lookup requires an explicit kind of weather, forecast, places, web, or general.');
            }
            $this->validateExternalFieldsForKind($kind, $arguments);
            if (array_key_exists('location', $arguments)) {
                $this->requireText($arguments, 'location', $tool);
            }
            if ($kind === 'places') {
                $this->requireText($arguments, 'location', $tool);
            }
            if (in_array($kind, ['weather', 'forecast'], true)) {
                $this->requireExactlyOneExternalLocation($arguments, $kind);
                $this->requireText($arguments, 'units', $tool);
                if (! in_array($arguments['units'], ['imperial', 'metric'], true)) {
                    throw $this->invalid('external.lookup weather units must be imperial or metric.');
                }
            }
            if ($kind === 'forecast') {
                if ($this->absoluteDateArgument($arguments) === null) {
                    throw $this->invalid('external.lookup forecast requires an absolute YYYY-MM-DD date.');
                }
                $this->absoluteTimeArgument($arguments);
            }
            if (in_array($kind, ['web', 'general'], true)) {
                $this->requireText($arguments, 'topic', $tool);
                if (! in_array(trim((string) $arguments['topic']), ['general', 'news', 'finance'], true)) {
                    throw $this->invalid('external.lookup topic must be general, news, or finance.');
                }
            }
        }
        if ($tool === 'voice.work.cancel') {
            $targetSupplied = array_key_exists('target_turn_id', $arguments);
            $target = trim((string) ($arguments['target_turn_id'] ?? ''));
            $allSupplied = array_key_exists('all', $arguments);
            $all = ($arguments['all'] ?? null) === true;
            if (($targetSupplied && $target === '') || ($allSupplied && ! $all) || (($target !== '') === $all)) {
                throw $this->invalid('voice.work.cancel requires exactly one selector: a concrete target_turn_id or all=true.');
            }
        }
        if ($tool === 'voice.work.status') {
            $targetSupplied = array_key_exists('target_turn_id', $arguments);
            $target = trim((string) ($arguments['target_turn_id'] ?? ''));
            $scopeSupplied = array_key_exists('scope', $arguments);
            $scope = trim((string) ($arguments['scope'] ?? ''));
            if (($targetSupplied && $target === '')
                || ($scopeSupplied && $scope !== 'latest')
                || (($target !== '') === ($scope === 'latest'))) {
                throw $this->invalid('voice.work.status requires exactly one selector: target_turn_id or scope=latest.');
            }
        }

        if (isset($arguments['limit']) && (! is_int($arguments['limit']) || $arguments['limit'] < 1 || $arguments['limit'] > 20)) {
            throw $this->invalid("{$tool} limit must be an integer between 1 and 20.");
        }
        foreach (['id', 'note_folder_id', 'calendar_event_id'] as $field) {
            if (isset($arguments[$field]) && (! is_int($arguments[$field]) || $arguments[$field] <= 0)) {
                throw $this->invalid("{$tool} {$field} must be a positive integer.");
            }
        }
        foreach (['is_critical', 'is_pinned', 'all_day', 'all', 'require_unique'] as $field) {
            if (array_key_exists($field, $arguments) && ! is_bool($arguments[$field])) {
                throw $this->invalid("{$tool} {$field} must be boolean.");
            }
        }
        if (isset($arguments['ids']) && (! is_array($arguments['ids']) || ! array_is_list($arguments['ids']))) {
            throw $this->invalid("{$tool} ids must be a list of positive integers.");
        }
        foreach ((array) ($arguments['ids'] ?? []) as $id) {
            if (! is_int($id) || $id <= 0) {
                throw $this->invalid("{$tool} ids must contain only positive integers.");
            }
        }
        if (isset($arguments['statuses']) && (! is_array($arguments['statuses']) || ! array_is_list($arguments['statuses']))) {
            throw $this->invalid("{$tool} statuses must be a list.");
        }
        foreach ((array) ($arguments['statuses'] ?? []) as $status) {
            if (! is_string($status) || trim($status) === '') {
                throw $this->invalid("{$tool} statuses must contain only non-empty strings.");
            }
        }
        foreach (['title', 'type', 'status', 'notes', 'category', 'color', 'description', 'location', 'plain_text', 'body_html', 'query', 'match_mode', 'kind', 'date', 'time', 'units', 'topic', 'target_turn_id', 'scope', 'recurrence', 'content', 'summary', 'expires_at', 'name', 'reason', 'display_name', 'event_type', 'tool_name'] as $field) {
            if (array_key_exists($field, $arguments) && $arguments[$field] !== null && ! is_string($arguments[$field])) {
                throw $this->invalid("{$tool} {$field} must be a string.");
            }
        }
        if (array_key_exists('context', $arguments)
            && $arguments['context'] !== null
            && ($tool === 'app.blocker.create' || $tool === 'app.blocker.update')
            && (! is_array($arguments['context']) || array_is_list($arguments['context']))) {
            throw $this->invalid("{$tool} context must be an object or null.");
        }
        if (array_key_exists('context', $arguments)
            && $arguments['context'] !== null
            && ! in_array($tool, ['app.blocker.create', 'app.blocker.update'], true)
            && ! is_string($arguments['context'])) {
            throw $this->invalid("{$tool} context must be a string or null.");
        }
        foreach (['confidence', 'importance'] as $field) {
            if (array_key_exists($field, $arguments)
                && (! is_int($arguments[$field]) || $arguments[$field] < 0 || $arguments[$field] > 100)) {
                throw $this->invalid("{$tool} {$field} must be an integer between 0 and 100.");
            }
        }
        if (array_key_exists('sort_order', $arguments)
            && (! is_int($arguments['sort_order']) || $arguments['sort_order'] < 0)) {
            throw $this->invalid("{$tool} sort_order must be a non-negative integer.");
        }
        foreach (['body_delta'] as $field) {
            if (isset($arguments[$field]) && ! is_array($arguments[$field])) {
                throw $this->invalid("{$tool} {$field} must be an object.");
            }
        }
        if (array_key_exists('latitude', $arguments)
            && ((! is_int($arguments['latitude']) && ! is_float($arguments['latitude']))
                || (float) $arguments['latitude'] < -90
                || (float) $arguments['latitude'] > 90)) {
            throw $this->invalid('external.lookup latitude must be between -90 and 90.');
        }
        if (array_key_exists('longitude', $arguments)
            && ((! is_int($arguments['longitude']) && ! is_float($arguments['longitude']))
                || (float) $arguments['longitude'] < -180
                || (float) $arguments['longitude'] > 180)) {
            throw $this->invalid('external.lookup longitude must be between -180 and 180.');
        }
    }

    /** @return array<int,string> */
    private function allowedArgumentKeys(string $tool): array
    {
        return match ($tool) {
            'system.clock.read' => ['kind'],
            'system.voice_state.read', 'voice.playback.stop' => [],
            'app.task.search', 'app.reminder.search', 'app.calendar.search' => [
                'id', 'ids', 'query', 'match_mode', 'require_unique', 'status', 'statuses', 'from', 'to', 'limit',
            ],
            'app.note.search' => ['id', 'ids', 'query', 'match_mode', 'require_unique', 'limit'],
            'app.memory.search' => ['id', 'ids', 'query', 'match_mode', 'require_unique', 'type', 'limit'],
            'app.task.create' => [
                'title', 'type', 'status', 'notes', 'category', 'color',
                'is_critical', 'due_at', 'completed_at', 'recurrence',
            ],
            'app.task.update' => [
                'id', 'result_ref', 'title', 'type', 'status', 'notes', 'category', 'color',
                'is_critical', 'due_at', 'completed_at', 'recurrence',
            ],
            'app.reminder.create' => [
                'title', 'notes', 'status', 'category', 'color',
                'is_critical', 'remind_at', 'recurrence', 'calendar_event_id',
            ],
            'app.reminder.update' => [
                'id', 'result_ref', 'title', 'notes', 'status', 'category', 'color',
                'is_critical', 'remind_at', 'recurrence', 'calendar_event_id',
            ],
            'app.calendar.create' => [
                'title', 'description', 'location', 'category', 'color',
                'is_critical', 'recurrence', 'starts_at', 'ends_at', 'status', 'all_day',
            ],
            'app.calendar.update' => [
                'id', 'result_ref', 'title', 'description', 'location', 'category', 'color',
                'is_critical', 'recurrence', 'starts_at', 'ends_at', 'status', 'all_day',
            ],
            'app.note.create' => [
                'title', 'plain_text', 'body_html', 'body_delta',
                'note_folder_id', 'is_pinned',
            ],
            'app.note.update' => [
                'id', 'result_ref', 'title', 'plain_text', 'body_html', 'body_delta',
                'note_folder_id', 'is_pinned',
            ],
            'app.memory.create' => [
                'type', 'title', 'content', 'summary', 'confidence', 'importance', 'expires_at',
            ],
            'app.memory.update' => [
                'id', 'result_ref', 'type', 'title', 'content', 'summary',
                'confidence', 'importance', 'expires_at',
            ],
            'app.note_folder.create' => ['name', 'sort_order'],
            'app.note_folder.update' => ['id', 'name', 'sort_order'],
            'app.event_category.create' => ['name', 'color'],
            'app.event_category.update' => ['id', 'name', 'color'],
            'app.blocker.create' => ['reason', 'status', 'context'],
            'app.blocker.update' => ['id', 'reason', 'status', 'context'],
            'app.blocker.resolve' => ['id'],
            'app.agent_profile.update' => ['display_name'],
            'app.conversation.update' => ['title'],
            'app.task.delete', 'app.reminder.delete', 'app.calendar.delete', 'app.note.delete' => [
                'id', 'result_ref',
            ],
            'app.memory.delete' => ['id', 'result_ref'],
            'app.note_folder.delete', 'app.event_category.delete', 'app.blocker.delete' => ['id'],
            'app.history.search' => ['query', 'from', 'to', 'limit'],
            'app.activity.search' => ['from', 'to', 'event_type', 'tool_name', 'limit'],
            'app.day.read' => ['date'],
            'external.lookup' => [
                'query', 'context', 'kind', 'location', 'latitude', 'longitude', 'date', 'time',
                'units', 'topic',
            ],
            'voice.work.status' => ['target_turn_id', 'scope'],
            'voice.work.cancel' => ['target_turn_id', 'all'],
            default => [],
        };
    }

    private function isIdTargetedMutation(string $tool): bool
    {
        return in_array($tool, [
            'app.task.update', 'app.task.delete',
            'app.reminder.update', 'app.reminder.delete',
            'app.calendar.update', 'app.calendar.delete',
            'app.note.update', 'app.note.delete',
            'app.note_folder.update', 'app.note_folder.delete',
            'app.event_category.update', 'app.event_category.delete',
            'app.blocker.update', 'app.blocker.resolve', 'app.blocker.delete',
            'app.memory.update', 'app.memory.delete',
        ], true);
    }

    /** @param array<string,mixed> $arguments */
    private function validateCanonicalDomainValues(string $tool, array $arguments): void
    {
        if (str_starts_with($tool, 'app.task.')
            && array_key_exists('type', $arguments)
            && ! in_array($arguments['type'], self::TASK_TYPES, true)) {
            throw $this->invalid("{$tool} type must be todo, chore, or maintenance.");
        }

        $statuses = match (true) {
            str_starts_with($tool, 'app.task.') => self::TASK_STATUSES,
            str_starts_with($tool, 'app.reminder.') => self::REMINDER_STATUSES,
            str_starts_with($tool, 'app.calendar.') => self::CALENDAR_STATUSES,
            str_starts_with($tool, 'app.blocker.') => ['open', 'resolved'],
            default => null,
        };
        if (is_array($statuses)) {
            $suppliedStatuses = [];
            if (array_key_exists('status', $arguments)) {
                $suppliedStatuses[] = $arguments['status'];
            }
            if (array_key_exists('statuses', $arguments)) {
                $suppliedStatuses = [
                    ...$suppliedStatuses,
                    ...(is_array($arguments['statuses']) ? $arguments['statuses'] : []),
                ];
            }
            foreach ($suppliedStatuses as $status) {
                if (! is_string($status) || ! in_array($status, $statuses, true)) {
                    throw $this->invalid("{$tool} received a non-canonical status.");
                }
            }
        }

        if (array_key_exists('recurrence', $arguments)
            && (! is_string($arguments['recurrence'])
                || ! in_array($arguments['recurrence'], self::RECURRENCES, true))) {
            throw $this->invalid("{$tool} recurrence must be none, daily, weekly, monthly, or yearly.");
        }
    }

    /** @param array<string,mixed> $arguments */
    private function requireText(array $arguments, string $field, string $tool): void
    {
        if (! isset($arguments[$field]) || ! is_string($arguments[$field]) || trim($arguments[$field]) === '') {
            throw $this->invalid("{$tool} requires {$field}.");
        }
    }

    /** @param array<string,mixed> $arguments */
    private function absoluteDateArgument(array $arguments): ?string
    {
        if (! array_key_exists('date', $arguments)) {
            return null;
        }
        $value = $arguments['date'];
        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $matches) !== 1
            || ! checkdate((int) $matches[2], (int) $matches[3], (int) $matches[1])) {
            throw $this->invalid('external.lookup date must be an absolute valid YYYY-MM-DD date.');
        }

        return $value;
    }

    /** @param array<string,mixed> $arguments */
    private function absoluteTimeArgument(array $arguments): ?string
    {
        if (! array_key_exists('time', $arguments)) {
            return null;
        }
        $value = $arguments['time'];
        if (! is_string($value) || preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value) !== 1) {
            throw $this->invalid('external.lookup time must use absolute 24-hour HH:MM form.');
        }

        return $value;
    }

    /** @param array<string,mixed> $arguments */
    private function validateExternalFieldsForKind(string $kind, array $arguments): void
    {
        $applicable = match ($kind) {
            'weather' => ['query', 'kind', 'location', 'latitude', 'longitude', 'units'],
            'forecast' => ['query', 'kind', 'location', 'latitude', 'longitude', 'date', 'time', 'units'],
            'places' => ['query', 'kind', 'location'],
            'web', 'general' => ['query', 'kind', 'topic', 'context', 'location'],
        };
        $inapplicable = array_values(array_diff(array_keys($arguments), $applicable));
        if ($inapplicable !== []) {
            throw $this->invalid(
                "external.lookup kind={$kind} received inapplicable field(s): ".implode(', ', $inapplicable).'.',
            );
        }
    }

    /** @param array<string,mixed> $arguments */
    private function requireExactlyOneExternalLocation(array $arguments, string $kind): void
    {
        $hasLocation = array_key_exists('location', $arguments);
        if ($hasLocation && (! is_string($arguments['location']) || trim($arguments['location']) === '')) {
            throw $this->invalid('external.lookup location must be a non-empty string when supplied.');
        }
        $hasLatitude = array_key_exists('latitude', $arguments);
        $hasLongitude = array_key_exists('longitude', $arguments);
        if ($hasLatitude !== $hasLongitude) {
            throw $this->invalid('external.lookup latitude and longitude must be supplied together.');
        }
        $hasCoordinates = $hasLatitude && $hasLongitude;
        if ($hasLocation === $hasCoordinates) {
            throw $this->invalid(
                "external.lookup {$kind} requires exactly one location representation: location or latitude/longitude.",
            );
        }
    }

    /** @param array<string,mixed> $arguments */
    private function requireIsoTimestamp(array $arguments, string $field, string $tool): void
    {
        $value = trim((string) ($arguments[$field] ?? ''));
        if ($value === '' || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?(?:Z|[+-]\d{2}:\d{2})$/', $value) !== 1) {
            throw $this->invalid("{$tool} requires {$field} as an absolute ISO-8601 timestamp with an offset.");
        }
        try {
            Carbon::parse($value);
        } catch (Throwable) {
            throw $this->invalid("{$tool} supplied an invalid {$field} timestamp.");
        }
    }

    /** @param array<string,mixed> $arguments */
    private function hasCompleteCalendarBounds(array $arguments): bool
    {
        foreach (['starts_at', 'ends_at'] as $field) {
            if (! array_key_exists($field, $arguments)
                || ! is_string($arguments[$field])
                || trim($arguments[$field]) === '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @return array<string,mixed>
     */
    private function schedulerSpec(HermesSemanticExecutionContext|VoiceTurn $turn, array $operation): array
    {
        $tool = $operation['tool'];
        $arguments = $operation['arguments'];
        $resourceType = match (true) {
            str_starts_with($tool, 'app.task.') => 'task',
            str_starts_with($tool, 'app.reminder.') => 'reminder',
            str_starts_with($tool, 'app.calendar.') => 'calendar',
            str_starts_with($tool, 'app.note.') => 'note',
            str_starts_with($tool, 'app.note_folder.') => 'note-folder',
            str_starts_with($tool, 'app.event_category.') => 'event-category',
            str_starts_with($tool, 'app.blocker.') => 'blocker',
            str_starts_with($tool, 'app.agent_profile.') => 'agent-profile',
            str_starts_with($tool, 'app.conversation.') => 'conversation',
            str_starts_with($tool, 'app.memory.') => 'memory',
            default => null,
        };
        $resourceLockKey = null;
        if ($resourceType !== null
            && $this->isIdTargetedMutation($tool)) {
            if (isset($arguments['id'])) {
                $resourceLockKey = 'semantic:'.(int) $turn->workspace_id.':'.$resourceType.':'.(int) $arguments['id'];
            }
        } elseif ($tool === 'voice.work.cancel') {
            $target = trim((string) ($arguments['target_turn_id'] ?? ''));
            $resourceLockKey = ($arguments['all'] ?? false) === true
                ? 'semantic:'.(int) $turn->workspace_id.':voice-work:all'
                : 'semantic:'.(int) $turn->workspace_id.':voice-turn:'.substr(hash('sha256', $target), 0, 24);
        }

        $lane = match (true) {
            in_array($tool, self::MUTATING_TOOLS, true) => VoiceTurnLane::AppWrite,
            $tool === 'external.lookup' => VoiceTurnLane::External,
            default => VoiceTurnLane::AppRead,
        };
        $priority = match (true) {
            str_ends_with($tool, '.delete'), $tool === 'voice.work.cancel' => 100,
            str_ends_with($tool, '.update'), $tool === 'app.blocker.resolve' => 50,
            default => 0,
        };

        return [
            'id' => $operation['id'],
            'tool' => $tool,
            'operation' => $operation,
            'lane' => $lane->value,
            'label' => $this->operationLabel($tool),
            'priority' => $priority,
            'resource_lock_key' => $resourceLockKey,
        ];
    }

    private function operationLabel(string $tool): string
    {
        return match ($tool) {
            'system.clock.read' => 'Read time and date',
            'system.voice_state.read' => 'Read voice state',
            'external.lookup' => 'Look up information',
            'voice.playback.stop' => 'Stop playback',
            'voice.work.status' => 'Check work status',
            'voice.work.cancel' => 'Cancel work',
            'app.history.search' => 'Search request history',
            'app.activity.search' => 'Search activity history',
            'app.day.read' => 'Read day context',
            default => ucfirst(str_replace(['app.', '.'], ['', ' '], $tool)),
        };
    }

    /** @return array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>} */
    private function operationFromRun(AssistantRun $run): array
    {
        $operation = json_decode((string) $run->input, true);
        if (! is_array($operation) || array_is_list($operation)) {
            throw $this->invalid('The durable semantic operation payload is invalid.');
        }
        $id = trim((string) ($operation['id'] ?? ''));
        $tool = trim((string) ($operation['tool'] ?? ''));
        $arguments = $operation['arguments'] ?? null;
        $dependencies = $operation['dependencies'] ?? null;
        if (preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,63}$/', $id) !== 1
            || ! in_array($tool, HermesSemanticOperation::TOOLS, true)
            || ! is_array($arguments)
            || ($arguments !== [] && array_is_list($arguments))
            || ! is_array($dependencies)
            || ! array_is_list($dependencies)) {
            throw $this->invalid('The durable semantic operation payload failed schema validation.');
        }
        $dependencies = array_values(array_map('strval', $dependencies));
        $this->validateArguments($tool, $arguments);
        if (array_key_exists('result_ref', $arguments)) {
            throw $this->invalid("Semantic operation {$id} has an unsealed mutation target.");
        }
        $normalized = compact('id', 'tool', 'arguments', 'dependencies');
        $expectedHash = trim((string) data_get($run->metadata, 'semantic_operation_hash', ''));
        $actualHash = hash('sha256', json_encode($normalized, JSON_THROW_ON_ERROR));
        if ($expectedHash === '' || ! hash_equals($expectedHash, $actualHash)) {
            throw new VoiceTurnConflictException('The durable semantic operation payload does not match its sealed hash.');
        }

        return $normalized;
    }

    /**
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @return array<string,array<string,mixed>>
     */
    private function dependencyReceipts(AssistantRun $run, array $operation): array
    {
        $runMap = data_get($run->metadata, 'dependency_run_map');
        if (! is_array($runMap)) {
            $runMap = [];
        }
        $receipts = [];
        foreach ($operation['dependencies'] as $dependencyOperationId) {
            $dependencyRunId = (int) ($runMap[$dependencyOperationId] ?? 0);
            $dependency = $dependencyRunId > 0
                ? AssistantRun::query()
                    ->whereKey($dependencyRunId)
                    ->where('voice_turn_id', $run->voice_turn_id)
                    ->first()
                : null;
            if (! $dependency instanceof AssistantRun
                || ! in_array($dependency->status, ['completed', 'failed', 'cancelled'], true)) {
                throw new VoiceTurnConflictException("Semantic operation {$operation['id']} has an unterminated dependency.");
            }
            $receipt = $this->receiptForRun($dependency);
            if (! is_array($receipt) || ($receipt['operation_id'] ?? null) !== $dependencyOperationId) {
                throw new VoiceTurnConflictException("Semantic operation {$operation['id']} dependency has no matching terminal receipt.");
            }
            $receipts[$dependencyOperationId] = $receipt;
        }

        return $receipts;
    }

    /** @param array<string,mixed> $receipt @return array<string,mixed> */
    private function persistReceipt(AssistantRun $run, array $receipt): array
    {
        return $this->lifecycle->sealSemanticOperationReceipt($run, $receipt);
    }

    /**
     * Persist one generic semantic operation receipt behind the generic run's
     * current execution claim. The execution key deliberately excludes the
     * worker generation so recovery can observe a prior committed operation
     * instead of executing it again.
     *
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @return array<string,mixed>
     */
    private function genericOperationReceipt(
        AssistantRunExecutionClaim $claim,
        array $operation,
        string $status,
        array $data,
        bool $sideEffectCommitted,
    ): array {
        return $this->assistantRuns->withExecutionClaim(
            $claim,
            function (ConversationSession $_session, AssistantRun $lockedRun) use (
                $claim,
                $operation,
                $status,
                $data,
                $sideEffectCommitted,
            ): array {
                if (($existing = $this->genericReceiptForOperation($lockedRun, $operation)) !== null) {
                    return $existing;
                }

                return $this->persistGenericReceipt(
                    $lockedRun,
                    $claim,
                    $operation,
                    $this->makeReceipt($operation, $status, $data, $sideEffectCommitted),
                );
            },
        );
    }

    /**
     * External provider I/O is intentionally between two short claim checks;
     * no session/run lock remains held while the provider is in flight.
     *
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @return array<string,mixed>
     */
    private function executeGenericExternalOperation(
        HermesSemanticExecutionContext $context,
        AssistantRunExecutionClaim $claim,
        array $operation,
    ): array {
        $existing = $this->assistantRuns->withExecutionClaim(
            $claim,
            fn (ConversationSession $_session, AssistantRun $lockedRun): ?array => $this->genericReceiptForOperation($lockedRun, $operation),
        );
        if (is_array($existing)) {
            return $existing;
        }

        $timeoutSeconds = max(1, (int) config(
            'services.hermes_runtime.semantic_external_operation_timeout_seconds',
            20,
        ));
        $data = $this->externalLookup(
            $context->session,
            $operation['arguments'],
            now()->addSeconds($timeoutSeconds),
            $context->timezone,
        );
        $receipt = $this->makeReceipt($operation, 'completed', $data, false);

        return $this->assistantRuns->withExecutionClaim(
            $claim,
            function (ConversationSession $_session, AssistantRun $lockedRun) use (
                $claim,
                $operation,
                $receipt,
            ): array {
                if (($existing = $this->genericReceiptForOperation($lockedRun, $operation)) !== null) {
                    return $existing;
                }

                return $this->persistGenericReceipt($lockedRun, $claim, $operation, $receipt);
            },
        );
    }

    /**
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @return array<string,mixed>|null
     */
    private function genericReceiptForOperation(AssistantRun $run, array $operation): ?array
    {
        $event = ActivityEvent::query()
            ->where('conversation_session_id', $run->conversation_session_id)
            ->where('event_type', 'assistant.semantic_operation.receipt')
            ->where('payload->execution_key', $this->genericExecutionKey($run, $operation))
            ->latest('id')
            ->first();
        $receipt = data_get($event?->payload, 'receipt');

        return is_array($receipt) ? $receipt : null;
    }

    /**
     * Caller must already be inside AssistantRunService::withExecutionClaim.
     *
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @param  array<string,mixed>  $receipt
     * @return array<string,mixed>
     */
    private function persistGenericReceipt(
        AssistantRun $run,
        AssistantRunExecutionClaim $claim,
        array $operation,
        array $receipt,
    ): array {
        if ((int) $run->id !== $claim->runId
            || ($receipt['operation_id'] ?? null) !== $operation['id']
            || ($receipt['tool'] ?? null) !== $operation['tool']) {
            throw new AssistantRunClaimLostException;
        }

        ActivityEvent::query()->create([
            'user_id' => $run->user_id,
            'workspace_id' => $run->workspace_id,
            'conversation_session_id' => $run->conversation_session_id,
            'event_type' => 'assistant.semantic_operation.receipt',
            'tool_name' => $operation['tool'],
            'status' => ($receipt['status'] ?? null) === 'completed' ? 'succeeded' : (string) ($receipt['status'] ?? 'failed'),
            'payload' => [
                'execution_key' => $this->genericExecutionKey($run, $operation),
                'assistant_run_id' => $run->id,
                'operation_id' => $operation['id'],
                'tool' => $operation['tool'],
                'operation_label' => $this->operationLabel($operation['tool']),
                'operation_hash' => $this->genericOperationHash($operation),
                'execution_generation' => $claim->generation,
                'receipt' => $receipt,
            ],
        ]);

        return $receipt;
    }

    /** @param array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>} $operation */
    private function genericExecutionKey(AssistantRun $run, array $operation): string
    {
        return hash('sha256', implode('|', [
            'semantic-operation-v1',
            (string) $run->id,
            $operation['id'],
            $this->genericOperationHash($operation),
        ]));
    }

    /** @param array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>} $operation */
    private function genericOperationHash(array $operation): string
    {
        return hash('sha256', json_encode($operation, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @param  array<string,mixed>  $data
     * @return array<string,mixed>
     */
    private function makeReceipt(
        array $operation,
        string $status,
        array $data,
        bool $sideEffectCommitted,
    ): array {
        return [
            'operation_id' => $operation['id'],
            'tool' => $operation['tool'],
            'status' => $status,
            'data' => $data,
            'side_effect_committed' => $sideEffectCommitted,
            'completed_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @param  array{id:string,tool:string,arguments:array<string,mixed>,dependencies:array<int,string>}  $operation
     * @return array<string,mixed>
     */
    private function executeOperation(HermesSemanticExecutionContext|VoiceTurn $turn, AssistantRun $run, array $operation): array
    {
        $tool = $operation['tool'];
        $arguments = $operation['arguments'];
        $session = ConversationSession::query()->findOrFail($turn->conversation_session_id);

        return match ($tool) {
            'system.clock.read' => $this->clock($turn, $arguments),
            'system.voice_state.read' => $this->voiceState($turn),
            'app.task.search' => $this->searchTasks($turn, $arguments),
            'app.reminder.search' => $this->searchReminders($turn, $arguments),
            'app.calendar.search' => $this->searchCalendar($turn, $arguments),
            'app.note.search' => $this->searchNotes($turn, $arguments),
            'app.memory.search' => $this->searchMemory($turn, $arguments),
            'app.history.search' => $this->searchHistory($turn, $run, $arguments),
            'app.activity.search' => $this->searchActivity($turn, $run, $arguments),
            'app.day.read' => $this->readDay($turn, $arguments),
            'app.memory.create', 'app.memory.update', 'app.memory.delete' => $this->executeMemoryWrite(
                $turn,
                $run,
                $session,
                $tool,
                $arguments,
            ),
            'external.lookup' => throw new VoiceTurnConflictException('External lookup must use the lock-free provider execution boundary.'),
            'voice.playback.stop' => $this->stopPlayback($turn, $run),
            'voice.work.status' => $this->workStatus($turn, $arguments),
            'voice.work.cancel' => $this->cancelWork($turn, $arguments),
            default => $this->executeStructuredWrite($session, $tool, $arguments),
        };
    }

    /** @param array<string,mixed> $arguments */
    private function clock(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $timezone = $this->timezone($turn);
        if ($timezone === null) {
            throw $this->missingTimezone('system.clock.read');
        }
        $now = now($timezone);

        return [
            'kind' => $arguments['kind'],
            'current_time' => $now->toIso8601String(),
            'timezone' => $timezone,
            'date' => $now->toDateString(),
            'day_of_week' => $now->format('l'),
        ];
    }

    private function voiceState(HermesSemanticExecutionContext|VoiceTurn $turn): array
    {
        $client = data_get($turn->metadata, 'client_context');
        $client = is_array($client) ? $client : [];

        return [
            'voice_mode_active' => is_bool($client['voice_mode_active'] ?? null)
                ? $client['voice_mode_active']
                : null,
            'request_state' => $turn instanceof VoiceTurn
                ? $turn->state->value
                : $turn->voiceTurn?->state?->value,
            'wake_detection_enabled' => is_bool($client['wake_detection_enabled'] ?? null)
                ? $client['wake_detection_enabled']
                : null,
            'playback_state' => trim((string) ($client['playback_state'] ?? 'unknown')) ?: 'unknown',
            'raw_audio_retained' => false,
        ];
    }

    /** @param array<string,mixed> $arguments */
    private function searchTasks(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $query = Task::query()->where('user_id', $turn->user_id)->where('workspace_id', $turn->workspace_id);
        $this->applyIdsAndText($query, $arguments);
        $this->applyStatus($query, $arguments);
        $this->applyRange($query, $arguments, 'due_at');
        $items = $query->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit($this->limit($arguments))->get();

        return $this->searchResult($arguments, $items->map(fn (Task $item): array => [
            'id' => $item->id, 'title' => $item->title, 'status' => $item->status,
            'recurrence' => ($item->metadata ?? [])['recurrence'] ?? null,
            'due_at' => $item->due_at?->toIso8601String(), 'completed_at' => $item->completed_at?->toIso8601String(),
        ])->all());
    }

    /** @param array<string,mixed> $arguments */
    private function searchReminders(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $query = Reminder::query()->where('user_id', $turn->user_id)->where('workspace_id', $turn->workspace_id);
        $this->applyIdsAndText($query, $arguments);
        $this->applyStatus($query, $arguments);
        $this->applyRange($query, $arguments, 'remind_at');
        $items = $query->orderBy('remind_at')->orderBy('id')->limit($this->limit($arguments))->get();

        return $this->searchResult($arguments, $items->map(fn (Reminder $item): array => [
            'id' => $item->id, 'title' => $item->title, 'status' => $item->status,
            'recurrence' => ($item->metadata ?? [])['recurrence'] ?? null,
            'remind_at' => $item->remind_at?->toIso8601String(),
        ])->all());
    }

    /** @param array<string,mixed> $arguments */
    private function searchCalendar(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $query = CalendarEvent::query()->where('user_id', $turn->user_id)->where('workspace_id', $turn->workspace_id);
        $this->applyIdsAndText($query, $arguments);
        $this->applyStatus($query, $arguments);
        $this->applyRange($query, $arguments, 'starts_at');
        $items = $query->orderBy('starts_at')->orderBy('id')->limit($this->limit($arguments))->get();

        return $this->searchResult($arguments, $items->map(fn (CalendarEvent $item): array => [
            'id' => $item->id, 'title' => $item->title, 'status' => $item->status, 'location' => $item->location,
            'recurrence' => $item->recurrence, 'all_day' => ($item->metadata ?? [])['all_day'] ?? null,
            'starts_at' => $item->starts_at?->toIso8601String(), 'ends_at' => $item->ends_at?->toIso8601String(),
        ])->all());
    }

    /** @param array<string,mixed> $arguments */
    private function searchNotes(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $user = User::query()->findOrFail($turn->user_id);
        if (! $this->planLimits->canUseNotes($user)) {
            throw new HermesSemanticOperationException(
                'subscription_limit_reached',
                'The current subscription does not include notes.',
            );
        }
        $query = Note::query()->where('user_id', $turn->user_id)->where('workspace_id', $turn->workspace_id);
        $ids = $this->ids($arguments);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $text = trim((string) ($arguments['query'] ?? ''));
        if ($text !== '') {
            if (($arguments['match_mode'] ?? null) === 'exact_title') {
                $query->where('title', $text);
            } else {
                $escaped = addcslashes($text, '%_\\');
                $query->where(fn (Builder $candidate): Builder => $candidate
                    ->where('title', 'like', "%{$escaped}%")
                    ->orWhere('plain_text', 'like', "%{$escaped}%"));
            }
        }
        $items = $query->latest('updated_at')->limit($this->limit($arguments))->get();

        return $this->searchResult($arguments, $items->map(fn (Note $item): array => [
            'id' => $item->id, 'title' => $item->title,
            'plain_text' => mb_substr(trim((string) $item->plain_text), 0, 2000),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ])->all());
    }

    /** @param array<string,mixed> $arguments */
    private function searchMemory(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $query = MemoryItem::query()
            ->where('user_id', $turn->user_id)
            ->where('workspace_id', $turn->workspace_id)
            ->where('status', 'active')
            ->where(fn (Builder $candidate): Builder => $candidate
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
        $ids = $this->ids($arguments);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        if (array_key_exists('type', $arguments)) {
            $query->where('type', $arguments['type']);
        }
        $text = trim((string) ($arguments['query'] ?? ''));
        if ($text !== '') {
            $matchMode = $arguments['match_mode'] ?? null;
            if ($matchMode === 'exact_title') {
                $query->where('title', $text);
            } elseif ($matchMode === 'exact_content') {
                $query->where('content', $text);
            } else {
                $escaped = addcslashes($text, '%_\\');
                $query->where(fn (Builder $candidate): Builder => $candidate
                    ->where('title', 'like', "%{$escaped}%")
                    ->orWhere('content', 'like', "%{$escaped}%")
                    ->orWhere('summary', 'like', "%{$escaped}%"));
            }
        }
        $items = $query
            ->orderByDesc('importance')
            ->orderByDesc('confidence')
            ->latest('updated_at')
            ->limit($this->limit($arguments))
            ->get();

        return $this->searchResult($arguments, $items->map(
            fn (MemoryItem $item): array => $this->memoryPayload($item),
        )->all());
    }

    /** @param array<string,mixed> $arguments */
    private function searchHistory(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        AssistantRun $run,
        array $arguments,
    ): array {
        $currentMessage = ConversationMessage::query()->find($run->user_message_id);
        $boundaryId = $currentMessage instanceof ConversationMessage
            ? (int) data_get($currentMessage->metadata, 'edited_from_message_id', $currentMessage->id)
            : 0;
        if ($currentMessage instanceof ConversationMessage && $boundaryId <= 0) {
            $boundaryId = (int) $currentMessage->id;
        }
        $query = ConversationMessage::query()
            ->where('user_id', $turn->user_id)
            ->where('role', 'user')
            ->whereHas('session', fn (Builder $session): Builder => $session
                ->where('workspace_id', $turn->workspace_id));
        if ($currentMessage instanceof ConversationMessage) {
            $query->whereKeyNot($currentMessage->id)
                ->where(function (Builder $candidate) use ($turn, $boundaryId): void {
                    $candidate->where('conversation_session_id', '!=', $turn->conversation_session_id)
                        ->orWhere(function (Builder $sameSession) use ($turn, $boundaryId): void {
                            $sameSession->where('conversation_session_id', $turn->conversation_session_id)
                                ->where('id', '<', $boundaryId);
                        });
                });
        }
        $text = trim((string) ($arguments['query'] ?? ''));
        if ($text !== '') {
            $query->where('content', 'like', '%'.addcslashes($text, '%_\\').'%');
        }
        $this->applyRange($query, $arguments, 'created_at');
        $items = $query->latest('created_at')->latest('id')->limit($this->limit($arguments))->get()
            ->sortBy('id')
            ->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'conversation_session_id' => $message->conversation_session_id,
                'content' => mb_substr(trim((string) $message->content), 0, 1500),
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ['items' => $items, 'count' => count($items)];
    }

    /** @param array<string,mixed> $arguments */
    private function searchActivity(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        AssistantRun $run,
        array $arguments,
    ): array {
        $query = ActivityEvent::query()
            ->where('user_id', $turn->user_id)
            ->where('workspace_id', $turn->workspace_id)
            ->where(function (Builder $candidate) use ($run): void {
                $candidate->whereNull('payload->assistant_run_id')
                    ->orWhere('payload->assistant_run_id', '!=', $run->id);
            });
        if (trim((string) ($arguments['event_type'] ?? '')) !== '') {
            $query->where('event_type', 'like', '%'.addcslashes((string) $arguments['event_type'], '%_\\').'%');
        }
        if (trim((string) ($arguments['tool_name'] ?? '')) !== '') {
            $query->where('tool_name', trim((string) $arguments['tool_name']));
        }
        $this->applyRange($query, $arguments, 'created_at');
        $items = $query->latest('created_at')->latest('id')->limit($this->limit($arguments))->get()
            ->sortBy('id')
            ->map(fn (ActivityEvent $event): array => [
                'id' => $event->id,
                'conversation_session_id' => $event->conversation_session_id,
                'event_type' => $event->event_type,
                'tool_name' => $event->tool_name,
                'status' => $event->status,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ['items' => $items, 'count' => count($items)];
    }

    /** @param array<string,mixed> $arguments */
    private function readDay(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $timezone = $this->timezone($turn);
        if ($timezone === null) {
            throw $this->missingTimezone('app.day.read');
        }
        $date = (string) $arguments['date'];
        $from = Carbon::createFromFormat('Y-m-d', $date, $timezone)->startOfDay();
        $to = $from->copy()->endOfDay();
        $range = [
            'from' => $from->toIso8601String(),
            'to' => $to->toIso8601String(),
            'limit' => 20,
        ];

        return [
            'date' => $date,
            'timezone' => $timezone,
            'tasks' => $this->searchTasks($turn, [...$range, 'status' => 'open'])['items'],
            'reminders' => $this->searchReminders($turn, $range)['items'],
            'calendar_events' => $this->searchCalendar($turn, $range)['items'],
        ];
    }

    /** @param array<string,mixed> $arguments */
    private function externalLookup(
        ConversationSession $session,
        array $arguments,
        Carbon $hardDeadlineAt,
        ?string $trustedTimezone,
    ): array {
        $kind = trim((string) ($arguments['kind'] ?? ''));
        if (in_array($kind, ['weather', 'forecast'], true)) {
            $hasLocation = trim((string) ($arguments['location'] ?? '')) !== '';
            $hasCoordinates = isset($arguments['latitude'], $arguments['longitude'])
                && is_numeric($arguments['latitude'])
                && is_numeric($arguments['longitude']);
            if (! $hasLocation && ! $hasCoordinates) {
                throw $this->invalid('A typed weather lookup requires an explicit Hermes location or coordinate pair.');
            }
        }

        // Provider failures are terminal operation facts, not application-authored
        // conversation failures. Preserve the structured receipt so Hermes can
        // explain it or ask the natural follow-up without a second fallback path.
        return $this->lookups->lookupTyped($session, $arguments, $hardDeadlineAt, $trustedTimezone);
    }

    private function stopPlayback(HermesSemanticExecutionContext|VoiceTurn $turn, AssistantRun $run): array
    {
        $voiceTurn = $turn instanceof VoiceTurn ? $turn : $turn->voiceTurn;
        if (! $voiceTurn instanceof VoiceTurn) {
            throw $this->invalid('Playback Stop requires an active voice turn.');
        }
        $directive = $this->lifecycle->issuePlaybackStopDirective($voiceTurn, $run);

        return [
            'stopped' => true,
            'directive_id' => $directive['id'],
            'changed' => false,
            'background_work_canceled' => false,
        ];
    }

    /** @param array<string,mixed> $arguments */
    private function workStatus(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $query = VoiceTurn::query()
            ->where('user_id', $turn->user_id)
            ->where('conversation_session_id', $turn->conversation_session_id);
        $currentVoiceTurnId = $turn instanceof VoiceTurn ? (int) $turn->id : (int) ($turn->voiceTurn?->id ?? 0);
        if ($currentVoiceTurnId > 0) {
            $query->where('id', '!=', $currentVoiceTurnId);
        }
        $targetId = trim((string) ($arguments['target_turn_id'] ?? ''));
        $target = $targetId !== ''
            ? $query->where('turn_id', $targetId)->first()
            : $query->latest('id')->first();

        return $target instanceof VoiceTurn ? [
            'found' => true,
            'stable_turn_id' => $target->turn_id,
            'state' => $target->state->value,
            'side_effect_status' => $target->side_effect_status->value,
            'final_text' => $target->finalAssistantMessage()->value('content'),
        ] : ['found' => false];
    }

    /** @param array<string,mixed> $arguments */
    private function cancelWork(HermesSemanticExecutionContext|VoiceTurn $turn, array $arguments): array
    {
        $query = VoiceTurn::query()
            ->where('user_id', $turn->user_id)
            ->where('conversation_session_id', $turn->conversation_session_id);
        $currentVoiceTurnId = $turn instanceof VoiceTurn ? (int) $turn->id : (int) ($turn->voiceTurn?->id ?? 0);
        if ($currentVoiceTurnId > 0) {
            $query->where('id', '!=', $currentVoiceTurnId);
        }
        $targetId = trim((string) ($arguments['target_turn_id'] ?? ''));
        $targets = ($arguments['all'] ?? false) === true
            ? $query->whereIn('state', [
                VoiceTurnState::AwaitingClarification->value,
                VoiceTurnState::Accepted->value,
                VoiceTurnState::Running->value,
            ])->get()
            : $query->where('turn_id', $targetId)->get();
        $outcomes = $targets->map(function (VoiceTurn $target) use ($turn): VoiceTurn {
            try {
                return $this->lifecycle->cancel($target, 'semantic_voice_cancellation', [
                    'cancellation_turn_id' => $turn->turn_id,
                ]);
            } catch (VoiceTurnConflictException) {
                return $target->fresh();
            }
        });
        $facts = $outcomes->map(function (VoiceTurn $outcome): array {
            $committedOperationIds = $this->committedOperationIds($outcome);

            return [
                'stable_turn_id' => $outcome->turn_id,
                'state' => $outcome->state->value,
                'side_effect_status' => $outcome->side_effect_status->value,
                'canceled' => $outcome->state === VoiceTurnState::Canceled,
                'completed_before_cancellation' => $outcome->state === VoiceTurnState::Completed,
                'partially_committed' => $outcome->state === VoiceTurnState::Canceled
                    && $outcome->side_effect_status === VoiceTurnSideEffectStatus::Committed,
                'committed_operation_ids' => $committedOperationIds,
            ];
        })->values();
        $canceled = $facts->where('canceled', true);
        $cancellationSucceeded = $canceled->isNotEmpty();

        return [
            'changed' => $cancellationSucceeded,
            'canceled' => $cancellationSucceeded,
            'completed_before_cancellation' => $facts->contains('completed_before_cancellation', true),
            'partially_committed' => $canceled->contains('partially_committed', true),
            'committed_operation_ids' => $facts
                ->pluck('committed_operation_ids')
                ->flatten()
                ->unique()
                ->values()
                ->all(),
            'canceled_turn_ids' => $canceled->pluck('stable_turn_id')->values()->all(),
            'requested_count' => $targets->count(),
            'target_outcomes' => $facts->all(),
        ];
    }

    /** @return list<string> */
    private function committedOperationIds(VoiceTurn $turn): array
    {
        return $turn->runs()
            ->where('handler', self::OPERATION_HANDLER)
            ->orderBy('id')
            ->get()
            ->map(function (AssistantRun $run): ?string {
                $receipt = $this->receiptForRun($run);
                if (! is_array($receipt) || ($receipt['side_effect_committed'] ?? false) !== true) {
                    return null;
                }

                $operationId = trim((string) ($receipt['operation_id'] ?? ''));

                return $operationId !== '' ? $operationId : null;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /** @param array<string,mixed> $arguments */
    private function executeMemoryWrite(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        AssistantRun $run,
        ConversationSession $session,
        string $tool,
        array $arguments,
    ): array {
        // Serializing explicit memory mutations per user makes duplicate
        // prevention deterministic without choosing or interpreting a target.
        User::query()->whereKey($turn->user_id)->lockForUpdate()->firstOrFail();

        return match ($tool) {
            'app.memory.create' => $this->createMemory($turn, $run, $session, $arguments),
            'app.memory.update' => $this->updateMemory($turn, $session, $arguments),
            'app.memory.delete' => $this->deleteMemory($turn, $session, $arguments),
            default => throw $this->invalid("{$tool} is not an explicit memory mutation."),
        };
    }

    /** @param array<string,mixed> $arguments */
    private function createMemory(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        AssistantRun $run,
        ConversationSession $session,
        array $arguments,
    ): array {
        $content = (string) $arguments['content'];
        $existing = $this->activeMemoryQuery($turn)
            ->where('type', $arguments['type'])
            ->whereRaw('lower(trim(content)) = ?', [mb_strtolower(trim($content))])
            ->orderBy('id')
            ->first();
        if ($existing instanceof MemoryItem) {
            return [
                'changed' => false,
                'created' => false,
                'duplicate_prevented' => true,
                'memory_item' => $this->memoryPayload($existing),
                'events' => [],
            ];
        }

        $attributes = [
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'created_by_user_id' => $turn->user_id,
            'type' => $arguments['type'],
            'status' => 'active',
            'visibility' => 'workspace',
            'content' => $content,
            'source_type' => 'browser_voice_semantic',
            'source_id' => $run->id,
            'last_seen_at' => now(),
        ];
        foreach (['title', 'summary', 'confidence', 'importance'] as $field) {
            if (array_key_exists($field, $arguments)) {
                $attributes[$field] = $arguments[$field];
            }
        }
        if (array_key_exists('expires_at', $arguments)) {
            $attributes['expires_at'] = $arguments['expires_at'] === null
                ? null
                : Carbon::parse((string) $arguments['expires_at'])->utc();
        }

        $item = MemoryItem::query()->create($attributes);
        $event = $this->recordMemoryEvent($session, 'assistant.memory.created', 'memory.create', $item);

        return $this->memoryMutationResult($item, $event, ['created' => true]);
    }

    /** @param array<string,mixed> $arguments */
    private function updateMemory(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        ConversationSession $session,
        array $arguments,
    ): array {
        $item = $this->memoryForMutation($turn, (int) $arguments['id']);
        $updates = [];
        foreach (['type', 'title', 'content', 'summary', 'confidence', 'importance'] as $field) {
            if (array_key_exists($field, $arguments)) {
                $updates[$field] = $arguments[$field];
            }
        }
        if (array_key_exists('expires_at', $arguments)) {
            $updates['expires_at'] = $arguments['expires_at'] === null
                ? null
                : Carbon::parse((string) $arguments['expires_at'])->utc();
        }

        $effectiveType = (string) ($updates['type'] ?? $item->type);
        $effectiveContent = (string) ($updates['content'] ?? $item->content);
        $duplicate = $this->activeMemoryQuery($turn)
            ->whereKeyNot($item->id)
            ->where('type', $effectiveType)
            ->whereRaw('lower(trim(content)) = ?', [mb_strtolower(trim($effectiveContent))])
            ->exists();
        if ($duplicate) {
            throw new HermesSemanticOperationException(
                'duplicate_memory_item',
                'The explicit memory update would duplicate another active memory item.',
            );
        }

        $item->fill($updates);
        if (! $item->isDirty()) {
            return [
                'changed' => false,
                'updated' => false,
                'memory_item' => $this->memoryPayload($item),
                'events' => [],
            ];
        }
        $item->save();
        $event = $this->recordMemoryEvent($session, 'assistant.memory.updated', 'memory.update', $item);

        return $this->memoryMutationResult($item->refresh(), $event, ['updated' => true]);
    }

    /** @param array<string,mixed> $arguments */
    private function deleteMemory(
        HermesSemanticExecutionContext|VoiceTurn $turn,
        ConversationSession $session,
        array $arguments,
    ): array {
        $item = $this->memoryForMutation($turn, (int) $arguments['id']);
        $payload = $this->memoryPayload($item);
        $item->forceFill(['status' => 'archived'])->save();
        $item->delete();
        $event = $this->recordMemoryEvent(
            $session,
            'assistant.memory.deleted',
            'memory.delete',
            $item,
            $payload,
        );

        return [
            'changed' => true,
            'deleted' => true,
            'memory_item' => $payload,
            'events' => [$this->activityEventPayload($event)],
        ];
    }

    /** @return Builder<MemoryItem> */
    private function activeMemoryQuery(HermesSemanticExecutionContext|VoiceTurn $turn): Builder
    {
        return MemoryItem::query()
            ->where('user_id', $turn->user_id)
            ->where('workspace_id', $turn->workspace_id)
            ->where('status', 'active')
            ->where(fn (Builder $candidate): Builder => $candidate
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()));
    }

    private function memoryForMutation(HermesSemanticExecutionContext|VoiceTurn $turn, int $id): MemoryItem
    {
        $item = $this->activeMemoryQuery($turn)
            ->whereKey($id)
            ->lockForUpdate()
            ->first();
        if (! $item instanceof MemoryItem) {
            throw new HermesSemanticOperationException(
                'memory_target_unavailable',
                'The authorized memory target is no longer active or available.',
            );
        }

        return $item;
    }

    /** @param array<string,mixed>|null $payload */
    private function recordMemoryEvent(
        ConversationSession $session,
        string $eventType,
        string $toolName,
        MemoryItem $item,
        ?array $payload = null,
    ): ActivityEvent {
        return ActivityEvent::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => $eventType,
            'tool_name' => $toolName,
            'status' => 'succeeded',
            'payload' => $payload ?? $this->memoryPayload($item),
        ]);
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    private function memoryMutationResult(MemoryItem $item, ActivityEvent $event, array $extra): array
    {
        return [
            'changed' => true,
            ...$extra,
            'memory_item' => $this->memoryPayload($item),
            'events' => [$this->activityEventPayload($event)],
        ];
    }

    /** @return array<string,mixed> */
    private function activityEventPayload(ActivityEvent $event): array
    {
        return [
            'id' => $event->id,
            'type' => $event->event_type,
            'status' => $event->status,
            'data' => $event->payload,
        ];
    }

    /** @return array<string,mixed> */
    private function memoryPayload(MemoryItem $item): array
    {
        return [
            'id' => $item->id,
            'memory_item_id' => $item->id,
            'type' => $item->type,
            'title' => $item->title,
            'content' => mb_substr((string) $item->content, 0, 4000),
            'summary' => $item->summary !== null
                ? mb_substr((string) $item->summary, 0, 1000)
                : null,
            'confidence' => $item->confidence,
            'importance' => $item->importance,
            'expires_at' => $item->expires_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];
    }

    /** @param array<string,mixed> $arguments */
    private function executeStructuredWrite(ConversationSession $session, string $tool, array $arguments): array
    {
        if ($tool === 'app.note.create') {
            $message = $this->planLimits->noteCreationUpgradeMessage(User::findOrFail($session->user_id));
            if ($message !== null) {
                throw new HermesSemanticOperationException(
                    'subscription_limit_reached',
                    $message,
                );
            }
        }
        $this->lockStructuredWriteTarget($session, $tool, $arguments);
        $type = match ($tool) {
            'app.calendar.create' => 'calendar_event.create',
            'app.calendar.update' => 'calendar_event.update',
            'app.calendar.delete' => 'calendar_event.delete',
            'app.conversation.update' => 'conversation_session.update',
            default => substr($tool, 4),
        };
        try {
            $events = $this->actions->applyCanonicalSemanticAction($session, $type, $arguments);
        } catch (ModelNotFoundException $exception) {
            if (! $this->isStructuredResourceModel($exception->getModel())) {
                throw $exception;
            }

            throw $this->staleTarget();
        }
        $failed = $events->first(fn ($event): bool => ! in_array((string) $event->status, ['succeeded', 'recorded'], true));
        if ($events->isEmpty() || $failed !== null) {
            throw new HermesSemanticOperationException(
                'typed_operation_failed',
                (string) data_get($failed?->payload, 'reason', "The {$tool} operation did not produce a success receipt."),
            );
        }

        return [
            'changed' => true,
            'events' => $events->map(fn ($event): array => [
                'id' => $event->id,
                'type' => $event->event_type,
                'status' => $event->status,
                'data' => $event->payload,
            ])->all(),
        ];
    }

    /** @param array<string,mixed> $arguments */
    private function lockStructuredWriteTarget(ConversationSession $session, string $tool, array $arguments): void
    {
        if (! $this->isIdTargetedMutation($tool)) {
            return;
        }

        $model = match (true) {
            str_starts_with($tool, 'app.task.') => Task::class,
            str_starts_with($tool, 'app.reminder.') => Reminder::class,
            str_starts_with($tool, 'app.calendar.') => CalendarEvent::class,
            str_starts_with($tool, 'app.note.') => Note::class,
            str_starts_with($tool, 'app.note_folder.') => NoteFolder::class,
            str_starts_with($tool, 'app.event_category.') => EventCategory::class,
            str_starts_with($tool, 'app.blocker.') => Blocker::class,
            default => null,
        };
        if ($model === null) {
            return;
        }

        $target = $model::query()
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->whereKey((int) $arguments['id'])
            ->lockForUpdate()
            ->first();
        if (! $target instanceof Model) {
            throw $this->staleTarget();
        }
    }

    private function staleTarget(): HermesSemanticOperationException
    {
        return new HermesSemanticOperationException(
            'stale_target',
            'target_changed_after_staging',
        );
    }

    /** @param class-string<Model>|null $model */
    private function isStructuredResourceModel(?string $model): bool
    {
        return in_array($model, [
            Task::class,
            Reminder::class,
            CalendarEvent::class,
            Note::class,
            NoteFolder::class,
            EventCategory::class,
            Blocker::class,
        ], true);
    }

    /** @param Builder<Model> $query @param array<string,mixed> $arguments */
    private function applyIdsAndText(Builder $query, array $arguments): void
    {
        $ids = $this->ids($arguments);
        if ($ids !== []) {
            $query->whereIn('id', $ids);
        }
        $text = trim((string) ($arguments['query'] ?? ''));
        if ($text !== '') {
            if (($arguments['match_mode'] ?? null) === 'exact_title') {
                $query->where('title', $text);
            } else {
                $query->where('title', 'like', '%'.addcslashes($text, '%_\\').'%');
            }
        }
    }

    /**
     * @param  array<string,mixed>  $arguments
     * @param  list<array<string,mixed>>  $items
     * @return array<string,mixed>
     */
    private function searchResult(array $arguments, array $items): array
    {
        $result = [
            'items' => $items,
            'count' => count($items),
        ];
        if (in_array(($arguments['match_mode'] ?? null), ['exact_title', 'exact_content'], true)
            && ($arguments['require_unique'] ?? null) === true) {
            $result['unique'] = count($items) === 1;
            $result['unique_id'] = count($items) === 1 ? (int) ($items[0]['id'] ?? 0) : null;
        }

        return $result;
    }

    /** @param Builder<Model> $query @param array<string,mixed> $arguments */
    private function applyStatus(Builder $query, array $arguments): void
    {
        if (is_string($arguments['status'] ?? null) && trim($arguments['status']) !== '') {
            $query->where('status', trim($arguments['status']));
        }
        if (is_array($arguments['statuses'] ?? null)) {
            $statuses = array_values(array_filter(array_map('strval', $arguments['statuses'])));
            if ($statuses !== []) {
                $query->whereIn('status', $statuses);
            }
        }
    }

    /** @param Builder<Model> $query @param array<string,mixed> $arguments */
    private function applyRange(Builder $query, array $arguments, string $field): void
    {
        if (! empty($arguments['from'])) {
            $query->where($field, '>=', Carbon::parse((string) $arguments['from'])->utc());
        }
        if (! empty($arguments['to'])) {
            $query->where($field, '<=', Carbon::parse((string) $arguments['to'])->utc());
        }
    }

    /** @param array<string,mixed> $arguments @return array<int,int> */
    private function ids(array $arguments): array
    {
        $ids = is_array($arguments['ids'] ?? null) ? $arguments['ids'] : [];
        if (isset($arguments['id'])) {
            $ids[] = $arguments['id'];
        }

        return array_values(array_unique(array_filter(array_map('intval', $ids), fn (int $id): bool => $id > 0)));
    }

    /** @param array<string,mixed> $arguments */
    private function limit(array $arguments): int
    {
        return max(1, min(20, (int) ($arguments['limit'] ?? 10)));
    }

    private function timezone(HermesSemanticExecutionContext|VoiceTurn $turn): ?string
    {
        if ($turn instanceof HermesSemanticExecutionContext) {
            return $turn->timezone;
        }

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
        } catch (Throwable) {
            return null;
        }
    }

    private function invalid(string $detail): HermesSemanticOperationException
    {
        return new HermesSemanticOperationException(
            'invalid_semantic_operation',
            $detail,
        );
    }

    private function missingTimezone(string $tool): HermesSemanticOperationException
    {
        return $this->invalid(match ($tool) {
            'system.clock.read' => 'timezone_required_for_clock_read',
            'app.day.read' => 'timezone_required_for_day_read',
        });
    }
}
