<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StubHermesRuntimeService implements HermesRuntimeService
{
    public function startSession(array $attributes = []): ConversationSession
    {
        return DB::transaction(function () use ($attributes): ConversationSession {
            $session = ConversationSession::create([
                'title' => $attributes['title'] ?? null,
                'status' => 'active',
                'runtime_mode' => $attributes['runtime_mode'] ?? 'stub',
                'metadata' => $attributes['metadata'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($session, 'runtime.session_started', [
                'runtime_mode' => $session->runtime_mode,
            ]);

            return $session->refresh();
        });
    }

    public function resumeSession(ConversationSession $session): ConversationSession
    {
        $session->update(['last_activity_at' => now()]);

        $this->recordEvent($session, 'runtime.session_resumed');

        return $session->refresh();
    }

    public function progressEvents(ConversationSession $session): Collection
    {
        return $session->activityEvents()->orderBy('id')->get();
    }

    public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array
    {
        return DB::transaction(function () use ($session, $content, $metadata): array {
            $userMessage = ConversationMessage::create([
                'conversation_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);

            $received = $this->recordEvent($session, 'runtime.message_received', [
                'message_id' => $userMessage->id,
            ]);

            if ($session->runtime_mode !== 'stub') {
                $blocker = Blocker::create([
                    'conversation_session_id' => $session->id,
                    'reason' => 'External Hermes invocation is not configured. User approval or operator setup is required before continuing.',
                    'status' => 'open',
                    'context' => [
                        'runtime_mode' => $session->runtime_mode,
                        'message_id' => $userMessage->id,
                    ],
                ]);

                $session->update(['status' => 'blocked', 'last_activity_at' => now()]);
                $blocked = $this->recordEvent($session, 'runtime.blocked', [
                    'blocker_id' => $blocker->id,
                    'reason' => $blocker->reason,
                ]);

                return [
                    'status' => 'blocked',
                    'session' => $session->refresh(),
                    'user_message' => $userMessage,
                    'assistant_message' => null,
                    'events' => collect([$received, $blocked]),
                    'blocker' => $blocker,
                ];
            }

            [$assistantContent, $domainEvents] = $this->handleLocalDemoActions($session, $content);

            $toolEvent = $this->recordEvent($session, 'tool.executed', [
                'input' => ['content' => $content],
                'output' => ['accepted' => true, 'domain_events' => $domainEvents->pluck('event_type')->all()],
            ], 'local_stub_runtime', 'succeeded');

            $assistantMessage = ConversationMessage::create([
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'metadata' => ['runtime' => 'stub', 'grounded' => true],
            ]);

            $completed = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => collect([$received])->concat($domainEvents)->push($toolEvent)->push($completed),
                'blocker' => null,
            ];
        });
    }

    private function handleLocalDemoActions(ConversationSession $session, string $content): array
    {
        $normalized = strtolower($content);
        $events = collect();

        if (str_contains($normalized, 'what did you just schedule')) {
            $event = CalendarEvent::where('conversation_session_id', $session->id)->latest('updated_at')->first();

            if (! $event) {
                return ['I checked the latest calendar event and did not find anything scheduled in this session.', $events];
            }

            return [sprintf(
                'I checked the latest calendar event: %s is scheduled for %s.',
                $event->title,
                $event->starts_at->format('Y-m-d H:i')
            ), $events];
        }

        if (str_contains($normalized, 'move that')) {
            $event = CalendarEvent::where('conversation_session_id', $session->id)->latest('updated_at')->first();

            if (! $event) {
                return ['I checked the latest calendar event and could not move anything because nothing is scheduled yet.', $events];
            }

            $startsAt = $this->demoDateTimeFromText($content, 16);
            $event->update([
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
            ]);

            $events->push($this->recordEvent($session, 'assistant.calendar_event.updated', [
                'calendar_event_id' => $event->id,
                'starts_at' => $event->starts_at->toIso8601String(),
            ], 'calendar.update', 'succeeded'));

            return ['I checked the latest calendar event and changed its start time to '.$event->starts_at->format('Y-m-d H:i').'.', $events];
        }

        if (! str_contains($normalized, 'add task') && ! str_contains($normalized, 'remind me') && ! str_contains($normalized, 'schedule')) {
            return ['Stub Hermes runtime received: '.$content, $events];
        }

        if (preg_match('/add task\s+([^;\.]+)/i', $content, $matches)) {
            $task = Task::create([
                'conversation_session_id' => $session->id,
                'title' => trim($matches[1]),
                'type' => 'todo',
                'status' => 'open',
                'metadata' => ['created_by' => 'local_demo_loop'],
            ]);

            $events->push($this->recordEvent($session, 'assistant.task.created', [
                'task_id' => $task->id,
                'title' => $task->title,
            ], 'tasks.create', 'succeeded'));
        }

        if (preg_match('/remind me tomorrow to\s+([^;\.]+)/i', $content, $matches)) {
            $reminder = Reminder::create([
                'conversation_session_id' => $session->id,
                'title' => trim($matches[1]),
                'remind_at' => now()->addDay()->setTime(9, 0),
                'status' => 'scheduled',
                'metadata' => ['created_by' => 'local_demo_loop'],
            ]);

            $events->push($this->recordEvent($session, 'assistant.reminder.created', [
                'reminder_id' => $reminder->id,
                'title' => $reminder->title,
                'remind_at' => $reminder->remind_at->toIso8601String(),
            ], 'reminders.create', 'succeeded'));
        }

        if (preg_match('/schedule\s+([^;\.]+?)(?:\s+tomorrow)?\s+at\s+(\d{1,2})(?::(\d{2}))?\s*(am|pm)?/i', $content, $matches)) {
            $startsAt = $this->demoDateTimeFromText($content, (int) $matches[2], $matches[3] ?? null, $matches[4] ?? null);
            $calendarEvent = CalendarEvent::create([
                'conversation_session_id' => $session->id,
                'title' => trim($matches[1]),
                'starts_at' => $startsAt,
                'ends_at' => $startsAt->copy()->addHour(),
                'status' => 'scheduled',
                'metadata' => ['created_by' => 'local_demo_loop'],
            ]);

            $events->push($this->recordEvent($session, 'assistant.calendar_event.created', [
                'calendar_event_id' => $calendarEvent->id,
                'title' => $calendarEvent->title,
                'starts_at' => $calendarEvent->starts_at->toIso8601String(),
            ], 'calendar.create', 'succeeded'));
        }

        return ['I checked this session and changed tasks, reminders, and calendar events. I recorded each action in the activity feed.', $events];
    }

    private function demoDateTimeFromText(string $content, int $hour, ?string $minute = null, ?string $meridiem = null): Carbon
    {
        $date = str_contains(strtolower($content), 'tomorrow') ? now()->addDay() : now();

        if ($meridiem && strtolower($meridiem) === 'pm' && $hour < 12) {
            $hour += 12;
        }

        if ($meridiem && strtolower($meridiem) === 'am' && $hour === 12) {
            $hour = 0;
        }

        return $date->setTime($hour, (int) ($minute ?? 0));
    }

    private function recordEvent(ConversationSession $session, string $type, array $payload = [], ?string $toolName = null, string $status = 'recorded'): ActivityEvent
    {
        return ActivityEvent::create([
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }
}
