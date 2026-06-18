<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
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
    private const INACTIVE_TASK_STATUSES = ['completed', 'complete', 'done'];
    private const INACTIVE_REMINDER_STATUSES = ['completed', 'complete', 'done', 'dismissed', 'canceled', 'cancelled', 'skipped', 'archived'];
    private const INACTIVE_APPROVAL_STATUSES = ['approved', 'denied', 'rejected', 'canceled', 'cancelled', 'expired'];
    private const INACTIVE_BLOCKER_STATUSES = ['resolved', 'closed', 'dismissed', 'canceled', 'cancelled', 'archived'];

    public function __construct(private readonly PlanLimitService $limits) {}

    public function cutoffFor(User $user): ?Carbon
    {
        return $this->limits->historyCutoffFor($user);
    }

    public function filterReminders(Collection|EloquentCollection $reminders, User $user): Collection
    {
        $cutoff = $this->cutoffFor($user);
        if ($cutoff === null) {
            return collect($reminders)->values();
        }

        return collect($reminders)
            ->filter(fn (Reminder $reminder): bool => ! $this->reminderIsPrunable($reminder, $cutoff))
            ->values();
    }

    public function filterCalendarEvents(Collection|EloquentCollection $events, User $user): Collection
    {
        $cutoff = $this->cutoffFor($user);
        if ($cutoff === null) {
            return collect($events)->values();
        }

        return collect($events)
            ->filter(fn (CalendarEvent $event): bool => ! $this->calendarEventIsPrunable($event, $cutoff))
            ->values();
    }

    public function filterApprovals(Collection|EloquentCollection $approvals, User $user): Collection
    {
        $cutoff = $this->cutoffFor($user);
        if ($cutoff === null) {
            return collect($approvals)->values();
        }

        return collect($approvals)
            ->filter(fn (Approval $approval): bool => ! $this->approvalIsPrunable($approval, $cutoff))
            ->values();
    }

    public function filterBlockers(Collection|EloquentCollection $blockers, User $user): Collection
    {
        $cutoff = $this->cutoffFor($user);
        if ($cutoff === null) {
            return collect($blockers)->values();
        }

        return collect($blockers)
            ->filter(fn (Blocker $blocker): bool => ! $this->blockerIsPrunable($blocker, $cutoff))
            ->values();
    }

    public function filterActivityEvents(Collection|EloquentCollection $events, User $user): Collection
    {
        $cutoff = $this->cutoffFor($user);
        if ($cutoff === null) {
            return collect($events)->values();
        }

        return collect($events)
            ->filter(fn (ActivityEvent $event): bool => ! $this->isBeforeCutoff($event->created_at, $cutoff))
            ->values();
    }

    public function sessionWithinHistory(ConversationSession $session, User $user): bool
    {
        $cutoff = $this->cutoffFor($user);

        return $cutoff === null || ! $this->isBeforeCutoff($this->sessionActivityAt($session), $cutoff);
    }

    public function taskIsPrunable(Task $task, Carbon $cutoff): bool
    {
        if (! in_array($this->status($task->status), self::INACTIVE_TASK_STATUSES, true)) {
            return false;
        }

        return $this->isBeforeCutoff($task->completed_at ?? $task->updated_at, $cutoff);
    }

    public function reminderIsPrunable(Reminder $reminder, Carbon $cutoff): bool
    {
        if (! in_array($this->status($reminder->status), self::INACTIVE_REMINDER_STATUSES, true)) {
            return false;
        }

        return $this->isBeforeCutoff($reminder->remind_at, $cutoff);
    }

    public function calendarEventIsPrunable(CalendarEvent $event, Carbon $cutoff): bool
    {
        if ($this->isOngoingRecurringSource($event, $cutoff)) {
            return false;
        }

        return $this->isBeforeCutoff($event->ends_at ?? $event->starts_at, $cutoff);
    }

    public function approvalIsPrunable(Approval $approval, Carbon $cutoff): bool
    {
        if (! in_array($this->status($approval->status), self::INACTIVE_APPROVAL_STATUSES, true)) {
            return false;
        }

        return $this->isBeforeCutoff($approval->updated_at ?? $approval->created_at, $cutoff);
    }

    public function blockerIsPrunable(Blocker $blocker, Carbon $cutoff): bool
    {
        if (! in_array($this->status($blocker->status), self::INACTIVE_BLOCKER_STATUSES, true)) {
            return false;
        }

        return $this->isBeforeCutoff($blocker->updated_at ?? $blocker->created_at, $cutoff);
    }

    public function pruneAllUsers(): array
    {
        $totals = [
            'users_checked' => 0,
            'tasks' => 0,
            'reminders' => 0,
            'calendar_events' => 0,
            'conversation_sessions' => 0,
            'conversation_messages' => 0,
            'activity_events' => 0,
            'approvals' => 0,
            'blockers' => 0,
            'dashboard_changes' => 0,
        ];

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
        $counts = [
            'tasks' => 0,
            'reminders' => 0,
            'calendar_events' => 0,
            'conversation_sessions' => 0,
            'conversation_messages' => 0,
            'activity_events' => 0,
            'approvals' => 0,
            'blockers' => 0,
            'dashboard_changes' => 0,
        ];

        $counts['tasks'] = $this->deleteMatchingTaskIds($user, $cutoff);
        $counts['reminders'] = $this->deleteMatchingReminderIds($user, $cutoff);
        $counts['calendar_events'] = $this->deleteMatchingCalendarEventIds($user, $cutoff);
        $counts['conversation_sessions'] = $this->deleteOldConversationSessions($user, $cutoff);
        $counts['conversation_messages'] = ConversationMessage::query()
            ->where('user_id', $user->id)
            ->where('created_at', '<', $cutoff)
            ->delete();
        $counts['activity_events'] = ActivityEvent::query()
            ->where('user_id', $user->id)
            ->where('created_at', '<', $cutoff)
            ->delete();
        $counts['approvals'] = $this->deleteMatchingApprovalIds($user, $cutoff);
        $counts['blockers'] = $this->deleteMatchingBlockerIds($user, $cutoff);
        $counts['dashboard_changes'] = DashboardChange::query()
            ->where('user_id', $user->id)
            ->where('created_at', '<', $cutoff)
            ->delete();

        return $counts;
    }

    private function deleteMatchingTaskIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            Task::query()->where('user_id', $user->id)->where(function ($query) use ($cutoff): void {
                $query->where('completed_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('completed_at')->where('updated_at', '<', $cutoff);
                    });
            }),
            fn (Task $task): bool => $this->taskIsPrunable($task, $cutoff),
            'tasks'
        );
    }

    private function deleteMatchingReminderIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            Reminder::query()->where('user_id', $user->id)->where('remind_at', '<', $cutoff),
            fn (Reminder $reminder): bool => $this->reminderIsPrunable($reminder, $cutoff),
            'reminders'
        );
    }

    private function deleteMatchingCalendarEventIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            CalendarEvent::query()->where('user_id', $user->id)->where('starts_at', '<', $cutoff),
            fn (CalendarEvent $event): bool => $this->calendarEventIsPrunable($event, $cutoff),
            'calendar_events'
        );
    }

    private function deleteMatchingApprovalIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            Approval::query()->where('user_id', $user->id)->where('updated_at', '<', $cutoff),
            fn (Approval $approval): bool => $this->approvalIsPrunable($approval, $cutoff)
        );
    }

    private function deleteMatchingBlockerIds(User $user, Carbon $cutoff): int
    {
        return $this->deleteFilteredIds(
            Blocker::query()->where('user_id', $user->id)->where('updated_at', '<', $cutoff),
            fn (Blocker $blocker): bool => $this->blockerIsPrunable($blocker, $cutoff)
        );
    }

    private function deleteOldConversationSessions(User $user, Carbon $cutoff): int
    {
        $count = 0;
        ConversationSession::query()
            ->where('user_id', $user->id)
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_activity_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('last_activity_at')->where('updated_at', '<', $cutoff);
                    });
            })
            ->orderBy('id')
            ->chunkById(100, function ($sessions) use (&$count): void {
                $ids = $sessions->pluck('id')->all();
                if ($ids === []) {
                    return;
                }
                $count += ConversationSession::query()->whereIn('id', $ids)->delete();
            });

        return $count;
    }

    private function deleteFilteredIds($query, callable $filter, ?string $workspaceLinkType = null): int
    {
        $count = 0;
        $query->orderBy('id')->chunkById(100, function ($models) use (&$count, $filter, $workspaceLinkType): void {
            $ids = $models->filter($filter)->pluck('id')->values()->all();
            if ($ids === []) {
                return;
            }
            if ($workspaceLinkType !== null) {
                $this->deleteWorkspaceItemLinks($workspaceLinkType, $ids);
            }
            $modelClass = get_class($models->first());
            $count += $modelClass::query()->whereIn('id', $ids)->delete();
        });

        return $count;
    }

    private function deleteWorkspaceItemLinks(string $type, array $ids): void
    {
        WorkspaceItemLink::query()
            ->where(function ($query) use ($type, $ids): void {
                $query->where('source_type', $type)->whereIn('source_id', $ids);
            })
            ->orWhere(function ($query) use ($type, $ids): void {
                $query->where('target_type', $type)->whereIn('target_id', $ids);
            })
            ->delete();
    }

    private function isOngoingRecurringSource(CalendarEvent $event, Carbon $cutoff): bool
    {
        $recurrence = strtolower(trim((string) ($event->recurrence ?? '')));
        if ($recurrence === '' || $recurrence === 'none' || $this->isGeneratedOccurrence($event)) {
            return false;
        }

        $until = $event->metadata['recurrence_until'] ?? null;
        if (is_string($until) && trim($until) !== '') {
            return Carbon::parse($until)->endOfDay()->gte($cutoff);
        }

        return true;
    }

    private function isGeneratedOccurrence(CalendarEvent $event): bool
    {
        $metadata = $event->metadata ?? [];

        return (bool) ($metadata['recurrence_generated'] ?? false)
            || filled($metadata['recurrence_parent_event_id'] ?? null);
    }

    private function sessionActivityAt(ConversationSession $session): mixed
    {
        return $session->last_activity_at ?? $session->updated_at ?? $session->created_at;
    }

    private function isBeforeCutoff(mixed $value, Carbon $cutoff): bool
    {
        return $value !== null && Carbon::parse($value)->lt($cutoff);
    }

    private function status(mixed $value): string
    {
        return strtolower(str_replace(['_', ' '], '-', trim((string) $value)));
    }
}
