<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
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

            if ($this->requiresApproval($session, $action)) {
                $approval = Approval::create([
                    'user_id' => $session->user_id,
                    'conversation_session_id' => $session->id,
                    'title' => $this->approvalTitle($action),
                    'description' => isset($action['description']) && is_string($action['description']) ? $action['description'] : null,
                    'status' => 'pending',
                    'payload' => ['action' => $action],
                ]);

                $events->push($this->recordEvent($session, 'assistant.approval.created', [
                    'approval_id' => $approval->id,
                    'action_type' => $action['type'] ?? null,
                    'risk' => $action['risk'] ?? null,
                ], 'approvals.create', 'succeeded'));

                continue;
            }

            $events = $events->concat($this->executeAction($session, $action));
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
        $category = $this->approvalCategoryFor($type);
        if ($category === null) {
            return false;
        }

        $profile = AgentProfile::where('user_id', $session->user_id)->first();
        $required = $profile?->approval_policy['require_approval_for']
            ?? config('services.hermes_runtime.require_approval_for', []);

        return is_array($required) && in_array($category, $required, true);
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

        return match ($type) {
            'task.create' => collect([$this->createTask($session, $parameters)]),
            'reminder.create' => collect([$this->createReminder($session, $parameters)]),
            'calendar_event.create', 'calendar.create' => collect([$this->createCalendarEvent($session, $parameters)]),
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
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Untitled task'),
            'type' => $this->stringParameter($parameters, 'type', 'todo'),
            'status' => $this->stringParameter($parameters, 'status', 'open'),
            'notes' => $parameters['notes'] ?? null,
            'due_at' => isset($parameters['due_at']) ? Carbon::parse((string) $parameters['due_at']) : null,
            'metadata' => ['created_by' => 'structured_hermes_action'],
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
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Untitled reminder'),
            'notes' => $parameters['notes'] ?? null,
            'remind_at' => Carbon::parse((string) ($parameters['remind_at'] ?? now()->addDay()->toIso8601String())),
            'status' => $this->stringParameter($parameters, 'status', 'scheduled'),
            'metadata' => ['created_by' => 'structured_hermes_action'],
        ]);

        return $this->recordEvent($session, 'assistant.reminder.created', [
            'reminder_id' => $reminder->id,
            'title' => $reminder->title,
            'remind_at' => $reminder->remind_at->toIso8601String(),
        ], 'reminders.create', 'succeeded');
    }

    private function createCalendarEvent(ConversationSession $session, array $parameters): ActivityEvent
    {
        $startsAt = Carbon::parse((string) ($parameters['starts_at'] ?? now()->toIso8601String()));
        $endsAt = isset($parameters['ends_at']) ? Carbon::parse((string) $parameters['ends_at']) : null;

        $calendarEvent = CalendarEvent::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'title' => $this->stringParameter($parameters, 'title', 'Untitled event'),
            'description' => $parameters['description'] ?? null,
            'location' => $parameters['location'] ?? null,
            'starts_at' => $startsAt,
            'ends_at' => $endsAt,
            'status' => $this->stringParameter($parameters, 'status', 'scheduled'),
            'metadata' => ['created_by' => 'structured_hermes_action'],
        ]);

        return $this->recordEvent($session, 'assistant.calendar_event.created', [
            'calendar_event_id' => $calendarEvent->id,
            'title' => $calendarEvent->title,
            'starts_at' => $calendarEvent->starts_at->toIso8601String(),
            'ends_at' => $calendarEvent->ends_at?->toIso8601String(),
        ], 'calendar.create', 'succeeded');
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
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }
}
