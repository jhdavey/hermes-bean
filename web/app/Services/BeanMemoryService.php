<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\MemoryEvent;
use App\Models\MemoryItem;
use App\Models\MemorySummary;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class BeanMemoryService
{
    public const TURN_ACTIVITY_EVENT_TYPE = 'conversation.turn_activity';

    public const CANONICAL_TYPES = [
        'fact',
        'preference',
        'identity',
        'relationship',
        'project',
        'routine',
        'constraint',
        'decision',
        'instruction',
        'temporary_context',
    ];

    public function __construct(private readonly WorkspaceService $workspaces) {}

    public function createItem(User $user, Workspace $workspace, array $attributes, ?User $actor = null): MemoryItem
    {
        $attributes = $this->normalizedItemAttributes($attributes);
        $content = (string) $attributes['content'];
        $type = (string) $attributes['type'];
        $status = (string) ($attributes['status'] ?? 'active');

        $existing = MemoryItem::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->where('type', $type)
            ->where('status', $status)
            ->whereRaw('lower(content) = ?', [mb_strtolower($content)])
            ->first();

        if ($existing) {
            $existing->forceFill([
                'confidence' => max((int) $existing->confidence, (int) $attributes['confidence']),
                'importance' => max((int) $existing->importance, (int) $attributes['importance']),
                'last_seen_at' => now(),
                'metadata' => array_replace_recursive($existing->metadata ?? [], $attributes['metadata'] ?? []),
            ])->save();

            return $existing->refresh();
        }

        $item = MemoryItem::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $actor?->id ?? $user->id,
            ...$attributes,
            'last_seen_at' => $attributes['last_seen_at'] ?? now(),
        ]);

        return $item->refresh();
    }

    public function updateItem(User $user, MemoryItem $item, array $attributes): MemoryItem
    {
        $this->authorizeItem($user, $item);
        $item->update($this->normalizedItemAttributes($attributes, partial: true));

        return $item->refresh();
    }

    public function forgetItem(User $user, MemoryItem $item): void
    {
        $this->authorizeItem($user, $item);
        $item->update(['status' => 'archived']);
        $item->delete();
    }

    public function recordTurnActivity(ConversationSession $session, ConversationMessage $userMessage, ConversationMessage $assistantMessage): void
    {
        if ((int) $userMessage->conversation_session_id !== (int) $session->id
            || (int) $assistantMessage->conversation_session_id !== (int) $session->id) {
            throw new \InvalidArgumentException('Turn activity messages must belong to the supplied conversation session.');
        }

        DB::transaction(function () use ($session, $userMessage, $assistantMessage): void {
            Workspace::query()->whereKey($session->workspace_id)->lockForUpdate()->firstOrFail();

            $event = MemoryEvent::query()->createOrFirst([
                'conversation_message_id' => $userMessage->id,
                'event_type' => self::TURN_ACTIVITY_EVENT_TYPE,
            ], [
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'conversation_session_id' => $session->id,
                'assistant_message_id' => $assistantMessage->id,
                'status' => 'processed',
                'content' => null,
                'payload' => null,
                'processed_at' => now(),
            ]);

            $this->upsertDailySummary($event);
        }, 3);
    }

    public function searchMemory(User $user, Workspace $workspace, array $filters = []): array
    {
        return $this->memoryQuery($user, $workspace, $filters)
            ->orderByDesc('importance')
            ->orderByDesc('confidence')
            ->latest('last_seen_at')
            ->latest('updated_at')
            ->limit($this->limit($filters))
            ->get()
            ->map(fn (MemoryItem $item): array => $this->memoryPayload($item))
            ->values()
            ->all();
    }

    public function requestHistory(
        ConversationSession $session,
        array $filters = [],
        ?string $trustedTimezone = null,
    ): array {
        [$from, $to, $timezone] = $this->dateWindow($session, $filters, $trustedTimezone);
        $query = ConversationMessage::query()
            ->where('user_id', $session->user_id)
            ->where('role', 'user')
            ->with('session')
            ->when($from && $to, fn (Builder $query) => $query->whereBetween('created_at', [$from, $to]));

        if (filled($filters['query'] ?? null)) {
            if ((bool) ($filters['strict_query'] ?? false)) {
                $this->whereStrictContent($query, (string) $filters['query'], ['content']);
            } else {
                $this->whereLooseContent($query, (string) $filters['query'], ['content']);
            }
        }
        if (filled($filters['exclude_message_id'] ?? null)) {
            $query->whereKeyNot((int) $filters['exclude_message_id']);
        }
        if (filled($filters['workspace_id'] ?? null)) {
            $query->whereHas('session', fn (Builder $sessionQuery) => $sessionQuery->where('workspace_id', (int) $filters['workspace_id']));
        }

        return $query
            ->latest('created_at')
            ->latest('id')
            ->limit($this->limit($filters))
            ->get()
            ->sortBy('created_at')
            ->map(fn (ConversationMessage $message): array => [
                'id' => $message->id,
                'session_id' => $message->conversation_session_id,
                'workspace_id' => $message->session?->workspace_id,
                'content' => str($message->content)->squish()->limit(1200, '')->toString(),
                'created_at' => $timezone !== null
                    ? $message->created_at?->copy()->setTimezone($timezone)->toIso8601String()
                    : $message->created_at?->copy()->utc()->toIso8601String(),
                'created_at_utc' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function activityTimeline(
        ConversationSession $session,
        array $filters = [],
        ?string $trustedTimezone = null,
    ): array {
        [$from, $to, $timezone] = $this->dateWindow($session, $filters, $trustedTimezone);
        $query = ActivityEvent::query()
            ->where('user_id', $session->user_id)
            ->when($from && $to, fn (Builder $query) => $query->whereBetween('created_at', [$from, $to]));

        if (filled($filters['workspace_id'] ?? null)) {
            $query->where('workspace_id', (int) $filters['workspace_id']);
        }
        if (filled($filters['event_type'] ?? null)) {
            $query->where('event_type', 'like', '%'.addcslashes((string) $filters['event_type'], '%_\\').'%');
        }
        if (filled($filters['tool_name'] ?? null)) {
            $query->where('tool_name', (string) $filters['tool_name']);
        }

        return $query
            ->latest('created_at')
            ->latest('id')
            ->limit($this->limit($filters))
            ->get()
            ->sortBy('created_at')
            ->map(fn (ActivityEvent $event): array => [
                'id' => $event->id,
                'session_id' => $event->conversation_session_id,
                'workspace_id' => $event->workspace_id,
                'event_type' => $event->event_type,
                'tool_name' => $event->tool_name,
                'status' => $event->status,
                'payload' => $event->payload,
                'created_at' => $timezone !== null
                    ? $event->created_at?->copy()->setTimezone($timezone)->toIso8601String()
                    : $event->created_at?->copy()->utc()->toIso8601String(),
                'created_at_utc' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return Builder<MemoryItem>
     */
    private function memoryQuery(User $user, Workspace $workspace, array $filters = []): Builder
    {
        $query = MemoryItem::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->where(function (Builder $query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            });

        if (! (bool) ($filters['include_archived'] ?? false)) {
            $query->where('status', 'active');
        }
        if (filled($filters['type'] ?? null)) {
            $query->where('type', (string) $filters['type']);
        }
        if (filled($filters['status'] ?? null)) {
            $query->where('status', (string) $filters['status']);
        }
        if (filled($filters['query'] ?? null)) {
            $this->whereLooseContent($query, (string) $filters['query'], ['content']);
        }

        return $query;
    }

    private function normalizedItemAttributes(array $attributes, bool $partial = false): array
    {
        if (! $partial && ! array_key_exists('type', $attributes)) {
            throw new \InvalidArgumentException('Memory type is required.');
        }

        $normalized = [];
        foreach ([
            'type', 'status', 'visibility', 'title', 'content', 'summary',
            'source_type', 'source_id', 'last_seen_at', 'last_verified_at', 'expires_at', 'metadata',
        ] as $field) {
            if (array_key_exists($field, $attributes)) {
                $normalized[$field] = $attributes[$field];
            }
        }

        if (array_key_exists('type', $attributes)) {
            $type = $attributes['type'];
            if (! is_string($type) || ! in_array($type, self::CANONICAL_TYPES, true)) {
                throw new \InvalidArgumentException('Memory type must be an explicit canonical type.');
            }
            $normalized['type'] = $type;
        }
        if (! $partial || array_key_exists('status', $attributes)) {
            $normalized['status'] = (string) ($attributes['status'] ?? 'active');
        }
        if (! $partial || array_key_exists('visibility', $attributes)) {
            $normalized['visibility'] = (string) ($attributes['visibility'] ?? 'workspace');
        }
        if (! $partial || array_key_exists('content', $attributes)) {
            $content = trim((string) ($attributes['content'] ?? ''));
            if ($content === '') {
                throw new \InvalidArgumentException('Memory content cannot be empty.');
            }
            $normalized['content'] = $content;
        }
        foreach (['confidence' => 70, 'importance' => 50] as $field => $default) {
            if (! $partial || array_key_exists($field, $attributes)) {
                $normalized[$field] = max(0, min(100, (int) ($attributes[$field] ?? $default)));
            }
        }

        return $normalized;
    }

    private function upsertDailySummary(MemoryEvent $event): void
    {
        $createdAt = $event->created_at ?: now();
        $periodKey = $createdAt->toDateString();
        $userRequests = MemoryEvent::query()
            ->where('user_id', $event->user_id)
            ->where('workspace_id', $event->workspace_id)
            ->where('event_type', self::TURN_ACTIVITY_EVENT_TYPE)
            ->where('status', 'processed')
            ->whereBetween('created_at', [$createdAt->copy()->startOfDay(), $createdAt->copy()->endOfDay()])
            ->count();

        MemorySummary::updateOrCreate([
            'user_id' => $event->user_id,
            'workspace_id' => $event->workspace_id,
            'summary_type' => 'daily_activity',
            'period_key' => $periodKey,
        ], [
            'title' => 'Bean activity for '.$periodKey,
            'summary' => $userRequests.' user request'.($userRequests === 1 ? '' : 's').' recorded for this workspace day. Use request history for exact wording.',
            'starts_at' => $createdAt->copy()->startOfDay(),
            'ends_at' => $createdAt->copy()->endOfDay(),
            'metadata' => ['request_count' => $userRequests],
        ]);
    }

    private function whereLooseContent(Builder $query, string $text, array $columns): void
    {
        $terms = collect(preg_split('/\s+/u', mb_strtolower($text)) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B'\".,!?-"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 2)
            ->unique()
            ->take(8)
            ->values();
        $escapedText = addcslashes($text, '%_\\');
        $query->where(function (Builder $query) use ($terms, $escapedText, $columns): void {
            foreach (array_values($columns) as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}($column, 'like', '%'.$escapedText.'%');
            }
            foreach ($terms as $term) {
                $escaped = addcslashes($term, '%_\\');
                foreach ($columns as $column) {
                    $query->orWhere($column, 'like', '%'.$escaped.'%');
                }
            }
        });
    }

    private function whereStrictContent(Builder $query, string $text, array $columns): void
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?: '');
        if ($text === '') {
            return;
        }

        $terms = collect(preg_split('/\s+/u', mb_strtolower($text)) ?: [])
            ->map(fn (string $term): string => trim($term, " \t\n\r\0\x0B'\".,!?-"))
            ->filter(fn (string $term): bool => mb_strlen($term) >= 3)
            ->reject(fn (string $term): bool => in_array($term, [
                'about', 'after', 'any', 'asked', 'earlier', 'for', 'make', 'made',
                'note', 'notes', 'request', 'requested', 'smoke', 'the', 'this',
            ], true))
            ->unique()
            ->take(8)
            ->values();

        $escapedText = addcslashes($text, '%_\\');
        $query->where(function (Builder $query) use ($terms, $escapedText, $columns): void {
            foreach (array_values($columns) as $index => $column) {
                $method = $index === 0 ? 'where' : 'orWhere';
                $query->{$method}($column, 'like', '%'.$escapedText.'%');
            }

            if ($terms->isEmpty()) {
                return;
            }

            $query->orWhere(function (Builder $query) use ($terms, $columns): void {
                foreach ($terms as $term) {
                    $escaped = addcslashes($term, '%_\\');
                    $query->where(function (Builder $query) use ($columns, $escaped): void {
                        foreach (array_values($columns) as $index => $column) {
                            $method = $index === 0 ? 'where' : 'orWhere';
                            $query->{$method}($column, 'like', '%'.$escaped.'%');
                        }
                    });
                }
            });
        });
    }

    private function memoryPayload(MemoryItem $item, bool $compact = false): array
    {
        $payload = [
            'id' => $item->id,
            'workspace_id' => $item->workspace_id,
            'type' => $item->type,
            'status' => $item->status,
            'visibility' => $item->visibility,
            'title' => $item->title,
            'content' => $compact ? str($item->content)->squish()->limit(500, '')->toString() : $item->content,
            'confidence' => $item->confidence,
            'importance' => $item->importance,
            'last_seen_at' => $item->last_seen_at?->toIso8601String(),
            'updated_at' => $item->updated_at?->toIso8601String(),
        ];

        if (! $compact) {
            $payload['summary'] = $item->summary;
            $payload['source_type'] = $item->source_type;
            $payload['source_id'] = $item->source_id;
            $payload['expires_at'] = $item->expires_at?->toIso8601String();
            $payload['metadata'] = $item->metadata;
        }

        return $payload;
    }

    private function authorizeItem(User $user, MemoryItem $item): void
    {
        if ((int) $item->user_id !== (int) $user->id) {
            abort(404);
        }
        if ($item->workspace_id) {
            $this->workspaces->authorizeMember($user, Workspace::findOrFail($item->workspace_id));
        }
    }

    /**
     * @return array{0:?Carbon,1:?Carbon,2:?string}
     */
    private function dateWindow(
        ConversationSession $session,
        array $filters,
        ?string $trustedTimezone = null,
    ): array {
        $timezone = $this->validTimezone($trustedTimezone) ?? $this->sessionTimezone($session);
        $aliases = array_values(array_intersect(['date', 'from_date', 'to_date'], array_keys($filters)));
        if ($aliases !== []) {
            throw new \InvalidArgumentException('History intervals accept only canonical from and to timestamps.');
        }

        $hasFrom = is_string($filters['from'] ?? null) && trim($filters['from']) !== '';
        $hasTo = is_string($filters['to'] ?? null) && trim($filters['to']) !== '';
        if ($hasFrom !== $hasTo) {
            throw new \InvalidArgumentException('History intervals require both from and to timestamps.');
        }

        if (! $hasFrom) {
            return [null, null, $timezone];
        }

        $from = $this->absoluteHistoryInstant($filters['from'], 'from');
        $to = $this->absoluteHistoryInstant($filters['to'], 'to');
        if ($to->lt($from)) {
            throw new \InvalidArgumentException('History interval to cannot be before from.');
        }

        return [$from, $to, $timezone];
    }

    private function absoluteHistoryInstant(mixed $value, string $field): Carbon
    {
        if (! is_string($value)
            || preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2})(?:\.(\d{1,6}))?)?(Z|[+-](\d{2}):(\d{2}))$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])
            || (int) $parts[4] > 23
            || (int) $parts[5] > 59
            || (isset($parts[6]) && $parts[6] !== '' && (int) $parts[6] > 59)) {
            throw new \InvalidArgumentException("History interval {$field} must be an absolute ISO-8601 timestamp with an explicit offset.");
        }

        if ($parts[8] !== 'Z') {
            $offsetHour = (int) ($parts[9] ?? 0);
            $offsetMinute = (int) ($parts[10] ?? 0);
            if ($offsetHour > 14 || $offsetMinute > 59 || ($offsetHour === 14 && $offsetMinute !== 0)) {
                throw new \InvalidArgumentException("History interval {$field} must use a valid explicit offset.");
            }
        }

        return Carbon::parse($value)->utc();
    }

    private function sessionTimezone(ConversationSession $session): ?string
    {
        $message = $session->messages()->whereNotNull('metadata')->latest('id')->first();
        $context = data_get($message?->metadata ?? [], 'client_context');
        if (! is_array($context)) {
            return null;
        }

        return $this->validTimezone($context['timezone'] ?? null)
            ?? $this->validTimezone($context['timezone_offset'] ?? null);
    }

    private function validTimezone(mixed $timezone): ?string
    {
        $timezone = is_string($timezone) ? trim($timezone) : '';
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

    private function limit(array $filters): int
    {
        return max(1, min((int) ($filters['limit'] ?? 20), 50));
    }
}
