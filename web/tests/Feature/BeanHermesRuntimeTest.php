<?php

namespace Tests\Feature;

use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\Task;
use App\Models\User;
use App\Services\Bean\BeanDashboardToolBridgeService;
use App\Services\Bean\BeanRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BeanHermesRuntimeTest extends TestCase
{
    use RefreshDatabase;

    public function test_bean_chat_uses_isolated_hermes_home_per_user_without_changing_api_shape(): void
    {
        $usersPath = storage_path('framework/testing/hermes-users-'.uniqid());
        $logPath = storage_path('framework/testing/fake-hermes-env-'.uniqid().'.json');
        $fakeHermes = storage_path('framework/testing/fake-hermes-'.uniqid().'.php');
        File::ensureDirectoryExists(dirname($fakeHermes));
        File::put($fakeHermes, <<<PHP
#!/usr/bin/env php
<?php
file_put_contents('{$logPath}', json_encode([
    'argv' => \$argv,
    'HERMES_HOME' => getenv('HERMES_HOME'),
    'BEAN_TOOL_CONTEXT' => getenv('BEAN_TOOL_CONTEXT'),
    'BEAN_ARTISAN' => getenv('BEAN_ARTISAN'),
    'BEAN_PHP' => getenv('BEAN_PHP'),
    'PATH' => getenv('PATH'),
], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
echo "⚠ tirith security scanner enabled but not available — command scanning will use pattern matching only\\n";
echo in_array('--resume', \$argv, true) ? "Hermes resumed this user agent.\\n" : "Hermes started this user agent.\\n";
fwrite(STDERR, "session_id: fake-hermes-session-123\\n");
PHP);
        chmod($fakeHermes, 0755);

        config([
            'bean.hermes.binary' => $fakeHermes,
            'bean.hermes.users_path' => $usersPath,
            'bean.hermes.provider' => 'custom',
            'bean.hermes.model' => 'gpt-test',
            'bean.hermes.base_url' => 'https://api.openai.com/v1',
            'bean.hermes.php_binary' => 'php',
        ]);
        putenv('FAKE_HERMES_LOG');

        $token = $this->apiToken('bean-hermes-runtime@example.com');
        $user = User::where('email', 'bean-hermes-runtime@example.com')->firstOrFail();

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'remember this conversation detail',
            'client_timezone' => 'America/Chicago',
        ])->assertOk();

        $response->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.run.model', 'hermes:custom/gpt-test')
            ->assertJsonFragment(['content' => 'Hermes started this user agent.'])
            ->assertJsonMissing(['content' => '⚠ tirith security scanner enabled but not available — command scanning will use pattern matching only']);

        $home = $usersPath.'/'.$user->id;
        $this->assertDirectoryExists($home);
        $this->assertFileExists($home.'/config.yaml');
        $this->assertFileExists($home.'/skills/bean-dashboard/SKILL.md');
        $this->assertFileExists($home.'/plugins/bean-dashboard/plugin.yaml');
        $this->assertFileExists($home.'/plugins/bean-dashboard/__init__.py');
        $this->assertStringContainsString('bean_dashboard', File::get($home.'/config.yaml'));
        $this->assertStringContainsString('base_url: https://api.openai.com/v1', File::get($home.'/config.yaml'));
        $this->assertStringContainsString('reasoning_effort: none', File::get($home.'/config.yaml'));
        $this->assertStringContainsString('Do not make up private dashboard facts', File::get($home.'/skills/bean-dashboard/SKILL.md'));

        $session = BeanSession::firstOrFail();
        $this->assertSame('hermes', data_get($session->metadata, 'runtime_driver'));
        $this->assertSame($home, data_get($session->metadata, 'hermes_home'));
        $this->assertSame('bean-session-'.$session->id, data_get($session->metadata, 'hermes_session_name'));

        $logs = collect(preg_split('/\R/', trim(File::get($logPath))) ?: [])
            ->filter()
            ->map(fn (string $line): array => json_decode($line, true))
            ->values();
        $log = $logs->first();
        $this->assertSame($home, $log['HERMES_HOME']);
        $this->assertStringContainsString('bean-tool-context-', $log['BEAN_TOOL_CONTEXT']);
        $this->assertSame('php', $log['BEAN_PHP']);
        $this->assertStringContainsString('/usr/bin', $log['PATH']);
        $this->assertStringContainsString('/bin', $log['PATH']);
        $this->assertNotContains('--continue', $log['argv']);
        $this->assertNotContains('--resume', $log['argv']);
        $this->assertContains('--toolsets', $log['argv']);
        $this->assertContains('bean_dashboard,skills,memory,session_search,web', $log['argv']);
        $this->assertContains('--provider', $log['argv']);
        $this->assertContains('custom', $log['argv']);
        $this->assertContains('--model', $log['argv']);
        $this->assertContains('gpt-test', $log['argv']);
        $this->assertSame('fake-hermes-session-123', data_get($session->refresh()->metadata, 'hermes_session_id'));

        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $session->id,
            'content' => 'continue this conversation',
            'client_timezone' => 'America/Chicago',
        ])->assertOk()
            ->assertJsonFragment(['content' => 'Hermes resumed this user agent.']);

        $logs = collect(preg_split('/\R/', trim(File::get($logPath))) ?: [])
            ->filter()
            ->map(fn (string $line): array => json_decode($line, true))
            ->values();
        $resumeLog = $logs->last();
        $this->assertContains('--resume', $resumeLog['argv']);
        $this->assertContains('fake-hermes-session-123', $resumeLog['argv']);
    }

    public function test_hermes_dashboard_tool_bridge_executes_scoped_laravel_actions(): void
    {
        $this->apiToken('bean-hermes-tool@example.com');
        $user = User::where('email', 'bean-hermes-tool@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'hermes',
            'input' => 'tool bridge test',
            'started_at' => now(),
        ]);

        $context = [
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'workspace_id' => $session->workspace_id,
            'client_timezone' => 'America/Chicago',
            'expires_at' => now()->addMinutes(5)->timestamp,
        ];
        $context['signature'] = app(BeanDashboardToolBridgeService::class)->sign($context);

        $result = app(BeanDashboardToolBridgeService::class)->execute($context, [
            'action' => 'task.create',
            'arguments' => ['title' => 'from hermes tool bridge', 'type' => 'todo'],
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('task.create', $result['action']);
        $this->assertDatabaseHas('tasks', ['title' => 'from hermes tool bridge', 'user_id' => $user->id]);
        $this->assertDatabaseHas('bean_tool_calls', ['bean_run_id' => $run->id, 'action' => 'task.create', 'status' => 'completed']);
        $this->assertSame(1, Task::where('user_id', $user->id)->count());
    }

    public function test_hermes_dashboard_tool_delete_requires_confirmation_and_can_be_approved(): void
    {
        $token = $this->apiToken('bean-hermes-confirmation@example.com');
        $user = User::where('email', 'bean-hermes-confirmation@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'hermes',
            'input' => 'delete through hermes tool',
            'started_at' => now(),
        ]);
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Delete through Hermes',
            'type' => 'todo',
            'status' => 'open',
        ]);

        $context = $this->signedToolContext($user, $session, $run);
        $result = app(BeanDashboardToolBridgeService::class)->execute($context, [
            'action' => 'task.delete',
            'arguments' => ['id' => $task->id],
        ]);

        $this->assertFalse((bool) ($result['ok'] ?? true));
        $this->assertTrue((bool) ($result['requires_confirmation'] ?? false));
        $this->assertDatabaseHas('tasks', ['id' => $task->id]);

        $this->withToken($token)->postJson('/api/bean/confirmations/'.$result['confirmation_id'].'/approve')
            ->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.confirmation.status', 'approved');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
    }

    public function test_yes_message_approves_pending_confirmation_without_invoking_hermes_again(): void
    {
        $usersPath = storage_path('framework/testing/hermes-users-'.uniqid());
        config(['bean.hermes.users_path' => $usersPath]);
        $token = $this->apiToken('bean-hermes-yes-confirmation@example.com');
        $user = User::where('email', 'bean-hermes-yes-confirmation@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'hermes',
            'input' => 'delete through hermes tool',
            'started_at' => now(),
        ]);
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Delete by voice yes',
            'type' => 'todo',
            'status' => 'open',
        ]);
        $result = app(BeanDashboardToolBridgeService::class)->execute($this->signedToolContext($user, $session, $run), [
            'action' => 'task.delete',
            'arguments' => ['id' => $task->id],
        ]);

        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $session->id,
            'content' => 'yes',
        ])->assertOk()
            ->assertJsonPath('data.result.ok', true)
            ->assertJsonPath('data.confirmation.status', 'approved');

        $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
        $this->assertDatabaseMissing('bean_confirmation_requests', ['id' => $result['confirmation_id'], 'status' => 'pending']);
    }

    public function test_hermes_internal_failure_details_are_not_shown_to_users(): void
    {
        $usersPath = storage_path('framework/testing/hermes-users-'.uniqid());
        $fakeHermes = storage_path('framework/testing/fake-hermes-error-'.uniqid().'.php');
        File::ensureDirectoryExists(dirname($fakeHermes));
        File::put($fakeHermes, <<<'PHP'
#!/usr/bin/env php
<?php
echo "The attempt to list overdue tasks encountered a repeated error related to php-fpm configuration, dashboard tool setup, and SQLSTATE details. This internal problem should not be shown.";
fwrite(STDERR, "session_id: fake-hermes-error-session\n");
PHP);
        chmod($fakeHermes, 0755);

        config([
            'bean.hermes.binary' => $fakeHermes,
            'bean.hermes.users_path' => $usersPath,
            'bean.hermes.provider' => 'custom',
            'bean.hermes.model' => 'gpt-test',
        ]);

        $token = $this->apiToken('bean-hermes-sanitized-error@example.com');

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'do I have overdue tasks',
            'client_timezone' => 'America/Chicago',
        ])->assertOk();

        $response->assertJsonPath('data.run.status', 'failed')
            ->assertJsonPath('data.run.output', 'I could not complete that request.')
            ->assertJsonPath('data.run.error', null)
            ->assertJsonFragment(['content' => 'I could not complete that request.'])
            ->assertJsonMissing(['content' => 'The attempt to list overdue tasks encountered a repeated error related to php-fpm configuration, dashboard tool setup, and SQLSTATE details. This internal problem should not be shown.']);

        $this->assertDatabaseMissing('bean_messages', ['content' => 'The attempt to list overdue tasks encountered a repeated error related to php-fpm configuration, dashboard tool setup, and SQLSTATE details. This internal problem should not be shown.']);
        $this->assertDatabaseHas('bean_activity_events', ['label' => 'I could not complete that request.']);
    }

    public function test_realtime_session_requires_openai_configuration(): void
    {
        config(['services.openai.api_key' => null]);
        $token = $this->apiToken('bean-realtime-missing-key@example.com');
        Http::fake();

        $this->withToken($token)->postJson('/api/bean/realtime/session')
            ->assertStatus(503)
            ->assertJsonPath('message', 'OpenAI realtime is not configured.');

        Http::assertNothingSent();
    }

    public function test_realtime_session_mints_client_secret_for_active_voice_turns(): void
    {
        config([
            'services.openai.api_key' => 'test-openai-key',
            'services.openai.realtime_model' => 'gpt-realtime',
            'services.openai.realtime_voice' => 'alloy',
            'services.openai.realtime_vad_threshold' => 0.82,
            'services.openai.realtime_vad_prefix_padding_ms' => 250,
            'services.openai.realtime_vad_silence_duration_ms' => 800,
        ]);
        $token = $this->apiToken('bean-realtime-client-secret@example.com');

        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.openai.com/v1/realtime/client_secrets', $request->url());
            $payload = $request->data();
            $this->assertSame('realtime', data_get($payload, 'session.type'));
            $this->assertSame('gpt-realtime', data_get($payload, 'session.model'));
            $this->assertSame('alloy', data_get($payload, 'session.audio.output.voice'));
            $this->assertSame('gpt-4o-mini-transcribe', data_get($payload, 'session.audio.input.transcription.model'));
            $this->assertSame('en', data_get($payload, 'session.audio.input.transcription.language'));
            $this->assertFalse((bool) data_get($payload, 'session.audio.input.turn_detection.create_response'));
            $this->assertSame(0.82, data_get($payload, 'session.audio.input.turn_detection.threshold'));
            $this->assertSame(250, data_get($payload, 'session.audio.input.turn_detection.prefix_padding_ms'));
            $this->assertSame(800, data_get($payload, 'session.audio.input.turn_detection.silence_duration_ms'));
            $this->assertStringContainsString('Always speak English', data_get($payload, 'session.instructions'));
            $this->assertStringContainsString('Laravel is the source of truth', data_get($payload, 'session.instructions'));

            return Http::response([
                'value' => 'ek_test_voice_secret',
                'expires_at' => 1234567890,
                'session' => ['id' => 'sess_test'],
            ], 200);
        });

        $this->withToken($token)->postJson('/api/bean/realtime/session')
            ->assertOk()
            ->assertJsonPath('client_secret.value', 'ek_test_voice_secret');

        Http::assertSentCount(1);
    }

    private function signedToolContext(User $user, BeanSession $session, BeanRun $run): array
    {
        $context = [
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'workspace_id' => $session->workspace_id,
            'client_timezone' => 'America/Chicago',
            'expires_at' => now()->addMinutes(5)->timestamp,
        ];
        $context['signature'] = app(BeanDashboardToolBridgeService::class)->sign($context);

        return $context;
    }
}
