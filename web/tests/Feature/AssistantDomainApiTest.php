<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class AssistantDomainApiTest extends TestCase
{
    use RefreshDatabase;

    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempDir = sys_get_temp_dir().'/assistant-domain-api-test-'.bin2hex(random_bytes(6));
        File::makeDirectory($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempDir) && File::isDirectory($this->tempDir)) {
            File::deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_personal_assistant_domain_resources_can_be_created_via_api(): void
    {
        $token = $this->apiToken();

        $taskResponse = $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Replace air filter',
            'type' => 'maintenance',
            'status' => 'open',
            'notes' => 'Use the garage filter',
            'due_at' => '2026-05-12T09:00:00Z',
        ])->assertCreated();

        $taskResponse->assertJsonPath('data.type', 'maintenance')
            ->assertJsonPath('data.status', 'open');

        $this->withToken($token)->postJson('/api/tasks', [
            'title' => 'Invalid task type',
            'type' => 'errand',
        ])->assertUnprocessable();

        $this->withToken($token)->postJson('/api/reminders', [
            'title' => 'Take out bins',
            'remind_at' => '2026-05-11T18:30:00Z',
        ])->assertCreated()
            ->assertJsonPath('data.title', 'Take out bins');

        $this->withToken($token)->postJson('/api/calendar-events', [
            'title' => 'Dentist',
            'starts_at' => '2026-05-14T15:00:00Z',
            'ends_at' => '2026-05-14T16:00:00Z',
            'location' => 'Main Street',
        ])->assertCreated()
            ->assertJsonPath('data.location', 'Main Street');

        $this->withToken($token)->postJson('/api/approvals', [
            'title' => 'Confirm booking',
            'status' => 'pending',
            'payload' => ['provider' => 'server_hermes'],
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending');

        $this->withToken($token)->postJson('/api/blockers', [
            'reason' => 'Needs user credentials',
            'status' => 'open',
            'context' => ['service' => 'calendar'],
        ])->assertCreated()
            ->assertJsonPath('data.reason', 'Needs user credentials');

        $this->withToken($token)->postJson('/api/scheduler-jobs', [
            'name' => 'daily-review',
            'status' => 'queued',
            'scheduled_for' => '2026-05-11T07:00:00Z',
            'payload' => ['timezone' => 'America/Los_Angeles'],
        ])->assertCreated()
            ->assertJsonPath('data.name', 'daily-review');

        $this->assertDatabaseHas('tasks', ['title' => 'Replace air filter', 'type' => 'maintenance']);
        $this->assertDatabaseHas('reminders', ['title' => 'Take out bins']);
        $this->assertDatabaseHas('calendar_events', ['title' => 'Dentist']);
        $this->assertDatabaseHas('approvals', ['title' => 'Confirm booking']);
        $this->assertDatabaseHas('blockers', ['reason' => 'Needs user credentials']);
        $this->assertDatabaseHas('scheduler_job_records', ['name' => 'daily-review']);
    }

    public function test_activity_events_can_be_polled_for_a_session(): void
    {
        $this->configureFakeHermes(<<<'PHP'
#!/usr/bin/env php
<?php
echo json_encode(['message' => 'Planning complete.'], JSON_THROW_ON_ERROR);
PHP);

        $token = $this->apiToken();

        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions', [
            'title' => 'Morning planning',
        ])->assertCreated()->json('data.id');

        $this->withToken($token)->postJson("/api/assistant/sessions/{$sessionId}/messages", [
            'content' => 'Plan my day',
        ])->assertCreated();

        $this->withToken($token)->getJson("/api/assistant/sessions/{$sessionId}/events")
            ->assertOk()
            ->assertJsonPath('data.0.event_type', 'runtime.session_started')
            ->assertJsonFragment(['event_type' => 'runtime.message_received'])
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_started'])
            ->assertJsonFragment(['event_type' => 'runtime.hermes_cli_completed']);
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
