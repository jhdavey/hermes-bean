<?php

namespace App\Services\Bean;

use App\Models\BeanConfirmationRequest;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\BeanToolCall;
use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceItemLink;
use App\Services\Bean\External\ExternalLookupService;
use App\Services\Domain\DomainResourceCatalog;
use App\Services\Domain\DomainResourceService;
use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Throwable;

class BeanActionExecutor
{
    public function __construct(
        private readonly BeanActivityLogger $activity,
        private readonly DomainResourceService $domainResources,
        private readonly DomainResourceCatalog $resourceCatalog,
        private readonly ExternalLookupService $externalLookup,
    ) {}

    public function execute(BeanSession $session, BeanRun $run, string $action, array $arguments = [], bool $confirmed = false): array
    {
        $arguments = $this->normalizeArguments($action, $arguments);

        $tool = BeanToolCall::create([
            'bean_run_id' => $run->id,
            'user_id' => $run->user_id,
            'workspace_id' => $run->workspace_id,
            'action' => $action,
            'arguments' => $arguments,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->markRunProgress($run, $action, 'running');
        $this->activity->log($session, $run, 'tool_started', $this->labelFor($action), ['action' => $action, 'progress' => $this->progressSnapshot($action, 'running')]);

        try {
            if ($this->isDestructive($action) && ! $confirmed) {
                $confirmation = BeanConfirmationRequest::create([
                    'bean_session_id' => $session->id,
                    'bean_run_id' => $run->id,
                    'user_id' => $run->user_id,
                    'workspace_id' => $run->workspace_id,
                    'action' => $action,
                    'arguments' => $arguments,
                    'summary' => $this->confirmationSummary($action, $arguments),
                    'status' => 'pending',
                ]);
                $result = ['ok' => false, 'requires_confirmation' => true, 'confirmation_id' => $confirmation->id, 'summary' => $confirmation->summary];
                $tool->update(['status' => 'waiting_confirmation', 'requires_confirmation' => true, 'result' => $result, 'completed_at' => now()]);
                $this->activity->log($session, $run, 'confirmation_requested', $confirmation->summary, $result);
                return $result;
            }

            $result = match ($action) {
                'dashboard.summary' => $this->dashboardSummary($run),
                'resource.query' => $this->genericResourceQuery($run, $arguments),
                'resource.relationships' => $this->resourceRelationships($run, $arguments),
                'time.now' => $this->timeNow(),
                'external.lookup' => $this->externalLookup($arguments),
                'task.list' => $this->listResources(Task::class, $run, 'due_at', $arguments),
                'task.search' => $this->searchResources(Task::class, $run, $arguments),
                'task.context' => $this->resourceContext(Task::class, $run, $arguments),
                'task.create' => $this->createTask($run, $arguments),
                'task.update' => $this->updateResource(Task::class, $run, $arguments, ['title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'due_at', 'completed_at', 'metadata']),
                'task.complete' => $this->completeResource(Task::class, $run, $arguments, 'completed_at'),
                'task.delete' => $this->deleteResource(Task::class, $run, $arguments),
                'reminder.list' => $this->listResources(Reminder::class, $run, 'remind_at', $arguments),
                'reminder.search' => $this->searchResources(Reminder::class, $run, $arguments),
                'reminder.create' => $this->createReminder($run, $arguments),
                'reminder.update' => $this->updateResource(Reminder::class, $run, $arguments, ['title', 'notes', 'category', 'color', 'is_critical', 'remind_at', 'status', 'metadata']),
                'reminder.complete' => $this->completeResource(Reminder::class, $run, $arguments, null),
                'reminder.delete' => $this->deleteResource(Reminder::class, $run, $arguments),
                'calendar_event.list' => $this->listResources(CalendarEvent::class, $run, 'starts_at', $arguments),
                'calendar_event.search' => $this->searchResources(CalendarEvent::class, $run, $arguments),
                'calendar_event.create' => $this->createCalendarEvent($run, $arguments),
                'calendar_event.update' => $this->updateResource(CalendarEvent::class, $run, $arguments, ['title', 'description', 'location', 'category', 'color', 'is_critical', 'recurrence', 'starts_at', 'ends_at', 'all_day', 'status', 'metadata']),
                'calendar_event.delete' => $this->deleteResource(CalendarEvent::class, $run, $arguments),
                'note.list' => $this->listResources(Note::class, $run, 'updated_at', $arguments),
                'note.search' => $this->searchResources(Note::class, $run, $arguments, ['title', 'plain_text']),
                'note.create' => $this->createNote($run, $arguments),
                'note.update' => $this->updateResource(Note::class, $run, $arguments, ['title', 'body_html', 'plain_text', 'body_delta', 'is_pinned', 'metadata']),
                'note.delete' => $this->deleteResource(Note::class, $run, $arguments),
                default => ['ok' => false, 'error' => "Unsupported Bean action: {$action}"],
            };

            $status = ($result['ok'] ?? false) ? 'completed' : 'failed';
            $tool->update(['status' => $status, 'result' => $result, 'error' => $result['error'] ?? null, 'completed_at' => now()]);
            $this->markRunProgress($run, $action, $status, $result);
            $this->activity->log($session, $run, $status === 'completed' ? 'tool_completed' : 'tool_failed', $this->resultLabel($action, $result), ['action' => $action, 'result' => $result, 'progress' => $this->progressSnapshot($action, $status, $result)]);
            return $result;
        } catch (HttpResponseException $exception) {
            $payload = json_decode((string) $exception->getResponse()->getContent(), true) ?: [];
            $message = (string) ($payload['message'] ?? data_get($payload, 'error.message') ?? 'Bean could not complete that action.');
            $result = ['ok' => false, 'error' => $message, 'response' => $payload];
            $tool->update(['status' => 'failed', 'result' => $result, 'error' => $message, 'completed_at' => now()]);
            $this->markRunProgress($run, $action, 'failed', $result);
            $this->activity->log($session, $run, 'tool_failed', $this->resultLabel($action, $result), ['action' => $action, 'result' => $result, 'progress' => $this->progressSnapshot($action, 'failed', $result)]);
            return $result;
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception->getMessage()];
            $tool->update(['status' => 'failed', 'result' => $result, 'error' => $exception->getMessage(), 'completed_at' => now()]);
            $this->markRunProgress($run, $action, 'failed', $result);
            $this->activity->log($session, $run, 'tool_failed', $this->resultLabel($action, $result), ['action' => $action, 'result' => $result, 'progress' => $this->progressSnapshot($action, 'failed', $result)]);
            return $result;
        }
    }

    private function workspaceIds(BeanRun $run): array
    {
        return app(WorkspaceService::class)->accessibleWorkspaces($this->user($run))->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function user(BeanRun $run): User
    {
        return User::findOrFail($run->user_id);
    }

    private function workspaceId(BeanRun $run): ?int
    {
        return $run->workspace_id ?: ($this->workspaceIds($run)[0] ?? null);
    }

    private function baseQuery(string $class, BeanRun $run): Builder
    {
        return $class::query()->whereIn('workspace_id', $this->workspaceIds($run));
    }

    private function normalizeArguments(string $action, array $arguments): array
    {
        $class = $this->classForReadAction($action, $arguments);
        if ($class === null) return $arguments;

        $arguments = $this->normalizeStatusArgument($class, $arguments);

        $field = $this->temporalField($class);
        if ($field === null) return $arguments;
        $arguments = $this->normalizeInlineReadFilters($class, $field, $arguments);

        $timeLabel = $this->timeLabel($arguments) ?? $this->inferTemporalLabelFromFilters($class, $field, $arguments);
        if ($timeLabel === null) return $arguments;
        $arguments['time_label'] = $timeLabel;

        $temporalFilters = $this->temporalFilters($class, $field, $timeLabel);
        if ($temporalFilters === []) return $arguments;

        $existing = collect(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [])
            ->filter(fn ($filter): bool => is_array($filter) && ($filter['field'] ?? null) !== $field)
            ->values()
            ->all();
        $arguments['filters'] = array_merge($existing, $temporalFilters);
        if (! isset($arguments['sort']) && in_array($action, ['task.list', 'reminder.list', 'calendar_event.list', 'resource.query'], true)) {
            $arguments['sort'] = [['field' => $field, 'direction' => 'asc']];
        }

        return $arguments;
    }

    private function normalizeStatusArgument(string $class, array $arguments): array
    {
        $status = strtolower(trim((string) ($arguments['status'] ?? '')));
        if ($status === '') {
            unset($arguments['status']);
            return $arguments;
        }

        $normalized = $this->resourceCatalog->normalizeStatusForClass($class, $status);
        if ($normalized !== null) {
            $arguments['status'] = $normalized;
            if ($status === 'overdue' && $this->timeLabel($arguments) === null) {
                $arguments['time_label'] = 'overdue';
            }
        }
        if (strtolower(trim((string) ($arguments['status'] ?? ''))) === 'overdue' && $this->timeLabel($arguments) === null) {
            $arguments['time_label'] = 'overdue';
        }

        return $arguments;
    }

    private function normalizeInlineReadFilters(string $class, string $field, array $arguments): array
    {
        $hasStructuredFilter = collect(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [])
            ->contains(fn ($filter): bool => is_array($filter) && ($filter['field'] ?? null) === $field);
        if ($hasStructuredFilter) {
            unset($arguments[$field]);
            return $arguments;
        }

        $start = $arguments[$field] ?? null;
        if ($start === null || $start === '') return $arguments;

        $filters = is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [];
        if ($class === CalendarEvent::class && ($arguments['ends_at'] ?? null) !== null) {
            $filters[] = ['field' => $field, 'operator' => 'between', 'value' => [$start, $arguments['ends_at']]];
            unset($arguments[$field], $arguments['ends_at']);
        } else {
            $filters[] = ['field' => $field, 'operator' => '=', 'value' => $start];
            unset($arguments[$field]);
        }
        $arguments['filters'] = array_values(array_filter($filters, 'is_array'));

        return $arguments;
    }

    private function inferTemporalLabelFromFilters(string $class, string $field, array $arguments): ?string
    {
        foreach (is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [] as $filter) {
            if (! is_array($filter) || ($filter['field'] ?? null) !== $field) continue;
            $operator = strtolower((string) ($filter['operator'] ?? '='));
            $value = $filter['value'] ?? null;
            if ($operator === 'between' && is_array($value) && count($value) >= 2) {
                $label = $this->timeLabelForRange($value[0], $value[1]);
                if ($label !== null) return $label;
            }
            if (in_array($operator, ['<', '<='], true) && in_array($class, [Task::class, Reminder::class], true)) {
                return 'overdue';
            }
        }

        return null;
    }

    private function timeLabelForRange(mixed $start, mixed $end): ?string
    {
        $startDate = $this->dateOrNull($start);
        $endDate = $this->dateOrNull($end);
        if (! $startDate || ! $endDate) return null;

        foreach (['today' => now(), 'tomorrow' => now()->addDay()] as $label => $date) {
            $dateStart = $date->copy()->startOfDay();
            $dateEnd = $date->copy()->setTime(23, 59, 59);
            if ($startDate->isSameDay($date) && $endDate->isSameDay($date) && $startDate->lte($dateStart) && $endDate->gte($dateEnd)) {
                return $label;
            }
        }

        return null;
    }

    private function classForReadAction(string $action, array $arguments): ?string
    {
        return match ($action) {
            'task.list', 'task.search' => Task::class,
            'reminder.list', 'reminder.search' => Reminder::class,
            'calendar_event.list', 'calendar_event.search' => CalendarEvent::class,
            'note.list', 'note.search' => Note::class,
            'resource.query', 'resource.relationships' => $this->classForResourceName((string) ($arguments['resource'] ?? '')),
            default => null,
        };
    }

    private function classForResourceName(string $resource): ?string
    {
        return $this->resourceCatalog->classForResource($resource);
    }

    private function temporalField(string $class): ?string
    {
        return $this->resourceCatalog->temporalFieldForClass($class);
    }

    private function temporalFilters(string $class, string $field, string $timeLabel): array
    {
        $start = now()->startOfDay()->toIso8601String();
        $end = now()->endOfDay()->toIso8601String();
        if ($timeLabel === 'tomorrow') {
            $start = now()->addDay()->startOfDay()->toIso8601String();
            $end = now()->addDay()->endOfDay()->toIso8601String();
        }

        return match ($timeLabel) {
            'overdue' => [['field' => $field, 'operator' => '<', 'value' => $start]],
            'today' => $class === CalendarEvent::class
                ? [['field' => $field, 'operator' => 'between', 'value' => [$start, $end]]]
                : [['field' => $field, 'operator' => '<=', 'value' => $end]],
            'tomorrow' => [['field' => $field, 'operator' => 'between', 'value' => [$start, $end]]],
            default => [],
        };
    }

    private function listResources(string $class, BeanRun $run, string $orderField, array $arguments = []): array
    {
        $query = $this->baseQuery($class, $run);
        $this->applyDefaultReadConstraints($query, $class, $arguments);
        if (($arguments['status'] ?? null) !== null) {
            $query->where('status', (string) $arguments['status']);
        }
        $this->applyStructuredFilters($query, $class, $arguments['filters'] ?? []);
        $accessibleWorkspaceIds = $this->workspaceIds($run);
        $totalItems = (clone $query)->get();
        $totalCount = count($this->collapseLinkedSummaries($this->summaries($totalItems, $accessibleWorkspaceIds)));
        $order = $this->primarySort($class, $arguments, $orderField);
        $items = $query->orderBy($order['field'], $order['direction'])->orderBy('id')->limit($this->limit($arguments))->get();
        $summaries = $this->collapseLinkedSummaries($this->summaries($items, $accessibleWorkspaceIds));

        return [
            'ok' => true,
            'items' => $summaries,
            'total_count' => $totalCount,
            'returned_count' => count($summaries),
            'limit' => $this->limit($arguments),
            'filters' => array_values(array_filter(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [], 'is_array')),
            'time_label' => $this->timeLabel($arguments),
        ];
    }

    private function applyDefaultReadConstraints(Builder $query, string $class, array $arguments): void
    {
        if (($arguments['status'] ?? null) !== null || $this->hasFilter($arguments, 'status')) return;

        $activeStatus = $this->resourceCatalog->activeStatusForClass($class);
        if ($activeStatus !== null) {
            $query->where('status', $activeStatus);
        }
    }

    private function applyStructuredFilters(Builder $query, string $class, mixed $filters): void
    {
        if (! is_array($filters)) return;

        $allowed = $this->filterableFields($class);
        foreach ($filters as $filter) {
            if (! is_array($filter)) continue;
            $field = (string) ($filter['field'] ?? '');
            $operator = strtolower((string) ($filter['operator'] ?? '='));
            $value = $filter['value'] ?? null;
            if (! in_array($field, $allowed, true)) continue;

            if ($operator === 'between' && is_array($value) && count($value) >= 2) {
                $query->whereNotNull($field)->whereBetween($field, [$this->filterValue($field, $value[0]), $this->filterValue($field, $value[1])]);
                continue;
            }
            if ($operator === 'in' && is_array($value)) {
                $query->whereIn($field, array_values($value));
                continue;
            }
            if ($operator === 'like' && is_scalar($value)) {
                $query->where($field, 'like', '%'.addcslashes((string) $value, '%_\\').'%');
                continue;
            }
            if ($operator === '=' && $this->isTemporalField($field) && $this->isDateOnlyValue($value)) {
                $date = Carbon::parse((string) $value, config('app.timezone', 'UTC'));
                $query->whereNotNull($field)->whereBetween($field, [$date->copy()->startOfDay(), $date->copy()->endOfDay()]);
                continue;
            }
            if (in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true)) {
                $query->whereNotNull($field)->where($field, $operator, $this->filterValue($field, $value));
            }
        }
    }

    private function filterValue(string $field, mixed $value): mixed
    {
        if ($this->isTemporalField($field)) {
            return $this->dateOrNull($value) ?: $value;
        }

        return $value;
    }

    private function isTemporalField(string $field): bool
    {
        return in_array($field, ['due_at', 'completed_at', 'remind_at', 'starts_at', 'ends_at', 'updated_at', 'created_at'], true);
    }

    private function isDateOnlyValue(mixed $value): bool
    {
        return is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1;
    }

    private function filterableFields(string $class): array
    {
        return $this->resourceCatalog->filterableFieldsForClass($class);
    }

    private function hasFilter(array $arguments, string $field): bool
    {
        foreach (is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [] as $filter) {
            if (is_array($filter) && ($filter['field'] ?? null) === $field) return true;
        }
        return array_key_exists($field, $arguments) && $arguments[$field] !== null;
    }

    private function primarySort(string $class, array $arguments, string $fallback): array
    {
        $sort = is_array($arguments['sort'] ?? null) ? ($arguments['sort'][0] ?? null) : null;
        $field = is_array($sort) ? (string) ($sort['field'] ?? '') : '';
        $direction = is_array($sort) && strtolower((string) ($sort['direction'] ?? 'asc')) === 'desc' ? 'desc' : 'asc';
        if (! in_array($field, $this->filterableFields($class), true)) $field = $fallback;

        return ['field' => $field, 'direction' => $direction];
    }

    private function limit(array $arguments): int
    {
        $limit = (int) ($arguments['limit'] ?? 20);
        return max(1, min(50, $limit));
    }

    private function timeLabel(array $arguments): ?string
    {
        $label = strtolower(trim((string) ($arguments['time_label'] ?? '')));
        return $label !== '' ? $label : null;
    }

    private function searchResources(string $class, BeanRun $run, array $arguments, array $fields = ['title']): array
    {
        $queryText = trim((string) ($arguments['query'] ?? $arguments['title'] ?? ''));
        $query = $this->baseQuery($class, $run);
        if ($queryText !== '') {
            $query->where(function (Builder $builder) use ($fields, $queryText): void {
                foreach ($fields as $field) {
                    $builder->orWhere($field, 'like', '%'.addcslashes($queryText, '%_\\').'%');
                }
            });
        }
        $this->applyDefaultReadConstraints($query, $class, $arguments);
        if (($arguments['status'] ?? null) !== null) {
            $query->where('status', (string) $arguments['status']);
        }
        $this->applyStructuredFilters($query, $class, $arguments['filters'] ?? []);
        $totalCount = (clone $query)->count();
        $items = $query->orderByDesc('updated_at')->limit($this->limit($arguments))->get();

        return [
            'ok' => true,
            'items' => $this->summaries($items, $this->workspaceIds($run)),
            'total_count' => $totalCount,
            'returned_count' => $items->count(),
            'limit' => $this->limit($arguments),
            'filters' => array_values(array_filter(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [], 'is_array')),
            'time_label' => $this->timeLabel($arguments),
        ];
    }

    private function resourceContext(string $class, BeanRun $run, array $arguments): array
    {
        $match = $this->findContextModel($class, $run, $arguments);
        if (! ($match['ok'] ?? false)) return $match;
        /** @var Model $model */
        $model = $match['model'];
        return [
            'ok' => true,
            'context_type' => 'workspace',
            'resource_type' => $this->resourceType($model),
            'item' => $this->summary($model, $this->workspaceIds($run)),
        ];
    }

    private function genericResourceQuery(BeanRun $run, array $arguments): array
    {
        $resource = strtolower(trim((string) ($arguments['resource'] ?? 'tasks')));
        $class = $this->resourceCatalog->classForResource($resource) ?? Task::class;
        $orderField = $this->resourceCatalog->temporalFieldForClass($class) ?? 'updated_at';
        $label = $this->resourceCatalog->resourceForClass($class) ?? 'tasks';

        $query = $this->baseQuery($class, $run);
        if (isset($arguments['id'])) {
            $query->whereKey((int) $arguments['id']);
        }
        $text = trim((string) ($arguments['query'] ?? $arguments['title'] ?? ''));
        if ($text !== '') {
            $fields = $class === Note::class ? ['title', 'plain_text'] : ['title'];
            $tokens = collect(preg_split('/\s+/', mb_strtolower($text)) ?: [])
                ->map(fn ($token): string => trim($token))
                ->filter(fn (string $token): bool => mb_strlen($token) >= 2)
                ->unique()
                ->values();
            if ($tokens->isNotEmpty()) {
                $query->where(function (Builder $outer) use ($fields, $tokens): void {
                    foreach ($tokens as $token) {
                        $outer->where(function (Builder $inner) use ($fields, $token): void {
                            foreach ($fields as $field) {
                                $inner->orWhere($field, 'like', '%'.addcslashes($token, '%_\\').'%');
                            }
                        });
                    }
                });
            }
        }

        $this->applyDefaultReadConstraints($query, $class, $arguments);
        if (($arguments['status'] ?? null) !== null) {
            $query->where('status', (string) $arguments['status']);
        }
        $this->applyStructuredFilters($query, $class, $arguments['filters'] ?? []);
        if (($arguments['workspace_id'] ?? null) !== null) {
            $query->where('workspace_id', (int) $arguments['workspace_id']);
        }

        $order = $this->primarySort($class, $arguments, $orderField);
        $items = $query->orderBy($order['field'], $order['direction'])->orderBy('id')->limit($this->limit($arguments))->get();
        $accessibleWorkspaceIds = $this->workspaceIds($run);
        $summaries = $this->collapseLinkedSummaries($this->summaries($items, $accessibleWorkspaceIds));
        $timeLabel = $this->timeLabel($arguments);
        $explanations = [];
        if (($arguments['explain_visibility'] ?? false) || $timeLabel !== null) {
            $explanations = collect($summaries)
                ->map(fn (array $item): array => [
                    'id' => $item['id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'reason' => $this->visibilityReason($item, $class, $timeLabel ?? ''),
                ])
                ->filter(fn (array $explanation): bool => ($explanation['reason'] ?? '') !== '')
                ->values()
                ->all();
        }

        return [
            'ok' => true,
            'resource' => $label,
            'query' => $text,
            'question' => $arguments['question'] ?? null,
            'filters' => array_values(array_filter(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [], 'is_array')),
            'time_label' => $timeLabel,
            'include_workspaces' => (bool) ($arguments['include_workspaces'] ?? false),
            'items' => $summaries,
            'explanations' => $explanations,
        ];
    }

    private function resourceRelationships(BeanRun $run, array $arguments): array
    {
        $result = $this->genericResourceQuery($run, [...$arguments, 'include_workspaces' => true]);
        $items = collect($result['items'] ?? [])->filter(fn ($item): bool => is_array($item))->values();
        return [
            ...$result,
            'relationship_type' => 'workspaces',
            'relationships' => $items->map(fn (array $item): array => [
                'id' => $item['id'] ?? null,
                'resource_type' => $item['resource_type'] ?? null,
                'title' => $item['title'] ?? null,
                'workspaces' => $item['workspace_names'] ?? [],
            ])->all(),
        ];
    }

    private function visibilityReason(array $item, string $class, string $timeLabel): string
    {
        if ($class === Task::class) {
            $title = (string) ($item['title'] ?? 'This task');
            $status = (string) ($item['status'] ?? 'open');
            $dueAt = isset($item['due_at']) ? Carbon::parse((string) $item['due_at']) : null;
            if ($timeLabel === 'today' && $status === 'open' && $dueAt) {
                return $dueAt->lt(now()->startOfDay())
                    ? "{$title} is on today's list because it is overdue and open."
                    : "{$title} is on today's list because it is due by today and open.";
            }
            if ($timeLabel === 'overdue' && $status === 'open' && $dueAt) {
                return "{$title} is overdue because it was due before today and is open.";
            }
        } elseif ($class === Reminder::class) {
            $title = (string) ($item['title'] ?? 'This reminder');
            $remindAt = isset($item['remind_at']) ? Carbon::parse((string) $item['remind_at']) : null;
            if ($timeLabel === 'today' && $remindAt) return "{$title} is included because it is scheduled by today.";
            if ($timeLabel === 'overdue' && $remindAt) return "{$title} is overdue because it was scheduled before today.";
        }
        return '';
    }

    private function createTask(BeanRun $run, array $args): array
    {
        $task = $this->domainResources->createTask($this->user($run), [
            'workspace_id' => $this->workspaceId($run),
            'title' => trim((string) ($args['title'] ?? 'New task')) ?: 'New task',
            'type' => $args['type'] ?? 'todo',
            'status' => $args['status'] ?? 'open',
            'notes' => $args['notes'] ?? null,
            'category' => $args['category'] ?? null,
            'color' => $args['color'] ?? null,
            'is_critical' => $args['is_critical'] ?? null,
            'due_at' => $args['due_at'] ?? null,
            'completed_at' => $args['completed_at'] ?? null,
            'metadata' => $this->metadata($args),
        ]);
        return ['ok' => true, 'resource_type' => 'task', 'item' => $this->summary($task, $this->workspaceIds($run))];
    }

    private function createReminder(BeanRun $run, array $args): array
    {
        $reminder = $this->domainResources->createReminder($this->user($run), [
            'workspace_id' => $this->workspaceId($run),
            'title' => trim((string) ($args['title'] ?? 'New reminder')) ?: 'New reminder',
            'notes' => $args['notes'] ?? null,
            'category' => $args['category'] ?? null,
            'color' => $args['color'] ?? null,
            'is_critical' => $args['is_critical'] ?? null,
            'remind_at' => $args['remind_at'] ?? now()->addDay()->toIso8601String(),
            'status' => $args['status'] ?? 'scheduled',
            'metadata' => $this->metadata($args),
        ]);
        return ['ok' => true, 'resource_type' => 'reminder', 'item' => $this->summary($reminder, $this->workspaceIds($run))];
    }

    private function createCalendarEvent(BeanRun $run, array $args): array
    {
        $startsAt = $this->dateOrNull($args['starts_at'] ?? null) ?: now()->addDay()->setTime(9, 0);
        $event = $this->domainResources->createCalendarEvent($this->user($run), [
            'workspace_id' => $this->workspaceId($run),
            'title' => trim((string) ($args['title'] ?? 'New event')) ?: 'New event',
            'description' => $args['description'] ?? null,
            'location' => $args['location'] ?? null,
            'category' => $args['category'] ?? null,
            'color' => $args['color'] ?? null,
            'is_critical' => $args['is_critical'] ?? null,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => ($this->dateOrNull($args['ends_at'] ?? null) ?: (clone $startsAt)->addHour())->toIso8601String(),
            'all_day' => (bool) ($args['all_day'] ?? false),
            'status' => $args['status'] ?? 'scheduled',
            'recurrence' => (string) ($args['recurrence'] ?? 'none'),
            'metadata' => $this->metadata($args),
        ]);
        return ['ok' => true, 'resource_type' => 'calendar_event', 'item' => $this->summary($event, $this->workspaceIds($run))];
    }

    private function createNote(BeanRun $run, array $args): array
    {
        $plain = trim((string) ($args['plain_text'] ?? $args['body'] ?? $args['content'] ?? ''));
        $groundedLookup = $this->latestExternalLookupResult($run);
        if ($plain === '' && $groundedLookup !== null && (($args['grounded_from'] ?? null) === 'external.lookup' || ($args['source_action'] ?? null) === 'external.lookup')) {
            $plain = $this->externalLookupNoteText($groundedLookup);
        }
        $title = trim((string) ($args['title'] ?? '')) ?: $this->externalLookupNoteTitle($groundedLookup) ?: (str($plain ?: 'New Note')->limit(80, '')->toString());
        $metadata = $this->metadata($args);
        if ($groundedLookup !== null && (($args['grounded_from'] ?? null) === 'external.lookup' || ($args['source_action'] ?? null) === 'external.lookup')) {
            $metadata['grounded_from'] = 'external.lookup';
            $metadata['external_evidence'] = $groundedLookup['evidence'] ?? [
                'query' => $groundedLookup['query'] ?? null,
                'sources_used' => collect($groundedLookup['sources'] ?? [])->pluck('url')->filter()->values()->all(),
                'retrieved_at' => $groundedLookup['retrieved_at'] ?? now()->toIso8601String(),
                'confidence' => $groundedLookup['confidence'] ?? null,
            ];
        }
        $note = $this->domainResources->createNote($this->user($run), [
            'workspace_id' => $this->workspaceId($run),
            'title' => $title,
            'plain_text' => $plain,
            'body_html' => $args['body_html'] ?? nl2br(e($plain)),
            'body_delta' => $args['body_delta'] ?? null,
            'note_folder_id' => $args['note_folder_id'] ?? null,
            'is_pinned' => $args['is_pinned'] ?? null,
            'metadata' => $metadata,
        ]);
        return ['ok' => true, 'resource_type' => 'note', 'item' => $this->summary($note, $this->workspaceIds($run)), 'grounded_from' => $metadata['grounded_from'] ?? null, 'evidence' => $metadata['external_evidence'] ?? null];
    }

    private function updateResource(string $class, BeanRun $run, array $args, array $allowed): array
    {
        $match = $this->findOne($class, $run, $args);
        if (! ($match['ok'] ?? false)) return $match;
        /** @var Model $model */
        $model = $match['model'];
        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $args)) $updates[$field] = $args[$field];
        }
        foreach (['due_at', 'completed_at', 'remind_at', 'starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $updates)) $updates[$field] = $this->dateOrNull($updates[$field]);
        }
        if ($model instanceof CalendarEvent
            && array_key_exists('starts_at', $updates)
            && ! array_key_exists('ends_at', $updates)
            && $updates['starts_at'] instanceof Carbon
            && $model->starts_at
            && $model->ends_at
        ) {
            $updates['ends_at'] = (clone $updates['starts_at'])->addSeconds($model->starts_at->diffInSeconds($model->ends_at, false));
        }
        if ($updates === []) return ['ok' => false, 'error' => 'No update fields were provided.'];
        $user = $this->user($run);
        $updated = match (true) {
            $model instanceof Task => $this->domainResources->updateTask($user, $model, $updates),
            $model instanceof Reminder => $this->domainResources->updateReminder($user, $model, $updates),
            $model instanceof CalendarEvent => $this->domainResources->updateCalendarEvent($user, $model, $updates),
            $model instanceof Note => $this->domainResources->updateNote($user, $model, $updates),
            default => throw new \RuntimeException('Unsupported resource type.'),
        };
        return ['ok' => true, 'resource_type' => $this->resourceType($updated), 'item' => $this->summary($updated, $this->workspaceIds($run))];
    }

    private function completeResource(string $class, BeanRun $run, array $args, ?string $completedAtField): array
    {
        $match = $this->findOne($class, $run, $args);
        if (! ($match['ok'] ?? false)) return $match;
        $model = $match['model'];
        $updates = ['status' => 'completed'];
        if ($completedAtField) $updates[$completedAtField] = now();
        $user = $this->user($run);
        $updated = match (true) {
            $model instanceof Task => $this->domainResources->updateTask($user, $model, $updates),
            $model instanceof Reminder => $this->domainResources->updateReminder($user, $model, $updates),
            default => throw new \RuntimeException('Unsupported completion resource type.'),
        };
        return ['ok' => true, 'resource_type' => $this->resourceType($updated), 'item' => $this->summary($updated, $this->workspaceIds($run))];
    }

    private function deleteResource(string $class, BeanRun $run, array $args): array
    {
        $match = $this->findOne($class, $run, $args);
        if (! ($match['ok'] ?? false)) return $match;
        $model = $match['model'];
        $summary = $this->summary($model);
        $user = $this->user($run);
        $options = array_intersect_key($args, array_flip(['delete_from_workspace_ids']));
        match (true) {
            $model instanceof Task => $this->domainResources->deleteTask($user, $model, $options),
            $model instanceof Reminder => $this->domainResources->deleteReminder($user, $model, $options),
            $model instanceof CalendarEvent => $this->domainResources->deleteCalendarEvent($user, $model, $options),
            $model instanceof Note => $this->domainResources->deleteNote($user, $model, $options),
            default => throw new \RuntimeException('Unsupported resource type.'),
        };
        return ['ok' => true, 'deleted' => $summary];
    }

    private function findContextModel(string $class, BeanRun $run, array $args): array
    {
        $query = $this->baseQuery($class, $run);
        if (isset($args['id'])) {
            $model = $query->whereKey($args['id'])->first();
            return $model ? ['ok' => true, 'model' => $model] : ['ok' => false, 'error' => 'I could not find that item.'];
        }
        $text = trim((string) ($args['query'] ?? $args['title'] ?? ''));
        if ($text === '') return ['ok' => false, 'error' => 'I need a task title to check its workspace.'];
        $matches = $query->where('title', 'like', '%'.addcslashes($text, '%_\\').'%')->limit(10)->get();
        if ($matches->count() === 1) return ['ok' => true, 'model' => $matches->first()];
        if ($matches->count() > 1) {
            $accessibleWorkspaceIds = $this->workspaceIds($run);
            $first = $matches->first();
            $linkedWorkspaceIds = $this->workspaceIdsForModel($first, $accessibleWorkspaceIds);
            $matchedWorkspaceIds = $matches->pluck('workspace_id')->map(fn ($id): int => (int) $id)->unique()->values()->all();
            if ($linkedWorkspaceIds !== [] && array_diff($matchedWorkspaceIds, $linkedWorkspaceIds) === []) {
                return ['ok' => true, 'model' => $first];
            }

            return ['ok' => false, 'ambiguous' => true, 'error' => 'I found multiple matching items. Please choose one.', 'items' => $this->summaries($matches, $accessibleWorkspaceIds)];
        }
        return ['ok' => false, 'error' => 'I could not find a matching task.'];
    }

    private function findOne(string $class, BeanRun $run, array $args): array
    {
        $query = $this->baseQuery($class, $run);
        if (isset($args['id'])) {
            $model = $query->whereKey($args['id'])->first();
            return $model ? ['ok' => true, 'model' => $model] : ['ok' => false, 'error' => 'I could not find that item.'];
        }
        $text = trim((string) ($args['query'] ?? $args['title'] ?? ''));
        if ($text === '') return ['ok' => false, 'error' => 'I need an item id or title to find the record.'];
        $matches = $query->where('title', 'like', '%'.addcslashes($text, '%_\\').'%')->limit(3)->get();
        if ($matches->count() === 1) return ['ok' => true, 'model' => $matches->first()];
        if ($matches->count() > 1) return ['ok' => false, 'ambiguous' => true, 'error' => 'I found multiple matching items. Please choose one.', 'items' => $this->summaries($matches, $this->workspaceIds($run))];
        return ['ok' => false, 'error' => 'I could not find a matching item.'];
    }

    private function dashboardSummary(BeanRun $run): array
    {
        return ['ok' => true, 'summary' => [
            'tasks' => $this->baseQuery(Task::class, $run)->where('status', $this->resourceCatalog->activeStatusForClass(Task::class))->count(),
            'reminders' => $this->baseQuery(Reminder::class, $run)->where('status', $this->resourceCatalog->activeStatusForClass(Reminder::class))->count(),
            'calendar_events' => $this->baseQuery(CalendarEvent::class, $run)->where('status', $this->resourceCatalog->activeStatusForClass(CalendarEvent::class))->where('starts_at', '>=', now()->startOfDay())->count(),
            'notes' => $this->baseQuery(Note::class, $run)->count(),
        ]];
    }

    private function timeNow(): array
    {
        return ['ok' => true, 'now' => now()->toIso8601String(), 'timezone' => config('app.timezone')];
    }

    private function externalLookup(array $args): array
    {
        return $this->externalLookup->lookup($args);
    }

    private function latestExternalLookupResult(BeanRun $run): ?array
    {
        $toolCall = $run->toolCalls()
            ->where('action', 'external.lookup')
            ->where('status', 'completed')
            ->latest('id')
            ->first();
        $result = is_array($toolCall?->result) ? $toolCall->result : null;
        return ($result['ok'] ?? false) === true ? $result : null;
    }

    private function externalLookupNoteTitle(?array $lookup): ?string
    {
        if ($lookup === null) return null;
        $structured = $this->firstStructuredRecipe($lookup);
        if ($structured !== null && trim((string) ($structured['name'] ?? '')) !== '') {
            return str((string) $structured['name'])->title()->limit(80, '')->toString();
        }
        $title = trim((string) ($lookup['title'] ?? '')) ?: trim((string) ($lookup['query'] ?? ''));
        $title = preg_replace('/^search results for\s+/i', '', $title) ?: $title;
        return $title !== '' ? str($title)->title()->limit(80, '')->toString() : null;
    }

    private function externalLookupNoteText(array $lookup): string
    {
        $structured = $this->firstStructuredRecipe($lookup);
        if ($structured !== null) {
            return $this->structuredRecipeNoteText($lookup, $structured);
        }

        $lines = [];
        $query = trim((string) ($lookup['query'] ?? ''));
        if ($query !== '') $lines[] = 'Lookup: '.$query;
        $summary = trim((string) ($lookup['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = '';
            $lines[] = 'Summary:';
            $lines[] = $summary;
        }
        $claims = collect($lookup['claims'] ?? [])->filter(fn ($claim): bool => is_array($claim))->values();
        if ($claims->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Key points:';
            foreach ($claims->take(5) as $claim) {
                $text = trim((string) ($claim['text'] ?? ''));
                $url = trim((string) ($claim['source_url'] ?? ''));
                if ($text === '') continue;
                $lines[] = '- '.$text.($url !== '' ? ' (Source: '.$url.')' : '');
            }
        }
        $sources = collect($lookup['sources'] ?? [])->filter(fn ($source): bool => is_array($source))->values();
        if ($sources->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Sources:';
            foreach ($sources->take(5) as $source) {
                $title = trim((string) ($source['title'] ?? 'Source')) ?: 'Source';
                $url = trim((string) ($source['url'] ?? ''));
                $lines[] = '- '.$title.($url !== '' ? ': '.$url : '');
            }
        }

        return trim(implode("\n", $lines));
    }

    private function firstStructuredRecipe(array $lookup): ?array
    {
        foreach ($lookup['documents'] ?? [] as $document) {
            if (! is_array($document)) continue;
            foreach (data_get($document, 'structured.recipes', []) as $recipe) {
                if (is_array($recipe)) {
                    $recipe['source_url'] = $document['url'] ?? null;
                    return $recipe;
                }
            }
        }
        return null;
    }

    private function structuredRecipeNoteText(array $lookup, array $recipe): string
    {
        $lines = [];
        $name = trim((string) ($recipe['name'] ?? $lookup['title'] ?? $lookup['query'] ?? ''));
        if ($name !== '') $lines[] = $name;
        $sourceUrl = trim((string) ($recipe['source_url'] ?? $lookup['source_url'] ?? ''));
        if ($sourceUrl !== '') $lines[] = 'Source: '.$sourceUrl;
        $yield = collect($recipe['yield'] ?? [])->filter()->implode(', ');
        if ($yield !== '') $lines[] = 'Servings/Yield: '.$yield;
        $times = collect([
            'Prep' => $this->humanDuration($recipe['prep_time'] ?? null),
            'Cook' => $this->humanDuration($recipe['cook_time'] ?? null),
            'Total' => $this->humanDuration($recipe['total_time'] ?? null),
        ])->filter(fn ($value): bool => trim((string) $value) !== '');
        if ($times->isNotEmpty()) {
            $lines[] = 'Time: '.$times->map(fn ($value, $label): string => $label.' '.$value)->implode(' · ');
        }

        $ingredients = collect($recipe['ingredients'] ?? [])->filter()->values();
        if ($ingredients->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Ingredients:';
            foreach ($ingredients->take(30) as $ingredient) $lines[] = '- '.trim((string) $ingredient, " \t\n\r\0\x0B*");
        }

        $instructions = collect($recipe['instructions'] ?? [])->filter()->values();
        if ($instructions->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Instructions:';
            foreach ($instructions->take(20) as $index => $step) $lines[] = ((int) $index + 1).'. '.$step;
        }

        $sources = collect($lookup['sources'] ?? [])->filter(fn ($source): bool => is_array($source))->values();
        if ($sources->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Sources:';
            foreach ($sources->take(5) as $source) {
                $title = trim((string) ($source['title'] ?? 'Source')) ?: 'Source';
                $url = trim((string) ($source['url'] ?? ''));
                $lines[] = '- '.$title.($url !== '' ? ': '.$url : '');
            }
        }

        return trim(implode("\n", $lines));
    }

    private function humanDuration(mixed $value): string
    {
        $duration = trim((string) $value);
        if ($duration === '') return '';
        if (preg_match('/^P(?:T)?(?:(\d+)H)?(?:(\d+)M)?$/i', $duration, $match) === 1) {
            $parts = [];
            if (! empty($match[1])) $parts[] = ((int) $match[1]).' hr';
            if (! empty($match[2])) $parts[] = ((int) $match[2]).' min';
            return implode(' ', $parts);
        }
        return $duration;
    }

    private function markRunProgress(BeanRun $run, string $action, string $status, array $result = []): void
    {
        $progress = $this->progressSnapshot($action, $status, $result);
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $history = collect($metadata['progress_history'] ?? [])
            ->filter(fn ($item): bool => is_array($item))
            ->push($progress)
            ->take(-20)
            ->values()
            ->all();

        $metadata['progress'] = $progress;
        $metadata['progress_history'] = $history;
        $run->forceFill(['metadata' => $metadata])->save();
    }

    private function progressSnapshot(string $action, string $status, array $result = []): array
    {
        $details = $this->progressDetails($action, $result);

        return array_filter([
            'action' => $action,
            'status' => $status,
            'label' => $status === 'running' ? $this->labelFor($action) : $this->resultLabel($action, $result),
            'status_text' => $status === 'running' ? $this->labelFor($action) : $this->resultLabel($action, $result),
            'details' => $details,
            'updated_at' => now()->toIso8601String(),
        ], fn ($value) => $value !== null && $value !== [] && $value !== '');
    }

    private function progressDetails(string $action, array $result): array
    {
        if ($result === []) return [];

        $details = [];
        if ($action === 'external.lookup') {
            $provider = trim((string) ($result['provider'] ?? ''));
            if ($provider !== '') $details['provider'] = $provider;
            $sourceCount = data_get($result, 'evidence.source_count');
            if ($sourceCount !== null) $details['source_count'] = (int) $sourceCount;
            $confidence = trim((string) ($result['confidence'] ?? data_get($result, 'evidence.confidence') ?? ''));
            if ($confidence !== '') $details['confidence'] = $confidence;
        }

        $title = trim((string) data_get($result, 'item.title', ''));
        if ($title !== '') {
            $details['title'] = $title;
        }

        foreach (['resource_type', 'total_count', 'returned_count', 'limit', 'grounded_from'] as $key) {
            if (array_key_exists($key, $result) && $result[$key] !== null && $result[$key] !== '') {
                $details[$key] = $result[$key];
            }
        }

        return $details;
    }

    private function progressDetailText(string $action, array $result): string
    {
        $details = $this->progressDetails($action, $result);
        $parts = [];
        if (($details['provider'] ?? null) !== null) $parts[] = 'provider '.$details['provider'];
        if (($details['source_count'] ?? null) !== null) $parts[] = ((int) $details['source_count']).' sources';
        if (($details['confidence'] ?? null) !== null) $parts[] = 'confidence '.$details['confidence'];
        if (($details['title'] ?? null) !== null) $parts[] = (string) $details['title'];
        if (($details['total_count'] ?? null) !== null) $parts[] = 'total '.$details['total_count'];
        if (($details['returned_count'] ?? null) !== null) $parts[] = 'shown '.$details['returned_count'];

        return $parts === [] ? '' : ' · '.implode(' · ', $parts);
    }

    private function isDestructive(string $action): bool { return str_ends_with($action, '.delete'); }
    private function confirmationSummary(string $action, array $arguments): string { return "Confirm before I run {$action}."; }
    private function labelFor(string $action): string { return "Working: {$action}"; }
    private function resultLabel(string $action, array $result): string { return ($result['ok'] ?? false) ? "Done: {$action}".$this->progressDetailText($action, $result) : "Failed: {$action}".($result['error'] ?? null ? ' · '.(string) $result['error'] : ''); }

    private function dateOrNull(mixed $value): ?Carbon { return blank($value) ? null : Carbon::parse((string) $value)->utc(); }
    private function metadata(array $args): array { return is_array($args['metadata'] ?? null) ? $args['metadata'] : ['created_by' => 'bean']; }

    private function summaries($items, ?array $accessibleWorkspaceIds = null): array { return $items->map(fn ($item): array => $this->summary($item, $accessibleWorkspaceIds))->values()->all(); }

    private function collapseLinkedSummaries(array $summaries): array
    {
        $collapsed = [];
        foreach ($summaries as $item) {
            if (! is_array($item)) continue;
            $workspaceNames = collect($item['workspace_names'] ?? [])
                ->map(fn ($name): string => trim((string) $name))
                ->filter()
                ->unique()
                ->sort()
                ->values()
                ->all();
            $key = implode('|', [
                (string) ($item['resource_type'] ?? 'resource'),
                mb_strtolower(trim((string) ($item['title'] ?? ''))),
                count($workspaceNames) > 1 ? implode(',', $workspaceNames) : 'id:'.(string) ($item['id'] ?? ''),
            ]);
            if (isset($collapsed[$key])) {
                $collapsed[$key]['workspace_names'] = collect($collapsed[$key]['workspace_names'] ?? [])
                    ->merge($workspaceNames)
                    ->unique()
                    ->values()
                    ->all();
                continue;
            }
            if ($workspaceNames !== []) {
                $item['workspace_names'] = $workspaceNames;
                $item['workspace_name'] = $workspaceNames[0];
            }
            $collapsed[$key] = $item;
        }

        return array_values($collapsed);
    }

    private function summary(Model $model, ?array $accessibleWorkspaceIds = null): array
    {
        $workspaceNames = $this->workspaceNames($model, $accessibleWorkspaceIds);
        return array_filter([
            'id' => $model->getKey(),
            'title' => $model->getAttribute('title'),
            'status' => $model->getAttribute('status'),
            'workspace_id' => $model->getAttribute('workspace_id'),
            'workspace_name' => $workspaceNames[0] ?? null,
            'workspace_names' => $workspaceNames,
            'due_at' => optional($model->getAttribute('due_at'))->toIso8601String(),
            'remind_at' => optional($model->getAttribute('remind_at'))->toIso8601String(),
            'starts_at' => optional($model->getAttribute('starts_at'))->toIso8601String(),
            'ends_at' => optional($model->getAttribute('ends_at'))->toIso8601String(),
            'plain_text' => str($model->getAttribute('plain_text') ?? '')->limit(160)->toString(),
            'resource_type' => $this->resourceType($model),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function workspaceNames(Model $model, ?array $accessibleWorkspaceIds = null): array
    {
        $workspaceIds = $this->workspaceIdsForModel($model, $accessibleWorkspaceIds);
        if ($workspaceIds === []) return [];
        $namesById = Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->pluck('name', 'id');
        return collect($workspaceIds)
            ->map(fn (int $id): string => trim((string) ($namesById[$id] ?? '')))
            ->filter(fn (string $name): bool => $name !== '')
            ->values()
            ->all();
    }

    private function workspaceIdsForModel(Model $model, ?array $accessibleWorkspaceIds = null): array
    {
        $workspaceIds = collect([(int) $model->getAttribute('workspace_id')])->filter();
        $type = $this->storageType($model);
        if ($type !== null) {
            $links = WorkspaceItemLink::query()
                ->where('source_type', $type)
                ->where('target_type', $type)
                ->where('link_type', 'copy')
                ->where(function ($query) use ($model): void {
                    $query->where(fn ($query) => $query->where('source_workspace_id', $model->getAttribute('workspace_id'))->where('source_id', $model->getKey()))
                        ->orWhere(fn ($query) => $query->where('target_workspace_id', $model->getAttribute('workspace_id'))->where('target_id', $model->getKey()));
                })->get();
            $sourcePairs = collect();
            foreach ($links as $link) {
                $workspaceIds->push((int) $link->source_workspace_id, (int) $link->target_workspace_id);
                $sourcePairs->push([(int) $link->source_workspace_id, (int) $link->source_id]);
            }
            $sourcePairs = $sourcePairs->unique(fn (array $pair): string => $pair[0].':'.$pair[1])->values();
            if ($sourcePairs->isNotEmpty()) {
                WorkspaceItemLink::query()
                    ->where('source_type', $type)
                    ->where('target_type', $type)
                    ->where('link_type', 'copy')
                    ->where(function ($query) use ($sourcePairs): void {
                        foreach ($sourcePairs as [$workspaceId, $sourceId]) {
                            $query->orWhere(fn ($query) => $query->where('source_workspace_id', $workspaceId)->where('source_id', $sourceId));
                        }
                    })->get()->each(fn (WorkspaceItemLink $link) => $workspaceIds->push((int) $link->source_workspace_id, (int) $link->target_workspace_id));
            }
        }
        $ids = $workspaceIds->unique()->values();
        if ($accessibleWorkspaceIds !== null) {
            $allowed = array_map('intval', $accessibleWorkspaceIds);
            $ids = $ids->filter(fn (int $id): bool => in_array($id, $allowed, true))->values();
        }
        return $ids->all();
    }

    private function storageType(Model $model): ?string
    {
        return match (true) {
            $model instanceof Task => 'tasks',
            $model instanceof Reminder => 'reminders',
            $model instanceof CalendarEvent => 'calendar_events',
            $model instanceof Note => 'notes',
            default => null,
        };
    }

    private function resourceType(Model $model): string
    {
        return match (true) {
            $model instanceof Task => 'task',
            $model instanceof Reminder => 'reminder',
            $model instanceof CalendarEvent => 'calendar_event',
            $model instanceof Note => 'note',
            default => 'resource',
        };
    }
}
