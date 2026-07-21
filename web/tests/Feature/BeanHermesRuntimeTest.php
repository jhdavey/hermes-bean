<?php

namespace Tests\Feature;

use App\Models\BeanActivityEvent;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\CalendarEvent;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Services\Bean\BeanDashboardToolBridgeService;
use App\Services\Bean\BeanRuntimeService;
use App\Services\Bean\DashboardContextBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Carbon;
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

        $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $session->id,
            'content' => 'what is on my dashboard today',
            'client_timezone' => 'America/Chicago',
            'source' => 'elevenlabs_agent',
        ])->assertOk();

        $logs = collect(preg_split('/\R/', trim(File::get($logPath))) ?: [])
            ->filter()
            ->map(fn (string $line): array => json_decode($line, true))
            ->values();
        $voiceLog = $logs->last();
        $this->assertContains('--toolsets', $voiceLog['argv']);
        $this->assertContains('bean_dashboard,skills,memory,session_search,web', $voiceLog['argv']);
        $this->assertContains('--max-turns', $voiceLog['argv']);
        $this->assertContains('24', $voiceLog['argv']);
    }

    public function test_voice_task_list_today_uses_hermes_fallback_not_a_to_do_specific_fast_path(): void
    {
        $usersPath = storage_path('framework/testing/hermes-users-'.uniqid());
        $fakeHermesLog = storage_path('framework/testing/voice-hermes-fallback-'.uniqid().'.json');
        $fakeHermes = storage_path('framework/testing/fake-hermes-fallback-'.uniqid().'.php');
        File::ensureDirectoryExists(dirname($fakeHermes));
        File::put($fakeHermes, <<<PHP
#!/usr/bin/env php
<?php
file_put_contents('{$fakeHermesLog}', json_encode(['argv' => \$argv], JSON_UNESCAPED_SLASHES).PHP_EOL, FILE_APPEND);
echo "Hermes handled the voice task list through the generic Bean runtime.";
fwrite(STDERR, "session_id: generic-voice-hermes-session\\n");
PHP);
        chmod($fakeHermes, 0755);
        config([
            'bean.hermes.binary' => $fakeHermes,
            'bean.hermes.users_path' => $usersPath,
            'bean.hermes.provider' => 'custom',
            'bean.hermes.model' => 'gpt-test',
        ]);

        $token = $this->apiToken('bean-voice-generic-runtime@example.com');
        $user = User::where('email', 'bean-voice-generic-runtime@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user, null, 'America/New_York');
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'generic voice task',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->timezone('America/New_York')->endOfDay()->utc(),
        ]);

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'session_id' => $session->id,
            'content' => "What's on my to-do list for today?",
            'client_timezone' => 'America/New_York',
            'source' => 'elevenlabs_agent',
        ])->assertOk();

        $response->assertJsonPath('data.run.mode', 'hermes')
            ->assertJsonPath('data.run.model', 'hermes:custom/gpt-test')
            ->assertJsonPath('data.run.status', 'completed');
        $this->assertStringContainsString('Hermes handled the voice task list through the generic Bean runtime.', $response->json('data.run.output'));
        $this->assertFileExists($fakeHermesLog);
    }

    public function test_bean_event_stream_includes_session_and_run_ids_for_voice_recovery(): void
    {
        $token = $this->apiToken('bean-event-stream-runtime@example.com');
        $user = User::where('email', 'bean-event-stream-runtime@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user);
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'completed',
            'mode' => 'hermes',
            'input' => 'voice followup',
            'output' => 'Here is the voice follow-up answer.',
            'started_at' => now()->subSecond(),
            'completed_at' => now(),
        ]);
        BeanActivityEvent::create([
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'type' => 'assistant_message',
            'label' => 'Here is the voice follow-up answer.',
            'payload' => ['runtime' => 'hermes'],
        ]);

        $content = $this->withToken($token)->get('/api/bean/events?after=0&wait=0')->streamedContent();

        $this->assertStringContainsString('"bean_session_id":'.$session->id, $content);
        $this->assertStringContainsString('"bean_run_id":'.$run->id, $content);
        $this->assertStringContainsString('Here is the voice follow-up answer.', $content);
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
            'expires_at' => time() + 300,
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

    public function test_dashboard_calendar_date_arguments_are_normalized_to_user_local_day_and_local_times(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-21T00:00:00Z'));
        $this->apiToken('bean-calendar-local-date@example.com');
        $user = User::where('email', 'bean-calendar-local-date@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user, null, 'America/New_York');
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'hermes',
            'input' => 'calendar date tool test',
            'metadata' => ['time_context' => app(\App\Services\Bean\BeanTimeContext::class)->forSession($session)],
            'started_at' => now(),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Saturday noon event',
            'status' => 'scheduled',
            'starts_at' => Carbon::parse('2026-07-25 12:00', 'America/New_York')->utc(),
            'ends_at' => Carbon::parse('2026-07-25 18:00', 'America/New_York')->utc(),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Friday event should not leak',
            'status' => 'scheduled',
            'starts_at' => Carbon::parse('2026-07-24 12:00', 'America/New_York')->utc(),
            'ends_at' => Carbon::parse('2026-07-24 18:00', 'America/New_York')->utc(),
        ]);

        $result = app(BeanDashboardToolBridgeService::class)->execute($this->signedToolContextForTimezone($user, $session, $run, 'America/New_York'), [
            'action' => 'calendar_event.list',
            'arguments' => ['date' => '2026-07-25'],
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame(1, $result['total_count']);
        $this->assertSame('Saturday noon event', $result['items'][0]['title']);
        $this->assertSame('2026-07-25T16:00:00+00:00', $result['items'][0]['starts_at']);
        $this->assertSame('2026-07-25T12:00:00-04:00', $result['items'][0]['starts_at_local']);
        $this->assertSame('2026-07-25T18:00:00-04:00', $result['items'][0]['ends_at_local']);
        $this->assertSame('2026-07-25', $result['time_label']);
        $this->assertSame([
            ['field' => 'starts_at', 'operator' => 'overlaps_day', 'value' => ['2026-07-25T04:00:00+00:00', '2026-07-26T03:59:59+00:00']],
        ], $result['filters']);

        $weekdayResult = app(BeanDashboardToolBridgeService::class)->execute($this->signedToolContextForTimezone($user, $session, $run, 'America/New_York'), [
            'action' => 'calendar_event.list',
            'arguments' => ['time_label' => 'saturday'],
        ]);
        $this->assertSame(1, $weekdayResult['total_count']);
        $this->assertSame('Saturday noon event', $weekdayResult['items'][0]['title']);
        $this->assertSame('2026-07-25T12:00:00-04:00', $weekdayResult['items'][0]['starts_at_local']);
    }

    public function test_task_date_moves_preserve_existing_local_due_time_when_no_new_time_is_explicit(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-20T15:00:00Z'));
        $this->apiToken('bean-task-preserve-time@example.com');
        $user = User::where('email', 'bean-task-preserve-time@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user, null, 'America/New_York');
        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'hermes',
            'input' => 'move overdue task to today',
            'metadata' => ['time_context' => app(\App\Services\Bean\BeanTimeContext::class)->forSession($session)],
            'started_at' => now(),
        ]);
        $task = Task::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Preserve my time',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => Carbon::parse('2026-07-11 17:00', 'America/New_York')->utc(),
        ]);

        $result = app(BeanDashboardToolBridgeService::class)->execute($this->signedToolContextForTimezone($user, $session, $run, 'America/New_York'), [
            'action' => 'task.update',
            'arguments' => ['id' => $task->id, 'due_at' => '2026-07-20T23:59:59-04:00'],
        ]);

        $this->assertTrue((bool) ($result['ok'] ?? false));
        $this->assertSame('2026-07-20T21:00:00+00:00', $task->refresh()->due_at->toIso8601String());
        $this->assertSame('2026-07-20T17:00:00-04:00', $result['item']['due_at_local']);
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
        $session = BeanSession::firstOrFail()->refresh();
        $this->assertNull(data_get($session->metadata, 'hermes_session_id'));
        $this->assertSame('fake-hermes-error-session', data_get($session->metadata, 'failed_hermes_session_id'));
    }

    public function test_relative_hermes_users_path_is_resolved_to_absolute_paths_for_tools(): void
    {
        $relativeUsersPath = 'storage/framework/testing/hermes-users-relative-'.uniqid();
        $logPath = storage_path('framework/testing/fake-hermes-relative-env-'.uniqid().'.json');
        $fakeHermes = storage_path('framework/testing/fake-hermes-relative-'.uniqid().'.php');
        File::ensureDirectoryExists(dirname($fakeHermes));
        File::put($fakeHermes, <<<PHP
#!/usr/bin/env php
<?php
file_put_contents('{$logPath}', json_encode([
    'HERMES_HOME' => getenv('HERMES_HOME'),
    'BEAN_TOOL_CONTEXT' => getenv('BEAN_TOOL_CONTEXT'),
], JSON_UNESCAPED_SLASHES));
echo "OK";
fwrite(STDERR, "session_id: fake-relative-session\\n");
PHP);
        chmod($fakeHermes, 0755);

        config([
            'bean.hermes.binary' => $fakeHermes,
            'bean.hermes.users_path' => $relativeUsersPath,
            'bean.hermes.provider' => 'custom',
            'bean.hermes.model' => 'gpt-test',
        ]);

        $token = $this->apiToken('bean-hermes-relative-path@example.com');
        $user = User::where('email', 'bean-hermes-relative-path@example.com')->firstOrFail();

        $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'check path setup',
        ])->assertOk();

        $env = json_decode(File::get($logPath), true);
        $expectedHome = base_path($relativeUsersPath).'/'.$user->id;
        $this->assertSame($expectedHome, $env['HERMES_HOME']);
        $this->assertStringStartsWith($expectedHome.'/tmp/bean-tool-context-', $env['BEAN_TOOL_CONTEXT']);
        $this->assertFileExists($env['BEAN_TOOL_CONTEXT']);
    }

    public function test_elevenlabs_conversation_token_requires_agent_configuration(): void
    {
        config([
            'services.elevenlabs.agent_enabled' => true,
            'services.elevenlabs.api_key' => null,
            'services.elevenlabs.agent_id' => 'agent_test',
        ]);
        $token = $this->apiToken('bean-elevenlabs-missing-key@example.com');
        Http::fake();

        $this->withToken($token)->postJson('/api/bean/elevenlabs/conversation-token')
            ->assertStatus(503)
            ->assertJsonPath('message', 'ElevenLabs Agent voice is not configured.');

        Http::assertNothingSent();
    }

    public function test_elevenlabs_conversation_token_mints_agent_session_without_bridge_state(): void
    {
        config([
            'services.elevenlabs.agent_enabled' => true,
            'services.elevenlabs.api_key' => 'test-elevenlabs-key',
            'services.elevenlabs.agent_id' => 'agent_test',
        ]);
        $token = $this->apiToken('bean-elevenlabs-token@example.com');
        $user = User::where('email', 'bean-elevenlabs-token@example.com')->firstOrFail();
        $session = app(BeanRuntimeService::class)->createSession($user, null, 'America/New_York');
        $this->assertSame('America/New_York', $user->refresh()->timezone);
        Task::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'context task today',
            'type' => 'todo',
            'status' => 'open',
            'due_at' => now()->timezone('America/New_York')->endOfDay()->utc(),
        ]);
        Reminder::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'context reminder today',
            'status' => 'scheduled',
            'remind_at' => now()->timezone('America/New_York')->addHour()->utc(),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'context event today',
            'status' => 'scheduled',
            'starts_at' => now()->timezone('America/New_York')->addHours(2)->utc(),
            'ends_at' => now()->timezone('America/New_York')->addHours(3)->utc(),
        ]);
        CalendarEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'context event this Saturday',
            'status' => 'scheduled',
            'starts_at' => now()->timezone('America/New_York')->next(Carbon::SATURDAY)->setTime(12, 0)->utc(),
            'ends_at' => now()->timezone('America/New_York')->next(Carbon::SATURDAY)->setTime(18, 0)->utc(),
        ]);

        Http::fake(function (HttpRequest $request) {
            $this->assertSame('https://api.elevenlabs.io/v1/convai/conversation/token?agent_id=agent_test&participant_name=bean-user-1', $request->url());
            $this->assertSame('test-elevenlabs-key', $request->header('xi-api-key')[0] ?? null);

            return Http::response(['token' => 'convai_test_token'], 200);
        });

        $this->withToken($token)->postJson('/api/bean/elevenlabs/conversation-token', [
            'session_id' => $session->id,
            'client_timezone' => 'America/New_York',
        ])->assertOk()
            ->assertJsonPath('data.token', 'convai_test_token')
            ->assertJsonPath('data.agent_id', 'agent_test')
            ->assertJsonPath('data.transport', 'elevenlabs_agent')
            ->assertJsonPath('data.bean_session_id', $session->id)
            ->assertJsonPath('data.dashboard_context.today.tasks.0.title', 'context task today')
            ->assertJsonPath('data.dashboard_context.today.reminders.0.title', 'context reminder today')
            ->assertJsonPath('data.dashboard_context.today.calendar_events.0.title', 'context event today')
            ->assertJsonPath('data.dashboard_context.timezone', 'America/New_York')
            ->assertJsonPath('data.dashboard_context.upcoming.horizon_days', 31)
            ->assertJsonPath('data.dashboard_context.upcoming.calendar_events.1.title', 'context event this Saturday')
            ->assertJsonPath('data.dashboard_context.upcoming.calendar_events.1.starts_at_local', now()->timezone('America/New_York')->next(Carbon::SATURDAY)->setTime(12, 0)->toIso8601String())
            ->assertJsonPath('data.dashboard_context.policy.answer_from_context_for_read_only', true)
            ->assertJsonPath('data.dashboard_context.policy.call_askBean_when_missing_or_mutating', true)
            ->assertJsonMissingPath('data.bridge_session_id');

        $this->assertDatabaseCount('bean_sessions', 1);
        Http::assertSentCount(1);
    }

    public function test_dashboard_context_uses_user_timezone_and_does_not_cap_time_sensitive_resources(): void
    {
        $this->apiToken('bean-dashboard-context-unlimited@example.com');
        $user = User::where('email', 'bean-dashboard-context-unlimited@example.com')->firstOrFail();
        $user->forceFill(['timezone' => 'America/Los_Angeles'])->save();
        $session = app(BeanRuntimeService::class)->createSession($user->refresh(), null, 'America/New_York');
        $workspaceId = $session->workspace_id;
        $today = now('UTC')->timezone('America/Los_Angeles');

        foreach (range(1, 9) as $index) {
            Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "context today task {$index}",
                'type' => 'todo',
                'status' => 'open',
                'due_at' => $today->copy()->setTime(20, 0)->addMinutes($index)->utc(),
            ]);
            Reminder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "context today reminder {$index}",
                'status' => 'scheduled',
                'remind_at' => $today->copy()->setTime(21, 0)->addMinutes($index)->utc(),
            ]);
            CalendarEvent::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "context today event {$index}",
                'status' => 'scheduled',
                'starts_at' => $today->copy()->setTime(22, 0)->addMinutes($index)->utc(),
                'ends_at' => $today->copy()->setTime(23, 0)->addMinutes($index)->utc(),
            ]);
            Task::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "context overdue task {$index}",
                'type' => 'todo',
                'status' => 'open',
                'due_at' => $today->copy()->subDay()->setTime(17, 0)->addMinutes($index)->utc(),
            ]);
        }

        foreach (range(1, 21) as $index) {
            Reminder::create([
                'user_id' => $user->id,
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'title' => "context upcoming reminder {$index}",
                'status' => 'scheduled',
                'remind_at' => $today->copy()->addDays(($index % 31) + 1)->setTime(9, 0)->utc(),
            ]);
        }

        $context = app(DashboardContextBuilder::class)->build($user->refresh(), $session, 'America/New_York');

        $this->assertSame('America/Los_Angeles', $context['timezone']);
        $this->assertSame(31, $context['upcoming']['horizon_days']);
        $this->assertCount(9, $context['today']['tasks']);
        $this->assertCount(9, $context['today']['reminders']);
        $this->assertCount(9, $context['today']['calendar_events']);
        $this->assertCount(9, $context['overdue']['tasks']);
        $this->assertGreaterThanOrEqual(21, count($context['upcoming']['reminders']));
    }

    private function signedToolContext(User $user, BeanSession $session, BeanRun $run): array
    {
        return $this->signedToolContextForTimezone($user, $session, $run, 'America/Chicago');
    }

    private function signedToolContextForTimezone(User $user, BeanSession $session, BeanRun $run, string $timezone): array
    {
        $context = [
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'workspace_id' => $session->workspace_id,
            'client_timezone' => $timezone,
            'expires_at' => time() + 300,
        ];
        $context['signature'] = app(BeanDashboardToolBridgeService::class)->sign($context);

        return $context;
    }
}
