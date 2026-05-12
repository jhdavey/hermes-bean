<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\Task;
use App\Services\HermesRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HermesRuntimeApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hermes-runtime-api-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
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
        $userId = \App\Models\User::where('email', 'test@example.com')->value('id');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Universal dashboard control',
        ])->assertCreated()->json('data.id');

        \App\Models\Task::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Plan launch', 'status' => 'open']);
        \App\Models\Reminder::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Standup', 'remind_at' => '2026-05-12T13:00:00Z']);
        \App\Models\Reminder::create(['id' => 2, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Delete me', 'remind_at' => '2026-05-12T15:00:00Z']);
        \App\Models\CalendarEvent::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'title' => 'Design review', 'starts_at' => '2026-05-12T18:00:00Z']);
        \App\Models\Blocker::create(['id' => 1, 'user_id' => $userId, 'conversation_session_id' => $sessionId, 'reason' => 'Needs OAuth', 'status' => 'open']);

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
        $this->assertSame('executive', \App\Models\AgentProfile::where('user_id', $userId)->firstOrFail()->settings['tone'] ?? null);
    }

    public function test_runtime_exposes_universal_dashboard_action_schema_to_hermes_prompt(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
$prompt = implode(' ', $argv);
$required = ['task.update', 'task.delete', 'reminder.update', 'calendar_event.delete', 'approval.approve', 'blocker.resolve', 'agent_profile.update'];
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
}
