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
    public function __construct(
        private readonly WorkspaceService $workspaces,
        private readonly BeanTimeContext $timeContext,
    ) {}

    /** @return array<string, mixed> */
    public function build(User $user, BeanSession $session, ?string $clientTimezone = null): array
    {
        $context = $clientTimezone !== null
            ? $this->timeContext->forClientTimezone($clientTimezone)
            : $this->timeContext->forSession($session);
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
            ->limit(8)
            ->get()
            ->map(fn (Task $task): array => $this->taskSummary($task, $workspaceMap))
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
            ->limit(8)
            ->get()
            ->map(fn (Task $task): array => $this->taskSummary($task, $workspaceMap))
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
            ->limit(8)
            ->get()
            ->map(fn (Reminder $reminder): array => [
                'id' => $reminder->id,
                'title' => $reminder->title,
                'remind_at' => optional($reminder->remind_at)->toIso8601String(),
                'workspace_id' => $reminder->workspace_id,
                'workspace_name' => $workspaceMap->get($reminder->workspace_id),
            ])
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
            ->where('ends_at', '>=', $start)
            ->orderBy('starts_at')
            ->limit(8)
            ->get()
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'starts_at' => optional($event->starts_at)->toIso8601String(),
                'ends_at' => optional($event->ends_at)->toIso8601String(),
                'location' => $event->location,
                'workspace_id' => $event->workspace_id,
                'workspace_name' => $workspaceMap->get($event->workspace_id),
            ])
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
    private function taskSummary(Task $task, Collection $workspaceMap): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'type' => $task->type,
            'status' => $task->status,
            'due_at' => optional($task->due_at)->toIso8601String(),
            'is_critical' => (bool) $task->is_critical,
            'workspace_id' => $task->workspace_id,
            'workspace_name' => $workspaceMap->get($task->workspace_id),
        ];
    }
}
