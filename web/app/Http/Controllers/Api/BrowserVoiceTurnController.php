<?php

namespace App\Http\Controllers\Api;

use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Exceptions\VoiceTurnConflictException;
use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeSession;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\BrowserVoiceProjectionService;
use App\Services\BrowserVoiceV2Gate;
use App\Services\RealtimeVoiceApplicationEventHandler;
use App\Services\RealtimeVoiceSessionService;
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
        private readonly VoiceTurnPrivacyService $privacy,
        private readonly RealtimeVoiceApplicationEventHandler $realtime,
        private readonly RealtimeVoiceSessionService $realtimeSessions,
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
            'realtime_session_id' => ['required', 'uuid'],
            'controller_generation' => ['required', 'integer', 'min:0'],
            'provider_connection_generation' => ['required', 'integer', 'min:0'],
            'input_generation' => ['required', 'integer', 'min:0'],
            'wake_detected_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'client_milestones' => ['sometimes', 'array:wake_detected_at_ms,pre_admission_started_at_ms,capture_started_at_ms'],
            'client_milestones.wake_detected_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'client_milestones.pre_admission_started_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'client_milestones.capture_started_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'conversation_context' => ['sometimes', 'array:mode,epoch'],
            'conversation_context.mode' => ['required_with:conversation_context', 'string', 'in:new_conversation,contextual_follow_up'],
            'conversation_context.epoch' => ['required_with:conversation_context', 'integer', 'min:1'],
        ]);
        $session = $this->ownedSession($request, (int) $data['session_id']);
        $realtimeSession = VoiceRealtimeSession::query()
            ->where('public_id', $data['realtime_session_id'])
            ->where('user_id', $request->user()->id)
            ->where('conversation_session_id', $session->id)
            ->firstOrFail();
        $realtimeSession = $this->realtimeSessions->awaitReady(
            $realtimeSession,
            (int) config('services.voice_realtime.admission_ready_timeout_ms', 1200),
        );
        if (! $realtimeSession instanceof VoiceRealtimeSession) {
            return response()->json([
                'message' => 'Bean is still connecting. Please try again.',
                'code' => 'voice_sideband_not_ready',
            ], 503);
        }
        $existingTurn = VoiceTurn::query()
            ->where('user_id', $request->user()->id)
            ->where('turn_id', $data['turn_id'])
            ->first();
        $existed = $existingTurn !== null;

        try {
            $turn = $this->lifecycle->preAdmitRealtime($request->user(), $session, $realtimeSession, $data);
        } catch (VoiceTurnConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => [
            'turn_id' => $turn->turn_id,
            'state' => $turn->state->value,
            'version' => $turn->version,
            'realtime_session_id' => $realtimeSession->public_id,
            'sideband_ready' => $realtimeSession->status->value === 'ready',
        ]], $existed ? 200 : 201);
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
        $state['cancellation'] = [
            'canceled' => $cancellationSucceeded,
            'completed_before_cancellation' => $completedBeforeCancellation,
            'partially_committed' => $partiallyCommitted,
        ];

        return response()->json(['data' => $state]);
    }

    public function delivery(Request $request, string $turnId): JsonResponse
    {
        $this->rejectRawAudio($request->all());
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'event' => ['required', 'string', 'in:acknowledgement_started,final_audio_started,playback_started,playback_finished,playback_stopped,potential_interruption,interruption_confirmed,interruption_rejected'],
            'timing' => ['sometimes', 'nullable', 'array:latency_ms,occurred_at_ms,speech_item_id,controller_generation,provider_connection_generation,purpose,reason,directive_id,error_code,error_message'],
            'timing.latency_ms' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:600000'],
            'timing.occurred_at_ms' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timing.speech_item_id' => ['sometimes', 'nullable', 'string', 'max:160'],
            'timing.controller_generation' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timing.provider_connection_generation' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'timing.purpose' => ['sometimes', 'nullable', 'string', 'max:80'],
            'timing.reason' => ['sometimes', 'nullable', 'string', 'max:160'],
            'timing.directive_id' => ['sometimes', 'nullable', 'string', 'max:200'],
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

        try {
            $directiveStop = $data['event'] === 'playback_stopped'
                && filled($timing['directive_id'] ?? null);
            if ($directiveStop) {
                $this->assertDirectiveBrowserGeneration($turn, $timing);
                $turn = $this->lifecycle->acknowledgePlaybackStopDirective(
                    $turn,
                    (string) $timing['directive_id'],
                    $timing,
                );
            } else {
                $this->assertAuthorizedPlayback($turn, (string) $data['event'], $timing);
            }

            if ($directiveStop) {
                // The semantic Stop turn owns the directive, while the
                // stopped speech item belongs to the turn it interrupted.
            } elseif ($data['event'] === 'acknowledgement_started'
                || ($data['event'] === 'playback_started' && data_get($timing, 'purpose') === 'acknowledgement')) {
                $turn = $this->lifecycle->markAcknowledged($turn, $timing);
            } elseif ($data['event'] === 'final_audio_started'
                || ($data['event'] === 'playback_started' && data_get($timing, 'purpose') === 'final')) {
                $turn = $this->lifecycle->markFinalAudioStarted($turn, $data['event'], $timing);
            } else {
                $turn = $this->lifecycle->recordBrowserEvent($turn, $data['event'], $timing);
                if (in_array($data['event'], ['playback_finished', 'playback_stopped'], true)
                    && data_get($timing, 'purpose') === 'acknowledgement') {
                    $this->realtime->releaseFinalAfterAcknowledgement($turn);
                }
                if ($turn->state === VoiceTurnState::AwaitingClarification
                    && in_array($data['event'], ['playback_finished', 'playback_stopped'], true)
                    && data_get($timing, 'purpose') === 'clarification') {
                    // The server owns the durable five-second clarification
                    // deadline. Browser timers control capture UX only and may
                    // never become the lifecycle authority.
                    $turn = $this->lifecycle->startClarificationDeadline($turn, 5);
                }
            }
        } catch (VoiceTurnConflictException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        }

        return response()->json(['data' => $this->projection->forTurn($turn)]);
    }

    private function ensureEnabled(Request $request): void
    {
        abort_unless($this->gate->enabledFor($request->user()), 404);
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
            return $this->lifecycle->abandonPendingRealtimeInput($fresh, $reason);
        } catch (VoiceTurnConflictException) {
            // Provider input or durable work already owns this turn.
        }

        try {
            return $this->lifecycle->cancel($fresh, $reason, $metadata);
        } catch (VoiceTurnConflictException) {
            return $fresh->fresh() ?? $fresh;
        }
    }

    /** @param array<string, mixed> $timing */
    private function assertDirectiveBrowserGeneration(VoiceTurn $turn, array $timing): void
    {
        $controllerGeneration = (int) data_get($turn->metadata, 'controller_generation', -1);
        $providerGeneration = (int) data_get($turn->metadata, 'provider_connection_generation', -1);
        if ($controllerGeneration < 0
            || $providerGeneration < 0
            || $controllerGeneration !== (int) data_get($timing, 'controller_generation', -2)
            || $providerGeneration !== (int) data_get($timing, 'provider_connection_generation', -2)) {
            throw new VoiceTurnConflictException(
                'That playback Stop directive does not belong to this browser generation.',
            );
        }
    }

    private function ownedSession(Request $request, int $sessionId): ConversationSession
    {
        return ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($sessionId);
    }

    /** @param array<string, mixed> $timing */
    private function assertAuthorizedPlayback(VoiceTurn $turn, string $event, array $timing): void
    {
        if (! in_array($event, [
            'acknowledgement_started',
            'final_audio_started',
            'playback_started',
            'playback_finished',
            'playback_stopped',
        ], true)) {
            return;
        }
        $purpose = trim((string) data_get($timing, 'purpose'));
        $speechItemId = trim((string) data_get($timing, 'speech_item_id'));
        if (! in_array($purpose, ['acknowledgement', 'clarification', 'final'], true)
            || $speechItemId === '') {
            throw new VoiceTurnConflictException('Playback delivery requires its authorized speech binding.');
        }
        $command = VoiceRealtimeCommand::query()
            ->where('voice_turn_id', $turn->id)
            ->where('speech_item_id', $speechItemId)
            ->where('purpose', $purpose)
            ->whereIn('status', ['sending', 'sent', 'acknowledged'])
            ->first();
        if (! $command instanceof VoiceRealtimeCommand
            || (int) $command->controller_generation !== (int) data_get($timing, 'controller_generation', -1)
            || (int) data_get($command->payload, 'response.metadata.provider_connection_generation', -2)
                !== (int) data_get($timing, 'provider_connection_generation', -1)) {
            throw new VoiceTurnConflictException('That speech item is not authorized for this browser generation.');
        }
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
