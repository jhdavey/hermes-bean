<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\HermesRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HermesRuntimeApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        Carbon::setTestNow(Carbon::parse('2026-05-13 12:00:00'));
        $this->tempDir = sys_get_temp_dir().'/hermes-runtime-api-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_runtime_can_start_resume_and_send_messages_through_cli_adapter(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode(['message' => 'Real Hermes runtime answered.'], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Kitchen remodel',
            'metadata' => ['source' => 'feature-test'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.runtime_mode', 'cli')
            ->assertJsonPath('data.title', 'Kitchen remodel')
            ->json('data.id');

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}")
            ->assertOk()
            ->assertJsonPath('data.id', $sessionId)
            ->assertJsonPath('data.status', 'active');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What should I do first?',
        ])->assertCreated()
            ->assertJsonPath('data.session.id', $sessionId)
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.assistant_message.content', 'Real Hermes runtime answered.')
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_started'])
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_completed']);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'What should I do first?',
        ]);

        $this->assertDatabaseMissing('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'tool.executed',
        ]);
    }

    public function test_runtime_exposes_explicit_ordered_progress_events_contract_from_cli_actions(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Created the task.',
    'actions' => [[
        'type' => 'task.create',
        'risk' => 'low',
        'parameters' => ['title' => 'Follow up with Sarah'],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Progress contract',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add task Follow up with Sarah.',
        ])->assertCreated();

        $session = ConversationSession::findOrFail($sessionId);
        $events = $this->app->make(HermesRuntimeService::class)->progressEvents($session);

        $this->assertSame([
            'runtime.session_started',
            'runtime.message_received',
            'runtime.hermes_cli_started',
            'runtime.hermes_cli_completed',
            'assistant.task.created',
            'runtime.message_completed',
        ], $events->pluck('event_type')->all());

        $this->assertSame(1, Task::where('conversation_session_id', $sessionId)->where('title', 'Follow up with Sarah')->count());

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'runtime.session_started')
            ->assertJsonPath('data.5.event_type', 'runtime.message_completed');
    }

    public function test_runtime_persists_saved_events_tasks_and_reminders_to_their_domain_tables(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Saved the task, reminder, and event.',
    'actions' => [
        ['type' => 'task.create', 'risk' => 'low', 'parameters' => ['title' => 'Persist DB task', 'due_at' => '2026-05-13T17:00:00Z']],
        ['type' => 'reminder.create', 'risk' => 'low', 'parameters' => ['title' => 'Persist DB reminder', 'remind_at' => '2026-05-13T18:00:00Z']],
        ['type' => 'calendar_event.create', 'risk' => 'low', 'parameters' => ['title' => 'Persist DB event', 'starts_at' => '2026-05-13T19:00:00Z', 'ends_at' => '2026-05-13T20:00:00Z']],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $userId = User::where('email', 'test@example.com')->value('id');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Persistence contract',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Save a task, a reminder, and a calendar event.',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $userId,
            'conversation_session_id' => $sessionId,
            'title' => 'Persist DB task',
        ]);
        $this->assertDatabaseHas('reminders', [
            'user_id' => $userId,
            'conversation_session_id' => $sessionId,
            'title' => 'Persist DB reminder',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $userId,
            'conversation_session_id' => $sessionId,
            'title' => 'Persist DB event',
        ]);

        $this->withToken($token)->getJson('/api/tasks')->assertOk()
            ->assertJsonFragment(['title' => 'Persist DB task']);
        $this->withToken($token)->getJson('/api/reminders')->assertOk()
            ->assertJsonFragment(['title' => 'Persist DB reminder']);
        $this->withToken($token)->getJson('/api/calendar-events')->assertOk()
            ->assertJsonFragment(['title' => 'Persist DB event']);
        $this->withToken($token)->getJson('/api/today')->assertOk()
            ->assertJsonFragment(['title' => 'Persist DB task'])
            ->assertJsonFragment(['title' => 'Persist DB reminder'])
            ->assertJsonFragment(['title' => 'Persist DB event']);
    }

    public function test_runtime_executes_complex_multi_step_dashboard_crud_actions(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'I completed the dashboard updates.',
    'actions' => [
        ['type' => 'task.update', 'risk' => 'low', 'parameters' => ['id' => 1, 'title' => 'Final launch plan', 'status' => 'completed', 'notes' => 'Ready for review']],
        ['type' => 'task.create', 'risk' => 'low', 'parameters' => ['title' => 'Book executive offsite', 'type' => 'todo', 'status' => 'open']],
        ['type' => 'reminder.update', 'risk' => 'low', 'parameters' => ['id' => 1, 'title' => 'Updated standup reminder', 'remind_at' => '2026-05-13T14:00:00Z']],
        ['type' => 'calendar_event.update', 'risk' => 'low', 'parameters' => ['id' => 1, 'title' => 'Moved design review', 'starts_at' => '2026-05-13T19:00:00Z', 'ends_at' => '2026-05-13T20:00:00Z']],
        ['type' => 'blocker.resolve', 'risk' => 'low', 'parameters' => ['id' => 1]],
        ['type' => 'reminder.delete', 'risk' => 'low', 'parameters' => ['id' => 2]],
        ['type' => 'agent_profile.update', 'risk' => 'low', 'parameters' => ['settings' => ['tone' => 'executive', 'proactive' => true]]],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $userId = User::where('email', 'test@example.com')->value('id');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Universal dashboard control',
        ])->assertCreated()->json('data.id');

        Task::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Plan launch', 'status' => 'open']);
        Reminder::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Standup', 'remind_at' => '2026-05-12T13:00:00Z']);
        Reminder::create(['id' => 2, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Delete me', 'remind_at' => '2026-05-12T15:00:00Z']);
        CalendarEvent::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Design review', 'starts_at' => '2026-05-12T18:00:00Z']);
        Blocker::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'reason' => 'Needs OAuth', 'status' => 'open']);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Complete launch planning, move design review, clean reminders, resolve blockers, and make Bean more executive.',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'I completed the dashboard updates.')
            ->assertJsonFragment(['event_type' => 'assistant.task.updated'])
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.updated'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.deleted'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.updated'])
            ->assertJsonFragment(['event_type' => 'assistant.blocker.resolved'])
            ->assertJsonFragment(['event_type' => 'assistant.agent_profile.updated']);

        $this->assertDatabaseHas('tasks', ['id' => 1, 'user_id' => $userId, 'title' => 'Final launch plan', 'status' => 'completed']);
        $this->assertDatabaseHas('tasks', ['user_id' => $userId, 'title' => 'Book executive offsite']);
        $this->assertDatabaseHas('reminders', ['id' => 1, 'user_id' => $userId, 'title' => 'Updated standup reminder']);
        $this->assertDatabaseMissing('reminders', ['id' => 2, 'user_id' => $userId]);
        $this->assertDatabaseHas('calendar_events', ['id' => 1, 'user_id' => $userId, 'title' => 'Moved design review']);
        $this->assertDatabaseHas('blockers', ['id' => 1, 'user_id' => $userId, 'status' => 'resolved']);
        $this->assertSame('executive', AgentProfile::where('user_id', $userId)->firstOrFail()->settings['tone'] ?? null);
    }

    public function test_runtime_exposes_universal_dashboard_action_schema_to_hermes_prompt(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
$prompt = implode(' ', $argv);
$required = ['task.update', 'task.delete', 'reminder.update', 'calendar_event.delete', 'approval.approve', 'blocker.resolve', 'agent_profile.update', 'is_critical', 'task_id', 'visible_response', 'response_contract', 'Do not ask for optional category, color, recurrence, notes, reminders, workspace, or critical/starred status', 'ask a follow-up about whether it should recur'];
$missing = array_values(array_filter($required, fn ($needle) => ! str_contains($prompt, $needle)));
echo json_encode([
    'message' => empty($missing) ? 'Schema includes universal dashboard controls.' : 'Missing schema controls: '.implode(', ', $missing),
    'actions' => [],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Schema contract',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What can you control?',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Schema includes universal dashboard controls.');
    }

    public function test_runtime_exposes_client_temporal_context_for_local_calendar_times(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
$prompt = implode(' ', $argv);
$required = [
    'temporal_context',
    'client_context',
    '2026-05-18T13:14:00.000',
    'timezone_offset',
    '-04:00',
    'emit ISO-8601 timestamps with that local UTC offset',
    'not a bare `Z` UTC timestamp',
];
$missing = array_values(array_filter($required, fn ($needle) => ! str_contains($prompt, $needle)));
echo json_encode([
    'message' => empty($missing) ? 'Temporal context is available.' : 'Missing temporal context: '.implode(', ', $missing),
    'actions' => [],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Temporal contract',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Fit a 15min yoga session somewhere for today please',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Temporal context is available.');
    }

    public function test_runtime_normalizes_unzoned_agent_times_with_client_timezone_for_all_dashboard_dates(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Saved local dashboard items.',
    'actions' => [
        ['type' => 'task.create', 'risk' => 'low', 'parameters' => ['title' => 'Local task', 'due_at' => '2026-05-18T14:15:00']],
        ['type' => 'reminder.create', 'risk' => 'low', 'parameters' => ['title' => 'Local reminder', 'remind_at' => '2026-05-18T14:30:00']],
        ['type' => 'calendar_event.create', 'risk' => 'low', 'parameters' => ['title' => 'Local yoga', 'starts_at' => '2026-05-18T13:45:00', 'ends_at' => '2026-05-18T14:00:00']],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Local date normalization',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Save local task, reminder, and yoga session.',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Saved local dashboard items.');

        $this->assertSame(
            '2026-05-18T18:15:00+00:00',
            Task::where('title', 'Local task')->firstOrFail()->due_at->utc()->toIso8601String()
        );
        $this->assertSame(
            '2026-05-18T18:30:00+00:00',
            Reminder::where('title', 'Local reminder')->firstOrFail()->remind_at->utc()->toIso8601String()
        );
        $event = CalendarEvent::where('title', 'Local yoga')->firstOrFail();
        $this->assertSame('2026-05-18T17:45:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T18:00:00+00:00', $event->ends_at->utc()->toIso8601String());
    }

    public function test_runtime_preserves_explicit_utc_and_offset_agent_times(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Saved explicit dashboard items.',
    'actions' => [
        ['type' => 'task.create', 'risk' => 'low', 'parameters' => ['title' => 'Explicit task', 'due_at' => '2026-05-18T14:15:00Z']],
        ['type' => 'reminder.create', 'risk' => 'low', 'parameters' => ['title' => 'Explicit reminder', 'remind_at' => '2026-05-18T14:30:00+01:00']],
        ['type' => 'calendar_event.create', 'risk' => 'low', 'parameters' => ['title' => 'Explicit event', 'starts_at' => '2026-05-18T13:45:00-07:00', 'ends_at' => '2026-05-18T14:00:00-07:00']],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Explicit date preservation',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Save explicit timezone items.',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated();

        $this->assertSame(
            '2026-05-18T14:15:00+00:00',
            Task::where('title', 'Explicit task')->firstOrFail()->due_at->utc()->toIso8601String()
        );
        $this->assertSame(
            '2026-05-18T13:30:00+00:00',
            Reminder::where('title', 'Explicit reminder')->firstOrFail()->remind_at->utc()->toIso8601String()
        );
        $event = CalendarEvent::where('title', 'Explicit event')->firstOrFail();
        $this->assertSame('2026-05-18T20:45:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-18T21:00:00+00:00', $event->ends_at->utc()->toIso8601String());
    }

    public function test_runtime_recovers_agent_utc_timestamps_that_match_user_local_wall_clock_times(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Saved the retreat from 1:00 PM to 8:00 PM.',
    'actions' => [
        ['type' => 'calendar_event.create', 'risk' => 'low', 'parameters' => [
            'title' => 'Multi-day retreat',
            'starts_at' => '2026-05-18T13:00:00Z',
            'ends_at' => '2026-05-21T20:00:00Z',
        ]],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'UTC wall-clock recovery',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a multi-day event from May 18 at 1pm until May 21 at 8pm.',
            'metadata' => $this->clientTemporalMetadata(),
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Saved the retreat from 1:00 PM to 8:00 PM.');

        $event = CalendarEvent::where('title', 'Multi-day retreat')->firstOrFail();
        $this->assertSame('2026-05-18T17:00:00+00:00', $event->starts_at->utc()->toIso8601String());
        $this->assertSame('2026-05-22T00:00:00+00:00', $event->ends_at->utc()->toIso8601String());
    }

    public function test_runtime_normalizes_nested_json_assistant_message_and_applies_nested_actions(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => json_encode([
        'message' => 'Added Workout to this week. Should I make it repeat every week?',
        'actions' => [[
            'type' => 'calendar_event.create',
            'risk' => 'low',
            'parameters' => [
                'title' => 'Workout',
                'start_at' => '2026-05-13T09:00:00Z',
                'end_at' => '2026-05-13T10:00:00Z',
            ],
        ]],
    ], JSON_THROW_ON_ERROR),
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $userId = User::where('email', 'test@example.com')->value('id');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Nested JSON response',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add Workout to my calendar for monday wednesday friday 9-10am',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Added Workout to this week. Should I make it repeat every week?')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $userId,
            'conversation_session_id' => $sessionId,
            'title' => 'Workout',
            'starts_at' => '2026-05-13 09:00:00',
            'ends_at' => '2026-05-13 10:00:00',
        ]);
    }

    public function test_runtime_dashboard_state_exposes_calendar_category_color_and_recurrence_to_agent(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
$prompt = implode(' ', $argv);
$required = ['Design review', 'Work', '#FF9500', 'weekly'];
$missing = array_values(array_filter($required, fn ($needle) => ! str_contains($prompt, $needle)));
echo json_encode([
    'message' => empty($missing) ? 'Dashboard state included calendar details.' : 'Missing calendar details: '.implode(', ', $missing),
    'actions' => [],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $userId = User::where('email', 'test@example.com')->value('id');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Calendar context',
        ])->assertCreated()->json('data.id');

        CalendarEvent::create([
            'user_id' => $userId,
            'conversation_session_id' => $sessionId,
            'title' => 'Design review',
            'category' => 'Work',
            'color' => '#FF9500',
            'recurrence' => 'weekly',
            'starts_at' => '2026-05-13T15:00:00Z',
            'ends_at' => '2026-05-13T16:00:00Z',
            'status' => 'scheduled',
        ]);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What details do you know about my design review?',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Dashboard state included calendar details.');
    }

    public function test_agent_created_task_reminder_and_event_are_visible_in_today_dashboard(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Added the task, reminder, and calendar event.',
    'actions' => [
        [
            'type' => 'task.create',
            'risk' => 'low',
            'parameters' => ['title' => 'Draft proposal', 'due_at' => '2026-05-13T17:00:00Z'],
        ],
        [
            'type' => 'reminder.create',
            'risk' => 'low',
            'parameters' => ['title' => 'Check oven', 'remind_at' => '2026-05-13T16:00:00Z'],
        ],
        [
            'type' => 'calendar_event.create',
            'risk' => 'low',
            'parameters' => ['title' => 'Design sync', 'starts_at' => '2026-05-13T18:00:00Z', 'ends_at' => '2026-05-13T18:30:00Z'],
        ],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Visible dashboard resources',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add a proposal task, oven reminder, and design sync.',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.reminder.created'])
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $this->withToken($token)->getJson('/api/today')
            ->assertOk()
            ->assertJsonFragment(['title' => 'Draft proposal'])
            ->assertJsonFragment(['title' => 'Check oven'])
            ->assertJsonFragment(['title' => 'Design sync'])
            ->assertJsonPath('data.counts.tasks', 1)
            ->assertJsonPath('data.counts.reminders', 1)
            ->assertJsonPath('data.counts.calendar_events', 1);
    }

    public function test_runtime_fails_safe_to_blocker_when_real_hermes_cli_is_not_configured(): void
    {
        config()->set('services.hermes_runtime.cli_path', $this->tempDir.'/missing-hermes');
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Real Hermes request',
        ])->assertCreated()
            ->assertJsonPath('data.runtime_mode', 'cli')
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Use real Hermes to book an appointment',
        ])->assertAccepted()
            ->assertJsonPath('data.session.status', 'blocked')
            ->assertJsonPath('data.blocker.status', 'open')
            ->assertJsonPath('data.blocker.context.failure_type', 'missing_cli')
            ->assertJsonPath('data.assistant_message', null);

        $this->assertDatabaseHas('conversation_sessions', [
            'id' => $sessionId,
            'status' => 'blocked',
        ]);

        $this->assertDatabaseHas('blockers', [
            'conversation_session_id' => $sessionId,
            'status' => 'open',
        ]);

        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'runtime.blocked',
        ]);
    }

    private function configureFakeHermes(string $contents): void
    {
        $path = $this->tempDir.'/fake-hermes.php';
        File::put($path, $contents);
        chmod($path, 0755);

        config()->set('services.hermes_runtime.cli_path', $path);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);
    }

    /**
     * @return array<string, mixed>
     */
    private function clientTemporalMetadata(): array
    {
        return [
            'source' => 'flutter',
            'client_context' => [
                'current_local_time' => '2026-05-18T13:14:00.000',
                'current_utc_time' => '2026-05-18T17:14:00.000Z',
                'timezone_name' => 'EDT',
                'timezone_offset' => '-04:00',
                'timezone_offset_minutes' => -240,
            ],
        ];
    }
}
