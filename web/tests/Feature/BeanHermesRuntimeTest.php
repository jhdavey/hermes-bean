<?php

namespace Tests\Feature;

use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\Task;
use App\Models\User;
use App\Services\Bean\BeanDashboardToolBridgeService;
use App\Services\Bean\BeanRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
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
], JSON_UNESCAPED_SLASHES));
echo "Hermes handled this through the user agent.\\n";
PHP);
        chmod($fakeHermes, 0755);

        config([
            'bean.runtime_driver' => 'hermes',
            'bean.hermes.binary' => $fakeHermes,
            'bean.hermes.users_path' => $usersPath,
            'bean.hermes.provider' => 'openai',
            'bean.hermes.model' => 'gpt-test',
        ]);
        putenv('FAKE_HERMES_LOG');

        $token = $this->apiToken('bean-hermes-runtime@example.com');
        $user = User::where('email', 'bean-hermes-runtime@example.com')->firstOrFail();

        $response = $this->withToken($token)->postJson('/api/bean/messages', [
            'content' => 'remember this conversation detail',
            'client_timezone' => 'America/Chicago',
        ])->assertOk();

        $response->assertJsonPath('data.run.status', 'completed')
            ->assertJsonPath('data.run.model', 'hermes:openai/gpt-test')
            ->assertJsonFragment(['content' => 'Hermes handled this through the user agent.']);

        $home = $usersPath.'/'.$user->id;
        $this->assertDirectoryExists($home);
        $this->assertFileExists($home.'/config.yaml');
        $this->assertFileExists($home.'/skills/bean-dashboard/SKILL.md');
        $this->assertFileExists($home.'/plugins/bean-dashboard/plugin.yaml');
        $this->assertFileExists($home.'/plugins/bean-dashboard/__init__.py');
        $this->assertStringContainsString('bean_dashboard', File::get($home.'/config.yaml'));
        $this->assertStringContainsString('Do not make up private dashboard facts', File::get($home.'/skills/bean-dashboard/SKILL.md'));

        $session = BeanSession::firstOrFail();
        $this->assertSame('hermes', data_get($session->metadata, 'runtime_driver'));
        $this->assertSame($home, data_get($session->metadata, 'hermes_home'));
        $this->assertSame('bean-session-'.$session->id, data_get($session->metadata, 'hermes_session_name'));

        $log = json_decode(File::get($logPath), true);
        $this->assertSame($home, $log['HERMES_HOME']);
        $this->assertStringContainsString('bean-tool-context-', $log['BEAN_TOOL_CONTEXT']);
        $this->assertContains('--continue', $log['argv']);
        $this->assertContains('bean-session-'.$session->id, $log['argv']);
        $this->assertContains('--toolsets', $log['argv']);
        $this->assertContains('bean_dashboard,skills,memory,session_search,web', $log['argv']);
    }

    public function test_hermes_dashboard_tool_bridge_executes_scoped_laravel_actions(): void
    {
        config(['bean.runtime_driver' => 'hermes']);
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
}
