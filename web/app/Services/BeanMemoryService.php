<?php

namespace App\Services;

use App\Jobs\ExtractBeanMemoryFromTurn;
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

class BeanMemoryService
{
    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly AgentProfileService $agentProfiles,
    ) {}

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
            $this->agentProfiles->refreshRuntimeMemoryForWorkspace($workspace, $user);

            return $existing->refresh();
        }

        $item = MemoryItem::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'created_by_user_id' => $actor?->id ?? $user->id,
            ...$attributes,
            'last_seen_at' => $attributes['last_seen_at'] ?? now(),
        ]);
        $this->agentProfiles->refreshRuntimeMemoryForWorkspace($workspace, $user);

        return $item->refresh();
    }

    public function updateItem(User $user, MemoryItem $item, array $attributes): MemoryItem
    {
        $this->authorizeItem($user, $item);
        $item->update($this->normalizedItemAttributes($attributes, partial: true));
        if ($item->workspace) {
            $this->agentProfiles->refreshRuntimeMemoryForWorkspace($item->workspace, $user);
        }

        return $item->refresh();
    }

    public function forgetItem(User $user, MemoryItem $item): void
    {
        $this->authorizeItem($user, $item);
        $workspace = $item->workspace;
        $item->update(['status' => 'archived']);
        $item->delete();
        if ($workspace) {
            $this->agentProfiles->refreshRuntimeMemoryForWorkspace($workspace, $user);
        }
    }

    public function recordTurnCandidate(ConversationSession $session, ConversationMessage $userMessage, ?ConversationMessage $assistantMessage = null): void
    {
        $event = MemoryEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'conversation_message_id' => $userMessage->id,
            'assistant_message_id' => $assistantMessage?->id,
            'event_type' => 'conversation.turn',
            'status' => 'pending',
            'content' => str($userMessage->content)->squish()->limit(2000, '')->toString(),
            'payload' => [
                'user_message' => $userMessage->content,
                'assistant_message' => $assistantMessage?->content,
                'metadata' => $userMessage->metadata,
            ],
        ]);

        ExtractBeanMemoryFromTurn::dispatch($event->id)->afterResponse();
    }

    public function processEvent(int $memoryEventId): void
    {
        $event = MemoryEvent::find($memoryEventId);
        if (! $event || $event->status !== 'pending') {
            return;
        }

        $user = User::find($event->user_id);
        $workspace = Workspace::find($event->workspace_id);
        if (! $user || ! $workspace) {
            $event->update(['status' => 'skipped', 'processed_at' => now()]);

            return;
        }

        $created = 0;
        foreach ($this->heuristicMemories((string) data_get($event->payload ?? [], 'user_message', $event->content ?? '')) as $memory) {
            $this->createItem($user, $workspace, [
                ...$memory,
                'source_type' => 'conversation_message',
                'source_id' => $event->conversation_message_id,
                'metadata' => [
                    'memory_event_id' => $event->id,
                    'extraction' => 'heuristic',
                ],
            ], $user);
            $created++;
        }

        $this->upsertDailySummary($event);
        $event->update([
            'status' => $created > 0 ? 'processed' : 'skipped',
            'processed_at' => now(),
            'payload' => [
                ...(is_array($event->payload) ? $event->payload : []),
                'created_memory_items' => $created,
            ],
        ]);
    }

    public function runtimeContext(User $user, Workspace $workspace, string $query = '', int $limit = 8): array
    {
        $items = $this->memoryQuery($user, $workspace, ['query' => $query])
            ->orderByDesc('importance')
            ->orderByDesc('confidence')
            ->latest('last_seen_at')
            ->latest('updated_at')
            ->limit(max(1, min($limit, 12)))
            ->get()
            ->map(fn (MemoryItem $item): array => $this->memoryPayload($item, compact: true))
            ->values()
            ->all();

        $summaries = MemorySummary::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->orderByDesc('ends_at')
            ->orderByDesc('updated_at')
            ->limit(3)
            ->get()
            ->map(fn (MemorySummary $summary): array => [
                'id' => $summary->id,
                'summary_type' => $summary->summary_type,
                'period_key' => $summary->period_key,
                'title' => $summary->title,
                'summary' => str($summary->summary)->squish()->limit(700, '')->toString(),
            ])
            ->values()
            ->all();

        return [
            'policy' => [
                'hot_path_limit' => max(1, min($limit, 12)),
                'retrieval' => 'bounded_indexed',
                'note' => 'Raw history is available through recall tools; only this compact memory slice is injected automatically.',
            ],
            'items' => $items,
            'summaries' => $summaries,
        ];
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

    public function requestHistory(ConversationSession $session, array $filters = []): array
    {
        [$from, $to, $timezone] = $this->dateWindow($session, $filters);
        $query = ConversationMessage::query()
            ->where('user_id', $session->user_id)
            ->where('role', 'user')
            ->with('session')
            ->when($from && $to, fn (Builder $query) => $query->whereBetween('created_at', [$from, $to]));

        if (filled($filters['query'] ?? null)) {
            $this->whereLooseContent($query, (string) $filters['query'], ['content', 'title', 'summary']);
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
                'created_at' => $message->created_at?->copy()->setTimezone($timezone)->toIso8601String(),
                'created_at_utc' => $message->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    public function activityTimeline(ConversationSession $session, array $filters = []): array
    {
        [$from, $to, $timezone] = $this->dateWindow($session, $filters);
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
                'created_at' => $event->created_at?->copy()->setTimezone($timezone)->toIso8601String(),
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
        $normalized = [];
        foreach ([
            'type', 'status', 'visibility', 'title', 'content', 'summary',
            'source_type', 'source_id', 'last_seen_at', 'last_verified_at', 'expires_at', 'metadata',
        ] as $field) {
            if (array_key_exists($field, $attributes)) {
                $normalized[$field] = $attributes[$field];
            }
        }

        if (! $partial || array_key_exists('type', $attributes)) {
            $normalized['type'] = $this->memoryType((string) ($attributes['type'] ?? 'fact'));
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

    private function memoryType(string $type): string
    {
        $type = str($type)->lower()->replace([' ', '-'], '_')->toString();

        return in_array($type, ['preference', 'identity', 'relationship', 'project', 'routine', 'constraint', 'decision', 'instruction', 'temporary_context', 'fact'], true)
            ? $type
            : 'fact';
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function heuristicMemories(string $message): array
    {
        $text = trim(preg_replace('/\s+/', ' ', $message) ?: '');
        if ($text === '') {
            return [];
        }

        $lower = mb_strtolower($text);
        $explicit = preg_match('/\b(?:remember|save this|keep in mind|don\'t forget)\b/i', $text) === 1;
        $preference = preg_match('/\b(?:i prefer|i like|i don\'t like|please always|always|never|don\'t|do not)\b/i', $text) === 1;
        $identity = preg_match('/\b(?:my name is|call me|i am|i\'m|my wife|my husband|my son|my daughter|my dog|my company)\b/i', $text) === 1;
        $project = preg_match('/\b(?:project|working on|miata|client|build|renovation|launch)\b/i', $text) === 1;

        if (! $explicit && ! $preference && ! $identity && ! $project) {
            return [];
        }

        $type = $preference ? 'preference' : ($identity ? 'identity' : ($project ? 'project' : 'fact'));
        if (preg_match('/\b(?:always|never|don\'t|do not)\b/i', $text) === 1) {
            $type = 'instruction';
        }

        return [[
            'type' => $type,
            'content' => str($text)->replaceMatches('/^(hey bean,?\s*)/i', '')->limit(500, '')->toString(),
            'confidence' => $explicit ? 95 : 70,
            'importance' => $explicit ? 75 : 55,
        ]];
    }

    private function upsertDailySummary(MemoryEvent $event): void
    {
        $createdAt = $event->created_at ?: now();
        $periodKey = $createdAt->toDateString();
        $userRequests = ConversationMessage::query()
            ->where('user_id', $event->user_id)
            ->where('role', 'user')
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
     * @return array{0:?Carbon,1:?Carbon,2:string}
     */
    private function dateWindow(ConversationSession $session, array $filters): array
    {
        $timezone = $this->sessionTimezone($session);
        $fromDate = trim((string) ($filters['from_date'] ?? $filters['date'] ?? ''));
        $toDate = trim((string) ($filters['to_date'] ?? $filters['date'] ?? ''));

        if ($fromDate === '' && $toDate === '') {
            return [null, null, $timezone];
        }

        $from = Carbon::parse($fromDate ?: $toDate, $timezone)->startOfDay()->utc();
        $to = Carbon::parse($toDate ?: $fromDate, $timezone)->endOfDay()->utc();

        return [$from, $to, $timezone];
    }

    private function sessionTimezone(ConversationSession $session): string
    {
        $message = $session->messages()->whereNotNull('metadata')->latest('id')->first();
        $timezone = data_get($message?->metadata ?? [], 'client_context.timezone');

        return is_string($timezone) && $timezone !== '' ? $timezone : config('app.timezone', 'UTC');
    }

    private function limit(array $filters): int
    {
        return max(1, min((int) ($filters['limit'] ?? 20), 50));
    }
}
