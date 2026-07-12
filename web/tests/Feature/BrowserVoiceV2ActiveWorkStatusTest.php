<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnState;
use App\Models\VoiceTurn;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class BrowserVoiceV2ActiveWorkStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        Queue::fake();
    }

    public function test_generic_status_ignores_an_interjected_instant_turn_and_reports_the_active_background_request(): void
    {
        $token = $this->apiToken('voice-v2-active-status-after-instant@example.com');
        $sessionId = (int) $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'active-status-background-0001',
            'Create a detailed seven-day travel plan.',
        ))->assertCreated();
        $background = VoiceTurn::where('turn_id', 'active-status-background-0001')->firstOrFail();
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $this->assertTrue($lifecycle->claimJobExecution(
            $background->runs()->firstOrFail(),
        ));
        $lifecycle->markProgress($background, ['phase' => 'background_work_started'], 'test');
        $this->assertSame(VoiceTurnState::Running, $background->fresh()->state);

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'active-status-instant-0001',
            'What time is it?',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'instant.current_time')
            ->assertJsonPath('data.turn.state', 'completed');

        $this->withToken($token)->postJson('/api/assistant/voice/turns', $this->payload(
            $sessionId,
            'active-status-question-0001',
            'Did you finish that?',
        ))->assertCreated()
            ->assertJsonPath('data.turn.handler', 'app.voice_work.status')
            ->assertJsonPath('data.turn.state', 'completed')
            ->assertJsonPath('data.turn.final_text', 'I’m still working on the request.');
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
}
