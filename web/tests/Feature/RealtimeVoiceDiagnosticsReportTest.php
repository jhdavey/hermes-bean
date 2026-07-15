<?php

namespace Tests\Feature;

use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceTurn;
use App\Services\RealtimeVoiceDiagnosticsReportService;
use App\Services\RealtimeVoiceSessionService;
use App\Services\VoiceTurnLifecycleService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RealtimeVoiceDiagnosticsReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
    }

    public function test_admin_report_is_scoped_transcript_free_and_reports_server_owned_milestones(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC'));
        $token = $this->apiToken('realtime-diagnostics-admin@example.com');
        $admin = User::query()->where('email', 'realtime-diagnostics-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true])->save();
        $conversation = ConversationSession::query()->where('user_id', $admin->id)->firstOrFail();
        $sessions = app(RealtimeVoiceSessionService::class);
        $realtime = $sessions->createPending($admin, $conversation, 'gpt-realtime-test', 'alloy', 1);
        $realtime = $sessions->bindProviderCall($realtime, 'call_diagnostics_1');
        $leased = $sessions->acquireLease($realtime, 'diagnostics-daemon', 30);
        $this->assertNotNull($leased);
        $realtime = $sessions->markReady($leased, 'diagnostics-daemon');
        $lifecycle = app(VoiceTurnLifecycleService::class);
        $turn = $lifecycle->preAdmitRealtime($admin, $conversation, $realtime, [
            'turn_id' => 'diagnostic-audio-native-turn-0001',
            'controller_generation' => 1,
            'provider_connection_generation' => 1,
            'input_generation' => 0,
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
            'client_milestones' => ['wake_detected_at_ms' => 100],
        ]);
        $turn = $lifecycle->bindRealtimeInputItem($turn, $realtime, 'diagnostic_input_1');
        $lifecycle->prepareRealtimeInterpretation(
            $turn,
            'A sanitized meaning summary that must not appear in diagnostics.',
            'diagnostic_input_1',
            'diagnostic_response_1',
        );
        $this->assertSame(1, VoiceTurn::query()->where('source', 'browser_voice_realtime')->count());

        $response = $this->withToken($token)->getJson('/api/admin/voice-quality?from=2026-07-15&to=2026-07-15')
            ->assertOk()
            ->assertJsonPath('data.browser_voice_realtime.summary.turn_count', 1)
            ->assertJsonPath('data.browser_voice_realtime.summary.raw_audio_retained_count', 0)
            ->assertJsonPath('data.browser_voice_realtime.turns.0.turn_id', $turn->turn_id)
            ->assertJsonPath('data.browser_voice_realtime.turns.0.realtime_session_id', $realtime->public_id)
            ->assertJsonPath('data.browser_voice_realtime.turns.0.provider_call_id', 'call_diagnostics_1')
            ->assertJsonPath('data.browser_voice_realtime.turns.0.provider_input_item_id', 'diagnostic_input_1')
            ->assertJsonPath('data.browser_voice_realtime.turns.0.display_mode', 'voice_only')
            ->assertJsonPath('data.browser_voice_realtime.latency_metrics.pre_admission_to_input_binding.sample_count', 1);

        $json = $response->getContent();
        $this->assertStringNotContainsString('A sanitized meaning summary', $json);
        $this->assertStringNotContainsString('sanitized_transcript', $json);
        $this->assertStringNotContainsString('final_delivered_at', $json);
    }

    public function test_report_is_admin_only_and_validates_bounded_windows(): void
    {
        $adminToken = $this->apiToken('realtime-window-admin@example.com');
        $userToken = $this->apiToken('realtime-window-user@example.com');
        User::query()->where('email', 'realtime-window-admin@example.com')->firstOrFail()
            ->forceFill(['is_admin' => true])
            ->save();

        $this->getJson('/api/admin/voice-quality')->assertUnauthorized();
        $this->withToken($userToken)->getJson('/api/admin/voice-quality')->assertForbidden();
        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?days='.(RealtimeVoiceDiagnosticsReportService::MAX_WINDOW_DAYS + 1))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('days');

        $this->travelTo(CarbonImmutable::parse('2026-07-15 12:00:00', 'UTC'));
        $this->withToken($adminToken)->getJson('/api/admin/voice-quality?from=2026-07-14&to=2026-07-15')
            ->assertOk()
            ->assertJsonPath('data.window.from', '2026-07-14T00:00:00+00:00')
            ->assertJsonPath('data.window.to', '2026-07-15T23:59:59+00:00');
    }
}
