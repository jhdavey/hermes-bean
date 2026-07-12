<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Models\Reminder;
use App\Models\VoiceTurn;
use App\Services\FastDomainWriteService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrowserVoiceV2JobCancellationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    public function test_canceling_one_job_reports_only_that_job_while_the_multi_run_parent_stays_visible_and_running(): void
    {
        $token = $this->apiToken('voice-v2-job-only-cancel@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'job-only-cancel-0001',
            'Check my calendar and reminders for tomorrow.',
        );
        $jobs = $turn->runs()->orderBy('id')->get();
        $canceledJob = $jobs->firstOrFail();
        $remainingJob = $jobs->last();

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $sessionId,
            'job_id' => $canceledJob->id,
        ])->assertOk()
            ->assertJsonPath('data.turn.turn_id', $turn->turn_id)
            ->assertJsonPath('data.turn.state', 'running')
            ->assertJsonPath('data.turn.visible_in_chat', true)
            ->assertJsonPath('data.canceled_job_ids', [$canceledJob->id])
            ->assertJsonPath('data.canceled_turn_ids', [])
            ->assertJsonPath('data.confirmation_text', 'Canceled.');

        $this->assertSame('cancelled', $canceledJob->fresh()->status);
        $this->assertSame('queued', $remainingJob->fresh()->status);
        $this->assertSame(VoiceTurnState::Running, $turn->fresh()->state);
        $this->assertNull($turn->fresh()->final_assistant_message_id);
    }

    public function test_job_cancellation_reconciles_a_committed_write_receipt_before_selecting_the_outcome(): void
    {
        Carbon::setTestNow('2026-07-11 12:00:00', 'America/New_York');
        $token = $this->apiToken('voice-v2-job-commit-race@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'job-commit-race-0001',
            'Create a reminder titled Call Mom for tomorrow at noon and create a note titled Packing.',
        );
        $run = $turn->runs()->where('handler', 'app.reminder.create')->firstOrFail();
        $remainingRun = $turn->runs()->whereKeyNot($run->id)->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);

        $this->assertTrue($lifecycle->claimJobExecution($run));
        $turn = $lifecycle->markProgress($turn, ['run_id' => $run->id], 'test');
        $this->assertNotNull(app(FastDomainWriteService::class)->execute($turn, $run->fresh()));
        $this->assertSame('running', $run->fresh()->status);

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $sessionId,
            'job_id' => $run->id,
        ])->assertOk()
            ->assertJsonPath('data.turn.state', 'running')
            ->assertJsonPath('data.canceled_job_ids', [])
            ->assertJsonPath('data.canceled_turn_ids', [])
            ->assertJsonPath('data.confirmation_text', 'That work had already finished, so I couldn’t cancel it.');

        $turn = $turn->fresh();
        $this->assertSame('completed', $run->fresh()->status);
        $this->assertSame('queued', $remainingRun->fresh()->status);
        $this->assertSame(VoiceTurnState::Running, $turn->state);
        $this->assertSame(VoiceTurnSideEffectStatus::Committed, $turn->side_effect_status);
        $this->assertNull($turn->final_assistant_message_id);
        $this->assertSame(1, Reminder::where('title', 'Call Mom')->count());
    }

    public function test_canceling_the_only_job_reports_both_the_job_and_terminally_canceled_parent(): void
    {
        $token = $this->apiToken('voice-v2-single-job-cancel@example.com');
        $sessionId = $this->sessionId($token);
        $turn = $this->admit(
            $token,
            $sessionId,
            'single-job-cancel-0001',
            'Create a note titled Packing.',
        );
        $run = $turn->runs()->sole();

        $this->withToken($token)->postJson('/api/assistant/voice/cancellations', [
            'session_id' => $sessionId,
            'job_id' => $run->id,
        ])->assertOk()
            ->assertJsonPath('data.turn.state', 'canceled')
            ->assertJsonPath('data.turn.visible_in_chat', false)
            ->assertJsonPath('data.canceled_job_ids', [$run->id])
            ->assertJsonPath('data.canceled_turn_ids', [$turn->turn_id])
            ->assertJsonPath('data.confirmation_text', 'Canceled.');

        $this->assertSame('cancelled', $run->fresh()->status);
        $this->assertSame(VoiceTurnState::Canceled, $turn->fresh()->state);
    }

    private function admit(string $token, int $sessionId, string $turnId, string $transcript): VoiceTurn
    {
        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            $turnId,
            $transcript,
        ))->assertCreated();

        return VoiceTurn::where('turn_id', $turnId)->firstOrFail();
    }

    /** @return array<string, mixed> */
    private function payload(int $sessionId, string $turnId, string $transcript): array
    {
        return [
            'turn_id' => $turnId,
            'session_id' => $sessionId,
            'transcript' => $transcript,
            'timezone' => 'America/New_York',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
        ];
    }

    private function sessionId(string $token): int
    {
        return (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
    }
}
