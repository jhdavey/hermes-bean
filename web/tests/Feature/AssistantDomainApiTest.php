<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantDomainApiTest extends TestCase
{
    use RefreshDatabase;

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
            'payload' => ['provider' => 'stub'],
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
            ->assertJsonFragment(['event_type' => 'tool.executed']);
    }
}
