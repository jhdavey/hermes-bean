<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\Reminder;
use App\Models\SchedulerJobRecord;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StructuredHermesActionService
{
    /**
     * @param  array<string, mixed>  $envelope
     * @return Collection<int, ActivityEvent>
     */
    public function applyEnvelope(ConversationSession $session, array $envelope): Collection
    {
        $events = collect();
        $actions = $envelope['actions'] ?? [];

        if (! is_array($actions)) {
            return $events;
        }

        foreach ($actions as $action) {
            if (! is_array($action)) {
                continue;
            }

            try {
                $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
                $actionSession = $this->sessionForAction($session, $parameters);
            } catch (\Throwable $exception) {
                $events->push($this->recordEvent($session, 'assistant.action.failed', [
                    'action_type' => $action['type'] ?? null,
                    'reason' => $exception->getMessage(),
                ], 'structured_action', 'failed'));

                continue;
            }

            if ($this->requiresApproval($actionSession, $action)) {
                $approval = Approval::create([
                    'user_id' => $actionSession->user_id,
                    'workspace_id' => $actionSession->workspace_id,
                    'conversation_session_id' => $actionSession->id,
                    'title' => $this->approvalTitle($action),
                    'description' => isset($action['description']) && is_string($action['description']) ? $action['description'] : null,
                    'status' => 'pending',
                    'payload' => ['action' => $action],
                ]);

                $events->push($this->recordEvent($actionSession, 'assistant.approval.created', [
                    'approval_id' => $approval->id,
                    'action_type' => $action['type'] ?? null,
                    'risk' => $action['risk'] ?? null,
                    'source_workspace_id' => $session->workspace_id,
                    'target_workspace_id' => $actionSession->workspace_id,
                ], 'approvals.create', 'succeeded'));

                continue;
            }

            try {
                $events = $events->concat($this->executeAction($actionSession, $action));
            } catch (\Throwable $exception) {
                $events->push($this->recordEvent($session, 'assistant.action.failed', [
                    'action_type' => $action['type'] ?? null,
                    'reason' => $exception->getMessage(),
                ], 'structured_action', 'failed'));
            }
        }

        return $events->values();
    }

    /**
     * @return array{approval: Approval, events: Collection<int, ActivityEvent>}
     */
    public function approve(Approval $approval): array
    {
        if ($approval->status !== 'pending') {
            throw new InvalidArgumentException('Only pending approvals can be approved.');
        }

        return DB::transaction(function () use ($approval): array {
            $session = ConversationSession::find($approval->conversation_session_id);
            if (! $session) {
                throw new InvalidArgumentException('Approval is not attached to an active conversation session.');
            }

            $action = $approval->payload['action'] ?? null;
            if (! is_array($action)) {
                throw new InvalidArgumentException('Approval payload is missing a structured action.');
            }

            $events = $this->executeAction($session, $action)->values();

            $approval->update([
                'status' => 'approved',
                'payload' => array_merge($approval->payload ?? [], [
                    'approved_at' => now()->toIso8601String(),
                    'executed_event_ids' => $events->pluck('id')->all(),
                ]),
            ]);

            $events->push($this->recordEvent($session, 'assistant.approval.approved', [
                'approval_id' => $approval->id,
                'action_type' => $action['type'] ?? null,
            ], 'approvals.approve', 'succeeded'));

            return ['approval' => $approval->refresh(), 'events' => $events];
        });
    }

    public function deny(Approval $approval): Approval
    {
        if ($approval->status !== 'pending') {
            throw new InvalidArgumentException('Only pending approvals can be denied.');
        }

        $approval->update([
            'status' => 'denied',
            'payload' => array_merge($approval->payload ?? [], [
                'denied_at' => now()->toIso8601String(),
            ]),
        ]);

        if ($approval->conversation_session_id) {
            $session = ConversationSession::find($approval->conversation_session_id);
            if ($session) {
                $this->recordEvent($session, 'assistant.approval.denied', [
                    'approval_id' => $approval->id,
                ], 'approvals.deny', 'succeeded');
            }
        }

        return $approval->refresh();
    }

    private function requiresApproval(ConversationSession $session, array $action): bool
    {
        $risk = strtolower((string) ($action['risk'] ?? 'medium'));
        if (! in_array($risk, ['low', 'safe'], true)) {
            return true;
        }

        $type = (string) ($action['type'] ?? '');
        if ($this->isLowRiskDashboardAction($type)) {
            return false;
        }

        $category = $this->approvalCategoryFor($type);
        if ($category === null) {
            return false;
        }

        $profile = $this->profileForSession($session);
        $required = $profile?->approval_policy['require_approval_for']
            ?? config('services.hermes_runtime.require_approval_for', []);

        return is_array($required) && in_array($category, $required, true);
    }

    private function isLowRiskDashboardAction(string $type): bool
    {
        return in_array($type, [
            'task.create', 'task.update', 'task.delete',
            'reminder.create', 'reminder.update', 'reminder.delete',
            'calendar_event.create', 'calendar_event.update', 'calendar_event.delete', 'calendar.create', 'calendar.update', 'calendar.delete',
            'event_category.create', 'event_category.update', 'event_category.delete',
            'approval.create', 'approval.update', 'approval.approve', 'approval.deny', 'approval.delete',
            'blocker.create', 'blocker.update', 'blocker.resolve', 'blocker.delete',
            'scheduler_job.create', 'scheduler_job.update', 'scheduler_job.delete',
            'agent_profile.update',
            'workspace_memory.note',
            'conversation_session.update',
            'activity_event.create',
        ], true);
    }

    private function approvalCategoryFor(string $type): ?string
    {
        return match (true) {
            str_starts_with($type, 'email.'), str_starts_with($type, 'mail.') => 'outgoing_mail',
            str_starts_with($type, 'payment.'), str_starts_with($type, 'payments.') => 'payments',
            str_starts_with($type, 'deploy.'), str_starts_with($type, 'deployment.') => 'deployments',
            str_contains($type, 'delete'), str_contains($type, 'destroy') => 'destructive_actions',
            default => null,
        };
    }

    private function approvalTitle(array $action): string
    {
        if (isset($action['title']) && is_string($action['title']) && $action['title'] !== '') {
            return $action['title'];
        }

        return 'Approve '.str_replace(['.', '_'], ' ', (string) ($action['type'] ?? 'Hermes action'));
    }

    /**
     * @return Collection<int, ActivityEvent>
     */
    private function executeAction(ConversationSession $session, array $action): Collection
    {
        $type = (string) ($action['type'] ?? '');
        $parameters = $action['parameters'] ?? [];
        if (! is_array($parameters)) {
            $parameters = [];
        }
        $session = $this->sessionForAction($session, $parameters);

        return match ($type) {
            'task.create' => collect([$this->createTask($session, $parameters)]),
            'task.update' => collect([$this->updateTask($session, $parameters)]),
            'task.delete' => collect([$this->deleteOwned(Task::class, $session, $parameters, 'assistant.task.deleted', 'tasks.delete', 'task_id')]),
            'reminder.create' => collect([$this->createReminder($session, $parameters)]),
            'reminder.update' => collect([$this->updateReminder($session, $parameters)]),
            'reminder.delete' => collect([$this->deleteOwned(Reminder::class, $session, $parameters, 'assistant.reminder.deleted', 'reminders.delete', 'reminder_id')]),
            'calendar_event.create', 'calendar.create' => collect([$this->createCalendarEvent($session, $parameters)]),
            'calendar_event.update', 'calendar.update' => collect([$this->updateCalendarEvent($session, $parameters)]),
            'calendar_event.delete', 'calendar.delete' => collect([$this->deleteOwned(CalendarEvent::class, $session, $parameters, 'assistant.calendar_event.deleted', 'calendar.delete', 'calendar_event_id')]),
            'event_category.create' => collect([$this->createEventCategory($session, $parameters)]),
            'event_category.update' => collect([$this->updateEventCategory($session, $parameters)]),
            'event_category.delete' => collect([$this->deleteEventCategory($session, $parameters)]),
            'approval.create' => collect([$this->createApproval($session, $parameters)]),
            'approval.update' => collect([$this->updateApproval($session, $parameters)]),
            'approval.approve' => $this->approveOwned($session, $parameters),
            'approval.deny' => collect([$this->denyOwned($session, $parameters)]),
            'approval.delete' => collect([$this->deleteOwned(Approval::class, $session, $parameters, 'assistant.approval.deleted', 'approvals.delete', 'approval_id')]),
            'blocker.create' => collect([$this->createBlocker($session, $parameters)]),
            'blocker.update' => collect([$this->updateBlocker($session, $parameters)]),
            'blocker.resolve' => collect([$this->resolveBlocker($session, $parameters)]),
            'blocker.delete' => collect([$this->deleteOwned(Blocker::class, $session, $parameters, 'assistant.blocker.deleted', 'blockers.delete', 'blocker_id')]),
            'scheduler_job.create' => $this->createSchedulerJob($session, $parameters),
            'scheduler_job.update' => collect([$this->updateSchedulerJob($session, $parameters)]),
            'scheduler_job.delete' => collect([$this->deleteOwned(SchedulerJobRecord::class, $session, $parameters, 'assistant.scheduler_job.deleted', 'scheduler_jobs.delete', 'scheduler_job_id')]),
            'agent_profile.update' => collect([$this->updateAgentProfile($session, $parameters)]),
            'workspace_memory.note' => collect([$this->createWorkspaceMemoryNote($session, $parameters)]),
            'conversation_session.update' => collect([$this->updateConversationSession($session, $parameters)]),
            'activity_event.create' => collect([$this->createActivityEvent($session, $parameters)]),
            default => collect([$this->recordEvent($session, 'assistant.action.skipped', [
                'action_type' => $type,
                'reason' => 'Unsupported structured action type.',
            ], 'structured_action', 'skipped')]),
        };
    }

    private function createTask(ConversationSession $session, array $parameters): ActivityEvent
    {
        $task = Task::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Untitled task'),
            'type' => $this->stringParameter($parameters, 'type', 'todo'),
            'status' => $this->stringParameter($parameters, 'status', 'open'),
            'notes' => $parameters['notes'] ?? null,
            'category' => $parameters['category'] ?? null,
            'color' => $parameters['color'] ?? null,
            'is_critical' => (bool) ($parameters['is_critical'] ?? $parameters['isCritical'] ?? false),
            'due_at' => isset($parameters['due_at']) ? Carbon::parse((string) $parameters['due_at']) : null,
            'metadata' => array_merge(
                ['created_by' => 'structured_hermes_action'],
                is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : []
            ),
        ]);

        return $this->recordEvent($session, 'assistant.task.created', [
            'task_id' => $task->id,
            'title' => $task->title,
        ], 'tasks.create', 'succeeded');
    }

    private function createReminder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $reminder = Reminder::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'calendar_event_id' => $parameters['calendar_event_id'] ?? null,
            'title' => $this->stringParameter($parameters, 'title', 'Untitled reminder'),
            'notes' => $parameters['notes'] ?? null,
            'category' => $parameters['category'] ?? null,
            'color' => $parameters['color'] ?? null,
            'is_critical' => (bool) ($parameters['is_critical'] ?? $parameters['isCritical'] ?? false),
            'remind_at' => Carbon::parse((string) ($parameters['remind_at'] ?? now()->addDay()->toIso8601String())),
            'status' => $this->stringParameter($parameters, 'status', 'scheduled'),
            'metadata' => array_merge(
                ['created_by' => 'structured_hermes_action'],
                is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : []
            ),
        ]);

        return $this->recordEvent($session, 'assistant.reminder.created', [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'remind_at' => $reminder->remind_at->toIso8601String(),
        ], 'reminders.create', 'succeeded');
    }

    private function createCalendarEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        $startsAtValue = $parameters['starts_at'] ?? $parameters['start_at'] ?? now()->toIso8601String();
        $endsAtValue = $parameters['ends_at'] ?? $parameters['end_at'] ?? null;
        $startsAt = Carbon::parse((string) $startsAtValue);
        $endsAt = $endsAtValue !== null ? Carbon::parse((string) $endsAtValue) : null;

        $calendarEvent = CalendarEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Untitled event'),
            'description' => $parameters['description'] ?? null,
            'location' => $parameters['location'] ?? null,
            'category' => $parameters['category'] ?? null,
            'color' => $parameters['color'] ?? null,
            'is_critical' => (bool) ($parameters['is_critical'] ?? $parameters['isCritical'] ?? false),
            'recurrence' => $parameters['recurrence'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $this->stringParameter($parameters, 'status', 'scheduled'),
            'metadata' => array_filter([
                'created_by' => 'structured_hermes_action',
                'recurrence' => $parameters['recurrence'] ?? null,
            ], static fn ($value) => $value !== null),
        ]);

        return $this->recordEvent($session, 'assistant.calendar_event.created', [
            'calendar_event_id' => $calendarEvent->id,
            'title' => $calendarEvent->title,
            'starts_at' => $calendarEvent->starts_at->toIso8601String(),
            'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
        ], 'calendar.create', 'succeeded');
    }

    private function updateTask(ConversationSession $session, array $parameters): ActivityEvent
    {
        $task = $this->ownedModel(Task::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'metadata']);
        if (array_key_exists('due_at', $parameters)) {
            $updates['due_at'] = $parameters['due_at'] ? Carbon::parse((string) $parameters['due_at']) : null;
        }
        $task->update($updates);

        return $this->recordEvent($session, 'assistant.task.updated', ['task_id' => $task->id, 'title' => $task->title], 'tasks.update', 'succeeded');
    }

    private function updateReminder(ConversationSession $session, array $parameters): ActivityEvent
    {
        $reminder = $this->ownedModel(Reminder::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'notes', 'status', 'category', 'color', 'is_critical', 'metadata']);
        if (array_key_exists('remind_at', $parameters)) {
            $updates['remind_at'] = Carbon::parse((string) $parameters['remind_at']);
        }
        $reminder->update($updates);

        return $this->recordEvent($session, 'assistant.reminder.updated', ['reminder_id' => $reminder->id, 'title' => $reminder->title], 'reminders.update', 'succeeded');
    }

    private function updateCalendarEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        $calendarEvent = $this->ownedModel(CalendarEvent::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['title', 'description', 'location', 'category', 'color', 'is_critical', 'recurrence', 'status', 'metadata']);
        if (array_key_exists('starts_at', $parameters)) {
            $updates['starts_at'] = Carbon::parse((string) $parameters['starts_at']);
        }
        if (array_key_exists('ends_at', $parameters)) {
            $updates['ends_at'] = $parameters['ends_at'] ? Carbon::parse((string) $parameters['ends_at']) : null;
        }
        $calendarEvent->update($updates);

        return $this->recordEvent($session, 'assistant.calendar_event.updated', ['calendar_event_id' => $calendarEvent->id, 'title' => $calendarEvent->title], 'calendar.update', 'succeeded');
    }

    private function createEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = EventCategory::updateOrCreate(
            [
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'name' => $this->stringParameter($parameters, 'name', 'Personal'),
            ],
            [
                'color' => $this->stringParameter($parameters, 'color', '#34C759'),
                'metadata' => array_merge(
                    ['created_by' => 'structured_hermes_action'],
                    is_array($parameters['metadata'] ?? null) ? $parameters['metadata'] : []
                ),
            ]
        );

        return $this->recordEvent($session, 'assistant.event_category.saved', ['event_category_id' => $category->id, 'name' => $category->name], 'event_categories.create', 'succeeded');
    }

    private function updateEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = $this->ownedModel(EventCategory::class, $session, $parameters);
        $oldName = $category->name;
        $category->update($this->onlyPresent($parameters, ['name', 'color', 'metadata']));
        if ($oldName !== $category->name) {
            CalendarEvent::where('user_id', $session->user_id)
                ->where('workspace_id', $session->workspace_id)
                ->where('category', $oldName)
                ->update(['category' => $category->name]);
        }

        return $this->recordEvent($session, 'assistant.event_category.updated', ['event_category_id' => $category->id, 'name' => $category->name], 'event_categories.update', 'succeeded');
    }

    private function deleteEventCategory(ConversationSession $session, array $parameters): ActivityEvent
    {
        $category = $this->ownedModel(EventCategory::class, $session, $parameters);
        CalendarEvent::where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where('category', $category->name)
            ->update(['category' => null]);
        $id = $category->id;
        $name = $category->name;
        $category->delete();

        return $this->recordEvent($session, 'assistant.event_category.deleted', ['event_category_id' => $id, 'name' => $name], 'event_categories.delete', 'succeeded');
    }

    private function createApproval(ConversationSession $session, array $parameters): ActivityEvent
    {
        $approval = Approval::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Approval requested'),
            'description' => $parameters['description'] ?? null,
            'status' => $this->stringParameter($parameters, 'status', 'pending'),
            'payload' => $parameters['payload'] ?? null,
        ]);

        return $this->recordEvent($session, 'assistant.approval.created_directly', ['approval_id' => $approval->id, 'title' => $approval->title], 'approvals.create', 'succeeded');
    }

    private function updateApproval(ConversationSession $session, array $parameters): ActivityEvent
    {
        $approval = $this->ownedModel(Approval::class, $session, $parameters);
        $approval->update($this->onlyPresent($parameters, ['title', 'description', 'status', 'payload']));

        return $this->recordEvent($session, 'assistant.approval.updated', ['approval_id' => $approval->id, 'title' => $approval->title], 'approvals.update', 'succeeded');
    }

    private function approveOwned(ConversationSession $session, array $parameters): Collection
    {
        $approval = $this->ownedModel(Approval::class, $session, $parameters);

        return $this->approve($approval)['events'];
    }

    private function denyOwned(ConversationSession $session, array $parameters): ActivityEvent
    {
        $approval = $this->ownedModel(Approval::class, $session, $parameters);
        $this->deny($approval);

        return $this->recordEvent($session, 'assistant.approval.denied_by_action', ['approval_id' => $approval->id], 'approvals.deny', 'succeeded');
    }

    private function createBlocker(ConversationSession $session, array $parameters): ActivityEvent
    {
        $blocker = Blocker::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'reason' => $this->stringParameter($parameters, 'reason', $this->stringParameter($parameters, 'title', 'Needs attention')),
            'status' => $this->stringParameter($parameters, 'status', 'open'),
            'context' => $parameters['context'] ?? null,
        ]);

        return $this->recordEvent($session, 'assistant.blocker.created', ['blocker_id' => $blocker->id, 'reason' => $blocker->reason], 'blockers.create', 'succeeded');
    }

    private function updateBlocker(ConversationSession $session, array $parameters): ActivityEvent
    {
        $blocker = $this->ownedModel(Blocker::class, $session, $parameters);
        $blocker->update($this->onlyPresent($parameters, ['reason', 'status', 'context']));

        return $this->recordEvent($session, 'assistant.blocker.updated', ['blocker_id' => $blocker->id, 'status' => $blocker->status], 'blockers.update', 'succeeded');
    }

    private function resolveBlocker(ConversationSession $session, array $parameters): ActivityEvent
    {
        $blocker = $this->ownedModel(Blocker::class, $session, $parameters);
        $context = is_array($blocker->context) ? $blocker->context : [];
        $blocker->update([
            'status' => 'resolved',
            'context' => array_merge($context, ['resolved_at' => now()->toIso8601String()]),
        ]);

        return $this->recordEvent($session, 'assistant.blocker.resolved', ['blocker_id' => $blocker->id], 'blockers.resolve', 'succeeded');
    }

    /**
     * @return Collection<int, ActivityEvent>
     */
    private function createSchedulerJob(ConversationSession $session, array $parameters): Collection
    {
        $scheduledFor = isset($parameters['scheduled_for']) ? Carbon::parse((string) $parameters['scheduled_for']) : null;
        $name = $this->stringParameter($parameters, 'name', $this->stringParameter($parameters, 'title', 'Scheduled job'));
        $job = SchedulerJobRecord::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'name' => $name,
            'status' => $this->stringParameter($parameters, 'status', 'queued'),
            'scheduled_for' => $scheduledFor,
            'started_at' => isset($parameters['started_at']) ? Carbon::parse((string) $parameters['started_at']) : null,
            'finished_at' => isset($parameters['finished_at']) ? Carbon::parse((string) $parameters['finished_at']) : null,
            'payload' => $parameters['payload'] ?? null,
            'last_error' => $parameters['last_error'] ?? null,
        ]);

        $events = collect([
            $this->recordEvent($session, 'assistant.scheduler_job.created', ['scheduler_job_id' => $job->id, 'name' => $job->name], 'scheduler_jobs.create', 'succeeded'),
        ]);

        if ($scheduledFor !== null && $this->shouldMirrorSchedulerJobToCalendar($parameters)) {
            $calendarEvent = CalendarEvent::create([
                'user_id' => $session->user_id,
                'workspace_id' => $session->workspace_id,
                'created_by_user_id' => $session->created_by_user_id ?: $session->user_id,
                'conversation_session_id' => $session->id,
                'title' => $name,
                'description' => isset($parameters['description']) ? (string) $parameters['description'] : null,
                'location' => $parameters['location'] ?? null,
                'category' => $parameters['category'] ?? 'Scheduled',
                'color' => $parameters['color'] ?? '#FF9500',
                'recurrence' => $parameters['recurrence'] ?? null,
                'starts_at' => $scheduledFor,
                'ends_at' => isset($parameters['ends_at'])
                    ? Carbon::parse((string) $parameters['ends_at'])
                    : $scheduledFor->copy()->addMinutes((int) ($parameters['duration_minutes'] ?? 30)),
                'status' => 'scheduled',
                'metadata' => array_filter([
                    'created_by' => 'structured_hermes_action',
                    'mirrored_from' => 'scheduler_job',
                    'scheduler_job_id' => $job->id,
                    'recurrence' => $parameters['recurrence'] ?? null,
                ], static fn ($value) => $value !== null),
            ]);

            $events->push($this->recordEvent($session, 'assistant.calendar_event.created', [
                'calendar_event_id' => $calendarEvent->id,
                'scheduler_job_id' => $job->id,
                'title' => $calendarEvent->title,
                'starts_at' => $calendarEvent->starts_at->toIso8601String(),
                'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
            ], 'calendar.create', 'succeeded'));
        }

        return $events;
    }

    private function shouldMirrorSchedulerJobToCalendar(array $parameters): bool
    {
        $payload = is_array($parameters['payload'] ?? null) ? $parameters['payload'] : [];
        $kind = strtolower((string) ($payload['kind'] ?? $parameters['kind'] ?? ''));

        if (in_array($kind, ['internal_automation', 'background_job', 'system_job'], true)) {
            return false;
        }

        return true;
    }

    private function updateSchedulerJob(ConversationSession $session, array $parameters): ActivityEvent
    {
        $job = $this->ownedModel(SchedulerJobRecord::class, $session, $parameters);
        $updates = $this->onlyPresent($parameters, ['name', 'status', 'payload', 'last_error']);
        foreach (['scheduled_for', 'started_at', 'finished_at'] as $dateKey) {
            if (array_key_exists($dateKey, $parameters)) {
                $updates[$dateKey] = $parameters[$dateKey] ? Carbon::parse((string) $parameters[$dateKey]) : null;
            }
        }
        $job->update($updates);

        return $this->recordEvent($session, 'assistant.scheduler_job.updated', ['scheduler_job_id' => $job->id, 'name' => $job->name], 'scheduler_jobs.update', 'succeeded');
    }

    private function updateAgentProfile(ConversationSession $session, array $parameters): ActivityEvent
    {
        $profile = $this->profileForSession($session);
        $updates = $this->onlyPresent($parameters, ['slug', 'display_name', 'status', 'provider', 'model', 'router_mode', 'runtime_home', 'tool_policy', 'approval_policy', 'metadata']);
        if (isset($parameters['settings']) && is_array($parameters['settings'])) {
            app(AgentProfileService::class)->mergeSettings($profile, $parameters['settings'], 'agent');
            if (data_get($parameters['settings'], 'onboarding.completed') === true) {
                User::where('id', $session->user_id)->update(['onboard_complete' => true]);
            }
        }
        if ($updates !== []) {
            $profile->update($updates);
        }

        return $this->recordEvent($session, 'assistant.agent_profile.updated', ['agent_profile_id' => $profile->id], 'agent_profile.update', 'succeeded');
    }

    private function updateConversationSession(ConversationSession $session, array $parameters): ActivityEvent
    {
        $session->update($this->onlyPresent($parameters, ['title', 'status', 'runtime_mode', 'metadata']));

        return $this->recordEvent($session, 'assistant.conversation_session.updated', ['session_id' => $session->id, 'title' => $session->title], 'conversation_sessions.update', 'succeeded');
    }

    private function createWorkspaceMemoryNote(ConversationSession $session, array $parameters): ActivityEvent
    {
        $workspace = Workspace::findOrFail($session->workspace_id);
        $actor = User::findOrFail($session->user_id);
        app(WorkspaceService::class)->authorizeMember($actor, $workspace);
        $profile = app(AgentProfileService::class)->appendWorkspaceMemoryNote(
            $workspace,
            $actor,
            $this->stringParameter($parameters, 'note', $this->stringParameter($parameters, 'content', ''))
        );

        return $this->recordEvent($session, 'assistant.workspace_memory.noted', [
            'workspace_id' => $workspace->id,
            'agent_profile_id' => $profile->id,
            'note' => $this->stringParameter($parameters, 'note', $this->stringParameter($parameters, 'content', '')),
        ], 'workspace_memory.note', 'succeeded');
    }

    private function createActivityEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        return $this->recordEvent(
            $session,
            $this->stringParameter($parameters, 'event_type', 'assistant.note'),
            is_array($parameters['payload'] ?? null) ? $parameters['payload'] : [],
            isset($parameters['tool_name']) ? (string) $parameters['tool_name'] : 'assistant',
            $this->stringParameter($parameters, 'status', 'recorded')
        );
    }

    private function profileForSession(ConversationSession $session): AgentProfile
    {
        if ($session->workspace_id) {
            $workspace = $session->workspace ?: Workspace::find($session->workspace_id);
            $user = User::find($session->user_id);
            if ($workspace && $user) {
                return app(AgentProfileService::class)->ensureForWorkspace($workspace, $user);
            }
        }

        return app(AgentProfileService::class)->ensureForUser(User::findOrFail($session->user_id));
    }

    private function sessionForAction(ConversationSession $session, array $parameters): ConversationSession
    {
        $targetWorkspaceId = $parameters['target_workspace_id'] ?? $parameters['workspace_id'] ?? null;
        if (! $targetWorkspaceId || (int) $targetWorkspaceId === (int) $session->workspace_id) {
            return $session;
        }

        $workspace = Workspace::findOrFail((int) $targetWorkspaceId);
        $actor = User::findOrFail($session->user_id);
        app(WorkspaceService::class)->authorizeMember($actor, $workspace);

        $targetSession = clone $session;
        $targetSession->workspace_id = $workspace->id;
        $targetSession->setRelation('workspace', $workspace);

        return $targetSession;
    }

    private function deleteOwned(string $modelClass, ConversationSession $session, array $parameters, string $eventType, string $toolName, string $payloadKey): ActivityEvent
    {
        $model = $this->ownedModel($modelClass, $session, $parameters);
        $id = $model->id;
        $model->delete();

        return $this->recordEvent($session, $eventType, [$payloadKey => $id], $toolName, 'succeeded');
    }

    private function ownedModel(string $modelClass, ConversationSession $session, array $parameters): mixed
    {
        $id = $parameters['id'] ?? null;
        if (! $id) {
            throw new InvalidArgumentException('Structured action is missing required resource id.');
        }

        $query = $modelClass::where('user_id', $session->user_id);
        if ($session->workspace_id) {
            $query->where('workspace_id', $session->workspace_id);
        }

        return $query->findOrFail($id);
    }

    private function onlyPresent(array $parameters, array $keys): array
    {
        $updates = [];
        foreach ($keys as $key) {
            if (array_key_exists($key, $parameters)) {
                $updates[$key] = $parameters[$key];
            }
        }

        return $updates;
    }

    private function stringParameter(array $parameters, string $key, string $default): string
    {
        return isset($parameters[$key]) && is_scalar($parameters[$key]) && (string) $parameters[$key] !== ''
            ? (string) $parameters[$key]
            : $default;
    }

    private function recordEvent(ConversationSession $session, string $type, array $payload = [], ?string $toolName = null, string $status = 'recorded'): ActivityEvent
    {
        return ActivityEvent::create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }
}
