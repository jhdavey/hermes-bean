<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\DashboardChange;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\WorkspaceItemLink;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class PlanHistoryService
{
    public function __construct(private readonly PlanLimitService $limits) {}

    public function cutoffFor(User $user): ?Carbon
    {
        return $this->limits->historyCutoffFor($user);
    }

    public function filterReminders(Collection|EloquentCollection $reminders, User $user): Collection
    {
        $cutoff = $this->cutoffFor($user);

        return $cutoff === null
            ? collect($reminders)->values()
            : collect($reminders)->filter(
                fn (Reminder $reminder): bool => ! $this->reminderIsPrunable($reminder, $cutoff),
            )->values();
    }

    public function filterCalendarEvents(Collection|EloquentCollection $events, User $user): Collection
    {
        $cutoff = $this->cutoffFor($user);

        return $cutoff === null
            ? collect($events)->values()
            : collect($events)->filter(
                fn (CalendarEvent $event): bool => ! $this->calendarEventIsPrunable($event, $cutoff),
            )->values();
    }

    public function taskIsPrunable(Task $task, Carbon $cutoff): bool
    {
        return $task->status === 'completed'
            && $this->isBeforeCutoff($task->completed_at ?? $task->updated_at, $cutoff);
    }

    public function reminderIsPrunable(Reminder $reminder, Carbon $cutoff): bool
    {
        return $reminder->status === 'completed'
            && $this->isBeforeCutoff($reminder->remind_at, $cutoff);
    }

    public function calendarEventIsPrunable(CalendarEvent $event, Carbon $cutoff): bool
    {
        return ! $this->isOngoingRecurringSource($event, $cutoff)
            && $this->isBeforeCutoff($event->ends_at ?? $event->starts_at, $cutoff);
    }

    public function pruneAllUsers(): array
    {
        $totals = $this->emptyTotals();

        User::query()->orderBy('id')->chunkById(100, function ($users) use (&$totals): void {
            foreach ($users as $user) {
                $totals['users_checked']++;
                $cutoff = $this->cutoffFor($user);
                if ($cutoff === null) {
                    continue;
                }

                foreach ($this->pruneUser($user, $cutoff) as $key => $count) {
                    $totals[$key] += $count;
                }
            }
        });

        return $totals;
    }

    public function pruneUser(User $user, Carbon $cutoff): array
    {
        return [
            'tasks' => $this->deleteMatchingTaskIds($user, $cutoff),
            'reminders' => $this->deleteMatchingReminderIds($user, $cutoff),
            'calendar_events' => $this->deleteMatchingCalendarEventIds($user, $cutoff),
            'dashboard_changes' => DashboardChange::query()
                ->where('user_id', $user->id)
                ->where('created_at', '<', $cutoff)
                ->delete(),
        ];
    }

    private function emptyTotals(): array
    {
        return [
            'users_checked' => 0,
            'tasks' => 0,
            'reminders' => 0,
            'calendar_events' => 0,
            'dashboard_changes' => 0,
        ];
    }

    private function deleteMatchingTaskIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            Task::query()->where('user_id', $user->id)->where(function ($query) use ($cutoff): void {
                $query->where('completed_at', '<', $cutoff)
                    ->orWhere(fn ($query) => $query->whereNull('completed_at')->where('updated_at', '<', $cutoff));
            }),
            fn (Task $task): bool => $this->taskIsPrunable($task, $cutoff),
            'tasks',
        );
    }

    private function deleteMatchingReminderIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            Reminder::query()->where('user_id', $user->id)->where('remind_at', '<', $cutoff),
            fn (Reminder $reminder): bool => $this->reminderIsPrunable($reminder, $cutoff),
            'reminders',
        );
    }

    private function deleteMatchingCalendarEventIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            CalendarEvent::query()->where('user_id', $user->id)->where('starts_at', '<', $cutoff),
            fn (CalendarEvent $event): bool => $this->calendarEventIsPrunable($event, $cutoff),
            'calendar_events',
        );
    }

    private function deleteFilteredIds($query, callable $filter, string $workspaceLinkType): int
    {
        $count = 0;
        $query->orderBy('id')->chunkById(100, function ($models) use (&$count, $filter, $workspaceLinkType): void {
            $ids = $models->filter($filter)->pluck('id')->values()->all();
            if ($ids === []) {
                return;
            }

            WorkspaceItemLink::query()
                ->where(fn ($query) => $query->where('source_type', $workspaceLinkType)->whereIn('source_id', $ids))
                ->orWhere(fn ($query) => $query->where('target_type', $workspaceLinkType)->whereIn('target_id', $ids))
                ->delete();

            $modelClass = get_class($models->first());
            $count += $modelClass::query()->whereIn('id', $ids)->delete();
        });

        return $count;
    }

    private function isOngoingRecurringSource(CalendarEvent $event, Carbon $cutoff): bool
    {
        $recurrence = strtolower(trim((string) ($event->recurrence ?? '')));
        $metadata = $event->metadata ?? [];
        $generated = (bool) ($metadata['recurrence_generated'] ?? false)
            || filled($metadata['recurrence_parent_event_id'] ?? null);
        if ($recurrence === '' || $recurrence === 'none' || $generated) {
            return false;
        }

        $until = $metadata['recurrence_until'] ?? null;

        return ! is_string($until) || trim($until) === ''
            || Carbon::parse($until)->endOfDay()->gte($cutoff);
    }

    private function isBeforeCutoff(mixed $value, Carbon $cutoff): bool
    {
        return $value !== null && Carbon::parse($value)->lt($cutoff);
    }
}
