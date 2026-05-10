<?php

use App\Models\ActivityEvent;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Artisan::command('hermes-bean:demo {--reset : Clear assistant demo records before running}', function (): int {
    if ($this->option('reset')) {
        DB::table('activity_events')->delete();
        DB::table('approvals')->delete();
        DB::table('blockers')->delete();
        DB::table('calendar_events')->delete();
        DB::table('reminders')->delete();
        DB::table('tasks')->delete();
        DB::table('conversation_messages')->delete();
        DB::table('conversation_sessions')->delete();
    }

    $session = ConversationSession::create([
        'title' => 'HB-6 local demo loop',
        'status' => 'active',
        'runtime_mode' => 'stub',
        'metadata' => ['source' => 'hermes-bean:demo'],
        'last_activity_at' => now(),
    ]);

    ActivityEvent::create([
        'conversation_session_id' => $session->id,
        'event_type' => 'runtime.session_started',
        'payload' => ['runtime_mode' => 'stub', 'source' => 'demo_command'],
    ]);

    $task = Task::create([
        'conversation_session_id' => $session->id,
        'title' => 'Replace air filter',
        'type' => 'todo',
        'status' => 'open',
        'metadata' => ['surface' => 'chat'],
    ]);
    ActivityEvent::create([
        'conversation_session_id' => $session->id,
        'event_type' => 'assistant.task.created',
        'tool_name' => 'tasks.create',
        'status' => 'succeeded',
        'payload' => ['task_id' => $task->id, 'title' => $task->title],
    ]);
    $this->line('Created task: '.$task->title);

    $reminder = Reminder::create([
        'conversation_session_id' => $session->id,
        'title' => 'take out bins',
        'remind_at' => now()->addDay()->setTime(9, 0),
        'status' => 'scheduled',
        'metadata' => ['surface' => 'chat'],
    ]);
    ActivityEvent::create([
        'conversation_session_id' => $session->id,
        'event_type' => 'assistant.reminder.created',
        'tool_name' => 'reminders.create',
        'status' => 'succeeded',
        'payload' => ['reminder_id' => $reminder->id, 'title' => $reminder->title],
    ]);
    $this->line('Created reminder: '.$reminder->title);

    $calendarEvent = CalendarEvent::create([
        'conversation_session_id' => $session->id,
        'title' => 'dentist',
        'starts_at' => now()->addDay()->setTime(15, 0),
        'ends_at' => now()->addDay()->setTime(16, 0),
        'status' => 'scheduled',
        'metadata' => ['surface' => 'chat'],
    ]);
    ActivityEvent::create([
        'conversation_session_id' => $session->id,
        'event_type' => 'assistant.calendar_event.created',
        'tool_name' => 'calendar.create',
        'status' => 'succeeded',
        'payload' => ['calendar_event_id' => $calendarEvent->id, 'title' => $calendarEvent->title],
    ]);
    $this->line('Created calendar event: '.$calendarEvent->title);

    $blocker = Blocker::create([
        'conversation_session_id' => $session->id,
        'reason' => 'Needs user approval before contacting an external calendar provider.',
        'status' => 'open',
        'context' => ['requested_action' => 'external_calendar_sync'],
    ]);
    ActivityEvent::create([
        'conversation_session_id' => $session->id,
        'event_type' => 'runtime.blocked',
        'status' => 'blocked',
        'payload' => ['blocker_id' => $blocker->id, 'reason' => $blocker->reason],
    ]);
    $this->line('Opened blocker: '.$blocker->reason);

    $approval = Approval::create([
        'conversation_session_id' => $session->id,
        'title' => 'Approve external calendar sync',
        'status' => 'approved',
        'payload' => ['blocker_id' => $blocker->id],
    ]);
    $blocker->update(['status' => 'resolved']);
    ActivityEvent::create([
        'conversation_session_id' => $session->id,
        'event_type' => 'approval.resolved_blocker',
        'status' => 'succeeded',
        'payload' => ['approval_id' => $approval->id, 'blocker_id' => $blocker->id],
    ]);
    $this->line('Approved blocker: '.$approval->title);

    $this->info('HB-6 demo complete. Activity feed contains '.ActivityEvent::where('conversation_session_id', $session->id)->count().' events for session '.$session->id.'.');

    return 0;
})->purpose('Run the Hermes Bean local demo loop for chat-created assistant resources and approval/blocker flow');
