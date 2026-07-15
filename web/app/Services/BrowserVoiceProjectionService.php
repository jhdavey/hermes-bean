<?php

namespace App\Services;

use App\Data\HermesSemanticOperation;
use App\Enums\VoiceRealtimeCommandStatus;
use App\Enums\VoiceRealtimeCommandType;
use App\Enums\VoiceTurnLane;
use App\Enums\VoiceTurnState;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Throwable;

final class BrowserVoiceProjectionService
{
    public const SOURCE = 'browser_voice_realtime';

    private const MAX_TURNS = 100;

    private const MAX_EVENTS = 500;

    private const SPEECH_PURPOSES = ['acknowledgement', 'clarification', 'final'];

    private const SPEECH_AUTHORIZATION_EVENT = 'speech_authorized';

    private const PLAYBACK_EVENT_TYPES = [
        'acknowledgement_started',
        'final_audio_started',
        'playback_started',
    ];

    private const SAFE_EVENT_CONTAINERS = [
        'milestones',
        'playback_authorization',
        'speech_authorization',
        'timing',
    ];

    private const SAFE_EVENT_STRING_FIELDS = [
        'authorization_id',
        'command_id',
        'directive_id',
        'operation_id',
        'phase',
        'provider_event_id',
        'provider_input_item_id',
        'provider_response_id',
        'purpose',
        'realtime_session_id',
        'speech_item_id',
        'status',
        'turn_id',
    ];

    private const SAFE_EVENT_INTEGER_FIELDS = [
        'attempts',
        'controller_generation',
        'duration_ms',
        'event_count',
        'expected_version',
        'input_generation',
        'job_id',
        'latency_ms',
        'occurred_at_ms',
        'operation_count',
        'phase_deadline_ms',
        'provider_connection_generation',
        'retry_count',
        'run_id',
        'semantic_sequence',
        'version',
    ];

    private const SAFE_EVENT_BOOLEAN_FIELDS = [
        'changed',
        'close_after_response',
        'raw_audio_retained',
        'response_expected',
        'side_effect_committed',
        'sideband_ready',
        'stopped',
    ];

    /** @return array<string, mixed> */
    public function forTurn(VoiceTurn $turn): array
    {
        $turn->loadMissing(['runs', 'realtimeSession:id,public_id']);
        $events = VoiceTurnEvent::query()
            ->where('voice_turn_id', $turn->id)
            ->orderBy('id')
            ->get();
        $playbackKeys = $this->playbackKeys($events);
        $authorizationMetadata = $this->speechAuthorizationMetadata($events);

        return [
            'source' => self::SOURCE,
            'turn' => $this->turn($turn, $this->finalAudioStarted($events)),
            'jobs' => $turn->runs
                ->map(fn (AssistantRun $run): array => $this->job($run, $turn))
                ->values()
                ->all(),
            'events' => $events
                ->map(fn (VoiceTurnEvent $event): array => $this->event($event, $turn->turn_id))
                ->values()
                ->all(),
            'speech_authorizations' => $this->speechAuthorizations(
                collect([$turn]),
                $playbackKeys,
                $authorizationMetadata,
            ),
            'cursor' => (int) ($events->max('id') ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    public function forSession(ConversationSession $session, int $afterCursor = 0): array
    {
        $afterCursor = max(0, $afterCursor);
        $turns = $this->projectableTurns($session);
        $turns->load(['runs', 'realtimeSession:id,public_id']);
        $turnIds = $turns->pluck('id');
        $stableTurnIds = $turns->pluck('turn_id', 'id');
        $supportEvents = $turnIds->isEmpty()
            ? collect()
            : VoiceTurnEvent::query()
                ->whereIn('voice_turn_id', $turnIds)
                ->whereIn('event_type', [
                    self::SPEECH_AUTHORIZATION_EVENT,
                    ...self::PLAYBACK_EVENT_TYPES,
                ])
                ->orderBy('id')
                ->get();
        $playbackKeys = $this->playbackKeys($supportEvents);
        $authorizationMetadata = $this->speechAuthorizationMetadata($supportEvents);
        $finalAudioTurnIds = $supportEvents
            ->filter(fn (VoiceTurnEvent $event): bool => $this->isFinalAudioStart($event))
            ->pluck('voice_turn_id')
            ->unique();

        $eventBatch = $turnIds->isEmpty()
            ? collect()
            : VoiceTurnEvent::query()
                ->whereIn('voice_turn_id', $turnIds)
                ->where('id', '>', $afterCursor)
                ->orderBy('id')
                ->limit(self::MAX_EVENTS + 1)
                ->get();
        $hasMoreEvents = $eventBatch->count() > self::MAX_EVENTS;
        $events = $eventBatch->take(self::MAX_EVENTS)->values();
        $sessionCursor = $turnIds->isEmpty()
            ? 0
            : (int) (VoiceTurnEvent::query()->whereIn('voice_turn_id', $turnIds)->max('id') ?? 0);
        $cursor = $hasMoreEvents
            ? (int) ($events->last()?->id ?? $afterCursor)
            : max($afterCursor, $sessionCursor);

        return [
            'source' => self::SOURCE,
            'turn' => null,
            'turns' => $turns
                ->map(fn (VoiceTurn $turn): array => $this->turn(
                    $turn,
                    $finalAudioTurnIds->contains($turn->id),
                ))
                ->values()
                ->all(),
            'jobs' => $turns
                ->flatMap(fn (VoiceTurn $turn) => $turn->runs->map(
                    fn (AssistantRun $run): array => $this->job($run, $turn),
                ))
                ->values()
                ->all(),
            'events' => $events
                ->map(fn (VoiceTurnEvent $event): array => $this->event(
                    $event,
                    (string) ($stableTurnIds[$event->voice_turn_id] ?? ''),
                ))
                ->values()
                ->all(),
            'speech_authorizations' => $this->speechAuthorizations(
                $turns,
                $playbackKeys,
                $authorizationMetadata,
            ),
            'cursor' => $cursor,
        ];
    }

    public function hasEventsAfter(ConversationSession $session, int $cursor): bool
    {
        return VoiceTurnEvent::query()
            ->where('conversation_session_id', $session->id)
            ->where('id', '>', max(0, $cursor))
            ->whereHas('turn', fn (Builder $turns): Builder => $turns
                ->where('source', self::SOURCE)
                ->where('display_mode', 'voice_only'))
            ->exists();
    }

    /** @return Collection<int, VoiceTurn> */
    private function projectableTurns(ConversationSession $session): Collection
    {
        $terminalStates = [
            VoiceTurnState::Completed->value,
            VoiceTurnState::Failed->value,
            VoiceTurnState::Canceled->value,
        ];
        $base = VoiceTurn::query()
            ->where('conversation_session_id', $session->id)
            ->where('source', self::SOURCE)
            ->where('display_mode', 'voice_only');
        $active = (clone $base)
            ->whereNotIn('state', $terminalStates)
            ->orderBy('id')
            ->limit(self::MAX_TURNS)
            ->get();
        $remaining = max(0, self::MAX_TURNS - $active->count());
        $terminal = $remaining === 0
            ? collect()
            : (clone $base)
                ->whereIn('state', $terminalStates)
                ->where('terminal_at', '>=', now()->subHours(24))
                ->latest('id')
                ->limit($remaining)
                ->get()
                ->reverse()
                ->values();

        return $active->concat($terminal)->sortBy('id')->values();
    }

    /** @return array<string, mixed> */
    private function turn(VoiceTurn $turn, bool $finalAudioStarted): array
    {
        $metadata = is_array($turn->metadata) ? $turn->metadata : [];
        $stopDirective = is_array($metadata['playback_stop_directive'] ?? null)
            ? $metadata['playback_stop_directive']
            : [];
        $stopDirectiveId = $this->safeIdentifier($stopDirective['id'] ?? null);
        $stopDirectivePending = $stopDirectiveId !== null
            && blank($stopDirective['acknowledged_at'] ?? null);
        $clarificationResolutions = is_array($metadata['clarification_resolutions'] ?? null)
            ? $metadata['clarification_resolutions']
            : [];
        $resolvedClarificationIds = collect(array_keys($clarificationResolutions))
            ->map(fn (mixed $id): ?string => $this->safeIdentifier($id))
            ->filter()
            ->values()
            ->all();

        return [
            'id' => $turn->id,
            'turn_id' => $turn->turn_id,
            'session_id' => $turn->conversation_session_id,
            'workspace_id' => $turn->workspace_id,
            'realtime_session_id' => $turn->realtimeSession?->public_id,
            'provider_input_item_id' => $this->safeIdentifier($turn->provider_input_item_id),
            'source' => self::SOURCE,
            'display_mode' => 'voice_only',
            'visible_in_chat' => false,
            'state' => $turn->state->value,
            'version' => $turn->version,
            'acknowledgement_required' => (bool) $turn->acknowledgement_required,
            'acknowledged_at' => $turn->acknowledged_at?->toIso8601String(),
            'accepted_at' => $turn->accepted_at?->toIso8601String(),
            'started_at' => $turn->started_at?->toIso8601String(),
            'first_progress_at' => $turn->first_progress_at?->toIso8601String(),
            'terminal_at' => $turn->terminal_at?->toIso8601String(),
            'final_audio_started' => $finalAudioStarted,
            'deadlines' => [
                'hard_at' => $turn->hard_deadline_at?->toIso8601String(),
                'no_progress_at' => $turn->no_progress_deadline_at?->toIso8601String(),
            ],
            'failure' => $turn->failure_category === null ? null : [
                'category' => $this->safeSlug($turn->failure_category),
                'retry_eligible' => $turn->retry_count < 1,
            ],
            'side_effect_status' => $turn->side_effect_status->value,
            'retry_count' => $turn->retry_count,
            'controller_generation' => max(0, (int) ($metadata['controller_generation'] ?? 0)),
            'provider_connection_generation' => max(0, (int) ($metadata['provider_connection_generation'] ?? 0)),
            'input_generation' => max(0, (int) ($metadata['input_generation'] ?? 0)),
            'semantic_sequence' => max(1, (int) ($metadata['semantic_sequence'] ?? 1)),
            'awaiting_provider_input' => ($metadata['awaiting_provider_input'] ?? false) === true,
            'clarification_pending' => $turn->state === VoiceTurnState::AwaitingClarification,
            'clarification_sequence' => max(0, (int) ($metadata['clarification_sequence'] ?? 0)),
            'resolved_clarification_ids' => $resolvedClarificationIds,
            'close_after_response' => (bool) data_get($metadata, 'response_directives.close_after_response', false),
            'response_expected' => (bool) data_get($metadata, 'response_directives.response_expected', false),
            'stop_playback' => $stopDirectivePending,
            'stop_playback_directive_id' => $stopDirectivePending ? $stopDirectiveId : null,
            'created_at' => $turn->created_at?->toIso8601String(),
            'updated_at' => $turn->updated_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function job(AssistantRun $run, VoiceTurn $turn): array
    {
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $tool = is_string($metadata['semantic_tool'] ?? null)
            && in_array($metadata['semantic_tool'], HermesSemanticOperation::TOOLS, true)
                ? $metadata['semantic_tool']
                : null;
        $role = in_array($metadata['role'] ?? null, ['composition', 'interpretation', 'operation'], true)
            ? $metadata['role']
            : null;
        $lane = VoiceTurnLane::tryFrom((string) $run->lane)?->value;
        $status = $run->status === 'cancelled' ? 'canceled' : $run->status;
        if (! in_array($status, ['canceled', 'completed', 'failed', 'finalizing', 'queued', 'running'], true)) {
            $status = 'unknown';
        }

        return [
            'id' => $run->id,
            'turn_id' => $turn->turn_id,
            'source' => self::SOURCE,
            'label' => $this->safeJobLabel($tool, $role),
            'status' => $status,
            'version' => $run->updated_at === null
                ? 0
                : (int) $run->updated_at->format('Uu'),
            'lane' => $lane,
            'role' => $role,
            'operation_id' => $this->safeIdentifier($metadata['semantic_operation_id'] ?? null),
            'tool' => $tool,
            'dependency_run_ids' => collect($metadata['dependency_run_ids'] ?? [])
                ->filter(fn (mixed $id): bool => is_int($id) && $id > 0)
                ->values()
                ->all(),
            'priority' => (int) $run->priority,
            'hard_deadline_at' => $run->hard_deadline_at?->toIso8601String(),
            'last_progress_at' => $run->last_progress_at?->toIso8601String(),
            'dispatch_requested_at' => $run->dispatch_requested_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    private function safeJobLabel(?string $tool, ?string $role): string
    {
        if ($role === 'interpretation') {
            return 'Understanding request';
        }
        if ($role === 'composition') {
            return 'Preparing response';
        }
        if ($tool === null) {
            return 'Working';
        }

        return match ($tool) {
            'system.clock.read' => 'Checking time and date',
            'system.voice_state.read' => 'Checking voice state',
            'external.lookup' => 'Looking up information',
            'voice.playback.stop' => 'Stopping playback',
            'voice.work.status' => 'Checking work status',
            'voice.work.cancel' => 'Canceling work',
            'app.history.search' => 'Searching request history',
            'app.activity.search' => 'Searching activity history',
            'app.day.read' => 'Checking the day',
            default => ucfirst(str_replace(
                ['app.', '.create', '.update', '.delete', '.search', '_'],
                ['', '', '', '', '', ' '],
                $tool,
            )).' work',
        };
    }

    /** @return array<string, mixed> */
    private function event(VoiceTurnEvent $event, string $stableTurnId): array
    {
        return [
            'id' => (int) $event->id,
            'cursor' => (int) $event->id,
            'turn_id' => $stableTurnId,
            'sequence' => $event->sequence,
            'type' => $this->safeSlug($event->event_type) ?? 'unknown',
            'from_state' => $this->safeSlug($event->from_state),
            'to_state' => $this->safeSlug($event->to_state),
            'version' => $event->version,
            'source' => self::SOURCE,
            'metadata' => $this->safeEventMetadata($event->payload ?? []),
            'created_at' => $event->created_at?->toIso8601String(),
        ];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function safeEventMetadata(array $payload): array
    {
        if (array_is_list($payload)) {
            return [];
        }

        $safe = [];
        foreach ($payload as $key => $value) {
            if (! is_string($key)) {
                continue;
            }
            if (in_array($key, self::SAFE_EVENT_CONTAINERS, true) && is_array($value)) {
                $nested = $this->safeEventMetadata($value);
                if ($nested !== []) {
                    $safe[$key] = $nested;
                }

                continue;
            }
            if (in_array($key, self::SAFE_EVENT_STRING_FIELDS, true) && is_string($value)) {
                $normalized = $key === 'purpose'
                    ? (in_array($value, self::SPEECH_PURPOSES, true) ? $value : null)
                    : ($key === 'phase' || $key === 'status'
                        ? $this->safeSlug($value)
                        : $this->safeIdentifier($value));
                if ($normalized !== null) {
                    $safe[$key] = $normalized;
                }

                continue;
            }
            if (in_array($key, self::SAFE_EVENT_INTEGER_FIELDS, true)
                && is_int($value)
                && $value >= 0) {
                $safe[$key] = $value;

                continue;
            }
            if (in_array($key, self::SAFE_EVENT_BOOLEAN_FIELDS, true) && is_bool($value)) {
                $safe[$key] = $value;
            }
        }

        return $safe;
    }

    /**
     * @param  Collection<int, VoiceTurn>  $turns
     * @param  array<string, true>  $playbackKeys
     * @param  array<string, array<string, mixed>>  $authorizationMetadata
     * @return list<array<string, mixed>>
     */
    private function speechAuthorizations(
        Collection $turns,
        array $playbackKeys,
        array $authorizationMetadata,
    ): array {
        $turnIds = $turns->pluck('id');
        if ($turnIds->isEmpty()) {
            return [];
        }
        $stableTurnIds = $turns->pluck('turn_id', 'id');

        return VoiceRealtimeCommand::query()
            ->with('realtimeSession:id,public_id,metadata')
            ->whereIn('voice_turn_id', $turnIds)
            ->where('command_type', VoiceRealtimeCommandType::ResponseCreate->value)
            ->whereNotNull('purpose')
            ->whereNotNull('speech_item_id')
            ->whereNotNull('approved_text_hash')
            ->orderBy('id')
            ->get()
            ->map(function (VoiceRealtimeCommand $command) use (
                $authorizationMetadata,
                $stableTurnIds,
                $playbackKeys,
            ): ?array {
                $purpose = in_array($command->purpose, self::SPEECH_PURPOSES, true)
                    ? $command->purpose
                    : null;
                $speechItemId = $this->safeIdentifier($command->speech_item_id);
                $authorizationId = $this->safeIdentifier($command->command_id);
                $approvedTextHash = $this->safeSha256($command->approved_text_hash);
                $realtimeSessionId = $command->realtimeSession?->public_id;
                $stableTurnId = (string) ($stableTurnIds[$command->voice_turn_id] ?? '');
                if ($purpose === null
                    || $speechItemId === null
                    || $authorizationId === null
                    || $approvedTextHash === null
                    || $this->safeIdentifier($realtimeSessionId) === null
                    || $this->safeIdentifier($stableTurnId) === null) {
                    return null;
                }

                $binding = data_get($command->payload, 'response.metadata');
                $authorizationEvent = $authorizationMetadata[$authorizationId] ?? null;
                if (! is_array($binding) || ! is_array($authorizationEvent)) {
                    return null;
                }

                $bindingAuthorizationId = $this->safeIdentifier($binding['authorization_id'] ?? null);
                $bindingTurnId = $this->safeIdentifier($binding['turn_id'] ?? null);
                $bindingSpeechItemId = $this->safeIdentifier($binding['speech_item_id'] ?? null);
                $bindingPurpose = in_array($binding['purpose'] ?? null, self::SPEECH_PURPOSES, true)
                    ? $binding['purpose']
                    : null;
                $bindingRealtimeSessionId = $this->safeIdentifier($binding['realtime_session_id'] ?? null);
                $controllerGeneration = $this->nonNegativeInteger($binding['controller_generation'] ?? null);
                $providerConnectionGeneration = $this->nonNegativeInteger(
                    $binding['provider_connection_generation'] ?? null,
                );
                $bindingApprovedTextHash = $this->safeSha256($binding['approved_text_sha256'] ?? null);
                $playbackCapability = $this->safeIdentifier($binding['playback_capability'] ?? null);
                $sessionPlaybackCapability = $this->safeIdentifier(
                    data_get($command->realtimeSession?->metadata, 'playback_capability'),
                );
                $expiresAt = $this->futureExpiry($binding['expires_at'] ?? null);
                if ($bindingAuthorizationId !== $authorizationId
                    || $bindingTurnId !== $stableTurnId
                    || $bindingSpeechItemId !== $speechItemId
                    || $bindingPurpose !== $purpose
                    || $bindingRealtimeSessionId !== $realtimeSessionId
                    || $controllerGeneration !== $command->controller_generation
                    || $bindingApprovedTextHash !== $approvedTextHash
                    || $playbackCapability === null
                    || $sessionPlaybackCapability !== $playbackCapability
                    || $expiresAt === null
                    || $authorizationEvent !== [
                        'authorization_id' => $authorizationId,
                        'turn_id' => $stableTurnId,
                        'speech_item_id' => $speechItemId,
                        'purpose' => $purpose,
                        'realtime_session_id' => $realtimeSessionId,
                        'controller_generation' => $controllerGeneration,
                        'provider_connection_generation' => $providerConnectionGeneration,
                        'approved_text_sha256' => $approvedTextHash,
                        'playback_capability' => $playbackCapability,
                        'expires_at' => $expiresAt,
                    ]) {
                    return null;
                }

                $status = $command->status->value;
                $consumed = isset($playbackKeys[$purpose.'|'.$speechItemId]);
                $authorized = ! $consumed && in_array($command->status, [
                    VoiceRealtimeCommandStatus::Queued,
                    VoiceRealtimeCommandStatus::Sending,
                    VoiceRealtimeCommandStatus::Sent,
                    VoiceRealtimeCommandStatus::Acknowledged,
                ], true)
                    && $providerConnectionGeneration !== null;
                if (! $authorized) {
                    return null;
                }

                return [
                    'authorization_id' => $authorizationId,
                    'turn_id' => $stableTurnId,
                    'realtime_session_id' => $realtimeSessionId,
                    'type' => VoiceRealtimeCommandType::ResponseCreate->value,
                    'purpose' => $purpose,
                    'speech_item_id' => $speechItemId,
                    'controller_generation' => $controllerGeneration,
                    'provider_connection_generation' => $providerConnectionGeneration,
                    'approved_text_sha256' => $approvedTextHash,
                    'playback_capability' => $playbackCapability,
                    'expires_at' => $expiresAt,
                    'provider_response_id' => $this->safeIdentifier($command->provider_response_id),
                    'status' => $status,
                    'authorized' => true,
                    'consumed' => false,
                    'single_use' => true,
                    'created_at' => $command->created_at?->toIso8601String(),
                    'updated_at' => $command->updated_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, VoiceTurnEvent>  $events
     * @return array<string, array<string, mixed>>
     */
    private function speechAuthorizationMetadata(Collection $events): array
    {
        $authorizations = [];
        foreach ($events as $event) {
            if ($event->event_type !== self::SPEECH_AUTHORIZATION_EVENT
                || ! is_array($event->payload)) {
                continue;
            }
            $payload = $event->payload;
            $authorizationId = $this->safeIdentifier($payload['authorization_id'] ?? null);
            if ($authorizationId === null) {
                continue;
            }
            $purpose = in_array($payload['purpose'] ?? null, self::SPEECH_PURPOSES, true)
                ? $payload['purpose']
                : null;
            $expiresAt = $this->futureExpiry($payload['expires_at'] ?? null);
            $authorization = [
                'authorization_id' => $authorizationId,
                'turn_id' => $this->safeIdentifier($payload['turn_id'] ?? null),
                'speech_item_id' => $this->safeIdentifier($payload['speech_item_id'] ?? null),
                'purpose' => $purpose,
                'realtime_session_id' => $this->safeIdentifier($payload['realtime_session_id'] ?? null),
                'controller_generation' => $this->nonNegativeInteger($payload['controller_generation'] ?? null),
                'provider_connection_generation' => $this->nonNegativeInteger(
                    $payload['provider_connection_generation'] ?? null,
                ),
                'approved_text_sha256' => $this->safeSha256($payload['approved_text_sha256'] ?? null),
                'playback_capability' => $this->safeIdentifier($payload['playback_capability'] ?? null),
                'expires_at' => $expiresAt,
            ];
            if (! in_array(null, $authorization, true)) {
                $authorizations[$authorizationId] = $authorization;
            }
        }

        return $authorizations;
    }

    /** @param Collection<int, VoiceTurnEvent> $events @return array<string, true> */
    private function playbackKeys(Collection $events): array
    {
        $keys = [];
        foreach ($events as $event) {
            $purpose = match ($event->event_type) {
                'acknowledgement_started' => 'acknowledgement',
                'final_audio_started' => 'final',
                'playback_started' => data_get($event->payload, 'purpose'),
                default => null,
            };
            $speechItemId = $this->safeIdentifier(data_get($event->payload, 'speech_item_id'));
            if (in_array($purpose, self::SPEECH_PURPOSES, true)
                && $speechItemId !== null) {
                $keys[$purpose.'|'.$speechItemId] = true;
            }
        }

        return $keys;
    }

    /** @param Collection<int, VoiceTurnEvent> $events */
    private function finalAudioStarted(Collection $events): bool
    {
        return $events->contains(fn (VoiceTurnEvent $event): bool => $this->isFinalAudioStart($event));
    }

    private function isFinalAudioStart(VoiceTurnEvent $event): bool
    {
        return $event->event_type === 'final_audio_started'
            || ($event->event_type === 'playback_started'
                && data_get($event->payload, 'purpose') === 'final');
    }

    private function safeIdentifier(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,190}\z/', $value) === 1
            ? $value
            : null;
    }

    private function safeSlug(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }
        $value = trim($value);

        return preg_match('/\A[a-z0-9][a-z0-9._:-]{0,99}\z/', $value) === 1
            ? $value
            : null;
    }

    private function safeSha256(mixed $value): ?string
    {
        return is_string($value) && preg_match('/\A[a-f0-9]{64}\z/', $value) === 1
            ? $value
            : null;
    }

    private function nonNegativeInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value >= 0 ? $value : null;
        }
        if (! is_string($value)
            || preg_match('/\A(?:0|[1-9][0-9]{0,18})\z/', $value) !== 1) {
            return null;
        }
        $integer = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);

        return is_int($integer) ? $integer : null;
    }

    private function futureExpiry(mixed $value): ?string
    {
        if (! is_string($value)
            || preg_match(
                '/\A[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}(?:\.[0-9]{1,6})?(?:Z|[+-][0-9]{2}:[0-9]{2})\z/',
                $value,
            ) !== 1) {
            return null;
        }

        try {
            return CarbonImmutable::parse($value)->isFuture() ? $value : null;
        } catch (Throwable) {
            return null;
        }
    }
}
