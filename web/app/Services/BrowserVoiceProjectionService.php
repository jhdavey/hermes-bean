<?php

namespace App\Services;

use App\Enums\VoiceTurnState;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Illuminate\Support\Collection;

class BrowserVoiceProjectionService
{
    /**
     * @return array<string, mixed>
     */
    public function forTurn(VoiceTurn $turn): array
    {
        $turn->loadMissing(['userMessage', 'finalAssistantMessage', 'runs']);
        $events = VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->orderBy('id')
            ->get();
        $finalAudioStarted = $events->contains(
            fn (VoiceTurnEvent $event): bool => $event->event_type === 'final_audio_started'
                || ($event->event_type === 'playback_started' && data_get($event->payload, 'purpose') === 'final'),
        );

        return [
            'turn' => $this->turn($turn, $finalAudioStarted),
            'jobs' => $turn->runs->map(fn (AssistantRun $run): array => $this->job($run, $turn))->values()->all(),
            'messages' => $this->messages(collect([$turn])),
            'events' => $events->map(fn (VoiceTurnEvent $event): array => $this->event($event, $turn->turn_id))->values()->all(),
            'cursor' => (int) ($events->max('id') ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function forSession(ConversationSession $session, int $afterCursor = 0): array
    {
        $terminalStates = [
            VoiceTurnState::Completed->value,
            VoiceTurnState::Failed->value,
            VoiceTurnState::Canceled->value,
        ];
        $baseQuery = VoiceTurn::query()
            ->where('conversation_session_id', $session->id)
            ->where('source', 'browser_voice_v2');
        $activeTurns = (clone $baseQuery)
            ->whereNotIn('state', $terminalStates)
            ->orderBy('id')
            ->get();
        $recentLimit = max(0, 100 - $activeTurns->count());
        $recentTerminalTurns = (clone $baseQuery)
            ->whereIn('state', $terminalStates)
            ->where('terminal_at', '>=', now()->subHours(24))
            ->latest('id')
            ->limit($recentLimit)
            ->get()
            ->reverse()
            ->values();
        $turns = $activeTurns->concat($recentTerminalTurns)->sortBy('id')->values();
        $turns->load(['userMessage', 'finalAssistantMessage', 'runs']);
        $turnIds = $turns->pluck('id');
        $turnIdByDatabaseId = $turns->pluck('turn_id', 'id');
        $finalAudioStartedTurnIds = VoiceTurnEvent::query()
            ->whereIn('voice_turn_id', $turnIds)
            ->finalAudioStarted()
            ->pluck('voice_turn_id')
            ->unique();
        $eventBatch = VoiceTurnEvent::query()
            ->where('conversation_session_id', $session->id)
            ->where('id', '>', $afterCursor)
            ->when($turnIds->isNotEmpty(), fn ($query) => $query->whereIn('voice_turn_id', $turnIds))
            ->when($turnIds->isEmpty(), fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('id')
            ->limit(501)
            ->get();
        $hasMoreEvents = $eventBatch->count() > 500;
        $events = $eventBatch->take(500)->values();
        $sessionCursor = (int) (VoiceTurnEvent::query()
            ->where('conversation_session_id', $session->id)
            ->max('id') ?? 0);
        $cursor = $hasMoreEvents
            ? (int) ($events->last()?->id ?? $afterCursor)
            : max($afterCursor, $sessionCursor);

        return [
            'turn' => null,
            'turns' => $turns->map(fn (VoiceTurn $turn): array => $this->turn(
                $turn,
                $finalAudioStartedTurnIds->contains($turn->id),
            ))->values()->all(),
            'jobs' => $turns->flatMap(fn (VoiceTurn $turn) => $turn->runs->map(
                fn (AssistantRun $run): array => $this->job($run, $turn)
            ))->values()->all(),
            'messages' => $this->messages($turns),
            'events' => $events->map(fn (VoiceTurnEvent $event): array => $this->event(
                $event,
                (string) ($turnIdByDatabaseId[$event->voice_turn_id] ?? ''),
            ))->values()->all(),
            'cursor' => $cursor,
        ];
    }

    /** @return array<string, mixed> */
    private function turn(VoiceTurn $turn, bool $finalAudioStarted): array
    {
        $stopDirective = data_get($turn->metadata, 'playback_stop_directive');
        $stopDirectivePending = is_array($stopDirective)
            && filled($stopDirective['id'] ?? null)
            && blank($stopDirective['acknowledged_at'] ?? null);
        $clarificationResolutions = data_get($turn->metadata, 'clarification_resolutions');
        $resolvedClarificationIds = is_array($clarificationResolutions)
            ? collect(array_keys($clarificationResolutions))
                ->map(fn (mixed $id): string => trim((string) $id))
                ->filter()
                ->values()
                ->all()
            : [];

        return [
            'id' => $turn->id,
            'turn_id' => $turn->turn_id,
            'session_id' => $turn->conversation_session_id,
            'workspace_id' => $turn->workspace_id,
            'source' => $turn->source,
            'state' => $turn->state->value,
            'transcript' => $turn->transcript,
            'version' => $turn->version,
            'acknowledgement_required' => $turn->acknowledgement_required,
            'acknowledgement_text' => $turn->acknowledgement_text,
            'acknowledged_at' => $turn->acknowledged_at?->toIso8601String(),
            'accepted_at' => $turn->accepted_at?->toIso8601String(),
            'started_at' => $turn->started_at?->toIso8601String(),
            'first_progress_at' => $turn->first_progress_at?->toIso8601String(),
            'terminal_at' => $turn->terminal_at?->toIso8601String(),
            'final_delivered_at' => $turn->final_delivered_at?->toIso8601String(),
            'final_audio_started' => $finalAudioStarted,
            'hard_deadline_at' => $turn->hard_deadline_at?->toIso8601String(),
            'no_progress_deadline_at' => $turn->no_progress_deadline_at?->toIso8601String(),
            'deadlines' => [
                'hard_at' => $turn->hard_deadline_at?->toIso8601String(),
                'no_progress_at' => $turn->no_progress_deadline_at?->toIso8601String(),
            ],
            'failure' => $turn->failure_category === null ? null : [
                'category' => $turn->failure_category,
                'message' => $turn->user_facing_failure_text,
                'retry_eligible' => $turn->retry_count < 1,
            ],
            'side_effect_status' => $turn->side_effect_status->value,
            'retry_count' => $turn->retry_count,
            'user_message_id' => $turn->user_message_id,
            'final_assistant_message_id' => $turn->final_assistant_message_id,
            'final_text' => $turn->finalAssistantMessage?->content,
            'resolved_clarification_ids' => $resolvedClarificationIds,
            'close_after_response' => (bool) data_get($turn->metadata, 'response_directives.close_after_response', false),
            'response_expected' => (bool) data_get($turn->metadata, 'response_directives.response_expected', false),
            'stop_playback' => $stopDirectivePending,
            'stop_playback_directive_id' => $stopDirectivePending
                ? (string) ($stopDirective['id'] ?? '')
                : null,
            'clarification' => $turn->state === VoiceTurnState::AwaitingClarification ? [
                'question' => (string) data_get($turn->metadata, 'clarification_question', ''),
                'sequence' => (int) data_get($turn->metadata, 'clarification_sequence', 1),
                'deadline_at' => $turn->hard_deadline_at?->toIso8601String(),
            ] : null,
            'visible_in_chat' => $turn->state !== VoiceTurnState::Canceled,
            'created_at' => $turn->created_at?->toIso8601String(),
            'updated_at' => $turn->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function job(AssistantRun $run, VoiceTurn $turn): array
    {
        return [
            'id' => $run->id,
            'turn_id' => $turn->turn_id,
            'label' => $run->label ?: 'Work on request',
            'status' => $run->status === 'cancelled' ? 'canceled' : $run->status,
            'lane' => $run->lane,
            'handler' => $run->handler,
            'role' => data_get($run->metadata, 'role'),
            'operation_id' => data_get($run->metadata, 'semantic_operation_id'),
            'tool' => data_get($run->metadata, 'semantic_tool'),
            'dependency_run_ids' => is_array(data_get($run->metadata, 'dependency_run_ids'))
                ? data_get($run->metadata, 'dependency_run_ids')
                : [],
            'priority' => $run->priority,
            'resource_lock_key' => $run->resource_lock_key,
            'hard_deadline_at' => $run->hard_deadline_at?->toIso8601String(),
            'last_progress_at' => $run->last_progress_at?->toIso8601String(),
            'dispatch_requested_at' => $run->dispatch_requested_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, VoiceTurn>  $turns
     * @return array<int, array<string, mixed>>
     */
    private function messages(Collection $turns): array
    {
        return $turns->flatMap(function (VoiceTurn $turn): array {
            $visible = $turn->state !== VoiceTurnState::Canceled;
            $messages = [];
            if ($turn->userMessage instanceof ConversationMessage) {
                $messages[] = $this->message($turn->userMessage, $turn, false, $visible);
            }
            if ($turn->finalAssistantMessage instanceof ConversationMessage) {
                $messages[] = $this->message($turn->finalAssistantMessage, $turn, true, $visible);
            }

            return $messages;
        })->sortBy('id')->values()->all();
    }

    /** @return array<string, mixed> */
    private function message(ConversationMessage $message, VoiceTurn $turn, bool $final, bool $visible): array
    {
        return [
            'id' => $message->id,
            'turn_id' => $turn->turn_id,
            'role' => $message->role,
            'content' => $message->content,
            'final' => $final,
            'visible_in_chat' => $visible,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function event(VoiceTurnEvent $event, string $stableTurnId): array
    {
        return [
            'cursor' => $event->id,
            'turn_id' => $stableTurnId,
            'sequence' => $event->sequence,
            'type' => $event->event_type,
            'from_state' => $event->from_state,
            'to_state' => $event->to_state,
            'version' => $event->version,
            'source' => $event->source,
            'payload' => $event->payload ?? [],
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }
}
