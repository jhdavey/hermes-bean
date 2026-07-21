<?php

namespace App\Services\Bean;

use App\Models\BeanSession;
use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\WorkspaceService;
use Illuminate\Support\Collection;

class DashboardContextBuilder
{
    private const UPCOMING_HORIZON_DAYS = 31;

    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly BeanTimeContext $timeContext,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, BeanSession $session, ?string $clientTimezone = null): array
    {
        $context = $this->timeContext->forUser($user, $clientTimezone);
        $localNow = $this->timeContext->localNow($context);
        $today = $localNow->toDateString();
        $tomorrow = $localNow->copy()->addDay()->toDateString();
        $workspaceMap = $this->workspaces->accessibleWorkspaces($user)
            ->keyBy('id')
            ->map(fn ($workspace): string => (string) $workspace->name);
        $workspaceIds = $workspaceMap->keys()->map(fn ($id): int => (int) $id)->values()->all();

        return [
            'schema' => 'heybean.dashboard_context.v1',
            'generated_at' => now('UTC')->toIso8601String(),
            'timezone' => $context['timezone'],
            'local_date' => $today,
            'session_id' => $session->id,
            'workspace_id' => $session->workspace_id,
            'workspaces' => $workspaceMap->map(fn (string $name, int|string $id): array => ['id' => (int) $id, 'name' => $name])->values()->all(),
            'policy' => [
                'answer_from_context_for_read_only' => true,
                'call_askBean_when_missing_or_mutating' => true,
                'call_askBean_when_stale_or_uncertain' => true,
                'authoritative_fallback_tool' => 'askBean',
            ],
            'today' => [
                'date' => $today,
                'tasks' => $this->tasksForDay($workspaceIds, $workspaceMap, $context, $today),
                'reminders' => $this->remindersForDay($workspaceIds, $workspaceMap, $context, $today),
                'calendar_events' => $this->eventsForDay($workspaceIds, $workspaceMap, $context, $today),
            ],
            'tomorrow' => [
                'date' => $tomorrow,
                'tasks' => $this->tasksForDay($workspaceIds, $workspaceMap, $context, $tomorrow),
                'reminders' => $this->remindersForDay($workspaceIds, $workspaceMap, $context, $tomorrow),
                'calendar_events' => $this->eventsForDay($workspaceIds, $workspaceMap, $context, $tomorrow),
            ],
            'upcoming' => [
                'horizon_days' => self::UPCOMING_HORIZON_DAYS,
                'tasks' => $this->upcomingTasks($workspaceIds, $workspaceMap, $context, self::UPCOMING_HORIZON_DAYS),
                'reminders' => $this->upcomingReminders($workspaceIds, $workspaceMap, $context, self::UPCOMING_HORIZON_DAYS),
                'calendar_events' => $this->upcomingEvents($workspaceIds, $workspaceMap, $context, self::UPCOMING_HORIZON_DAYS),
            ],
            'overdue' => [
                'tasks' => $this->overdueTasks($workspaceIds, $workspaceMap, $context),
            ],
            'recent_notes' => $this->recentNotes($workspaceIds, $workspaceMap),
        ];
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap @param array{timezone:string} $context */
    private function tasksForDay(array $workspaceIds, Collection $workspaceMap, array $context, string $date): array
    {
        [$start, $end] = $this->timeContext->localDayUtcRange($date, $context);

        return Task::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', Task::STATUS_OPEN)
            ->whereBetween('due_at', [$start, $end])
            ->orderBy('due_at')
            ->get()
            ->map(fn (Task $task): array => $this->taskSummary($task, $workspaceMap, $context))
            ->values()
            ->all();
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap @param array{timezone:string} $context */
    private function overdueTasks(array $workspaceIds, Collection $workspaceMap, array $context): array
    {
        [$todayStart] = $this->timeContext->todayUtcRange($context);

        return Task::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', Task::STATUS_OPEN)
            ->whereNotNull('due_at')
            ->where('due_at', '<', $todayStart)
            ->orderBy('due_at')
            ->get()
            ->map(fn (Task $task): array => $this->taskSummary($task, $workspaceMap, $context))
            ->values()
            ->all();
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap @param array{timezone:string} $context */
    private function upcomingTasks(array $workspaceIds, Collection $workspaceMap, array $context, int $days): array
    {
        [$start] = $this->timeContext->todayUtcRange($context);
        [, $end] = $this->timeContext->localDayUtcRange($this->timeContext->localNow($context)->addDays($days)->toDateString(), $context);

        return Task::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', Task::STATUS_OPEN)
            ->whereBetween('due_at', [$start, $end])
            ->orderBy('due_at')
            ->get()
            ->map(fn (Task $task): array => $this->taskSummary($task, $workspaceMap, $context))
            ->values()
            ->all();
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap @param array{timezone:string} $context */
    private function remindersForDay(array $workspaceIds, Collection $workspaceMap, array $context, string $date): array
    {
        [$start, $end] = $this->timeContext->localDayUtcRange($date, $context);

        return Reminder::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', 'scheduled')
            ->whereBetween('remind_at', [$start, $end])
            ->orderBy('remind_at')
            ->get()
            ->map(fn (Reminder $reminder): array => $this->reminderSummary($reminder, $workspaceMap, $context))
            ->values()
            ->all();
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap @param array{timezone:string} $context */
    private function upcomingReminders(array $workspaceIds, Collection $workspaceMap, array $context, int $days): array
    {
        [$start] = $this->timeContext->todayUtcRange($context);
        [, $end] = $this->timeContext->localDayUtcRange($this->timeContext->localNow($context)->addDays($days)->toDateString(), $context);

        return Reminder::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', 'scheduled')
            ->whereBetween('remind_at', [$start, $end])
            ->orderBy('remind_at')
            ->get()
            ->map(fn (Reminder $reminder): array => $this->reminderSummary($reminder, $workspaceMap, $context))
            ->values()
            ->all();
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap @param array{timezone:string} $context */
    private function eventsForDay(array $workspaceIds, Collection $workspaceMap, array $context, string $date): array
    {
        [$start, $end] = $this->timeContext->localDayUtcRange($date, $context);

        return CalendarEvent::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', 'scheduled')
            ->where('starts_at', '<=', $end)
            ->where(function ($query) use ($start): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $start);
            })
            ->orderBy('starts_at')
            ->get()
            ->map(fn (CalendarEvent $event): array => $this->eventSummary($event, $workspaceMap, $context))
            ->values()
            ->all();
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap @param array{timezone:string} $context */
    private function upcomingEvents(array $workspaceIds, Collection $workspaceMap, array $context, int $days): array
    {
        [$start] = $this->timeContext->todayUtcRange($context);
        [, $end] = $this->timeContext->localDayUtcRange($this->timeContext->localNow($context)->addDays($days)->toDateString(), $context);

        return CalendarEvent::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where('status', 'scheduled')
            ->where('starts_at', '<=', $end)
            ->where(function ($query) use ($start): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>=', $start);
            })
            ->orderBy('starts_at')
            ->get()
            ->map(fn (CalendarEvent $event): array => $this->eventSummary($event, $workspaceMap, $context))
            ->values()
            ->all();
    }

    /** @param array<int> $workspaceIds @param Collection<int|string, string> $workspaceMap */
    private function recentNotes(array $workspaceIds, Collection $workspaceMap): array
    {
        return Note::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->orderByDesc('updated_at')
            ->limit(5)
            ->get()
            ->map(fn (Note $note): array => [
                'id' => $note->id,
                'title' => $note->title,
                'updated_at' => optional($note->updated_at)->toIso8601String(),
                'workspace_id' => $note->workspace_id,
                'workspace_name' => $workspaceMap->get($note->workspace_id),
            ])
            ->values()
            ->all();
    }

    /** @param Collection<int|string, string> $workspaceMap */
    private function taskSummary(Task $task, Collection $workspaceMap, array $context): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type,
            'status' => $task->status,
            'due_at' => optional($task->due_at)->toIso8601String(),
            'due_at_local' => $this->localIso($task->due_at, $context),
            'local_date' => $this->localDate($task->due_at, $context),
            'is_critical' => (bool) $task->is_critical,
            'workspace_id' => $task->workspace_id,
            'workspace_name' => $workspaceMap->get($task->workspace_id),
        ];
    }

    /** @param Collection<int|string, string> $workspaceMap */
    private function reminderSummary(Reminder $reminder, Collection $workspaceMap, array $context): array
    {
        return [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'remind_at' => optional($reminder->remind_at)->toIso8601String(),
            'remind_at_local' => $this->localIso($reminder->remind_at, $context),
            'local_date' => $this->localDate($reminder->remind_at, $context),
            'workspace_id' => $reminder->workspace_id,
            'workspace_name' => $workspaceMap->get($reminder->workspace_id),
        ];
    }

    /** @param Collection<int|string, string> $workspaceMap */
    private function eventSummary(CalendarEvent $event, Collection $workspaceMap, array $context): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => optional($event->starts_at)->toIso8601String(),
            'starts_at_local' => $this->localIso($event->starts_at, $context),
            'ends_at' => optional($event->ends_at)->toIso8601String(),
            'ends_at_local' => $this->localIso($event->ends_at, $context),
            'local_date' => $this->localDate($event->starts_at, $context),
            'location' => $event->location,
            'workspace_id' => $event->workspace_id,
            'workspace_name' => $workspaceMap->get($event->workspace_id),
        ];
    }

    private function localIso(mixed $value, array $context): ?string
    {
        return $value ? $value->copy()->timezone($this->timeContext->timezone($context))->toIso8601String() : null;
    }

    private function localDate(mixed $value, array $context): ?string
    {
        return $value ? $value->copy()->timezone($this->timeContext->timezone($context))->toDateString() : null;
    }
}
