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
                'time.now' => $this->timeNow(),
                'weather.lookup' => $this->weatherLookup($arguments),
                'task.list' => $this->listResources(Task::class, $run, 'due_at'),
                'task.search' => $this->searchResources(Task::class, $run, $arguments),
                'task.create' => $this->createTask($run, $arguments),
                'task.update' => $this->updateResource(Task::class, $run, $arguments, ['title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'due_at', 'completed_at', 'metadata']),
                'task.complete' => $this->completeResource(Task::class, $run, $arguments, 'completed_at'),
                'task.delete' => $this->deleteResource(Task::class, $run, $arguments),
                'reminder.list' => $this->listResources(Reminder::class, $run, 'remind_at'),
                'reminder.search' => $this->searchResources(Reminder::class, $run, $arguments),
                'reminder.create' => $this->createReminder($run, $arguments),
                'reminder.update' => $this->updateResource(Reminder::class, $run, $arguments, ['title', 'notes', 'category', 'color', 'is_critical', 'remind_at', 'status', 'metadata']),
                'reminder.complete' => $this->completeResource(Reminder::class, $run, $arguments, null),
                'reminder.delete' => $this->deleteResource(Reminder::class, $run, $arguments),
                'calendar_event.list' => $this->listResources(CalendarEvent::class, $run, 'starts_at'),
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

    private function listResources(string $class, BeanRun $run, string $orderField): array
    {
        $items = $this->baseQuery($class, $run)->orderBy($orderField)->orderBy('id')->limit(20)->get();
        return ['ok' => true, 'items' => $this->summaries($items)];
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
        return ['ok' => true, 'items' => $this->summaries($query->orderByDesc('updated_at')->limit(10)->get())];
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
        return ['ok' => true, 'resource_type' => 'task', 'item' => $this->summary($task)];
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
        return ['ok' => true, 'resource_type' => 'reminder', 'item' => $this->summary($reminder)];
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
        return ['ok' => true, 'resource_type' => 'calendar_event', 'item' => $this->summary($event)];
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
        return ['ok' => true, 'resource_type' => 'note', 'item' => $this->summary($note)];
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
        return ['ok' => true, 'resource_type' => $this->resourceType($updated), 'item' => $this->summary($updated)];
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
        return ['ok' => true, 'resource_type' => $this->resourceType($updated), 'item' => $this->summary($updated)];
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
        if ($matches->count() > 1) return ['ok' => false, 'ambiguous' => true, 'error' => 'I found multiple matching items. Please choose one.', 'items' => $this->summaries($matches)];
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

    private function summaries($items): array { return $items->map(fn ($item): array => $this->summary($item))->values()->all(); }
    private function summary(Model $model): array
    {
        return array_filter([
            'id' => $model->getKey(),
            'title' => $model->getAttribute('title'),
            'status' => $model->getAttribute('status'),
            'due_at' => optional($model->getAttribute('due_at'))->toIso8601String(),
            'remind_at' => optional($model->getAttribute('remind_at'))->toIso8601String(),
            'starts_at' => optional($model->getAttribute('starts_at'))->toIso8601String(),
            'ends_at' => optional($model->getAttribute('ends_at'))->toIso8601String(),
            'plain_text' => str($model->getAttribute('plain_text') ?? '')->limit(160)->toString(),
            'resource_type' => $this->resourceType($model),
        ], fn ($value) => $value !== null && $value !== '');
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
