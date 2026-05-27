<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Task;
use App\Models\User;
use App\Services\AgentProfileService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class HermesCliRuntimeServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/hermes-cli-runtime-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_cli_mode_invokes_configured_executable_and_persists_messages_and_runtime_events(): void
    {
        $script = $this->writeExecutable('fake-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = $argv[array_search('-q', $argv, true) + 1] ?? '';
$payloadJson = trim(substr($prompt, strpos($prompt, "Runtime payload:") + strlen("Runtime payload:")));
$payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
file_put_contents(getenv('HERMES_FAKE_LOG'), json_encode([
    'argv' => $argv,
    'cwd' => getcwd(),
    'payload' => $payload,
], JSON_THROW_ON_ERROR));
echo json_encode(['content' => 'Hermes CLI answered: '.$payload['message']['content']], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);
        config()->set('services.hermes_runtime.profile', 'test-profile');
        config()->set('services.hermes_runtime.environment', [
            'HERMES_FAKE_LOG' => $this->tempDir.'/invocation.json',
        ]);

        $token = $this->apiToken('cli-owner@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'CLI session',
        ])->assertCreated()
            ->assertJsonPath('data.runtime_mode', 'cli')
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan my day',
            'metadata' => ['source' => 'test'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Hermes CLI answered: Plan my day')
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_started'])
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_completed']);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Plan my day',
        ]);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Hermes CLI answered: Plan my day',
        ]);
        $assistantMessage = ConversationMessage::where('conversation_session_id', $sessionId)
            ->where('role', 'assistant')
            ->firstOrFail();
        $this->assertSame('gpt-5.5', $assistantMessage->metadata['model']);
        $this->assertSame('complex', $assistantMessage->metadata['model_route']['tier']);
        $this->assertSame([
            'runtime.session_started',
            'runtime.message_received',
            'runtime.hermes_cli_started',
            'runtime.hermes_cli_completed',
            'runtime.message_completed',
        ], ActivityEvent::where('conversation_session_id', $sessionId)->orderBy('id')->pluck('event_type')->all());

        $invocation = json_decode(File::get($this->tempDir.'/invocation.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($script, $invocation['argv'][0]);
        $this->assertContains('--profile', $invocation['argv']);
        $this->assertContains('test-profile', $invocation['argv']);
        $this->assertContains('chat', $invocation['argv']);
        $this->assertContains('-q', $invocation['argv']);
        $this->assertSame(realpath($this->tempDir), $invocation['cwd']);
        $this->assertSame('Plan my day', $invocation['payload']['message']['content']);
        $this->assertSame('cli-owner@example.com', $invocation['payload']['user']['email']);
    }

    public function test_cancel_endpoint_marks_running_session_for_cli_stop(): void
    {
        $token = $this->apiToken('cancel-cli-owner@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Cancelable session',
        ])->assertCreated()
            ->json('data.id');
        ConversationSession::findOrFail($sessionId)->update(['status' => 'running']);

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/cancel")
            ->assertAccepted()
            ->assertJsonPath('data.status', 'cancelling');

        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'runtime.cancel_requested',
        ]);
    }

    public function test_heuristic_router_selects_simple_standard_and_complex_models(): void
    {
        $script = $this->writeExecutable('router-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = $argv[array_search('-q', $argv, true) + 1] ?? '';
$payloadJson = trim(substr($prompt, strpos($prompt, "Runtime payload:") + strlen("Runtime payload:")));
$payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
file_put_contents(getenv('HERMES_FAKE_LOG'), json_encode([
    'content' => $payload['message']['content'],
    'argv' => $argv,
], JSON_THROW_ON_ERROR).PHP_EOL, FILE_APPEND);
echo json_encode(['message' => 'ok', 'actions' => []], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.router_mode', 'heuristic');
        config()->set('services.hermes_runtime.simple_model', 'cheap-fast-model');
        config()->set('services.hermes_runtime.standard_model', 'capable-cheap-model');
        config()->set('services.hermes_runtime.complex_model', 'frontier-model');
        config()->set('services.hermes_runtime.environment', [
            'HERMES_FAKE_LOG' => $this->tempDir.'/router.jsonl',
        ]);

        $token = $this->apiToken('router-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        foreach ([
            'hey Bean' => 'cheap-fast-model',
            'create a Maintenance category and make it yellow' => 'capable-cheap-model',
            'Schedule workout on my calendar today from 6 to 7pm' => 'capable-cheap-model',
            'plan my day around school pickup and move anything that conflicts' => 'frontier-model',
        ] as $message => $expectedModel) {
            $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
                'content' => $message,
            ])->assertCreated();

            $lastInvocation = collect(explode(PHP_EOL, trim(File::get($this->tempDir.'/router.jsonl'))))
                ->map(fn (string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR))
                ->last();
            $this->assertSame($message, $lastInvocation['content']);
            $this->assertContains($expectedModel, $lastInvocation['argv']);
        }

        $routes = ActivityEvent::where('conversation_session_id', $sessionId)
            ->where('event_type', 'runtime.hermes_cli_started')
            ->orderBy('id')
            ->pluck('payload')
            ->map(fn (array $payload): string => $payload['model_route']['tier'])
            ->all();

        $this->assertSame(['simple', 'standard', 'standard', 'complex'], $routes);
    }

    public function test_household_session_uses_household_agent_profile_runtime_home_and_workspace_payload(): void
    {
        $script = $this->writeExecutable('fake-household-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = $argv[array_search('-q', $argv, true) + 1] ?? '';
$payloadJson = trim(substr($prompt, strpos($prompt, "Runtime payload:") + strlen("Runtime payload:")));
$payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
file_put_contents(getenv('HERMES_FAKE_LOG'), json_encode([
    'home' => getenv('HERMES_HOME'),
    'payload' => $payload,
], JSON_THROW_ON_ERROR));
echo json_encode(['content' => 'Household Bean answered.'], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);
        config()->set('services.hermes_runtime.users_home', $this->tempDir.'/hermes-users');
        config()->set('services.hermes_runtime.environment', [
            'HERMES_FAKE_LOG' => $this->tempDir.'/household-invocation.json',
        ]);

        $token = $this->apiToken('household-cli-owner@example.com');
        $user = User::where('email', 'household-cli-owner@example.com')->firstOrFail();
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $household = app(WorkspaceService::class)->createHousehold($user, 'Family');
        $personalProfile = AgentProfile::where('workspace_id', $personalWorkspaceId)->firstOrFail();
        $householdProfile = AgentProfile::where('workspace_id', $household->id)->first();
        $this->assertNull($householdProfile);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Family chat',
            'workspace_id' => $household->id,
        ])->assertCreated()
            ->assertJsonPath('data.workspace_id', $household->id)
            ->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan dinner for the household',
        ])->assertCreated()
            ->assertJsonPath('data.assistant_message.content', 'Household Bean answered.');

        $householdProfile = AgentProfile::where('workspace_id', $household->id)->firstOrFail();
        $this->assertNotSame($personalProfile->id, $householdProfile->id);
        $this->assertStringContainsString('/workspaces/'.$household->id.'/', $householdProfile->runtime_home);

        $invocation = json_decode(File::get($this->tempDir.'/household-invocation.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($householdProfile->runtime_home, $invocation['home']);
        $this->assertSame($household->id, $invocation['payload']['session']['workspace_id']);
        $this->assertSame($household->id, $invocation['payload']['workspace']['id']);
        $this->assertSame($householdProfile->id, $invocation['payload']['agent_profile']['id']);
        $this->assertSame($household->id, $invocation['payload']['agent_profile']['workspace_id']);
        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'workspace_id' => $household->id,
            'event_type' => 'runtime.hermes_cli_started',
        ]);
    }

    public function test_personal_agent_can_target_accessible_household_workspace_for_items_and_memory_notes(): void
    {
        $script = $this->writeExecutable('cross-workspace-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$workspaceId = (int) getenv('HERMES_TARGET_WORKSPACE_ID');
echo json_encode([
    'message' => 'Added that to Davey House.',
    'actions' => [
        [
            'type' => 'task.create',
            'risk' => 'low',
            'parameters' => [
                'workspace_id' => $workspaceId,
                'title' => 'Buy soccer snacks',
                'type' => 'todo',
                'status' => 'open',
            ],
        ],
        [
            'type' => 'calendar_event.create',
            'risk' => 'low',
            'parameters' => [
                'workspace_id' => $workspaceId,
                'title' => 'Family dinner',
                'starts_at' => '2026-05-17T18:00:00-04:00',
                'ends_at' => '2026-05-17T19:00:00-04:00',
            ],
        ],
        [
            'type' => 'workspace_memory.note',
            'risk' => 'low',
            'parameters' => [
                'workspace_id' => $workspaceId,
                'note' => 'Lauren prefers school reminders the night before.',
            ],
        ],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);
        config()->set('services.hermes_runtime.users_home', $this->tempDir.'/hermes-users');

        $token = $this->apiToken('cross-workspace-owner@example.com');
        $user = User::where('email', 'cross-workspace-owner@example.com')->firstOrFail();
        $personalWorkspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $household = app(WorkspaceService::class)->createHousehold($user, 'Davey House');
        config()->set('services.hermes_runtime.environment', [
            'HERMES_TARGET_WORKSPACE_ID' => (string) $household->id,
        ]);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Personal chat',
            'workspace_id' => $personalWorkspaceId,
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add soccer snacks and dinner to Davey House, and remember Lauren likes school reminders the night before.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.workspace_memory.noted']);

        $this->assertDatabaseHas('tasks', [
            'user_id' => $user->id,
            'workspace_id' => $household->id,
            'conversation_session_id' => $sessionId,
            'title' => 'Buy soccer snacks',
        ]);
        $this->assertDatabaseMissing('tasks', [
            'user_id' => $user->id,
            'workspace_id' => $personalWorkspaceId,
            'title' => 'Buy soccer snacks',
        ]);
        $this->assertDatabaseHas('calendar_events', [
            'user_id' => $user->id,
            'workspace_id' => $household->id,
            'conversation_session_id' => $sessionId,
            'title' => 'Family dinner',
        ]);

        $householdProfile = AgentProfile::where('workspace_id', $household->id)->firstOrFail();
        $this->assertStringContainsString('Lauren prefers school reminders the night before.', File::get($householdProfile->runtime_home.'/memories/MEMORY.md'));
        $this->assertFileDoesNotExist($householdProfile->runtime_home.'/MEMORY.md');
        $personalProfile = AgentProfile::where('workspace_id', $personalWorkspaceId)->firstOrFail();
        $this->assertStringNotContainsString('Lauren prefers school reminders the night before.', File::get($personalProfile->runtime_home.'/memories/MEMORY.md'));
    }

    public function test_personal_agent_cannot_target_inaccessible_household_workspace(): void
    {
        $script = $this->writeExecutable('blocked-cross-workspace-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$workspaceId = (int) getenv('HERMES_TARGET_WORKSPACE_ID');
echo json_encode([
    'message' => 'Trying to add this to another household.',
    'actions' => [[
        'type' => 'task.create',
        'risk' => 'low',
        'parameters' => [
            'workspace_id' => $workspaceId,
            'title' => 'Should not be created',
        ],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);

        $token = $this->apiToken('cross-workspace-blocked@example.com');
        $user = User::where('email', 'cross-workspace-blocked@example.com')->firstOrFail();
        $otherUser = User::factory()->create(['email' => 'other-household-owner@example.com']);
        $otherHousehold = app(WorkspaceService::class)->createHousehold($otherUser, 'Other House');
        config()->set('services.hermes_runtime.environment', [
            'HERMES_TARGET_WORKSPACE_ID' => (string) $otherHousehold->id,
        ]);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add this to Other House',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.action.failed']);

        $this->assertDatabaseMissing('tasks', [
            'workspace_id' => $otherHousehold->id,
            'title' => 'Should not be created',
        ]);
        $failure = ActivityEvent::where('conversation_session_id', $sessionId)
            ->where('event_type', 'assistant.action.failed')
            ->firstOrFail();
        $this->assertStringContainsString('not a member', $failure->payload['reason']);
    }

    public function test_missing_cli_path_fails_closed_with_blocker_without_assistant_message(): void
    {
        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $this->tempDir.'/missing-hermes');
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('missing-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Use Hermes',
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.assistant_message', null)
            ->assertJsonPath('data.blocker.status', 'open')
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_failed']);

        $this->assertSame(1, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'user')->count());
        $this->assertSame(0, ConversationMessage::where('conversation_session_id', $sessionId)->where('role', 'assistant')->count());
        $this->assertDatabaseHas('conversation_sessions', ['id' => $sessionId, 'status' => 'blocked']);
        $this->assertSame(1, Blocker::where('conversation_session_id', $sessionId)->where('status', 'open')->count());
    }

    public function test_non_zero_cli_exit_fails_closed_with_blocker(): void
    {
        $script = $this->writeExecutable('failing-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
fwrite(STDERR, 'model unavailable');
exit(42);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('failing-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Please continue',
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.blocker.context.exit_code', 42);

        $this->assertDatabaseHas('activity_events', [
            'conversation_session_id' => $sessionId,
            'event_type' => 'runtime.hermes_cli_failed',
            'status' => 'failed',
        ]);
    }

    public function test_timed_out_cli_process_fails_closed_with_blocker(): void
    {
        $script = $this->writeExecutable('slow-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
sleep(3);
echo 'late response';
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 1);

        $token = $this->apiToken('timeout-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Take too long',
        ])->assertAccepted()
            ->assertJsonPath('data.status', 'blocked')
            ->assertJsonPath('data.blocker.context.failure_type', 'timeout');
    }

    public function test_cli_runtime_keeps_authenticated_user_ownership_intact(): void
    {
        $script = $this->writeExecutable('owner-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo 'owned response';
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $ownerToken = $this->apiToken('real-owner@example.com');
        $otherToken = $this->apiToken('intruder@example.com');
        $sessionId = $this->withToken($ownerToken)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($otherToken)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Cross account message',
        ])->assertNotFound();

        $this->withToken($ownerToken)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Owner message',
        ])->assertCreated();

        $session = ConversationSession::findOrFail($sessionId);
        ConversationMessage::where('conversation_session_id', $sessionId)->get()->each(
            fn (ConversationMessage $message) => $this->assertSame($session->user_id, $message->user_id)
        );
        ActivityEvent::where('conversation_session_id', $sessionId)->get()->each(
            fn (ActivityEvent $event) => $this->assertSame($session->user_id, $event->user_id)
        );
    }

    public function test_structured_cli_output_executes_low_risk_actions_and_queues_risky_actions_for_approval(): void
    {
        $script = $this->writeExecutable('structured-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'I created the launch task and queued the email for approval.',
    'actions' => [
        [
            'type' => 'task.create',
            'risk' => 'low',
            'parameters' => ['title' => 'Review launch plan', 'type' => 'todo'],
        ],
        [
            'type' => 'email.send',
            'risk' => 'high',
            'title' => 'Send launch email',
            'description' => 'Send the launch note to Lauren.',
            'parameters' => ['to' => 'lauren@example.com', 'subject' => 'Launch'],
        ],
    ],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('structured-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a launch task and send Lauren the launch email.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I created the launch task and queued the email for approval.')
            ->assertJsonFragment(['event_type' => 'assistant.task.created'])
            ->assertJsonFragment(['event_type' => 'assistant.approval.created']);

        $this->assertDatabaseHas('tasks', [
            'conversation_session_id' => $sessionId,
            'title' => 'Review launch plan',
            'status' => 'open',
        ]);
        $this->assertDatabaseHas('approvals', [
            'conversation_session_id' => $sessionId,
            'title' => 'Send launch email',
            'status' => 'pending',
        ]);
        $approval = Approval::where('conversation_session_id', $sessionId)->firstOrFail();
        $this->assertSame('email.send', $approval->payload['action']['type']);
        $this->assertSame('high', $approval->payload['action']['risk']);
    }

    public function test_structured_cli_output_inside_markdown_fence_only_persists_message_text(): void
    {
        $script = $this->writeExecutable('fenced-structured-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$payload = [
    'message' => 'Hello! How can I assist you today?',
    'actions' => [[
        'type' => 'agent_profile.update',
        'risk' => 'low',
        'parameters' => ['settings' => ['onboarding' => ['completed' => true]]],
    ]],
];
echo "```json\n".json_encode($payload, JSON_THROW_ON_ERROR)."\n```";
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('fenced-structured-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'hello',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Hello! How can I assist you today?')
            ->assertJsonMissingPath('data.assistant_message.content.actions')
            ->assertJsonFragment(['event_type' => 'assistant.agent_profile.updated']);
    }

    public function test_structured_cli_output_without_message_does_not_leak_json_to_chat(): void
    {
        $script = $this->writeExecutable('message-less-structured-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'actions' => [[
        'type' => 'calendar_event.create',
        'risk' => 'low',
        'parameters' => [
            'title' => 'Workout',
            'starts_at' => '2026-05-27T18:00:00-04:00',
            'ends_at' => '2026-05-27T19:00:00-04:00',
        ],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('message-less-structured-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Schedule workout on my calendar today from 6 to 7pm',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I added Workout to your calendar.')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $this->assertDatabaseHas('calendar_events', [
            'conversation_session_id' => $sessionId,
            'title' => 'Workout',
        ]);
    }

    public function test_visible_response_is_preferred_over_message_alias(): void
    {
        $script = $this->writeExecutable('visible-response-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'visible_response' => 'Workout is on your calendar from 6:00 PM to 7:00 PM.',
    'message' => 'Legacy message should not be shown.',
    'actions' => [[
        'type' => 'calendar_event.create',
        'risk' => 'low',
        'parameters' => [
            'title' => 'Workout',
            'starts_at' => '2026-05-27T18:00:00-04:00',
            'ends_at' => '2026-05-27T19:00:00-04:00',
        ],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('visible-response-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Schedule workout on my calendar today from 6 to 7pm',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Workout is on your calendar from 6:00 PM to 7:00 PM.');
    }

    public function test_invalid_structured_create_action_does_not_create_junk_resource(): void
    {
        $script = $this->writeExecutable('invalid-create-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'visible_response' => 'I need a time before I can put that on your calendar.',
    'actions' => [[
        'type' => 'calendar_event.create',
        'risk' => 'low',
        'parameters' => [
            'title' => 'Workout',
        ],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('invalid-create-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Put workout on my calendar',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I need a time before I can put that on your calendar.')
            ->assertJsonFragment(['event_type' => 'assistant.action.failed']);

        $this->assertDatabaseMissing('calendar_events', [
            'conversation_session_id' => $sessionId,
            'title' => 'Workout',
        ]);
    }

    public function test_empty_structured_message_falls_back_and_task_update_can_match_title(): void
    {
        $script = $this->writeExecutable('empty-message-task-update-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => '',
    'actions' => [[
        'type' => 'task.update',
        'risk' => 'low',
        'parameters' => [
            'task_title' => 'Litter Box',
            'status' => 'complete',
        ],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('task-title-update-cli@example.com');
        $user = User::where('email', 'task-title-update-cli@example.com')->firstOrFail();
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $workspaceId,
            'title' => 'Litter Box',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now(),
        ]);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'workspace_id' => $workspaceId,
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Mark litter box complete',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'I updated that task.')
            ->assertJsonFragment(['event_type' => 'assistant.task.updated']);

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'title' => 'Litter Box',
            'status' => 'completed',
        ]);
    }

    public function test_structured_create_actions_accept_common_time_field_aliases(): void
    {
        $script = $this->writeExecutable('aliased-calendar-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'visible_response' => 'Workout is on your calendar from 6:00 PM to 7:00 PM.',
    'actions' => [[
        'type' => 'calendar_event.create',
        'risk' => 'low',
        'parameters' => [
            'summary' => 'Workout',
            'date' => '2026-05-27',
            'start_time' => '6:00 PM',
            'end_time' => '7:00 PM',
        ],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('aliased-calendar-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Schedule workout on my calendar today from 6 to 7pm',
            'metadata' => [
                'client_context' => [
                    'current_local_time' => '2026-05-27T12:00:00-04:00',
                    'timezone_offset' => '-04:00',
                    'timezone_offset_minutes' => -240,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Workout is on your calendar from 6:00 PM to 7:00 PM.')
            ->assertJsonFragment(['event_type' => 'assistant.calendar_event.created']);

        $this->assertDatabaseHas('calendar_events', [
            'conversation_session_id' => $sessionId,
            'title' => 'Workout',
        ]);
        $event = \App\Models\CalendarEvent::where('conversation_session_id', $sessionId)->firstOrFail();
        $this->assertSame('2026-05-27T22:00:00+00:00', $event->starts_at->toIso8601String());
        $this->assertSame('2026-05-27T23:00:00+00:00', $event->ends_at->toIso8601String());
    }

    public function test_clear_calendar_create_prompt_discourages_unnecessary_followups(): void
    {
        $script = $this->writeExecutable('prompt-capture-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = $argv[array_search('-q', $argv, true) + 1] ?? '';
file_put_contents(getenv('HERMES_FAKE_LOG'), $prompt);
echo json_encode(['message' => 'Workout is on your calendar for today from 6:00 PM to 7:00 PM.', 'actions' => []], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.environment', [
            'HERMES_FAKE_LOG' => $this->tempDir.'/prompt.txt',
        ]);

        $token = $this->apiToken('clear-calendar-prompt@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Schedule workout on my calendar today from 6 to 7pm',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed');

        $prompt = File::get($this->tempDir.'/prompt.txt');
        $this->assertStringContainsString('Prefer acting on clear scheduling/productivity requests instead of interrogating the user for optional details.', $prompt);
        $this->assertStringContainsString('Schedule workout on my calendar today from 6 to 7pm', $prompt);
        $this->assertStringContainsString('visible_response', $prompt);
        $this->assertStringContainsString('Treat `visible_response` and `actions` as separate channels', $prompt);
        $this->assertStringContainsString('response_contract', $prompt);
        $this->assertStringContainsString('Do not ask for optional category, color, recurrence, notes, reminders, workspace, or critical/starred status', $prompt);
    }

    public function test_approving_a_queued_structured_action_executes_it_once(): void
    {
        $script = $this->writeExecutable('approval-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'This needs approval before changing tasks.',
    'actions' => [[
        'type' => 'task.create',
        'risk' => 'high',
        'title' => 'Approve task creation',
        'parameters' => ['title' => 'Book flights', 'type' => 'todo'],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('approve-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Add a task, but ask me first.',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.approval.created']);

        $approval = Approval::where('conversation_session_id', $sessionId)->firstOrFail();
        $this->assertSame(0, Task::where('conversation_session_id', $sessionId)->where('title', 'Book flights')->count());

        $this->withToken($token)->postJson("/api/approvals/{$approval->id}/approve")
            ->assertOk()
            ->assertJsonPath('data.approval.status', 'approved')
            ->assertJsonFragment(['event_type' => 'assistant.task.created']);

        $this->assertSame(1, Task::where('conversation_session_id', $sessionId)->where('title', 'Book flights')->count());

        $this->withToken($token)->postJson("/api/approvals/{$approval->id}/approve")
            ->assertStatus(409);
        $this->assertSame(1, Task::where('conversation_session_id', $sessionId)->where('title', 'Book flights')->count());
    }

    public function test_event_category_creation_is_low_risk_and_available_in_runtime_payload(): void
    {
        $script = $this->writeExecutable('category-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = $argv[array_search('-q', $argv, true) + 1] ?? '';
$payloadJson = trim(substr($prompt, strpos($prompt, "Runtime payload:") + strlen("Runtime payload:")));
$payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
file_put_contents(getenv('HERMES_FAKE_LOG'), json_encode($payload['allowed_action_schema'], JSON_THROW_ON_ERROR));
echo json_encode([
    'message' => 'I created the Maintenance category in yellow.',
    'actions' => [[
        'type' => 'event_category.create',
        'risk' => 'medium',
        'parameters' => ['name' => 'Maintenance', 'color' => '#FACC15'],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);
        config()->set('services.hermes_runtime.environment', [
            'HERMES_FAKE_LOG' => $this->tempDir.'/allowed-actions.json',
        ]);

        $token = $this->apiToken('category-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Create a Maintenance category and make it yellow.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.event_category.saved']);

        $this->assertDatabaseHas('event_categories', [
            'name' => 'Maintenance',
            'color' => '#FACC15',
        ]);
        $this->assertSame(0, Approval::where('conversation_session_id', $sessionId)->count());

        $allowedActions = json_decode(File::get($this->tempDir.'/allowed-actions.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertContains('event_category.create', $allowedActions['low_risk']);
    }

    public function test_always_approve_persists_action_type_exemption(): void
    {
        $script = $this->writeExecutable('always-approval-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'This needs approval before sending email.',
    'actions' => [[
        'type' => 'email.send',
        'risk' => 'high',
        'title' => 'Send email',
        'description' => 'Send an email.',
        'parameters' => ['to' => 'lauren@example.com', 'subject' => 'Hello'],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);

        $token = $this->apiToken('always-approve-cli@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Send Lauren an email.',
        ])->assertCreated()
            ->assertJsonFragment(['event_type' => 'assistant.approval.created']);

        $approval = Approval::where('conversation_session_id', $sessionId)->firstOrFail();

        $this->withToken($token)->postJson("/api/approvals/{$approval->id}/approve", [
            'always_approve' => true,
        ])->assertOk()
            ->assertJsonPath('data.approval.status', 'approved');

        $profile = AgentProfile::query()->firstOrFail();
        $this->assertContains('email.send', $profile->approval_policy['always_approve_action_types']);
    }

    public function test_onboarding_agent_profile_update_marks_user_complete_and_saves_memory_settings(): void
    {
        $script = $this->writeExecutable('onboarding-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode([
    'message' => 'Great — I saved your Bean preferences.',
    'actions' => [[
        'type' => 'agent_profile.update',
        'risk' => 'low',
        'parameters' => [
            'settings' => [
                'personality_type' => 'coach',
                'onboarding' => [
                    'completed' => true,
                    'priorities' => ['Family', 'Planning'],
                    'context' => 'Protect family dinner.',
                ],
            ],
        ],
    ]],
], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);

        $token = $this->apiToken('chat-onboarding@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'I am Harley. Family and planning matter most. Be a coach.',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonFragment(['event_type' => 'assistant.agent_profile.updated']);

        $profile = AgentProfile::query()->firstOrFail();
        $this->assertTrue((bool) $profile->user->onboard_complete);
        $this->assertSame('coach', $profile->settings['personality_type']);
        $this->assertTrue($profile->settings['onboarding']['completed']);
        $this->assertSame(['Family', 'Planning'], $profile->settings['onboarding']['priorities']);
        $this->assertSame('Protect family dinner.', $profile->settings['memory']['user_preferences']['context']);
        $this->assertFileExists($profile->runtime_home.'/memories/MEMORY.md');
        $this->assertStringContainsString('Protect family dinner.', File::get($profile->runtime_home.'/memories/MEMORY.md'));
        $this->assertFileDoesNotExist($profile->runtime_home.'/PREFERENCES.md');
        $this->assertFileDoesNotExist($profile->runtime_home.'/bean-preferences-memory.json');
    }

    public function test_runtime_payload_includes_onboarding_status_and_preference_memory(): void
    {
        $script = $this->writeExecutable('payload-hermes.php', <<<'PHP'
#!/usr/bin/env php
<?php
$prompt = $argv[array_search('-q', $argv, true) + 1] ?? '';
$payloadJson = trim(substr($prompt, strpos($prompt, "Runtime payload:") + strlen("Runtime payload:")));
$payload = json_decode($payloadJson, true, flags: JSON_THROW_ON_ERROR);
file_put_contents(getenv('HERMES_FAKE_LOG'), json_encode($payload, JSON_THROW_ON_ERROR));
echo json_encode(['message' => 'Loaded preferences.', 'actions' => []], JSON_THROW_ON_ERROR);
PHP);

        config()->set('services.hermes_runtime.mode', 'cli');
        config()->set('services.hermes_runtime.cli_path', $script);
        config()->set('services.hermes_runtime.timeout', 5);
        config()->set('services.hermes_runtime.workdir', $this->tempDir);
        config()->set('services.hermes_runtime.environment', [
            'HERMES_FAKE_LOG' => $this->tempDir.'/payload.json',
        ]);

        $token = $this->apiToken('payload-preferences@example.com');
        $userId = User::where('email', 'payload-preferences@example.com')->value('id');
        $profile = AgentProfile::where('user_id', $userId)->firstOrFail();
        app(AgentProfileService::class)->applyOnboarding($profile, [
            'agent_personality' => 'organizer',
            'onboarding_priorities' => ['Work', 'Focus'],
            'onboarding_context' => 'Protect deep work.',
        ], 'settings');
        User::where('id', $userId)->update(['onboard_complete' => true]);

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'What should I do?',
        ])->assertCreated();

        $payload = json_decode(File::get($this->tempDir.'/payload.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($payload['user']['onboard_complete']);
        $this->assertSame('organizer', $payload['agent_profile']['settings']['personality_type']);
        $this->assertSame(['Work', 'Focus'], $payload['agent_profile']['settings']['onboarding']['priorities']);
        $this->assertStringContainsString('Protect deep work', $payload['agent_profile']['settings']['memory']['user_preferences']['summary']);
    }

    private function writeExecutable(string $name, string $contents): string
    {
        $path = $this->tempDir.'/'.$name;
        File::put($path, $contents);
        chmod($path, 0755);

        return $path;
    }
}
