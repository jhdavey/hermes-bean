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
use App\Services\Domain\DomainResourceService;
use App\Services\WorkspaceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Throwable;

class BeanActionExecutor
{
    public function __construct(
        private readonly BeanActivityLogger $activity,
        private readonly DomainResourceService $domainResources,
    ) {}

    public function execute(BeanSession $session, BeanRun $run, string $action, array $arguments = [], bool $confirmed = false): array
    {
        $tool = BeanToolCall::create([
            'bean_run_id' => $run->id,
            'user_id' => $run->user_id,
            'workspace_id' => $run->workspace_id,
            'action' => $action,
            'arguments' => $arguments,
            'status' => 'running',
            'started_at' => now(),
        ]);

        $this->activity->log($session, $run, 'tool_started', $this->labelFor($action), ['action' => $action]);

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
                'weather.lookup' => $this->weatherLookup($arguments),
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
                'note.list' => $this->listResources(Note::class, $run, 'updated_at'),
                'note.search' => $this->searchResources(Note::class, $run, $arguments, ['title', 'plain_text']),
                'note.create' => $this->createNote($run, $arguments),
                'note.update' => $this->updateResource(Note::class, $run, $arguments, ['title', 'body_html', 'plain_text', 'body_delta', 'is_pinned', 'metadata']),
                'note.delete' => $this->deleteResource(Note::class, $run, $arguments),
                default => ['ok' => false, 'error' => "Unsupported Bean action: {$action}"],
            };

            $tool->update(['status' => ($result['ok'] ?? false) ? 'completed' : 'failed', 'result' => $result, 'error' => $result['error'] ?? null, 'completed_at' => now()]);
            $this->activity->log($session, $run, ($result['ok'] ?? false) ? 'tool_completed' : 'tool_failed', $this->resultLabel($action, $result), ['action' => $action, 'result' => $result]);
            return $result;
        } catch (HttpResponseException $exception) {
            $payload = json_decode((string) $exception->getResponse()->getContent(), true) ?: [];
            $message = (string) ($payload['message'] ?? data_get($payload, 'error.message') ?? 'Bean could not complete that action.');
            $result = ['ok' => false, 'error' => $message, 'response' => $payload];
            $tool->update(['status' => 'failed', 'result' => $result, 'error' => $message, 'completed_at' => now()]);
            $this->activity->log($session, $run, 'tool_failed', $message, $result);
            return $result;
        } catch (Throwable $exception) {
            $result = ['ok' => false, 'error' => $exception->getMessage()];
            $tool->update(['status' => 'failed', 'result' => $result, 'error' => $exception->getMessage(), 'completed_at' => now()]);
            $this->activity->log($session, $run, 'tool_failed', "Bean could not complete {$action}.", $result);
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

    private function listResources(string $class, BeanRun $run, string $orderField, array $arguments = []): array
    {
        $query = $this->baseQuery($class, $run);
        if ($class === Task::class) {
            $query->where('status', '!=', 'completed');
        } elseif ($class === Reminder::class) {
            $query->where('status', 'scheduled');
        } elseif ($class === CalendarEvent::class) {
            $query->where('status', 'scheduled');
        }
        $dateScope = strtolower(trim((string) ($arguments['date_scope'] ?? $arguments['scope'] ?? '')));
        if (in_array($dateScope, ['today', 'overdue'], true)) {
            $this->applyDateScope($query, $class, $orderField, $dateScope);
        }
        $accessibleWorkspaceIds = $this->workspaceIds($run);
        $items = $query->orderBy($orderField)->orderBy('id')->limit(20)->get();
        return ['ok' => true, 'items' => $this->collapseLinkedSummaries($this->summaries($items, $accessibleWorkspaceIds)), 'date_scope' => $dateScope ?: null];
    }

    private function applyDateScope(Builder $query, string $class, string $field, string $scope): void
    {
        $start = now()->startOfDay()->utc();
        $end = now()->endOfDay()->utc();
        $query->whereNotNull($field);
        if ($scope === 'overdue') {
            $query->where($field, '<', $start);
            return;
        }
        if ($class === CalendarEvent::class) {
            $query->whereBetween($field, [$start, $end]);
            return;
        }
        if (in_array($class, [Task::class, Reminder::class], true)) {
            $query->where($field, '<=', $end);
        }
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
        return ['ok' => true, 'items' => $this->summaries($query->orderByDesc('updated_at')->limit(10)->get(), $this->workspaceIds($run))];
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
        [$class, $orderField, $label] = match ($resource) {
            'task', 'tasks', 'todo', 'todos' => [Task::class, 'due_at', 'tasks'],
            'reminder', 'reminders' => [Reminder::class, 'remind_at', 'reminders'],
            'calendar', 'calendar_event', 'calendar_events', 'events' => [CalendarEvent::class, 'starts_at', 'calendar_events'],
            'note', 'notes' => [Note::class, 'updated_at', 'notes'],
            default => [Task::class, 'due_at', 'tasks'],
        };

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

        if ($class === Task::class && ($arguments['status'] ?? null) === null) {
            $query->where('status', '!=', 'completed');
        } elseif ($class === Reminder::class && ($arguments['status'] ?? null) === null) {
            $query->where('status', 'scheduled');
        } elseif ($class === CalendarEvent::class && ($arguments['status'] ?? null) === null) {
            $query->where('status', 'scheduled');
        }
        if (($arguments['status'] ?? null) !== null) {
            $query->where('status', (string) $arguments['status']);
        }

        $dateScope = strtolower(trim((string) ($arguments['date_scope'] ?? '')));
        if (in_array($dateScope, ['today', 'overdue'], true)) {
            $this->applyDateScope($query, $class, $orderField, $dateScope);
        }
        if (($arguments['workspace_id'] ?? null) !== null) {
            $query->where('workspace_id', (int) $arguments['workspace_id']);
        }

        $items = $query->orderBy($orderField)->orderBy('id')->limit(20)->get();
        $accessibleWorkspaceIds = $this->workspaceIds($run);
        $summaries = $this->collapseLinkedSummaries($this->summaries($items, $accessibleWorkspaceIds));
        $explanations = [];
        if (($arguments['explain_visibility'] ?? false) || $dateScope !== '') {
            $explanations = collect($summaries)
                ->map(fn (array $item): array => [
                    'id' => $item['id'] ?? null,
                    'title' => $item['title'] ?? null,
                    'reason' => $this->visibilityReason($item, $class, $dateScope),
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
            'date_scope' => $dateScope ?: null,
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

    private function visibilityReason(array $item, string $class, string $dateScope): string
    {
        if ($class === Task::class) {
            $title = (string) ($item['title'] ?? 'This task');
            $status = (string) ($item['status'] ?? 'open');
            $dueAt = isset($item['due_at']) ? Carbon::parse((string) $item['due_at']) : null;
            if ($dateScope === 'today' && $status !== 'completed' && $dueAt) {
                return $dueAt->lt(now()->startOfDay())
                    ? "{$title} is on today's list because it is overdue and still open."
                    : "{$title} is on today's list because it is due by today and still open.";
            }
            if ($dateScope === 'overdue' && $status !== 'completed' && $dueAt) {
                return "{$title} is overdue because it was due before today and is still open.";
            }
        }
        if ($class === Reminder::class) {
            $title = (string) ($item['title'] ?? 'This reminder');
            $remindAt = isset($item['remind_at']) ? Carbon::parse((string) $item['remind_at']) : null;
            if ($dateScope === 'today' && $remindAt) return "{$title} is included because it is scheduled by today.";
            if ($dateScope === 'overdue' && $remindAt) return "{$title} is overdue because it was scheduled before today.";
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
        $title = trim((string) ($args['title'] ?? '')) ?: (str($plain ?: 'New Note')->limit(80, '')->toString());
        $note = $this->domainResources->createNote($this->user($run), [
            'workspace_id' => $this->workspaceId($run),
            'title' => $title,
            'plain_text' => $plain,
            'body_html' => $args['body_html'] ?? nl2br(e($plain)),
            'body_delta' => $args['body_delta'] ?? null,
            'note_folder_id' => $args['note_folder_id'] ?? null,
            'is_pinned' => $args['is_pinned'] ?? null,
            'metadata' => $this->metadata($args),
        ]);
        return ['ok' => true, 'resource_type' => 'note', 'item' => $this->summary($note, $this->workspaceIds($run))];
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
            'tasks' => $this->baseQuery(Task::class, $run)->where('status', '!=', 'completed')->count(),
            'reminders' => $this->baseQuery(Reminder::class, $run)->where('status', 'scheduled')->count(),
            'calendar_events' => $this->baseQuery(CalendarEvent::class, $run)->where('starts_at', '>=', now()->startOfDay())->count(),
            'notes' => $this->baseQuery(Note::class, $run)->count(),
        ]];
    }

    private function timeNow(): array
    {
        return ['ok' => true, 'now' => now()->toIso8601String(), 'timezone' => config('app.timezone')];
    }

    private function weatherLookup(array $args): array
    {
        $lat = $args['latitude'] ?? $args['lat'] ?? null;
        $lon = $args['longitude'] ?? $args['lon'] ?? null;
        if ($lat === null || $lon === null) {
            $location = trim((string) ($args['location'] ?? $args['query'] ?? ''));
            $location = trim(preg_replace('/\b(weather|forecast|temperature|in|for|at|near)\b/i', ' ', $location) ?: $location);
            if ($location === '') return ['ok' => false, 'error' => 'I need a location for weather.'];
            $geo = Http::timeout(8)->get('https://geocoding-api.open-meteo.com/v1/search', ['name' => $location, 'count' => 1, 'language' => 'en', 'format' => 'json'])->json('results.0');
            if (! is_array($geo)) return ['ok' => false, 'error' => 'I could not find that weather location.'];
            $lat = $geo['latitude']; $lon = $geo['longitude'];
        }
        $data = Http::timeout(8)->get('https://api.open-meteo.com/v1/forecast', [
            'latitude' => $lat,
            'longitude' => $lon,
            'daily' => 'temperature_2m_max,temperature_2m_min,precipitation_probability_max,weather_code',
            'timezone' => 'auto',
            'forecast_days' => 3,
        ])->json();
        return ['ok' => true, 'provider' => 'open-meteo', 'forecast' => $data['daily'] ?? $data];
    }

    private function isDestructive(string $action): bool { return str_ends_with($action, '.delete'); }
    private function confirmationSummary(string $action, array $arguments): string { return "Confirm before I run {$action}."; }
    private function labelFor(string $action): string { return 'Bean is '.str_replace('_', ' ', str_replace('.', ' ', $action)).'...'; }
    private function resultLabel(string $action, array $result): string { return ($result['ok'] ?? false) ? 'Bean finished '.str_replace('.', ' ', $action).'.' : (string) ($result['error'] ?? 'Bean hit a problem.'); }

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
