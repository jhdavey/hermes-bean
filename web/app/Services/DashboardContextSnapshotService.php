<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;

class DashboardContextSnapshotService
{
    public function __construct(private readonly GoogleCalendarSyncService $googleCalendar) {}

    public function snapshot(User $user, Workspace $workspace): array
    {
        $now = now();
        $todayStart = $now->copy()->startOfDay();
        $todayEnd = $now->copy()->endOfDay();
        $weekEnd = $now->copy()->addDays(7)->endOfDay();
        $monthEnd = $now->copy()->endOfMonth();

        $tasksQuery = Task::query()
            ->where('workspace_id', $workspace->id)
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['completed', 'complete', 'done', 'COMPLETED', 'Complete', 'Done']);
            });

        $tasks = (clone $tasksQuery)
            ->where(function (Builder $query) use ($monthEnd): void {
                $query->whereNull('due_at')
                    ->orWhere('due_at', '<=', $monthEnd)
                    ->orWhere('is_critical', true);
            })
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(40)
            ->get();

        $reminders = Reminder::query()
            ->where('workspace_id', $workspace->id)
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['completed', 'complete', 'done', 'COMPLETED', 'Complete', 'Done']);
            })
            ->where('remind_at', '<=', $weekEnd)
            ->orderBy('remind_at')
            ->orderBy('id')
            ->limit(25)
            ->get();

        $calendarEventsQuery = CalendarEvent::query()
            ->where('workspace_id', $workspace->id)
            ->where('starts_at', '<=', $weekEnd)
            ->where(function (Builder $query) use ($todayStart): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $todayStart);
            });
        $this->scopeVisibleGoogleCalendars($calendarEventsQuery, $user, $workspace);
        $calendarEvents = $calendarEventsQuery
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(30)
            ->get()
            ->reject(fn (CalendarEvent $event): bool => (bool) (($event->metadata ?? [])['recurrence_source_hidden'] ?? false))
            ->values();

        return [
            'generated_at' => $now->toIso8601String(),
            'today' => $todayStart->toDateString(),
            'timezone' => config('app.timezone'),
            'workspace' => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
            ],
            'counts' => [
                'open_tasks' => (clone $tasksQuery)->count(),
                'calendar_events_next_7_days' => $calendarEvents->count(),
                'reminders_next_7_days' => $reminders->count(),
            ],
            'calendar_today' => $calendarEvents
                ->filter(fn (CalendarEvent $event): bool => $this->overlaps($event->starts_at, $event->ends_at, $todayStart, $todayEnd))
                ->take(12)
                ->map(fn (CalendarEvent $event): array => $this->calendarEventPayload($event))
                ->values()
                ->all(),
            'calendar_upcoming' => $calendarEvents
                ->filter(fn (CalendarEvent $event): bool => $event->starts_at?->gt($todayEnd))
                ->take(12)
                ->map(fn (CalendarEvent $event): array => $this->calendarEventPayload($event))
                ->values()
                ->all(),
            'tasks_overdue' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at?->lt($todayStart) ?? false)
                ->take(12)
                ->map(fn (Task $task): array => $this->taskPayload($task))
                ->values()
                ->all(),
            'tasks_due_today' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at ? $task->due_at->betweenIncluded($todayStart, $todayEnd) : false)
                ->take(12)
                ->map(fn (Task $task): array => $this->taskPayload($task))
                ->values()
                ->all(),
            'tasks_upcoming_month' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at?->gt($todayEnd) ?? false)
                ->take(12)
                ->map(fn (Task $task): array => $this->taskPayload($task))
                ->values()
                ->all(),
            'critical_unscheduled_tasks' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at === null && (bool) $task->is_critical)
                ->take(8)
                ->map(fn (Task $task): array => $this->taskPayload($task))
                ->values()
                ->all(),
            'reminders_due' => $reminders
                ->take(15)
                ->map(fn (Reminder $reminder): array => $this->reminderPayload($reminder))
                ->values()
                ->all(),
        ];
    }

    public function promptText(User $user, Workspace $workspace): string
    {
        $snapshot = $this->snapshot($user, $workspace);
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<TEXT
Dashboard context snapshot for fast read-only answers.
Use this snapshot to answer simple questions about today's calendar, upcoming events, current tasks, and reminders without calling tools. If the user asks for anything outside this snapshot, needs a write/change, or needs fresh external data, call queue_bean_work. Treat this snapshot as current as of generated_at.
{$json}
TEXT;
    }

    private function taskPayload(Task $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'due_at' => $task->due_at?->toIso8601String(),
            'type' => $task->type,
            'category' => $task->category,
            'critical' => (bool) $task->is_critical,
            'recurrence' => data_get($task->metadata, 'recurrence') ?? data_get($task->metadata, 'recurring') ?? data_get($task->metadata, 'rrule'),
        ];
    }

    private function reminderPayload(Reminder $reminder): array
    {
        return [
            'id' => $reminder->id,
            'title' => $reminder->title,
            'remind_at' => $reminder->remind_at?->toIso8601String(),
            'category' => $reminder->category,
            'critical' => (bool) $reminder->is_critical,
        ];
    }

    private function calendarEventPayload(CalendarEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'starts_at' => $event->starts_at?->toIso8601String(),
            'ends_at' => $event->ends_at?->toIso8601String(),
            'location' => $event->location,
            'category' => $event->category,
            'critical' => (bool) $event->is_critical,
            'source' => data_get($event->metadata, 'source'),
        ];
    }

    private function overlaps(?Carbon $startsAt, ?Carbon $endsAt, Carbon $rangeStart, Carbon $rangeEnd): bool
    {
        if (! $startsAt) {
            return false;
        }
        $end = $endsAt ?: $startsAt;

        return $startsAt->lte($rangeEnd) && $end->gte($rangeStart);
    }

    private function scopeVisibleGoogleCalendars(Builder $query, User $user, Workspace $workspace): void
    {
        $visibleGoogleCalendarIds = $this->googleCalendar->visibleGoogleCalendarIdsForWorkspace($user, $workspace);
        if ($visibleGoogleCalendarIds === null) {
            return;
        }

        $query->where(function (Builder $query) use ($visibleGoogleCalendarIds): void {
            $query->where(function (Builder $query): void {
                $query->whereNull('metadata->source')
                    ->orWhere('metadata->source', '!=', 'google_calendar');
            });

            if ($visibleGoogleCalendarIds !== []) {
                $query->orWhere(function (Builder $query) use ($visibleGoogleCalendarIds): void {
                    $query->where('metadata->source', 'google_calendar')
                        ->where(function (Builder $query) use ($visibleGoogleCalendarIds): void {
                            $query->whereIn('google_calendar_id', $visibleGoogleCalendarIds);
                            foreach ($visibleGoogleCalendarIds as $calendarId) {
                                $query->orWhere('metadata->google_calendar_id', $calendarId);
                            }
                        });
                });
            }
        });
    }
}
