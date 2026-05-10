<?php

namespace Tests\Feature;

use App\Models\ActivityEvent;
use App\Models\Blocker;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
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
$payload = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
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
        $this->assertSame([$script, '--profile', 'test-profile'], $invocation['argv']);
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

    private function writeExecutable(string $name, string $contents): string
    {
        $path = $this->tempDir.'/'.$name;
        File::put($path, $contents);
        chmod($path, 0755);

        return $path;
    }
}
