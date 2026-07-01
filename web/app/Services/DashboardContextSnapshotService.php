<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class DashboardContextSnapshotService
{
    public function __construct(
        private readonly GoogleCalendarSyncService $googleCalendar,
        private readonly AgentProfileService $agentProfiles,
        private readonly OpenMeteoWeatherService $weather,
        private readonly PlanLimitService $planLimits,
    ) {}

    public function snapshot(User $user, Workspace $workspace, ?array $clientContext = null): array
    {
        $timezone = $this->displayTimezone($clientContext);
        $now = now($timezone);
        $todayStartLocal = $now->copy()->startOfDay();
        $todayEndLocal = $now->copy()->endOfDay();
        $weekEndLocal = $now->copy()->addDays(7)->endOfDay();
        $todayStart = $todayStartLocal->copy()->utc();
        $todayEnd = $todayEndLocal->copy()->utc();
        $weekEnd = $weekEndLocal->copy()->utc();
        $workspaces = $this->snapshotWorkspaces($user, $workspace);
        $workspaceIds = $workspaces->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $workspacesById = $workspaces->keyBy('id');

        $tasksQuery = Task::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['completed', 'complete', 'done', 'COMPLETED', 'Complete', 'Done']);
            });

        $tasks = (clone $tasksQuery)
            ->where(function (Builder $query) use ($weekEnd): void {
                $query->whereNull('due_at')
                    ->orWhere('due_at', '<=', $weekEnd);
            })
            ->orderByRaw('due_at IS NULL')
            ->orderBy('due_at')
            ->orderBy('id')
            ->limit(60)
            ->get();

        $remindersQuery = Reminder::query()
            ->whereIn('workspace_id', $workspaceIds)
            ->where(function (Builder $query): void {
                $query->whereNull('status')
                    ->orWhereNotIn('status', ['completed', 'complete', 'done', 'COMPLETED', 'Complete', 'Done']);
            })
            ->where('remind_at', '<=', $weekEnd);
        $reminders = (clone $remindersQuery)
            ->orderBy('remind_at')
            ->orderBy('id')
            ->limit(40)
            ->get();

        $calendarEventsQuery = CalendarEvent::query()
            ->where('starts_at', '<=', $weekEnd)
            ->where(function (Builder $query) use ($todayStart): void {
                $query->whereNull('ends_at')
                    ->orWhere('ends_at', '>=', $todayStart);
            });
        $this->scopeAccessibleWorkspaceCalendars($calendarEventsQuery, $user, $workspaces);
        $calendarEventsCount = (clone $calendarEventsQuery)->count();
        $calendarEvents = $calendarEventsQuery
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(60)
            ->get()
            ->reject(fn (CalendarEvent $event): bool => (bool) (($event->metadata ?? [])['recurrence_source_hidden'] ?? false))
            ->values();

        $notesEnabled = $this->planLimits->canUseNotes($user);
        $notesQuery = $notesEnabled
            ? Note::query()
                ->whereIn('workspace_id', $workspaceIds)
                ->with('folder')
            : null;
        $notes = $notesQuery === null
            ? collect()
            : (clone $notesQuery)
                ->orderByDesc('is_pinned')
                ->latest('updated_at')
                ->limit(30)
                ->get();

        $weatherLocation = $this->defaultWeatherLocation($user, $workspace, $clientContext);
        $weatherCurrent = $weatherLocation !== null && (bool) config('services.hermes_runtime.weather_lookup_enabled', true)
            ? $this->weather->currentWeather($weatherLocation, [
                'source' => 'dashboard_context_snapshot',
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
            ])
            : null;

        return [
            'generated_at' => $now->toIso8601String(),
            'generated_at_utc' => now()->utc()->toIso8601String(),
            'today' => $todayStartLocal->toDateString(),
            'timezone' => $timezone,
            'window' => [
                'starts_on' => $todayStartLocal->toDateString(),
                'ends_on' => $weekEndLocal->toDateString(),
                'future_days' => 7,
            ],
            'workspace' => $this->workspacePayload($workspace, true),
            'workspaces' => $workspaces
                ->map(fn (Workspace $candidate): array => $this->workspacePayload($candidate, (int) $candidate->id === (int) $workspace->id))
                ->values()
                ->all(),
            'counts' => [
                'open_tasks' => (clone $tasksQuery)->count(),
                'calendar_events_next_7_days' => $calendarEventsCount,
                'reminders_next_7_days' => (clone $remindersQuery)->count(),
                'notes' => $notesQuery === null ? 0 : (clone $notesQuery)->count(),
                'workspaces' => count($workspaceIds),
            ],
            'weather_current' => $weatherCurrent,
            'calendar_today' => $calendarEvents
                ->filter(fn (CalendarEvent $event): bool => $this->overlaps($event->starts_at, $event->ends_at, $todayStart, $todayEnd))
                ->take(20)
                ->map(fn (CalendarEvent $event): array => $this->calendarEventPayload($event, $timezone, $workspacesById))
                ->values()
                ->all(),
            'calendar_upcoming' => $calendarEvents
                ->filter(fn (CalendarEvent $event): bool => $event->starts_at?->gt($todayEnd))
                ->take(30)
                ->map(fn (CalendarEvent $event): array => $this->calendarEventPayload($event, $timezone, $workspacesById))
                ->values()
                ->all(),
            'tasks_overdue' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at?->lt($todayStart) ?? false)
                ->take(20)
                ->map(fn (Task $task): array => $this->taskPayload($task, $timezone, $workspacesById))
                ->values()
                ->all(),
            'tasks_due_today' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at ? $task->due_at->betweenIncluded($todayStart, $todayEnd) : false)
                ->take(20)
                ->map(fn (Task $task): array => $this->taskPayload($task, $timezone, $workspacesById))
                ->values()
                ->all(),
            'tasks_upcoming_next_7_days' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at?->gt($todayEnd) ?? false)
                ->take(30)
                ->map(fn (Task $task): array => $this->taskPayload($task, $timezone, $workspacesById))
                ->values()
                ->all(),
            'critical_unscheduled_tasks' => $tasks
                ->filter(fn (Task $task): bool => $task->due_at === null && (bool) $task->is_critical)
                ->take(12)
                ->map(fn (Task $task): array => $this->taskPayload($task, $timezone, $workspacesById))
                ->values()
                ->all(),
            'reminders_due' => $reminders
                ->take(30)
                ->map(fn (Reminder $reminder): array => $this->reminderPayload($reminder, $timezone, $workspacesById))
                ->values()
                ->all(),
            'notes_recent' => $notes
                ->map(fn (Note $note): array => $this->notePayload($note, $workspacesById))
                ->values()
                ->all(),
        ];
    }

    public function promptText(User $user, Workspace $workspace, ?array $clientContext = null): string
    {
        return $this->promptTextFromSnapshot($this->snapshot($user, $workspace, $clientContext));
    }

    public function promptTextFromSnapshot(array $snapshot): string
    {
        $json = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return <<<TEXT
Dashboard context snapshot for fast read-only answers.
Use this cross-workspace snapshot to answer simple questions about today's calendar, upcoming events, current tasks, reminders, and recent notes without calling tools. It includes the user's accessible workspaces and only warms app data through window.ends_on, up to 7 days in the future, plus recent notes. If the user asks for anything outside this snapshot, needs a write/change, or needs fresh external data, call queue_bean_work. Treat this snapshot as current as of generated_at.
If weather_current.ok is true and the user asks for current weather without naming a location, use weather_current as the default location and answer immediately without tools. Also answer immediately when they name the same place as weather_current.location. If they ask for a different location or a forecast not covered by weather_current, call queue_bean_work.
When the snapshot contains the answer, the turn is complete: answer once from the snapshot and do not queue background work, bridge updates, or a second final answer.
Timed *_at timestamps in this snapshot are formatted in the snapshot timezone and match the user-visible dashboard. Use display_* fields for dates and times you mention to the user; use *_utc only as canonical instants. For all_day events, ignore midnight wall-clock internals and use display_start_date/display_end_date. When multiple workspaces are relevant, mention the workspace names only when needed to avoid ambiguity.
{$json}
TEXT;
    }

    public function defaultWeatherLocation(User $user, Workspace $workspace, ?array $clientContext): ?string
    {
        $clientLocation = $this->firstString([
            data_get($clientContext ?? [], 'weather.location'),
            data_get($clientContext ?? [], 'weather_location'),
            data_get($clientContext ?? [], 'default_weather_location'),
            data_get($clientContext ?? [], 'home_location'),
            data_get($clientContext ?? [], 'location.weather'),
        ]);
        if ($clientLocation !== null) {
            return $clientLocation;
        }

        $profile = $this->agentProfiles->ensureForWorkspace($workspace, $user);
        $settings = $profile->settings ?? [];
        $storedLocation = $this->firstString([
            data_get($settings, 'weather.location'),
            data_get($settings, 'weather_location'),
            data_get($settings, 'default_weather_location'),
            data_get($settings, 'home_location'),
            data_get($settings, 'location'),
            data_get($settings, 'memory.user_preferences.weather_location'),
            data_get($settings, 'memory.user_preferences.home_location'),
            data_get($settings, 'memory.user_preferences.current_location'),
            data_get($settings, 'memory.user_preferences.location'),
            data_get($settings, 'memory.user_preferences.city'),
        ]);
        if ($storedLocation !== null) {
            return $storedLocation;
        }

        return $this->locationFromText($this->firstString([
            data_get($settings, 'onboarding.context'),
            data_get($settings, 'memory.user_preferences.summary'),
        ]) ?? '');
    }

    private function firstString(array $values): ?string
    {
        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim(preg_replace('/\s+/', ' ', $value) ?: '');
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    private function locationFromText(string $text): ?string
    {
        $sentences = preg_split('/[.;\n]+/', $text) ?: [];
        foreach ($sentences as $sentence) {
            if (preg_match('/\b(?:live|lives|living|based|located)\s+(?:in|near|around)\s+([A-Za-z][A-Za-z .\'-]+(?:,\s*[A-Za-z]{2,}| [A-Z]{2})?)/i', $sentence, $matches) === 1) {
                return $this->cleanLocationCandidate((string) ($matches[1] ?? ''));
            }

            if (preg_match('/\b(?:home|weather location|default location)\s+(?:is|in|near|around)\s+([A-Za-z][A-Za-z .\'-]+(?:,\s*[A-Za-z]{2,}| [A-Z]{2})?)/i', $sentence, $matches) === 1) {
                return $this->cleanLocationCandidate((string) ($matches[1] ?? ''));
            }
        }

        return null;
    }

    private function cleanLocationCandidate(string $candidate): ?string
    {
        $candidate = trim(preg_replace('/\b(?:and|but|with|where|because)\b.*$/i', '', $candidate) ?: '');
        $candidate = trim($candidate, " \t\n\r\0\x0B,.?!'\"");

        return $candidate !== '' ? $candidate : null;
    }

    private function taskPayload(Task $task, string $timezone, Collection $workspacesById): array
    {
        return [
            'id' => $task->id,
            'workspace_id' => $task->workspace_id,
            'workspace' => $this->workspaceReference($workspacesById->get((int) $task->workspace_id), (int) $task->workspace_id),
            'title' => $task->title,
            'due_at' => $this->localIso($task->due_at, $timezone),
            'due_at_utc' => $this->utcIso($task->due_at),
            'display_due_date' => $this->localDate($task->due_at, $timezone),
            'display_due_time' => $this->localTime($task->due_at, $timezone),
            'type' => $task->type,
            'category' => $task->category,
            'critical' => (bool) $task->is_critical,
            'recurrence' => data_get($task->metadata, 'recurrence') ?? data_get($task->metadata, 'recurring') ?? data_get($task->metadata, 'rrule'),
        ];
    }

    private function reminderPayload(Reminder $reminder, string $timezone, Collection $workspacesById): array
    {
        return [
            'id' => $reminder->id,
            'workspace_id' => $reminder->workspace_id,
            'workspace' => $this->workspaceReference($workspacesById->get((int) $reminder->workspace_id), (int) $reminder->workspace_id),
            'title' => $reminder->title,
            'remind_at' => $this->localIso($reminder->remind_at, $timezone),
            'remind_at_utc' => $this->utcIso($reminder->remind_at),
            'display_remind_date' => $this->localDate($reminder->remind_at, $timezone),
            'display_remind_time' => $this->localTime($reminder->remind_at, $timezone),
            'category' => $reminder->category,
            'critical' => (bool) $reminder->is_critical,
        ];
    }

    private function calendarEventPayload(CalendarEvent $event, string $timezone, Collection $workspacesById): array
    {
        $allDay = $this->eventAllDay($event);
        $displayStartDate = $allDay ? $this->storedDate($event->starts_at) : $this->localDate($event->starts_at, $timezone);
        $displayEndDate = $this->eventDisplayEndDate($event, $timezone, $allDay);

        return [
            'id' => $event->id,
            'workspace_id' => $event->workspace_id,
            'workspace' => $this->workspaceReference($workspacesById->get((int) $event->workspace_id), (int) $event->workspace_id),
            'title' => $event->title,
            'starts_at' => $allDay ? $displayStartDate : $this->localIso($event->starts_at, $timezone),
            'ends_at' => $allDay ? $displayEndDate : $this->localIso($event->ends_at, $timezone),
            'starts_at_utc' => $this->utcIso($event->starts_at),
            'ends_at_utc' => $this->utcIso($event->ends_at),
            'display_start_date' => $displayStartDate,
            'display_end_date' => $displayEndDate,
            'display_start_time' => $allDay ? null : $this->localTime($event->starts_at, $timezone),
            'display_end_time' => $allDay ? null : $this->localTime($event->ends_at, $timezone),
            'display_date_range' => $this->displayDateRange($displayStartDate, $displayEndDate),
            'all_day' => $allDay,
            'location' => $event->location,
            'category' => $event->category,
            'critical' => (bool) $event->is_critical,
            'source' => data_get($event->metadata, 'source'),
        ];
    }

    private function notePayload(Note $note, Collection $workspacesById): array
    {
        return [
            'id' => $note->id,
            'workspace_id' => $note->workspace_id,
            'workspace' => $this->workspaceReference($workspacesById->get((int) $note->workspace_id), (int) $note->workspace_id),
            'folder_id' => $note->note_folder_id,
            'folder' => $note->folder?->name,
            'title' => $note->title,
            'plain_text' => str((string) $note->plain_text)->squish()->limit(1200, '')->toString(),
            'is_pinned' => (bool) $note->is_pinned,
            'updated_at' => $note->updated_at?->toIso8601String(),
        ];
    }

    private function snapshotWorkspaces(User $user, Workspace $activeWorkspace): Collection
    {
        $workspaces = Workspace::query()
            ->whereHas('memberships', fn (Builder $query) => $query->where('user_id', $user->id)->where('status', 'active'))
            ->orderByRaw('case when id = ? then 0 else 1 end', [$activeWorkspace->id])
            ->orderByRaw("case when type = 'personal' then 0 else 1 end")
            ->orderBy('name')
            ->get();

        if (! $workspaces->contains(fn (Workspace $workspace): bool => (int) $workspace->id === (int) $activeWorkspace->id)) {
            $workspaces->prepend($activeWorkspace);
        }

        return $workspaces->values();
    }

    private function workspacePayload(Workspace $workspace, bool $active): array
    {
        return [
            'id' => $workspace->id,
            'name' => $workspace->name,
            'type' => $workspace->type,
            'active' => $active,
        ];
    }

    private function workspaceReference(?Workspace $workspace, ?int $workspaceId): array
    {
        return [
            'id' => $workspace?->id ?? $workspaceId,
            'name' => $workspace?->name,
            'type' => $workspace?->type,
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

    private function displayTimezone(?array $clientContext): string
    {
        $timezone = data_get($clientContext ?? [], 'timezone');
        if (is_string($timezone) && $this->validTimezone($timezone)) {
            return $timezone;
        }

        $offset = data_get($clientContext ?? [], 'timezone_offset');
        if (is_string($offset) && preg_match('/^[+-]\d{2}:?\d{2}$/', $offset)) {
            return strlen($offset) === 5
                ? substr($offset, 0, 3).':'.substr($offset, 3, 2)
                : $offset;
        }

        $minutes = data_get($clientContext ?? [], 'timezone_offset_minutes');
        if (is_numeric($minutes)) {
            $totalMinutes = (int) $minutes;
            $sign = $totalMinutes < 0 ? '-' : '+';
            $absolute = abs($totalMinutes);

            return sprintf('%s%02d:%02d', $sign, intdiv($absolute, 60), $absolute % 60);
        }

        $fallback = (string) config('app.timezone', 'UTC');

        return $this->validTimezone($fallback) ? $fallback : 'UTC';
    }

    private function validTimezone(string $timezone): bool
    {
        try {
            new \DateTimeZone($timezone);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function localIso(?Carbon $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->toIso8601String();
    }

    private function utcIso(?Carbon $value): ?string
    {
        return $value?->copy()->utc()->toIso8601String();
    }

    private function localDate(?Carbon $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->toDateString();
    }

    private function localTime(?Carbon $value, string $timezone): ?string
    {
        return $value?->copy()->setTimezone($timezone)->format('H:i');
    }

    private function eventAllDay(CalendarEvent $event): bool
    {
        $value = data_get($event->metadata ?? [], 'all_day', data_get($event->metadata ?? [], 'allDay'));

        return $value === true
            || $value === 1
            || in_array(strtolower((string) $value), ['true', '1', 'yes'], true);
    }

    private function eventDisplayEndDate(CalendarEvent $event, string $timezone, bool $allDay): ?string
    {
        if (! $event->ends_at) {
            return $allDay ? $this->storedDate($event->starts_at) : $this->localDate($event->starts_at, $timezone);
        }

        if ($allDay) {
            return $this->allDayDisplayEndDate($event);
        }

        $end = $event->ends_at->copy()->setTimezone($timezone);

        return $end->toDateString();
    }

    private function storedDate(?Carbon $value): ?string
    {
        return $value?->copy()->utc()->toDateString();
    }

    private function allDayDisplayEndDate(CalendarEvent $event): ?string
    {
        if (! $event->ends_at) {
            return $this->storedDate($event->starts_at);
        }

        $end = $event->ends_at->copy()->utc();
        $start = $event->starts_at?->copy()->utc()->startOfDay();

        if ($start && $end->isStartOfDay() && $end->gt($start)) {
            return $end->copy()->subDay()->toDateString();
        }

        return $end->toDateString();
    }

    private function displayDateRange(?string $startDate, ?string $endDate): ?string
    {
        if ($startDate === null) {
            return $endDate;
        }

        if ($endDate === null || $endDate === $startDate) {
            return $startDate;
        }

        return "{$startDate} through {$endDate}";
    }

    private function scopeAccessibleWorkspaceCalendars(Builder $query, User $user, Collection $workspaces): void
    {
        $query->where(function (Builder $workspaceQuery) use ($user, $workspaces): void {
            foreach ($workspaces as $workspace) {
                $workspaceQuery->orWhere(function (Builder $candidateQuery) use ($user, $workspace): void {
                    $candidateQuery->where('workspace_id', $workspace->id);
                    $this->scopeVisibleGoogleCalendars($candidateQuery, $user, $workspace);
                });
            }
        });
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
