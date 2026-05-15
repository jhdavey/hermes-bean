<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Task;
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
        $this->assertFileExists($profile->runtime_home.'/bean-preferences-memory.json');
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
        $userId = \App\Models\User::where('email', 'payload-preferences@example.com')->value('id');
        $profile = AgentProfile::where('user_id', $userId)->firstOrFail();
        app(\App\Services\AgentProfileService::class)->applyOnboarding($profile, [
            'agent_personality' => 'organizer',
            'onboarding_priorities' => ['Work', 'Focus'],
            'onboarding_context' => 'Protect deep work.',
        ], 'settings');
        \App\Models\User::where('id', $userId)->update(['onboard_complete' => true]);

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
