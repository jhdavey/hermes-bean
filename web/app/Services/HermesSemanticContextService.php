<?php

namespace App\Services;

use App\Enums\VoiceTurnState;
use App\Models\AgentProfile;
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
use Illuminate\Support\Collection;

/**
 * Builds bounded, read-only facts for Hermes. This service deliberately does
 * not interpret transcript text or resolve references; Hermes receives stable
 * identifiers and decides what the user meant.
 */
class HermesSemanticContextService
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

    /** @return array<string, mixed> */
    public function forVoiceTurn(VoiceTurn $turn): array
    {
        $session = ConversationSession::query()->findOrFail($turn->conversation_session_id);
        $user = User::query()->findOrFail($turn->user_id);

        return [
            'conversation_reference_scope' => [
                'authorized' => data_get($turn->metadata, 'prior_context_authorized') === true,
                'authorized_prior_turn_id' => data_get($turn->metadata, 'prior_turn_id'),
                'conversation_epoch' => data_get($turn->metadata, 'conversation_context.epoch'),
                'controller_generation' => data_get($turn->metadata, 'controller_generation'),
            ],
            'authorized_conversation' => $this->authorizedConversation($session, $turn),
            'resources' => [
                'tasks' => $this->tasks((int) $turn->user_id, (int) $turn->workspace_id),
                'reminders' => $this->reminders((int) $turn->user_id, (int) $turn->workspace_id),
                'calendar_events' => $this->calendarEvents((int) $turn->user_id, (int) $turn->workspace_id),
                'notes' => $this->planLimits->canUseNotes($user)
                    ? $this->notes((int) $turn->user_id, (int) $turn->workspace_id)
                    : [],
                'note_folders' => $this->planLimits->canUseNotes($user)
                    ? $this->noteFolders((int) $turn->user_id, (int) $turn->workspace_id)
                    : [],
                'event_categories' => $this->eventCategories((int) $turn->user_id, (int) $turn->workspace_id),
                'blockers' => $this->blockers((int) $turn->user_id, (int) $turn->workspace_id),
                'memory_items' => $this->memoryItems((int) $turn->user_id, (int) $turn->workspace_id),
            ],
            'current_session' => $this->sessionContext($session),
            'assistant_profile' => $this->agentProfile((int) $turn->workspace_id),
            'capabilities' => [
                'notes_available' => $this->planLimits->canUseNotes($user),
            ],
            'trusted_location' => $this->trustedLocation(is_array($turn->metadata) ? $turn->metadata : []),
            'recent_voice_turns' => $this->voiceTurns($turn),
        ];
    }

    /** @return array<string, mixed> */
    public function forAssistantRun(AssistantRun $run): array
    {
        $session = ConversationSession::query()->findOrFail($run->conversation_session_id);
        $user = User::query()->findOrFail($run->user_id);
        $message = ConversationMessage::query()
            ->whereKey($run->user_message_id)
            ->where('conversation_session_id', $session->id)
            ->firstOrFail();
        $metadata = is_array($run->metadata) ? $run->metadata : [];

        return [
            'conversation_reference_scope' => [
                'authorized' => true,
                'surface' => 'chat',
                'replacement_anchor_message_id' => data_get($message->metadata, 'edited_from_message_id'),
            ],
            'authorized_conversation' => $this->assistantConversation($session, $message),
            'resources' => [
                'tasks' => $this->tasks((int) $run->user_id, (int) $run->workspace_id),
                'reminders' => $this->reminders((int) $run->user_id, (int) $run->workspace_id),
                'calendar_events' => $this->calendarEvents((int) $run->user_id, (int) $run->workspace_id),
                'notes' => $this->planLimits->canUseNotes($user)
                    ? $this->notes((int) $run->user_id, (int) $run->workspace_id)
                    : [],
                'note_folders' => $this->planLimits->canUseNotes($user)
                    ? $this->noteFolders((int) $run->user_id, (int) $run->workspace_id)
                    : [],
                'event_categories' => $this->eventCategories((int) $run->user_id, (int) $run->workspace_id),
                'blockers' => $this->blockers((int) $run->user_id, (int) $run->workspace_id),
                'memory_items' => $this->memoryItems((int) $run->user_id, (int) $run->workspace_id),
            ],
            'current_session' => $this->sessionContext($session),
            'assistant_profile' => $this->agentProfile((int) $run->workspace_id),
            'capabilities' => [
                'notes_available' => $this->planLimits->canUseNotes($user),
                'voice_surface' => false,
            ],
            'trusted_location' => $this->trustedLocation($metadata),
            'recent_voice_turns' => $this->voiceTurnsForSession(
                (int) $run->user_id,
                (int) $run->conversation_session_id,
            ),
        ];
    }

    /** @return array{label:?string,latitude:?float,longitude:?float,is_local:bool}|null */
    private function trustedLocation(array $metadata): ?array
    {
        $location = data_get($metadata, 'location_context');
        if (! is_array($location)) {
            return null;
        }

        $label = is_string($location['label'] ?? null)
            ? trim((string) $location['label'])
            : '';
        $latitude = $location['latitude'] ?? null;
        $longitude = $location['longitude'] ?? null;
        $hasCoordinates = is_numeric($latitude)
            && is_numeric($longitude)
            && (float) $latitude >= -90
            && (float) $latitude <= 90
            && (float) $longitude >= -180
            && (float) $longitude <= 180;
        if ($label === '' && ! $hasCoordinates) {
            return null;
        }

        return [
            'label' => $label !== '' ? mb_substr($label, 0, 180) : null,
            'latitude' => $hasCoordinates ? (float) $latitude : null,
            'longitude' => $hasCoordinates ? (float) $longitude : null,
            'is_local' => ($location['is_local'] ?? false) === true,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function assistantConversation(
        ConversationSession $session,
        ConversationMessage $currentMessage,
    ): array {
        $historyBoundaryId = (int) data_get(
            $currentMessage->metadata,
            'edited_from_message_id',
            $currentMessage->id,
        );
        if ($historyBoundaryId <= 0) {
            $historyBoundaryId = (int) $currentMessage->id;
        }

        $history = $session->messages()
            ->where('id', '<', $historyBoundaryId)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit(16)
            ->get()
            ->sortBy('id')
            ->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => mb_substr(trim((string) $message->content), 0, 1500),
                'stable_turn_id' => $message->client_turn_id,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->filter(fn (array $message): bool => $message['content'] !== '')
            ->values();

        return $history->push([
            'id' => $currentMessage->id,
            'role' => 'user',
            'content' => mb_substr(trim((string) $currentMessage->content), 0, 1500),
            'stable_turn_id' => $currentMessage->client_turn_id,
            'created_at' => $currentMessage->created_at?->toIso8601String(),
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function authorizedConversation(ConversationSession $session, VoiceTurn $requestTurn): array
    {
        $turnIds = [$requestTurn->turn_id];
        if (data_get($requestTurn->metadata, 'prior_context_authorized') === true) {
            $epoch = (int) data_get($requestTurn->metadata, 'conversation_context.epoch', -1);
            $generation = (int) data_get($requestTurn->metadata, 'controller_generation', -1);
            $priorCandidates = VoiceTurn::query()
                ->where('user_id', $requestTurn->user_id)
                ->where('conversation_session_id', $requestTurn->conversation_session_id)
                ->where('id', '<', $requestTurn->id)
                ->latest('id')
                ->limit(20)
                ->get()
                ->keyBy('turn_id');
            $cursor = $requestTurn;
            for ($depth = 0; $depth < 20; $depth++) {
                if (data_get($cursor->metadata, 'prior_context_authorized') !== true) {
                    break;
                }
                $priorTurnId = trim((string) data_get($cursor->metadata, 'prior_turn_id', ''));
                if ($priorTurnId === '' || in_array($priorTurnId, $turnIds, true)) {
                    break;
                }
                $prior = $priorCandidates->get($priorTurnId);
                if (! $prior instanceof VoiceTurn
                    || $prior->id >= $cursor->id
                    || (int) data_get($prior->metadata, 'conversation_context.epoch', -2) !== $epoch
                    || (int) data_get($prior->metadata, 'controller_generation', -2) !== $generation) {
                    break;
                }
                $turnIds[] = $prior->turn_id;
                $cursor = $prior;
            }
        }

        return ConversationMessage::query()
            ->where('conversation_session_id', $session->id)
            ->whereIn('client_turn_id', $turnIds)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit(16)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'role' => $message->role,
                'content' => mb_substr(trim((string) $message->content), 0, 1500),
                'stable_turn_id' => $message->client_turn_id,
                'created_at' => $message->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function tasks(int $userId, int $workspaceId): array
    {
        return Task::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->orderByRaw('due_at is null')
            ->orderBy('due_at')
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'status' => $task->status,
                'recurrence' => ($task->metadata ?? [])['recurrence'] ?? null,
                'due_at' => $task->due_at?->toIso8601String(),
                'completed_at' => $task->completed_at?->toIso8601String(),
                'updated_at' => $task->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function reminders(int $userId, int $workspaceId): array
    {
        return Reminder::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->orderBy('remind_at')
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (Reminder $reminder): array => [
                'id' => $reminder->id,
                'title' => $reminder->title,
                'status' => $reminder->status,
                'recurrence' => ($reminder->metadata ?? [])['recurrence'] ?? null,
                'remind_at' => $reminder->remind_at?->toIso8601String(),
                'updated_at' => $reminder->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function calendarEvents(int $userId, int $workspaceId): array
    {
        return CalendarEvent::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->where('starts_at', '>=', now()->subDays(7))
            ->orderBy('starts_at')
            ->limit(40)
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'status' => $event->status,
                'location' => $event->location,
                'recurrence' => $event->recurrence,
                'all_day' => ($event->metadata ?? [])['all_day'] ?? null,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
                'updated_at' => $event->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function notes(int $userId, int $workspaceId): array
    {
        return Note::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->latest('updated_at')
            ->limit(25)
            ->get()
            ->map(fn (Note $note): array => [
                'id' => $note->id,
                'title' => $note->title,
                'preview' => mb_substr(trim((string) $note->plain_text), 0, 240),
                'updated_at' => $note->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function noteFolders(int $userId, int $workspaceId): array
    {
        return NoteFolder::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (NoteFolder $folder): array => [
                'id' => $folder->id,
                'name' => $folder->name,
                'updated_at' => $folder->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function eventCategories(int $userId, int $workspaceId): array
    {
        return EventCategory::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->orderBy('name')
            ->limit(50)
            ->get()
            ->map(fn (EventCategory $category): array => [
                'id' => $category->id,
                'name' => $category->name,
                'color' => $category->color,
                'updated_at' => $category->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function blockers(int $userId, int $workspaceId): array
    {
        return Blocker::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (Blocker $blocker): array => [
                'id' => $blocker->id,
                'reason' => mb_substr(trim((string) $blocker->reason), 0, 500),
                'status' => $blocker->status,
                'conversation_session_id' => $blocker->conversation_session_id,
                'updated_at' => $blocker->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<string,mixed> */
    private function sessionContext(ConversationSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'created_at' => $session->created_at?->toIso8601String(),
        ];
    }

    /** @return array<string,mixed>|null */
    private function agentProfile(int $workspaceId): ?array
    {
        $profile = AgentProfile::query()->where('workspace_id', $workspaceId)->first();
        if (! $profile instanceof AgentProfile) {
            return null;
        }
        $settings = is_array($profile->settings) ? $profile->settings : [];

        return [
            'id' => $profile->id,
            'display_name' => $profile->display_name,
            'status' => $profile->status,
            'personality_type' => data_get($settings, 'personality_type'),
            'personality_prompt' => data_get($settings, 'personality_prompt'),
            'onboarding_priorities' => data_get($settings, 'onboarding.priorities', []),
            'onboarding_context' => data_get($settings, 'onboarding.context'),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function memoryItems(int $userId, int $workspaceId): array
    {
        return MemoryItem::query()
            ->where('user_id', $userId)
            ->where('workspace_id', $workspaceId)
            ->where('status', 'active')
            ->where(fn ($query) => $query
                ->whereNull('expires_at')
                ->orWhere('expires_at', '>', now()))
            ->orderByDesc('importance')
            ->orderByDesc('confidence')
            ->latest('updated_at')
            ->limit(30)
            ->get()
            ->map(fn (MemoryItem $item): array => [
                'id' => $item->id,
                'type' => $item->type,
                'title' => $item->title,
                'content' => mb_substr(trim((string) $item->content), 0, 500),
                'summary' => $item->summary !== null
                    ? mb_substr(trim((string) $item->summary), 0, 240)
                    : null,
                'confidence' => $item->confidence,
                'importance' => $item->importance,
                'expires_at' => $item->expires_at?->toIso8601String(),
                'updated_at' => $item->updated_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    private function voiceTurns(VoiceTurn $requestTurn): array
    {
        return $this->voiceTurnsForSession(
            (int) $requestTurn->user_id,
            (int) $requestTurn->conversation_session_id,
            (int) $requestTurn->id,
        );
    }

    /** @return array<int, array<string, mixed>> */
    private function voiceTurnsForSession(
        int $userId,
        int $sessionId,
        ?int $excludedVoiceTurnId = null,
    ): array {
        $turns = VoiceTurn::query()
            ->where('user_id', $userId)
            ->where('conversation_session_id', $sessionId)
            ->when($excludedVoiceTurnId !== null, fn ($query) => $query->where('id', '!=', $excludedVoiceTurnId))
            ->latest('id')
            ->limit(20)
            ->get();
        $runsByTurn = AssistantRun::query()
            ->whereIn('voice_turn_id', $turns->pluck('id'))
            ->where('handler', HermesSemanticOperationExecutor::OPERATION_HANDLER)
            ->orderBy('id')
            ->get()
            ->groupBy('voice_turn_id');

        return $turns
            ->map(fn (VoiceTurn $turn): array => [
                'stable_turn_id' => $turn->turn_id,
                'state' => $turn->state->value,
                'active' => in_array($turn->state, [
                    VoiceTurnState::AwaitingClarification,
                    VoiceTurnState::Accepted,
                    VoiceTurnState::Running,
                ], true),
                'side_effect_status' => $turn->side_effect_status->value,
                'operations' => $this->structuredWorkOperations(
                    $runsByTurn->get($turn->id, collect()),
                ),
                'created_at' => $turn->created_at?->toIso8601String(),
            ])
            ->all();
    }

    /** @param Collection<int,AssistantRun> $runs @return list<array<string,mixed>> */
    private function structuredWorkOperations(Collection $runs): array
    {
        return $runs
            ->take(12)
            ->map(function (AssistantRun $run): array {
                $operation = json_decode((string) $run->input, true);
                $operation = is_array($operation) ? $operation : [];
                $arguments = is_array($operation['arguments'] ?? null) ? $operation['arguments'] : [];
                $tool = trim((string) data_get($run->metadata, 'semantic_tool', $operation['tool'] ?? ''));
                $receiptData = data_get($run->metadata, 'semantic_operation_receipt.data');
                $receiptData = is_array($receiptData) ? $receiptData : [];
                $eventData = data_get($receiptData, 'events.0.data');
                $eventData = is_array($eventData) ? $eventData : [];
                [$resourceType, $resourceId, $resourceTitle] = $this->safeWorkResourceDescriptor(
                    $tool,
                    $arguments,
                    $eventData,
                );

                return [
                    'operation_id' => trim((string) data_get($run->metadata, 'semantic_operation_id', $operation['id'] ?? '')),
                    'tool' => $tool,
                    'label' => mb_substr(trim((string) $run->label), 0, 180),
                    'run_status' => $run->status,
                    'resource_type' => $resourceType,
                    'resource_id' => $resourceId,
                    'resource_title' => $resourceTitle,
                    'completed_at' => $run->completed_at?->toIso8601String(),
                ];
            })
            ->all();
    }

    /**
     * Return only app-owned resource identity. Never copy arbitrary operation
     * query/context text, note bodies, transcripts, or composed responses.
     *
     * @param  array<string,mixed>  $arguments
     * @param  array<string,mixed>  $eventData
     * @return array{0:?string,1:?int,2:?string}
     */
    private function safeWorkResourceDescriptor(
        string $tool,
        array $arguments,
        array $eventData,
    ): array {
        [$resourceType, $receiptIdKey] = match (true) {
            str_starts_with($tool, 'app.task.') => ['task', 'task_id'],
            str_starts_with($tool, 'app.reminder.') => ['reminder', 'reminder_id'],
            str_starts_with($tool, 'app.calendar.') => ['calendar_event', 'calendar_event_id'],
            str_starts_with($tool, 'app.note.') => ['note', 'note_id'],
            str_starts_with($tool, 'app.memory.') => ['memory', 'memory_item_id'],
            default => [null, null],
        };
        if ($resourceType === null || $receiptIdKey === null) {
            return [null, null, null];
        }

        $resourceId = filter_var($eventData[$receiptIdKey] ?? null, FILTER_VALIDATE_INT) !== false
            ? (int) $eventData[$receiptIdKey]
            : (filter_var($arguments['id'] ?? null, FILTER_VALIDATE_INT) !== false
                ? (int) $arguments['id']
                : null);
        $resourceTitle = is_string($eventData['title'] ?? null)
            ? trim((string) $eventData['title'])
            : (is_string($arguments['title'] ?? null) ? trim((string) $arguments['title']) : '');

        return [
            $resourceType,
            $resourceId !== null && $resourceId > 0 ? $resourceId : null,
            $resourceTitle !== '' ? mb_substr($resourceTitle, 0, 240) : null,
        ];
    }
}
