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
use App\Services\Bean\External\OpenMeteoWeatherService;
use App\Services\DashboardChangeNotifier;
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
        private readonly OpenMeteoWeatherService $weather,
        private readonly BeanTimeContext $timeContext,
    ) {}

    public function execute(BeanSession $session, BeanRun $run, string $action, array $arguments = [], bool $confirmed = false): array
    {
        $arguments = $this->normalizeArguments($action, $arguments, $session);

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
            if ($this->requiresConfirmation($action, $arguments) && ! $confirmed) {
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
                'workspace.list' => $this->workspaceList($run),
                'settings.show' => $this->settingsShow($run),
                'settings.update' => $this->settingsUpdate($run, $arguments),
                'resource.query' => $this->genericResourceQuery($run, $arguments),
                'resource.relationships' => $this->resourceRelationships($run, $arguments),
                'time.now' => $this->timeNow($run),
                'external.lookup' => $this->externalLookup($arguments),
                'external.weather' => $this->weather->forecast($arguments, $run),
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
                'note.update' => $this->updateResource(Note::class, $run, $this->normalizedNoteArguments($arguments), ['title', 'body_markdown', 'is_pinned', 'metadata']),
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

    private function workspaceId(BeanRun $run, array $arguments = []): int
    {
        $workspaces = app(WorkspaceService::class)->accessibleWorkspaces($this->user($run));

        if (($arguments['workspace_id'] ?? null) !== null) {
            $workspaceId = (int) $arguments['workspace_id'];
            if ($workspaces->contains(fn (Workspace $workspace): bool => (int) $workspace->id === $workspaceId)) {
                return $workspaceId;
            }

            throw new \RuntimeException('I cannot access that workspace.');
        }

        $workspaceName = trim((string) ($arguments['workspace_name'] ?? ''));
        if ($workspaceName !== '') {
            $matches = $workspaces
                ->filter(fn (Workspace $workspace): bool => mb_strtolower(trim((string) $workspace->name)) === mb_strtolower($workspaceName))
                ->values();
            if ($matches->count() === 1) {
                return (int) $matches->first()->id;
            }

            throw new \RuntimeException($matches->isEmpty()
                ? 'I could not find an accessible workspace with that name.'
                : 'I found multiple accessible workspaces with that name. Please use a workspace id.');
        }

        return app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($this->user($run));
    }

    private function baseQuery(string $class, BeanRun $run): Builder
    {
        return $class::query()->whereIn('workspace_id', $this->workspaceIds($run));
    }

    private function applyWorkspaceConstraint(Builder $query, BeanRun $run, array $arguments): void
    {
        $workspaceName = trim((string) ($arguments['workspace_name'] ?? ''));
        if (($arguments['workspace_id'] ?? null) === null && $workspaceName === '') {
            return;
        }

        $query->where('workspace_id', $this->workspaceId($run, $arguments));
    }

    private function normalizeArguments(string $action, array $arguments, BeanSession $session): array
    {
        $class = $this->classForReadAction($action, $arguments);
        if ($class === null) return $arguments;

        $arguments = $this->normalizeStatusArgument($class, $arguments);

        $timeContext = $this->timeContext->forSession($session);
        $field = $this->temporalField($class);
        if ($field === null) return $arguments;
        $arguments = $this->normalizeInlineReadFilters($class, $field, $arguments);
        $arguments = $this->normalizeDateOnlyTemporalFilters($arguments, $field, $timeContext);
        $arguments = $this->normalizeDateShortcutArgument($class, $field, $arguments, $timeContext);

        $timeLabel = $this->timeLabel($arguments) ?? $this->inferTemporalLabelFromFilters($class, $field, $arguments, $timeContext);
        if ($timeLabel === null) return $arguments;
        $arguments['time_label'] = $timeLabel;

        $temporalFilters = $this->temporalFilters($class, $field, $timeLabel, $timeContext);
        if ($temporalFilters === []) return $arguments;

        $temporalFields = $class === CalendarEvent::class ? [$field, 'ends_at'] : [$field];
        $existing = collect(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [])
            ->filter(fn ($filter): bool => is_array($filter) && ! in_array($filter['field'] ?? null, $temporalFields, true))
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

    private function normalizeDateOnlyTemporalFilters(array $arguments, string $field, array $timeContext): array
    {
        $filters = collect(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [])
            ->filter(fn ($filter): bool => is_array($filter))
            ->map(function (array $filter) use ($field, $timeContext): array {
                $operator = strtolower((string) ($filter['operator'] ?? '='));
                $value = $filter['value'] ?? null;
                if (($filter['field'] ?? null) === $field && $operator === '=' && $this->timeContext->isDateOnly($value)) {
                    return ['field' => $field, 'operator' => 'between', 'value' => $this->timeContext->localDayUtcRange((string) $value, $timeContext)];
                }

                return $filter;
            })
            ->values()
            ->all();

        if ($filters !== []) {
            $arguments['filters'] = $filters;
        }

        return $arguments;
    }

    private function normalizeDateShortcutArgument(string $class, string $field, array $arguments, array $timeContext): array
    {
        $date = $arguments['date'] ?? null;
        if (! is_string($date) || ! $this->timeContext->isDateOnly($date)) {
            return $arguments;
        }

        unset($arguments['date']);
        $arguments['time_label'] ??= $date;
        $filters = collect(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [])
            ->filter(fn ($filter): bool => is_array($filter) && ! in_array($filter['field'] ?? null, [$field, 'ends_at'], true))
            ->values()
            ->all();
        [$start, $end] = $this->timeContext->localDayUtcRange($date, $timeContext);
        if ($class === CalendarEvent::class) {
            $filters[] = ['field' => 'starts_at', 'operator' => 'overlaps_day', 'value' => [$start, $end]];
        } else {
            $filters[] = ['field' => $field, 'operator' => 'between', 'value' => [$start, $end]];
        }
        $arguments['filters'] = $filters;

        return $arguments;
    }

    private function inferTemporalLabelFromFilters(string $class, string $field, array $arguments, array $timeContext): ?string
    {
        foreach (is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [] as $filter) {
            if (! is_array($filter) || ($filter['field'] ?? null) !== $field) continue;
            $operator = strtolower((string) ($filter['operator'] ?? '='));
            $value = $filter['value'] ?? null;
            if ($operator === 'between' && is_array($value) && count($value) >= 2) {
                $label = $this->timeLabelForRange($value[0], $value[1], $timeContext);
                if ($label !== null) return $label;
            }
            if (in_array($operator, ['<', '<='], true) && in_array($class, [Task::class, Reminder::class], true)) {
                return 'overdue';
            }
        }

        return null;
    }

    private function timeLabelForRange(mixed $start, mixed $end, array $timeContext): ?string
    {
        $startDate = $this->dateOrNull($start);
        $endDate = $this->dateOrNull($end);
        if (! $startDate || ! $endDate) return null;

        foreach (['today' => $this->timeContext->todayUtcRange($timeContext), 'tomorrow' => $this->timeContext->tomorrowUtcRange($timeContext)] as $label => $range) {
            $dateStart = Carbon::parse($range[0])->utc();
            $dateEnd = Carbon::parse($range[1])->utc();
            if ($startDate->lte($dateStart) && $endDate->gte($dateEnd)) {
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

    private function temporalFilters(string $class, string $field, string $timeLabel, array $timeContext): array
    {
        [$start, $end] = $this->timeContext->todayUtcRange($timeContext);
        if ($timeLabel === 'tomorrow') {
            [$start, $end] = $this->timeContext->tomorrowUtcRange($timeContext);
        } elseif (($date = $this->dateForTimeLabel($timeLabel, $timeContext)) !== null) {
            [$start, $end] = $this->timeContext->localDayUtcRange($date, $timeContext);
        }

        if ($class === CalendarEvent::class && in_array($timeLabel, ['today', 'tomorrow'], true) || ($class === CalendarEvent::class && ($date ?? null) !== null)) {
            return [
                ['field' => 'starts_at', 'operator' => 'overlaps_day', 'value' => [$start, $end]],
            ];
        }

        return match ($timeLabel) {
            'overdue' => [['field' => $field, 'operator' => '<', 'value' => $start]],
            'today' => [['field' => $field, 'operator' => '<=', 'value' => $end]],
            'tomorrow' => [['field' => $field, 'operator' => 'between', 'value' => [$start, $end]]],
            default => ($date ?? null) !== null ? [['field' => $field, 'operator' => 'between', 'value' => [$start, $end]]] : [],
        };
    }

    private function dateForTimeLabel(string $timeLabel, array $timeContext): ?string
    {
        $rawLabel = mb_strtolower(trim((string) $timeLabel));
        $label = strtolower(trim(preg_replace('/[^a-z0-9\- ]+/', ' ', $rawLabel) ?: $rawLabel));
        $label = trim(preg_replace('/\s+/', ' ', $label) ?: $label);
        if ($this->timeContext->isDateOnly($label)) return $label;
        $label = trim(preg_replace('/^(this|coming|next|on|the)\s+/u', '', $label) ?: $label);
        $weekdays = [
            'monday' => Carbon::MONDAY,
            'tuesday' => Carbon::TUESDAY,
            'wednesday' => Carbon::WEDNESDAY,
            'thursday' => Carbon::THURSDAY,
            'friday' => Carbon::FRIDAY,
            'saturday' => Carbon::SATURDAY,
            'sunday' => Carbon::SUNDAY,
        ];
        if (! array_key_exists($label, $weekdays)) return null;
        $local = $this->timeContext->localNow($timeContext)->startOfDay();
        if ($local->dayOfWeek === $weekdays[$label] && str_starts_with($timeLabel, 'this ')) {
            return $local->toDateString();
        }
        return $local->next($weekdays[$label])->toDateString();
    }

    private function listResources(string $class, BeanRun $run, string $orderField, array $arguments = []): array
    {
        $timeContext = $this->timeContext->forRun($run);
        $query = $this->baseQuery($class, $run);
        $this->applyWorkspaceConstraint($query, $run, $arguments);
        $this->applyDefaultReadConstraints($query, $class, $arguments);
        if (($arguments['status'] ?? null) !== null) {
            $query->where('status', (string) $arguments['status']);
        }
        $this->applyStructuredFilters($query, $class, $arguments['filters'] ?? [], $timeContext);
        $accessibleWorkspaceIds = $this->workspaceIds($run);
        $totalItems = (clone $query)->get();
        $totalCount = count($this->collapseLinkedSummaries($this->summaries($totalItems, $accessibleWorkspaceIds)));
        $order = $this->primarySort($class, $arguments, $orderField);
        $items = $query->orderBy($order['field'], $order['direction'])->orderBy('id')->limit($this->limit($arguments))->get();
        $summaries = $this->collapseLinkedSummaries($this->summaries($items, $accessibleWorkspaceIds, $timeContext));

        return [
            'ok' => true,
            'items' => $summaries,
            'total_count' => $totalCount,
            'returned_count' => count($summaries),
            'limit' => $this->limit($arguments),
            'filters' => array_values(array_filter(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [], 'is_array')),
            'time_label' => $this->timeLabel($arguments),
            'time_context' => $timeContext,
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

    private function applyStructuredFilters(Builder $query, string $class, mixed $filters, ?array $timeContext = null): void
    {
        if (! is_array($filters)) return;
        $timeContext ??= $this->timeContext->forClientTimezone(null, 'app_default');

        $allowed = $this->filterableFields($class);
        foreach ($filters as $filter) {
            if (! is_array($filter)) continue;
            $field = (string) ($filter['field'] ?? '');
            $operator = strtolower((string) ($filter['operator'] ?? '='));
            $value = $filter['value'] ?? null;
            if (! in_array($field, $allowed, true)) continue;

            if ($class === CalendarEvent::class && $field === 'starts_at' && $operator === 'overlaps_day' && is_array($value) && count($value) >= 2) {
                $start = $this->filterValue($field, $value[0], $timeContext);
                $end = $this->filterValue($field, $value[1], $timeContext);
                $query->whereNotNull('starts_at')
                    ->where('starts_at', '<=', $end)
                    ->where(function (Builder $builder) use ($start): void {
                        $builder->where('ends_at', '>=', $start)
                            ->orWhere(function (Builder $instant) use ($start): void {
                                $instant->whereNull('ends_at')->where('starts_at', '>=', $start);
                            });
                    });
                continue;
            }
            if ($operator === 'between' && is_array($value) && count($value) >= 2) {
                $query->whereNotNull($field)->whereBetween($field, [$this->filterValue($field, $value[0], $timeContext), $this->filterValue($field, $value[1], $timeContext)]);
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
            if ($operator === '=' && $this->isTemporalField($field) && $this->timeContext->isDateOnly($value)) {
                $query->whereNotNull($field)->whereBetween($field, $this->timeContext->localDayUtcRange((string) $value, $timeContext));
                continue;
            }
            if (in_array($operator, ['=', '!=', '<', '<=', '>', '>='], true)) {
                $query->whereNotNull($field)->where($field, $operator, $this->filterValue($field, $value, $timeContext));
            }
        }
    }

    private function filterValue(string $field, mixed $value, ?array $timeContext = null): mixed
    {
        if ($this->isTemporalField($field)) {
            return $this->dateOrNull($value, $timeContext) ?: $value;
        }

        return $value;
    }

    private function isTemporalField(string $field): bool
    {
        return in_array($field, ['due_at', 'completed_at', 'remind_at', 'starts_at', 'ends_at', 'updated_at', 'created_at'], true);
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
        $timeContext = $this->timeContext->forRun($run);
        $queryText = trim((string) ($arguments['query'] ?? $arguments['title'] ?? ''));
        $query = $this->baseQuery($class, $run);
        $this->applyWorkspaceConstraint($query, $run, $arguments);
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
        $this->applyStructuredFilters($query, $class, $arguments['filters'] ?? [], $timeContext);
        $totalCount = (clone $query)->count();
        $items = $query->orderByDesc('updated_at')->limit($this->limit($arguments))->get();

        return [
            'ok' => true,
            'items' => $this->summaries($items, $this->workspaceIds($run), $timeContext),
            'total_count' => $totalCount,
            'returned_count' => $items->count(),
            'limit' => $this->limit($arguments),
            'filters' => array_values(array_filter(is_array($arguments['filters'] ?? null) ? $arguments['filters'] : [], 'is_array')),
            'time_label' => $this->timeLabel($arguments),
            'time_context' => $timeContext,
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
            'item' => $this->summary($model, $this->workspaceIds($run), $this->timeContext->forRun($run)),
        ];
    }

    private function genericResourceQuery(BeanRun $run, array $arguments): array
    {
        $timeContext = $this->timeContext->forRun($run);
        $resource = strtolower(trim((string) ($arguments['resource'] ?? 'tasks')));
        $class = $this->resourceCatalog->classForResource($resource) ?? Task::class;
        $orderField = $this->resourceCatalog->temporalFieldForClass($class) ?? 'updated_at';
        $label = $this->resourceCatalog->resourceForClass($class) ?? 'tasks';

        $query = $this->baseQuery($class, $run);
        $this->applyWorkspaceConstraint($query, $run, $arguments);
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
        $this->applyStructuredFilters($query, $class, $arguments['filters'] ?? [], $timeContext);
        $order = $this->primarySort($class, $arguments, $orderField);
        $items = $query->orderBy($order['field'], $order['direction'])->orderBy('id')->limit($this->limit($arguments))->get();
        $accessibleWorkspaceIds = $this->workspaceIds($run);
        $summaries = $this->collapseLinkedSummaries($this->summaries($items, $accessibleWorkspaceIds, $timeContext));
        $timeLabel = $this->timeLabel($arguments);
        $explanations = [];
        if (($arguments['explain_visibility'] ?? false) || $timeLabel !== null) {
            $explanations = collect($summaries)
                ->map(fn (array $item): array => [
                    'id' => $item['id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'reason' => $this->visibilityReason($item, $class, $timeLabel ?? '', $timeContext),
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
            'time_context' => $timeContext,
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

    private function visibilityReason(array $item, string $class, string $timeLabel, array $timeContext): string
    {
        if ($class === Task::class) {
            $title = (string) ($item['title'] ?? 'This task');
            $status = (string) ($item['status'] ?? 'open');
            $dueAt = isset($item['due_at']) ? Carbon::parse((string) $item['due_at'])->utc() : null;
            if ($timeLabel === 'today' && $status === 'open' && $dueAt) {
                $startOfLocalTodayUtc = Carbon::parse($this->timeContext->todayUtcRange($timeContext)[0])->utc();
                return $dueAt->lt($startOfLocalTodayUtc)
                    ? "{$title} is on today's list because it is overdue and open."
                    : "{$title} is on today's list because it is due by today and open.";
            }
            if ($timeLabel === 'overdue' && $status === 'open' && $dueAt) {
                return "{$title} is overdue because it was due before today and is open.";
            }
        } elseif ($class === Reminder::class) {
            $title = (string) ($item['title'] ?? 'This reminder');
            $remindAt = isset($item['remind_at']) ? Carbon::parse((string) $item['remind_at'])->utc() : null;
            if ($timeLabel === 'today' && $remindAt) return "{$title} is included because it is scheduled by today.";
            if ($timeLabel === 'overdue' && $remindAt) return "{$title} is overdue because it was scheduled before today.";
        }
        return '';
    }

    private function createTask(BeanRun $run, array $args): array
    {
        $timeContext = $this->timeContext->forRun($run);
        $task = $this->domainResources->createTask($this->user($run), [
            'workspace_id' => $this->workspaceId($run, $args),
            'title' => trim((string) ($args['title'] ?? 'New task')) ?: 'New task',
            'type' => $args['type'] ?? 'todo',
            'status' => $args['status'] ?? 'open',
            'notes' => $args['notes'] ?? null,
            'category' => $args['category'] ?? null,
            'color' => $args['color'] ?? null,
            'is_critical' => $args['is_critical'] ?? null,
            'due_at' => ($this->dateOrNull($args['due_at'] ?? null, $timeContext))?->toIso8601String(),
            'completed_at' => ($this->dateOrNull($args['completed_at'] ?? null, $timeContext))?->toIso8601String(),
            'metadata' => $this->metadata($args),
        ]);
        return ['ok' => true, 'resource_type' => 'task', 'item' => $this->summary($task, $this->workspaceIds($run), $timeContext)];
    }

    private function createReminder(BeanRun $run, array $args): array
    {
        $timeContext = $this->timeContext->forRun($run);
        $remindAt = $this->dateOrNull($args['remind_at'] ?? null, $timeContext)
            ?: $this->timeContext->localNow($timeContext)->addDay()->utc();
        $reminder = $this->domainResources->createReminder($this->user($run), [
            'workspace_id' => $this->workspaceId($run, $args),
            'title' => trim((string) ($args['title'] ?? 'New reminder')) ?: 'New reminder',
            'notes' => $args['notes'] ?? null,
            'category' => $args['category'] ?? null,
            'color' => $args['color'] ?? null,
            'is_critical' => $args['is_critical'] ?? null,
            'remind_at' => $remindAt->toIso8601String(),
            'status' => $args['status'] ?? 'scheduled',
            'metadata' => $this->metadata($args),
        ]);
        return ['ok' => true, 'resource_type' => 'reminder', 'item' => $this->summary($reminder, $this->workspaceIds($run), $timeContext)];
    }

    private function createCalendarEvent(BeanRun $run, array $args): array
    {
        $timeContext = $this->timeContext->forRun($run);
        $startsAt = $this->calendarEventStartAt($args, $timeContext)
            ?: $this->timeContext->localNow($timeContext)->addDay()->setTime(9, 0)->utc();
        $endsAt = $this->calendarEventEndAt($args, $startsAt, $timeContext);
        $event = $this->domainResources->createCalendarEvent($this->user($run), [
            'workspace_id' => $this->workspaceId($run, $args),
            'title' => trim((string) ($args['title'] ?? 'New event')) ?: 'New event',
            'description' => $args['description'] ?? null,
            'location' => $args['location'] ?? null,
            'category' => $args['category'] ?? null,
            'color' => $args['color'] ?? null,
            'is_critical' => $args['is_critical'] ?? null,
            'starts_at' => $startsAt->toIso8601String(),
            'ends_at' => $endsAt->toIso8601String(),
            'all_day' => (bool) ($args['all_day'] ?? false),
            'status' => $args['status'] ?? 'scheduled',
            'recurrence' => (string) ($args['recurrence'] ?? 'none'),
            'metadata' => $this->metadata($args),
        ]);
        return ['ok' => true, 'resource_type' => 'calendar_event', 'item' => $this->summary($event, $this->workspaceIds($run), $timeContext)];
    }

    private function createNote(BeanRun $run, array $args): array
    {
        $markdown = trim((string) ($args['body_markdown'] ?? $args['plain_text'] ?? $args['body'] ?? $args['content'] ?? ''));
        if ($markdown === '') {
            return ['ok' => false, 'error' => 'I need note content before I can create that note.'];
        }

        $groundedLookup = $this->latestExternalLookupResult($run);
        $title = trim((string) ($args['title'] ?? ''));
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
        $noteAttributes = [
            'workspace_id' => $this->workspaceId($run, $args),
            'body_markdown' => $markdown,
            'note_folder_id' => $args['note_folder_id'] ?? null,
            'is_pinned' => $args['is_pinned'] ?? null,
            'metadata' => $metadata,
        ];
        if ($title !== '') $noteAttributes['title'] = $title;
        $note = $this->domainResources->createNote($this->user($run), $noteAttributes);
        return ['ok' => true, 'resource_type' => 'note', 'item' => $this->summary($note, $this->workspaceIds($run), $this->timeContext->forRun($run)), 'grounded_from' => $metadata['grounded_from'] ?? null, 'evidence' => $metadata['external_evidence'] ?? null];
    }

    private function normalizedNoteArguments(array $arguments): array
    {
        if (array_key_exists('body_markdown', $arguments)) return $arguments;
        foreach (['plain_text', 'body', 'content'] as $field) {
            if (! array_key_exists($field, $arguments)) continue;
            $arguments['body_markdown'] = (string) ($arguments[$field] ?? '');
            break;
        }

        return $arguments;
    }

    private function updateResource(string $class, BeanRun $run, array $args, array $allowed): array
    {
        $match = $this->findOne($class, $run, $args);
        if (! ($match['ok'] ?? false)) return $match;
        /** @var Model $model */
        $model = $match['model'];
        $timeContext = $this->timeContext->forRun($run);
        $updates = [];
        foreach ($allowed as $field) {
            if (array_key_exists($field, $args)) $updates[$field] = $args[$field];
        }
        foreach (['due_at', 'completed_at', 'remind_at', 'starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $updates)) $updates[$field] = $this->normalizeTemporalUpdate($model, $field, $updates[$field], $timeContext, $args);
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
        return ['ok' => true, 'resource_type' => $this->resourceType($updated), 'item' => $this->summary($updated, $this->workspaceIds($run), $timeContext)];
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
        $this->applyWorkspaceConstraint($query, $run, $args);
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
        $this->applyWorkspaceConstraint($query, $run, $args);
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
        $timeContext = $this->timeContext->forRun($run);
        $todayStartUtc = $this->timeContext->todayUtcRange($timeContext)[0];
        return ['ok' => true, 'summary' => [
            'tasks' => $this->baseQuery(Task::class, $run)->where('status', $this->resourceCatalog->activeStatusForClass(Task::class))->count(),
            'reminders' => $this->baseQuery(Reminder::class, $run)->where('status', $this->resourceCatalog->activeStatusForClass(Reminder::class))->count(),
            'calendar_events' => $this->baseQuery(CalendarEvent::class, $run)->where('status', $this->resourceCatalog->activeStatusForClass(CalendarEvent::class))->where('starts_at', '>=', Carbon::parse($todayStartUtc)->utc())->count(),
            'notes' => $this->baseQuery(Note::class, $run)->count(),
        ], 'time_context' => $timeContext];
    }

    private function workspaceList(BeanRun $run): array
    {
        $baseWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($this->user($run));
        $workspaces = app(WorkspaceService::class)->accessibleWorkspaces($this->user($run))
            ->map(fn (Workspace $workspace): array => [
                'id' => (int) $workspace->id,
                'name' => (string) $workspace->name,
                'type' => (string) $workspace->type,
                'is_personal' => (int) $workspace->id === $baseWorkspaceId,
                'is_default_dashboard_workspace' => (bool) $workspace->getAttribute('is_default'),
                'role' => (string) $workspace->getAttribute('membership_role'),
            ])
            ->values()
            ->all();

        return [
            'ok' => true,
            'base_workspace_id' => $baseWorkspaceId,
            'workspaces' => $workspaces,
        ];
    }

    private function settingsShow(BeanRun $run): array
    {
        $settings = $this->settingsSummary($this->user($run));

        return ['ok' => true, 'settings' => $settings, 'supported_fields' => array_keys($settings), 'sensitive_fields' => $this->sensitiveSettingsFields()];
    }

    private function settingsUpdate(BeanRun $run, array $arguments): array
    {
        $user = $this->user($run);
        $updates = $this->normalizedSettingsUpdates($arguments);
        if ($updates === []) {
            return ['ok' => false, 'error' => 'I need a supported setting and value to update. Supported settings include theme_mode, theme, preferred_map_app, timezone, name, email, and notification_preferences.'];
        }

        $invalid = array_diff(array_keys($updates), $this->supportedSettingsFields());
        if ($invalid !== []) {
            return ['ok' => false, 'error' => 'I cannot update that setting from Bean yet: '.implode(', ', $invalid).'.'];
        }

        $validated = [];
        foreach ($updates as $field => $value) {
            $normalized = $this->normalizeSettingValue($field, $value, $user);
            if (is_array($normalized) && ($normalized['ok'] ?? null) === false) {
                return $normalized;
            }
            $validated[$field] = $normalized;
        }

        if (array_key_exists('notification_preferences', $validated)) {
            $preferences = $validated['notification_preferences'];
            if (($preferences['reminder_email'] ?? false) && ! app(\App\Services\PlanLimitService::class)->canUseEmailReminders($user)) {
                return ['ok' => false, 'error' => 'Email reminders are available on Premium, Pro, and Enterprise plans.'];
            }
        }

        $before = $this->settingsSummary($user);
        $user->fill($validated);
        $user->save();
        $user = $user->refresh();
        $after = $this->settingsSummary($user);
        $changed = collect(array_keys($validated))
            ->filter(fn (string $field): bool => ($before[$field] ?? null) !== ($after[$field] ?? null))
            ->values()
            ->all();

        if ($changed !== []) {
            app(DashboardChangeNotifier::class)->notify(
                userId: $user->id,
                workspaceId: $run->workspace_id,
                resourceType: 'settings',
                action: 'updated',
                resourceId: null,
                payload: ['changed_fields' => $changed, 'settings' => array_intersect_key($after, array_flip($changed))]
            );
        }

        return ['ok' => true, 'settings' => $after, 'changed_fields' => $changed];
    }

    private function settingsSummary(User $user): array
    {
        return [
            'name' => (string) $user->name,
            'email' => (string) $user->email,
            'theme' => (string) ($user->theme ?: 'green'),
            'theme_mode' => (string) ($user->theme_mode ?: 'auto'),
            'preferred_map_app' => (string) ($user->preferred_map_app ?: 'google'),
            'timezone' => $user->timezone,
            'notification_preferences' => $user->notification_preferences,
        ];
    }

    private function normalizedSettingsUpdates(array $arguments): array
    {
        $updates = [];
        if (is_array($arguments['settings'] ?? null)) {
            $updates = $arguments['settings'];
        }
        foreach ($arguments as $key => $value) {
            if ($key === 'settings') continue;
            $updates[$key] = $value;
        }
        $field = $arguments['field'] ?? $arguments['setting'] ?? $arguments['key'] ?? null;
        if (is_string($field) && array_key_exists('value', $arguments)) {
            $updates[$field] = $arguments['value'];
        }
        unset($updates['field'], $updates['setting'], $updates['key'], $updates['value']);

        $aliases = [
            'mode' => 'theme_mode',
            'themeMode' => 'theme_mode',
            'theme mode' => 'theme_mode',
            'dark_mode' => 'theme_mode',
            'dark mode' => 'theme_mode',
            'accent' => 'theme',
            'accent_color' => 'theme',
            'accent color' => 'theme',
            'map' => 'preferred_map_app',
            'map_app' => 'preferred_map_app',
            'map app' => 'preferred_map_app',
            'preferred map' => 'preferred_map_app',
            'notifications' => 'notification_preferences',
            'notification preferences' => 'notification_preferences',
            'email_notifications' => 'reminder_email',
            'email notifications' => 'reminder_email',
            'reminder emails' => 'reminder_email',
            'reminder_email' => 'reminder_email',
            'push_notifications' => 'reminder_push',
            'push notifications' => 'reminder_push',
            'reminder pushes' => 'reminder_push',
            'reminder_push' => 'reminder_push',
        ];

        $normalized = [];
        foreach ($updates as $key => $value) {
            $fieldName = trim((string) $key);
            $canonical = $aliases[$fieldName] ?? $aliases[strtolower(str_replace(['_', '-'], ' ', $fieldName))] ?? $fieldName;
            if (in_array($canonical, ['reminder_email', 'reminder_push'], true)) {
                $normalized['notification_preferences'] ??= [];
                if (is_array($normalized['notification_preferences'])) {
                    $normalized['notification_preferences'][$canonical] = $value;
                }
                continue;
            }
            if ($canonical === 'theme_mode' && is_bool($value)) {
                $value = $value ? 'dark' : 'light';
            }
            $normalized[$canonical] = $value;
        }

        return $normalized;
    }

    private function supportedSettingsFields(): array
    {
        return ['name', 'email', 'theme', 'theme_mode', 'preferred_map_app', 'timezone', 'notification_preferences'];
    }

    private function sensitiveSettingsFields(): array
    {
        return ['email', 'timezone', 'notification_preferences'];
    }

    private function normalizeSettingValue(string $field, mixed $value, User $user): mixed
    {
        return match ($field) {
            'name' => $this->settingString($value, 'name', 1, 255),
            'email' => $this->settingEmail($value, $user),
            'theme' => $this->settingIn($value, 'theme', ['green', 'gray', 'blue', 'purple', 'pink', 'red', 'orange', 'gold', 'teal', 'indigo']),
            'theme_mode' => $this->settingIn($value, 'theme_mode', ['auto', 'light', 'dark']),
            'preferred_map_app' => $this->settingIn($value, 'preferred_map_app', ['google', 'apple']),
            'timezone' => $this->settingTimezone($value),
            'notification_preferences' => $this->settingNotificationPreferences($value, $user),
            default => ['ok' => false, 'error' => "Unsupported setting: {$field}"],
        };
    }

    private function settingString(mixed $value, string $field, int $min, int $max): string|array
    {
        $text = trim((string) $value);
        if (mb_strlen($text) < $min || mb_strlen($text) > $max) {
            return ['ok' => false, 'error' => "The {$field} setting must be between {$min} and {$max} characters."];
        }

        return $text;
    }

    private function settingEmail(mixed $value, User $user): string|array
    {
        $email = strtolower(trim((string) $value));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return ['ok' => false, 'error' => 'That email address is not valid.'];
        }
        if (User::where('email', $email)->whereKeyNot($user->id)->exists()) {
            return ['ok' => false, 'error' => 'That email address is already connected to another account.'];
        }

        return $email;
    }

    private function settingIn(mixed $value, string $field, array $allowed): string|array
    {
        $normalized = strtolower(trim((string) $value));
        if ($field === 'theme_mode' && in_array($normalized, ['system', 'device', 'automatic'], true)) {
            $normalized = 'auto';
        }
        if ($field === 'preferred_map_app' && in_array($normalized, ['apple maps', 'apple map'], true)) {
            $normalized = 'apple';
        }
        if ($field === 'preferred_map_app' && in_array($normalized, ['google maps', 'google map'], true)) {
            $normalized = 'google';
        }
        if (! in_array($normalized, $allowed, true)) {
            return ['ok' => false, 'error' => "The {$field} setting must be one of: ".implode(', ', $allowed).'.'];
        }

        return $normalized;
    }

    private function settingTimezone(mixed $value): string|array|null
    {
        if ($value === null || trim((string) $value) === '') return null;
        $timezone = trim((string) $value);
        $isIanaTimezone = in_array($timezone, timezone_identifiers_list(\DateTimeZone::ALL), true);
        $isUtcOffset = preg_match('/^[+-](?:(?:0\\d|1[0-3]):[0-5]\\d|14:00)$/', $timezone) === 1;
        if (! $isIanaTimezone && ! $isUtcOffset) {
            return ['ok' => false, 'error' => 'The timezone setting must be a valid IANA timezone such as America/New_York.'];
        }

        return $timezone;
    }

    private function settingNotificationPreferences(mixed $value, User $user): array
    {
        $input = is_array($value) ? $value : [];
        foreach (['reminder_push', 'reminder_email'] as $key) {
            if (array_key_exists($key, $input)) {
                $input[$key] = filter_var($input[$key], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? (bool) $input[$key];
            }
        }

        return array_merge(User::defaultNotificationPreferences(), $user->notification_preferences ?? [], array_intersect_key($input, array_flip(['reminder_push', 'reminder_email'])));
    }

    private function timeNow(BeanRun $run): array
    {
        $timeContext = $this->timeContext->forRun($run);
        return [
            'ok' => true,
            'now' => $timeContext['local_now'],
            'now_utc' => $timeContext['now_utc'],
            'local_date' => $timeContext['local_date'],
            'timezone' => $timeContext['timezone'],
            'time_context' => $timeContext,
        ];
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

    private function requiresConfirmation(string $action, array $arguments = []): bool
    {
        if (str_ends_with($action, '.delete')) return true;
        if ($action !== 'settings.update') return false;

        $fields = array_keys($this->normalizedSettingsUpdates($arguments));
        return array_intersect($fields, $this->sensitiveSettingsFields()) !== [];
    }

    private function confirmationSummary(string $action, array $arguments): string
    {
        if ($action === 'settings.update') {
            $fields = implode(', ', array_intersect(array_keys($this->normalizedSettingsUpdates($arguments)), $this->sensitiveSettingsFields()));
            return 'Confirm before I update sensitive settings'.($fields !== '' ? ": {$fields}" : '.');
        }

        return "Confirm before I run {$action}.";
    }

    private function labelFor(string $action): string
    {
        return match ($action) {
            'dashboard.summary' => 'Checking your dashboard',
            'settings.show' => 'Checking your settings',
            'settings.update' => 'Updating your settings',
            'resource.query', 'resource.relationships' => 'Looking through your workspace',
            'time.now' => 'Checking the current time',
            'external.lookup' => 'Looking that up',
            'external.weather' => 'Checking the forecast',
            'task.list', 'task.search', 'task.context' => 'Checking your tasks',
            'task.create' => 'Adding a task',
            'task.update' => 'Updating a task',
            'task.complete' => 'Completing a task',
            'task.delete' => 'Deleting a task',
            'reminder.list', 'reminder.search' => 'Checking your reminders',
            'reminder.create' => 'Adding a reminder',
            'reminder.update' => 'Updating a reminder',
            'reminder.complete' => 'Completing a reminder',
            'reminder.delete' => 'Deleting a reminder',
            'calendar_event.list', 'calendar_event.search' => 'Checking your calendar',
            'calendar_event.create' => 'Adding a calendar event',
            'calendar_event.update' => 'Updating a calendar event',
            'calendar_event.delete' => 'Deleting a calendar event',
            'note.list', 'note.search' => 'Checking your notes',
            'note.create' => 'Creating a note',
            'note.update' => 'Updating a note',
            'note.delete' => 'Deleting a note',
            default => 'Working on that',
        };
    }

    private function resultLabel(string $action, array $result): string
    {
        if (! ($result['ok'] ?? false)) {
            return 'Could not finish: '.$this->labelFor($action).($result['error'] ?? null ? ' · '.(string) $result['error'] : '');
        }

        return match (true) {
            str_ends_with($action, '.create') => str_replace(['Adding', 'Creating'], ['Added', 'Created'], $this->labelFor($action)).$this->progressDetailText($action, $result),
            str_ends_with($action, '.update') => str_replace('Updating', 'Updated', $this->labelFor($action)).$this->progressDetailText($action, $result),
            str_ends_with($action, '.complete') => str_replace('Completing', 'Completed', $this->labelFor($action)).$this->progressDetailText($action, $result),
            str_ends_with($action, '.delete') => str_replace('Deleting', 'Deleted', $this->labelFor($action)).$this->progressDetailText($action, $result),
            default => $this->labelFor($action).$this->progressDetailText($action, $result),
        };
    }

    private function dateOrNull(mixed $value, ?array $timeContext = null): ?Carbon
    {
        if (blank($value)) return null;
        $timeContext ??= $this->timeContext->forClientTimezone(null, 'app_default');
        return $this->timeContext->parseUserDateTime($value, $timeContext);
    }

    private function calendarEventStartAt(array $args, array $timeContext): ?Carbon
    {
        $direct = $this->dateOrNull($args['starts_at'] ?? $args['start_at'] ?? null, $timeContext);
        if ($direct instanceof Carbon) return $direct;

        $date = $this->dateStringFromNaturalArgument($args['date'] ?? $args['day'] ?? $args['start_date'] ?? null, $timeContext);
        if ($date === null) return null;

        $time = trim((string) ($args['time'] ?? $args['start_time'] ?? $args['starts_at_time'] ?? ''));
        if ($time === '') {
            return Carbon::parse($date, $this->timeContext->timezone($timeContext))->setTime(9, 0)->utc();
        }

        return $this->dateOrNull("{$date} {$time}", $timeContext);
    }

    private function calendarEventEndAt(array $args, Carbon $startsAt, array $timeContext): Carbon
    {
        $direct = $this->dateOrNull($args['ends_at'] ?? $args['end_at'] ?? null, $timeContext);
        if ($direct instanceof Carbon) return $direct;

        $endTime = trim((string) ($args['end_time'] ?? $args['ends_at_time'] ?? ''));
        if ($endTime !== '') {
            $date = $this->dateStringFromNaturalArgument($args['end_date'] ?? $args['date'] ?? $args['day'] ?? $args['start_date'] ?? null, $timeContext)
                ?: $startsAt->copy()->timezone($this->timeContext->timezone($timeContext))->toDateString();
            $combined = $this->dateOrNull("{$date} {$endTime}", $timeContext);
            if ($combined instanceof Carbon) {
                if ($combined->lessThanOrEqualTo($startsAt)) return $combined->addDay();
                return $combined;
            }
        }

        $durationMinutes = isset($args['duration_minutes']) ? (int) $args['duration_minutes'] : 60;
        return (clone $startsAt)->addMinutes(max(1, min(24 * 60, $durationMinutes)));
    }

    private function dateStringFromNaturalArgument(mixed $value, array $timeContext): ?string
    {
        if (blank($value)) return null;
        $label = trim((string) $value);
        if ($this->timeContext->isDateOnly($label)) return $label;

        $rawLabel = mb_strtolower($label);
        $normalized = strtolower(trim(preg_replace('/[^a-z0-9\- ]+/', ' ', $rawLabel) ?: $rawLabel));
        $normalized = trim(preg_replace('/\s+/', ' ', $normalized) ?: $normalized);
        $normalized = trim(preg_replace('/^(this|coming|next|on|the)\s+/u', '', $normalized) ?: $normalized);

        $local = $this->timeContext->localNow($timeContext)->startOfDay();
        if ($normalized === 'today') return $local->toDateString();
        if ($normalized === 'tomorrow') return $local->addDay()->toDateString();

        return $this->dateForTimeLabel($label, $timeContext);
    }

    private function normalizeTemporalUpdate(Model $model, string $field, mixed $value, array $timeContext, array $args): ?Carbon
    {
        $parsed = $this->dateOrNull($value, $timeContext);
        if (! $parsed instanceof Carbon) return null;
        if (! $this->shouldPreserveExistingLocalTime($model, $field, $value, $parsed, $timeContext, $args)) return $parsed;

        $existing = $model->getAttribute($field);
        if (! $existing instanceof Carbon) return $parsed;

        $timezone = $this->timeContext->timezone($timeContext);
        $targetLocal = $parsed->copy()->timezone($timezone);
        $existingLocal = $existing->copy()->timezone($timezone);

        return $targetLocal
            ->setTime((int) $existingLocal->format('H'), (int) $existingLocal->format('i'), (int) $existingLocal->format('s'))
            ->utc();
    }

    private function shouldPreserveExistingLocalTime(Model $model, string $field, mixed $value, Carbon $parsed, array $timeContext, array $args): bool
    {
        if (! in_array($field, ['due_at', 'remind_at', 'starts_at'], true)) return false;
        if (($args['preserve_time'] ?? null) === false || ($args[$field.'_time_explicit'] ?? false) === true || ($args['time_was_explicit'] ?? false) === true) return false;
        if (($args['preserve_time'] ?? null) === true) return true;
        if (! $model->getAttribute($field) instanceof Carbon) return false;
        if ($this->timeContext->isDateOnly($value)) return true;

        $local = $parsed->copy()->timezone($this->timeContext->timezone($timeContext));
        return in_array($local->format('H:i:s'), ['00:00:00', '23:59:59'], true);
    }

    private function metadata(array $args): array { return is_array($args['metadata'] ?? null) ? $args['metadata'] : ['created_by' => 'bean']; }

    private function summaries($items, ?array $accessibleWorkspaceIds = null, ?array $timeContext = null): array { return $items->map(fn ($item): array => $this->summary($item, $accessibleWorkspaceIds, $timeContext))->values()->all(); }

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

    private function summary(Model $model, ?array $accessibleWorkspaceIds = null, ?array $timeContext = null): array
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
            'due_at_local' => $this->localIso($model->getAttribute('due_at'), $timeContext),
            'remind_at' => optional($model->getAttribute('remind_at'))->toIso8601String(),
            'remind_at_local' => $this->localIso($model->getAttribute('remind_at'), $timeContext),
            'starts_at' => optional($model->getAttribute('starts_at'))->toIso8601String(),
            'starts_at_local' => $this->localIso($model->getAttribute('starts_at'), $timeContext),
            'ends_at' => optional($model->getAttribute('ends_at'))->toIso8601String(),
            'ends_at_local' => $this->localIso($model->getAttribute('ends_at'), $timeContext),
            'plain_text' => str($model->getAttribute('plain_text') ?? '')->limit(160)->toString(),
            'resource_type' => $this->resourceType($model),
        ], fn ($value) => $value !== null && $value !== '' && $value !== []);
    }

    private function localIso(mixed $value, ?array $timeContext): ?string
    {
        if (! $value instanceof Carbon || $timeContext === null) return null;
        return $value->copy()->timezone($this->timeContext->timezone($timeContext))->toIso8601String();
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
