<?php

namespace App\Http\Controllers\Api;

use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\VoiceTurnConflictException;
use App\Http\Controllers\Controller;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\BrowserVoiceComplexPlanService;
use App\Services\BrowserVoiceContextReferenceResolver;
use App\Services\BrowserVoiceInstantHandler;
use App\Services\BrowserVoiceJobPolicy;
use App\Services\BrowserVoiceProjectionService;
use App\Services\BrowserVoiceRequestCompletenessService;
use App\Services\BrowserVoiceV2Gate;
use App\Services\BrowserVoiceWorkReferenceResolver;
use App\Services\BrowserVoiceWorkStatusService;
use App\Services\VoiceTurnLifecycleService;
use App\Services\VoiceTurnPrivacyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BrowserVoiceTurnController extends Controller
{
    public function __construct(
        private readonly BrowserVoiceV2Gate $gate,
        private readonly VoiceTurnLifecycleService $lifecycle,
        private readonly BrowserVoiceProjectionService $projection,
        private readonly BrowserVoiceRequestCompletenessService $completeness,
        private readonly BrowserVoiceContextReferenceResolver $contextReferences,
        private readonly BrowserVoiceComplexPlanService $complexPlans,
        private readonly BrowserVoiceInstantHandler $instantHandler,
        private readonly BrowserVoiceJobPolicy $jobPolicy,
        private readonly BrowserVoiceWorkReferenceResolver $workReferences,
        private readonly BrowserVoiceWorkStatusService $workStatus,
        private readonly VoiceTurnPrivacyService $privacy,
    ) {}

    public function capabilities(Request $request): JsonResponse
    {
        return response()->json(['data' => [
            'browser_voice_v2_enabled' => $this->gate->enabledFor($request->user()),
        ]]);
    }

    public function store(Request $request): JsonResponse
    {
        $this->ensureEnabled($request);
        $this->rejectRawAudio($request->all());
        $data = $request->validate([
            'turn_id' => ['required', 'string', 'min:8', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]+$/'],
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'transcript' => ['required', 'string', 'max:12000'],
            'timezone' => ['sometimes', 'nullable', 'timezone:all'],
            'location_context' => ['sometimes', 'nullable', 'array:label,latitude,longitude,is_local,source'],
            'location_context.label' => ['sometimes', 'nullable', 'string', 'max:180'],
            'location_context.latitude' => ['sometimes', 'nullable', 'numeric', 'between:-90,90'],
            'location_context.longitude' => ['sometimes', 'nullable', 'numeric', 'between:-180,180'],
            'location_context.is_local' => ['sometimes', 'boolean'],
            'location_context.source' => ['sometimes', 'nullable', 'string', 'max:80'],
            'transcript_timing' => ['sometimes', 'nullable', 'array:started_at_ms,final_at_ms,duration_ms,partial_count'],
            'transcript_timing.started_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'transcript_timing.final_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'transcript_timing.duration_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600000'],
            'transcript_timing.partial_count' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:10000'],
            'controller_generation' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'provider_connection_generation' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'conversation_context' => ['sometimes', 'array:mode,epoch'],
            'conversation_context.mode' => ['required_with:conversation_context', 'string', 'in:new_conversation,contextual_follow_up'],
            'conversation_context.epoch' => ['required_with:conversation_context', 'integer', 'min:1'],
            'declared_local_handler' => ['sometimes', 'nullable', 'string', 'in:instant.current_time,instant.current_date,instant.voice_state'],
        ]);
        $session = $this->ownedSession($request, (int) $data['session_id']);
        $existingTurn = VoiceTurn::query()
            ->where('user_id', $request->user()->id)
            ->where('turn_id', $data['turn_id'])
            ->first();
        if ($existingTurn !== null && (
            $existingTurn->conversation_session_id !== $session->id
            || $existingTurn->transcript !== trim((string) $data['transcript'])
        )) {
            return response()->json([
                'message' => 'That voice turn id is already assigned to a different request.',
            ], 409);
        }
        $hasDefaultLocation = filled(data_get($data, 'location_context.label'))
            || (data_get($data, 'location_context.latitude') !== null
                && data_get($data, 'location_context.longitude') !== null);
        $priorTurn = $this->contextReferences->priorTurn($request->user(), $session, $data);
        $contextualFollowUp = $priorTurn instanceof VoiceTurn;
        $contextualReference = data_get($existingTurn?->metadata, 'contextual_reference');
        if (! is_array($contextualReference)) {
            $contextualReference = $priorTurn instanceof VoiceTurn
                ? $this->contextReferences->resolve(
                    $request->user(),
                    $session,
                    $priorTurn,
                    (string) $data['transcript'],
                )
                : null;
        }
        $hasActiveReference = $this->hasUnambiguousActiveContextualReference(
            $request,
            $session,
            (string) $data['transcript'],
        );
        $question = $this->completeness->clarificationQuestion(
            (string) $data['transcript'],
            $hasDefaultLocation,
            $data['timezone'] ?? null,
            true,
            $contextualFollowUp || $hasActiveReference,
            $contextualReference !== null,
        );
        $data['_clarification_question'] = $question;
        $existed = $existingTurn !== null;

        try {
            $turn = $this->lifecycle->admit($request->user(), $session, $data);
            $turn = $this->processAcceptedTurn($turn);
        } catch (VoiceTurnConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->projection->forTurn($turn)], $existed ? 200 : 201);
    }

    public function clarify(Request $request, string $turnId): JsonResponse
    {
        $this->ensureEnabled($request);
        $this->rejectRawAudio($request->all());
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'answer' => ['required', 'string', 'max:12000'],
            'clarification_id' => ['required', 'string', 'min:8', 'max:160', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]+$/'],
        ]);
        $session = $this->ownedSession($request, (int) $data['session_id']);
        $turn = VoiceTurn::query()
            ->where('user_id', $request->user()->id)
            ->where('conversation_session_id', $session->id)
            ->where('turn_id', $turnId)
            ->firstOrFail();

        try {
            $turn = $this->lifecycle->resolveClarification(
                $turn,
                (string) $data['answer'],
                (string) $data['clarification_id'],
            );
            $turn = $this->processAcceptedTurn($turn);
        } catch (VoiceTurnConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->projection->forTurn($turn)]);
    }

    public function state(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'cursor' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'wait' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:2'],
        ]);
        $session = $this->ownedSession($request, (int) $data['session_id']);
        $this->lifecycle->enforceDeadlines(sessionId: $session->id);
        $cursor = (int) ($data['cursor'] ?? 0);
        $waitSeconds = (int) ($data['wait'] ?? 0);
        $waitUntil = microtime(true) + $waitSeconds;
        while ($waitSeconds > 0
            && microtime(true) < $waitUntil
            && ! VoiceTurnEvent::query()
                ->where('conversation_session_id', $session->id)
                ->where('id', '>', $cursor)
                ->exists()) {
            usleep(100_000);
        }
        $projection = $this->projection->forSession($session, $cursor);

        return response()->json(['data' => $projection]);
    }

    public function cancel(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'turn_id' => ['sometimes', 'nullable', 'string', 'max:120'],
            'job_id' => ['sometimes', 'nullable', 'integer', 'exists:assistant_runs,id'],
            'all' => ['sometimes', 'boolean'],
            'reason' => ['sometimes', 'nullable', 'string', 'max:240'],
        ]);
        $session = $this->ownedSession($request, (int) $data['session_id']);
        $targets = (int) (! empty($data['turn_id'])) + (int) (! empty($data['job_id'])) + (int) (($data['all'] ?? false) === true);
        if ($targets !== 1) {
            throw ValidationException::withMessages([
                'target' => 'Provide exactly one of turn_id, job_id, or all=true.',
            ]);
        }

        $reason = trim((string) ($data['reason'] ?? '')) ?: 'user_requested';
        $canceledTurns = collect();
        $canceledJobIds = collect();
        $completedBeforeCancellation = false;
        $targetedTurn = null;

        if (! empty($data['job_id'])) {
            $run = AssistantRun::query()
                ->where('user_id', $request->user()->id)
                ->where('conversation_session_id', $session->id)
                ->whereNotNull('voice_turn_id')
                ->findOrFail((int) $data['job_id']);
            if (in_array($run->status, ['queued', 'running', 'finalizing'], true)) {
                try {
                    $outcome = $this->lifecycle->cancelJob($run, $reason);
                } catch (VoiceTurnConflictException) {
                    $outcome = VoiceTurn::findOrFail($run->voice_turn_id);
                }
                $targetedTurn = $outcome->fresh();
                $freshRun = $run->fresh();
                if ($freshRun instanceof AssistantRun && $freshRun->status === 'cancelled') {
                    $canceledJobIds->push($freshRun->id);
                    if ($targetedTurn instanceof VoiceTurn && $targetedTurn->state === VoiceTurnState::Canceled) {
                        $canceledTurns->push($targetedTurn);
                    }
                } elseif ($freshRun instanceof AssistantRun && $freshRun->status === 'completed') {
                    $completedBeforeCancellation = true;
                }
            } elseif ($run->status === 'completed') {
                $completedBeforeCancellation = true;
            }
        } elseif (! empty($data['turn_id'])) {
            $turn = VoiceTurn::query()
                ->where('user_id', $request->user()->id)
                ->where('conversation_session_id', $session->id)
                ->where('turn_id', $data['turn_id'])
                ->firstOrFail();
            $outcome = $this->cancelTurnSafely($turn, $reason);
            $targetedTurn = $outcome;
            if ($outcome->state === VoiceTurnState::Canceled) {
                $canceledTurns->push($outcome);
            } elseif ($outcome->state === VoiceTurnState::Completed) {
                $completedBeforeCancellation = true;
            }
        } else {
            VoiceTurn::query()
                ->where('user_id', $request->user()->id)
                ->where('conversation_session_id', $session->id)
                ->whereIn('state', [
                    VoiceTurnState::AwaitingClarification->value,
                    VoiceTurnState::Accepted->value,
                    VoiceTurnState::Running->value,
                ])
                ->orderBy('id')
                ->get()
                ->each(function (VoiceTurn $turn) use ($canceledTurns, $reason, &$completedBeforeCancellation): void {
                    $outcome = $this->cancelTurnSafely($turn, $reason);
                    if ($outcome->state === VoiceTurnState::Canceled) {
                        $canceledTurns->push($outcome);
                    } elseif ($outcome->state === VoiceTurnState::Completed) {
                        $completedBeforeCancellation = true;
                    }
                });
        }

        $state = $this->projection->forSession($session);
        $state['turn'] = $targetedTurn instanceof VoiceTurn
            ? $this->projection->forTurn($targetedTurn)['turn']
            : ($canceledTurns->count() === 1
                ? $this->projection->forTurn($canceledTurns->first())['turn']
                : null);
        $state['canceled_turn_ids'] = $canceledTurns->pluck('turn_id')->values()->all();
        $state['canceled_job_ids'] = $canceledJobIds->unique()->values()->all();
        $partiallyCommitted = $canceledTurns->contains(
            fn (VoiceTurn $turn): bool => $turn->side_effect_status === VoiceTurnSideEffectStatus::Committed,
        );
        $cancellationSucceeded = $canceledTurns->isNotEmpty() || $canceledJobIds->isNotEmpty();
        $state['confirmation_text'] = ! $cancellationSucceeded
            ? ($completedBeforeCancellation
                ? 'That work had already finished, so I couldn’t cancel it.'
                : 'There was no active work to cancel.')
            : ($partiallyCommitted
                ? 'I canceled the remaining work, but part of that request had already finished and couldn’t be undone.'
                : ($completedBeforeCancellation
                    ? 'I canceled the active work, but some work had already finished.'
                    : 'Canceled.'));

        return response()->json(['data' => $state]);
    }

    public function delivery(Request $request, string $turnId): JsonResponse
    {
        $this->rejectRawAudio($request->all());
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'event' => ['required', 'string', 'in:acknowledgement_started,final_text_delivered,final_audio_started,playback_started,playback_finished,playback_stopped,potential_interruption,interruption_confirmed,interruption_rejected'],
            'timing' => ['sometimes', 'nullable', 'array:latency_ms,occurred_at_ms,speech_item_id,controller_generation,provider_connection_generation,purpose,reason,error_code,error_message'],
            'timing.latency_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600000'],
            'timing.occurred_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timing.speech_item_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'timing.controller_generation' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timing.provider_connection_generation' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timing.purpose' => ['sometimes', 'nullable', 'string', 'max:80'],
            'timing.reason' => ['sometimes', 'nullable', 'string', 'max:160'],
            'timing.error_code' => ['sometimes', 'nullable', 'string', 'max:80'],
            'timing.error_message' => ['sometimes', 'nullable', 'string', 'max:240'],
        ]);
        $session = $this->ownedSession($request, (int) $data['session_id']);
        $turn = VoiceTurn::query()
            ->where('user_id', $request->user()->id)
            ->where('conversation_session_id', $session->id)
            ->where('turn_id', $turnId)
            ->firstOrFail();
        $timing = $data['timing'] ?? [];

        if ($data['event'] === 'final_text_delivered') {
            if (! $turn->state->isTerminal() || $turn->final_assistant_message_id === null) {
                return response()->json(['message' => 'The final text is not ready for delivery.'], 409);
            }
            $turn = $this->lifecycle->markFinalDelivered($turn, $timing);
        } elseif ($data['event'] === 'acknowledgement_started') {
            $turn = $this->lifecycle->markAcknowledged($turn, $timing);
        } else {
            $turn = $this->lifecycle->recordBrowserEvent($turn, $data['event'], $timing);
            if ($turn->state === VoiceTurnState::AwaitingClarification
                && in_array($data['event'], ['playback_finished', 'playback_stopped'], true)
                && data_get($timing, 'purpose') === 'clarification') {
                // The browser owns the five-second conversational timer and
                // cancels it as soon as speech begins. Keep a wider durable
                // fallback so an answer that starts at 4.9s can finish its
                // utterance without racing a server cancellation.
                $turn = $this->lifecycle->startClarificationDeadline($turn, 30);
            }
        }

        return response()->json(['data' => $this->projection->forTurn($turn)]);
    }

    private function processAcceptedTurn(VoiceTurn $turn): VoiceTurn
    {
        if ($turn->state !== VoiceTurnState::Accepted) {
            return $turn->load(['userMessage', 'finalAssistantMessage', 'runs']);
        }

        if ($turn->lane === VoiceTurnLane::Instant) {
            $turn = $this->lifecycle->markProgress($turn, ['handler' => $turn->handler], 'instant_handler');

            return $this->lifecycle->complete($turn, $this->instantHandler->answer($turn));
        }
        if ($turn->handler === 'app.voice_work.cancel') {
            return $this->cancelContextualWork($turn);
        }
        if ($turn->handler === 'app.voice_work.status') {
            $turn = $this->lifecycle->markProgress($turn, [
                'handler' => $turn->handler,
            ], 'work_status');

            return $this->lifecycle->complete($turn, $this->workStatus->answer($turn));
        }

        $plannedJobs = $turn->lane === VoiceTurnLane::ComplexAgent
            ? $this->complexPlans->plan($turn)
            : [[
                'key' => 'primary',
                'label' => $this->jobLabel($turn),
                'lane' => $turn->lane,
                'handler' => $turn->handler,
                'input' => $turn->transcript,
                'hard_deadline_seconds' => null,
                ...$this->jobPolicy->forTurn($turn),
                'metadata' => [],
            ]];
        $jobs = collect($plannedJobs)->map(function (array $planned) use ($turn): array {
            return $this->lifecycle->createJob(
                $turn,
                (string) $planned['label'],
                (string) $planned['key'],
                $planned['resource_lock_key'] ?? null,
                (int) ($planned['priority'] ?? 0),
                [
                    ...(is_array($planned['metadata'] ?? null) ? $planned['metadata'] : []),
                    'scheduling_policy' => [
                        'priority' => (int) ($planned['priority'] ?? 0),
                        'resource_lock_key' => $planned['resource_lock_key'] ?? null,
                    ],
                ],
                $planned['lane'],
                (string) $planned['handler'],
                (string) $planned['input'],
                isset($planned['hard_deadline_seconds'])
                    ? (int) $planned['hard_deadline_seconds']
                    : null,
            );
        });
        foreach ($jobs as $job) {
            if (! $this->lifecycle->jobRequiresDispatch($job['run'])) {
                continue;
            }
            if ($this->shouldProcessSynchronously($turn)) {
                ProcessAssistantRun::dispatchSync($job['run']->id);
            } else {
                ProcessAssistantRun::dispatch($job['run']->id);
            }
            // Mark only after the queue accepted the job. A crash before this
            // point causes an idempotent redispatch on the stable-turn retry.
            $this->lifecycle->markJobDispatched($job['run']);
        }

        return $turn->fresh(['userMessage', 'finalAssistantMessage', 'runs']);
    }

    private function ensureEnabled(Request $request): void
    {
        abort_unless($this->gate->enabledFor($request->user()), 404);
    }

    private function jobLabel(VoiceTurn $turn): string
    {
        return match ($turn->lane) {
            VoiceTurnLane::AppRead => match ($turn->handler) {
                'app.calendar.read' => 'Check calendar',
                'app.reminder.read' => 'Check reminders',
                'app.task.read' => 'Check tasks',
                'app.note.read' => 'Check notes',
                default => 'Check app information',
            },
            VoiceTurnLane::AppWrite => match ($turn->handler) {
                'app.calendar.create', 'app.calendar.delete', 'app.calendar.reschedule' => 'Update calendar',
                'app.reminder.create', 'app.reminder.delete', 'app.reminder.reschedule' => 'Update reminders',
                'app.task.create', 'app.task.delete', 'app.task.reschedule', 'app.task.complete' => 'Update tasks',
                'app.note.create', 'app.note.delete' => 'Update notes',
                default => 'Update app information',
            },
            VoiceTurnLane::External => $turn->handler === 'external.weather' ? 'Check weather' : 'Check external information',
            VoiceTurnLane::ComplexAgent => 'Work on request',
            VoiceTurnLane::Instant => 'Answer request',
        };
    }

    private function shouldProcessSynchronously(VoiceTurn $turn): bool
    {
        return $turn->lane === VoiceTurnLane::AppRead
            || ($turn->lane === VoiceTurnLane::External && ! $turn->acknowledgement_required);
    }

    private function hasUnambiguousActiveContextualReference(
        Request $request,
        ConversationSession $session,
        string $transcript,
    ): bool {
        $domain = match (true) {
            preg_match('/\breminders?\b|\bremind me\b/iu', $transcript) === 1 => 'reminder',
            preg_match('/\b(?:tasks?|todo|to do)\b/iu', $transcript) === 1 => 'task',
            preg_match('/\bnotes?\b/iu', $transcript) === 1 => 'note',
            preg_match('/\b(?:calendar|events?|meetings?|appointments?)\b/iu', $transcript) === 1 => 'calendar',
            default => null,
        };
        $operation = match (true) {
            preg_match('/\b(?:delete|remove)\b/iu', $transcript) === 1 => 'delete',
            preg_match('/\b(?:move|reschedule|change)\b/iu', $transcript) === 1 => 'reschedule',
            preg_match('/\b(?:complete|mark)\b/iu', $transcript) === 1 => 'complete',
            default => null,
        };
        if ($domain === null || $operation === null
            || ! $this->jobPolicy->isContextualMutationReference("app.{$domain}.{$operation}", $transcript)) {
            return false;
        }

        return VoiceTurn::query()
            ->where('user_id', $request->user()->id)
            ->where('conversation_session_id', $session->id)
            ->where('handler', "app.{$domain}.create")
            ->whereIn('state', [VoiceTurnState::Accepted->value, VoiceTurnState::Running->value])
            ->count() === 1;
    }

    private function cancelTurnSafely(
        VoiceTurn $turn,
        string $reason,
        array $metadata = [],
    ): VoiceTurn {
        $fresh = $turn->fresh();
        if (! $fresh instanceof VoiceTurn || $fresh->state->isTerminal()) {
            return $fresh ?? $turn;
        }

        try {
            return $this->lifecycle->cancel($fresh, $reason, $metadata);
        } catch (VoiceTurnConflictException) {
            return $fresh->fresh() ?? $fresh;
        }
    }

    private function cancelContextualWork(VoiceTurn $requestTurn): VoiceTurn
    {
        $requestTurn = $this->lifecycle->markProgress($requestTurn, [
            'handler' => $requestTurn->handler,
        ], 'cancellation');
        $cancelAll = $this->workReferences->requestsAll($requestTurn->transcript);
        $activeStates = [VoiceTurnState::Accepted, VoiceTurnState::Running];
        $targets = $cancelAll
            ? VoiceTurn::query()
                ->where('user_id', $requestTurn->user_id)
                ->where('conversation_session_id', $requestTurn->conversation_session_id)
                ->where('id', '!=', $requestTurn->id)
                ->whereNotIn('handler', ['app.voice_work.cancel', 'app.voice_work.status'])
                ->whereIn('state', collect($activeStates)->map->value->all())
                ->latest('id')
                ->get()
            : collect(array_filter([$this->workReferences->resolve($requestTurn, $activeStates)]));

        if ($targets->isEmpty() && ! $cancelAll) {
            $prior = $this->workReferences->resolve($requestTurn);
            $answer = match ($prior?->state) {
                VoiceTurnState::Completed => 'That had already finished, so I couldn’t cancel it.',
                VoiceTurnState::Canceled => 'That was already canceled.',
                VoiceTurnState::Failed => 'That request had already failed, so there was nothing left to cancel.',
                default => 'There wasn’t any active work to cancel.',
            };

            return $this->lifecycle->complete($requestTurn, $answer);
        }

        $outcomes = $targets->map(fn (VoiceTurn $target): VoiceTurn => $this->cancelTurnSafely(
            $target,
            'contextual_voice_cancellation',
            ['cancellation_turn_id' => $requestTurn->turn_id],
        ));
        $tooLate = $outcomes->contains(fn (VoiceTurn $target): bool => $target->state === VoiceTurnState::Completed);
        $canceled = $outcomes->filter(fn (VoiceTurn $target): bool => $target->state === VoiceTurnState::Canceled);
        $partiallyCommitted = $outcomes->contains(
            fn (VoiceTurn $target): bool => $target->side_effect_status === VoiceTurnSideEffectStatus::Committed,
        );

        return $this->lifecycle->complete(
            $requestTurn,
            $targets->isEmpty() || ($canceled->isEmpty() && ! $tooLate)
                ? 'There wasn’t any active work to cancel.'
                : ($tooLate
                    ? ($targets->count() === 1
                        ? 'That had already finished, so I couldn’t cancel it.'
                        : 'I canceled the active work, but some work had already finished.')
                    : ($partiallyCommitted
                        ? 'I canceled the remaining work, but part of that request had already finished and couldn’t be undone.'
                        : 'Canceled.')),
            $canceled->isEmpty() ? VoiceTurnSideEffectStatus::None : VoiceTurnSideEffectStatus::Committed,
            ['canceled_turn_ids' => $canceled
                ->pluck('turn_id')
                ->values()
                ->all()],
        );
    }

    private function ownedSession(Request $request, int $sessionId): ConversationSession
    {
        return ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($sessionId);
    }

    /** @param array<string, mixed> $payload */
    private function rejectRawAudio(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if ($this->privacy->isRawAudioKey((string) $key)) {
                throw ValidationException::withMessages([
                    (string) $key => 'Raw microphone audio is not accepted or retained.',
                ]);
            }
            if (is_array($value)) {
                $this->rejectRawAudio($value);
            }
        }
    }
}
