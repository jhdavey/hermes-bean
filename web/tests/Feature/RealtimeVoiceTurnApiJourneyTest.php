<?php

namespace Tests\Feature;

use App\Enums\VoiceTurnLane;
use App\Exceptions\VoiceTurnConflictException;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeSession;
use App\Models\VoiceTurn;
use App\Services\HermesSemanticOperationExecutor;
use App\Services\RealtimeVoiceSessionService;
use App\Services\VoiceTurnLifecycleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RealtimeVoiceTurnApiJourneyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        config()->set('services.voice_realtime.admission_ready_timeout_ms', 0);
        Queue::fake();
    }

    public function test_admission_fails_closed_before_sideband_readiness_without_creating_a_turn(): void
    {
        $fixture = $this->fixture('voice-not-ready@example.com', ready: false);

        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/turns', $this->admission(
                $fixture['conversation'],
                $fixture['realtime'],
                'voice-not-ready-turn-0001',
            ))
            ->assertStatus(503)
            ->assertJsonPath('code', 'voice_sideband_not_ready');

        $this->assertSame(0, VoiceTurn::query()->count());
    }

    public function test_lifecycle_rechecks_the_sideband_lease_inside_the_admission_transaction(): void
    {
        $fixture = $this->fixture('voice-lease-race@example.com');
        $fixture['realtime']->forceFill(['lease_expires_at' => now()->subSecond()])->save();

        $this->expectException(VoiceTurnConflictException::class);
        try {
            app(VoiceTurnLifecycleService::class)->preAdmitRealtime(
                $fixture['user'],
                $fixture['conversation'],
                $fixture['realtime'],
                $this->admission(
                    $fixture['conversation'],
                    $fixture['realtime'],
                    'voice-lease-race-turn-0001',
                ),
            );
        } finally {
            $this->assertSame(0, VoiceTurn::query()->count());
        }
    }

    public function test_ready_admission_and_unreleased_cancellation_complete_without_work_or_final(): void
    {
        $fixture = $this->fixture('voice-abandon-api@example.com');
        $turnId = 'voice-abandon-api-turn-0001';

        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/turns', $this->admission(
                $fixture['conversation'],
                $fixture['realtime'],
                $turnId,
            ))
            ->assertCreated()
            ->assertJsonPath('data.sideband_ready', true)
            ->assertJsonPath('data.state', 'accepted');

        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/cancellations', [
                'session_id' => $fixture['conversation']->id,
                'turn_id' => $turnId,
                'reason' => 'no_speech_detected',
            ])
            ->assertOk()
            ->assertJsonPath('data.cancellation.canceled', true)
            ->assertJsonPath('data.turn.state', 'canceled');

        $turn = VoiceTurn::query()->where('turn_id', $turnId)->sole();
        $this->assertNull($turn->final_assistant_message_id);
        $this->assertSame(0, $turn->runs()->count());
        $this->assertTrue((bool) data_get($turn->metadata, 'client_context.voice_mode_active'));
        $this->assertTrue((bool) data_get($turn->metadata, 'client_context.wake_detection_enabled'));
        $this->assertTrue((bool) data_get(
            $turn->events()->where('event_type', 'turn_canceled')->sole()->payload,
            'abandoned_before_provider_input',
        ));
    }

    public function test_spoken_stop_acknowledges_the_directive_owning_turn_without_cross_turn_speech_authorization(): void
    {
        $fixture = $this->fixture('voice-stop-api@example.com');
        $turnId = 'voice-stop-api-turn-0001';
        $this->withToken($fixture['token'])
            ->postJson('/api/assistant/voice/turns', $this->admission(
                $fixture['conversation'],
                $fixture['realtime'],
                $turnId,
            ))
            ->assertCreated();
        $turn = VoiceTurn::query()->where('turn_id', $turnId)->sole();
        $run = AssistantRun::query()->create([
            'voice_turn_id' => $turn->id,
            'user_id' => $turn->user_id,
            'workspace_id' => $turn->workspace_id,
            'conversation_session_id' => $turn->conversation_session_id,
            'user_message_id' => $turn->user_message_id,
            'source' => 'browser_voice_realtime',
            'lane' => VoiceTurnLane::Semantic->value,
            'handler' => HermesSemanticOperationExecutor::OPERATION_HANDLER,
            'label' => 'Stop playback',
            'priority' => 100,
            'idempotency_key' => $turnId.':stop',
            'hard_deadline_at' => now()->addSeconds(2),
            'last_progress_at' => now(),
            'status' => 'running',
            'input' => 'Stop playback',
            'metadata' => ['semantic_tool' => 'voice.playback.stop'],
            'queued_at' => now(),
            'started_at' => now(),
        ]);
        $directive = app(VoiceTurnLifecycleService::class)->issuePlaybackStopDirective($turn, $run);
        $payload = [
            'session_id' => $fixture['conversation']->id,
            'event' => 'playback_stopped',
            'timing' => [
                'directive_id' => $directive['id'],
                'controller_generation' => 7,
                'provider_connection_generation' => 3,
                'purpose' => 'final',
                'speech_item_id' => 'speech-owned-by-the-interrupted-turn',
                'reason' => 'semantic_stop',
            ],
        ];

        $this->withToken($fixture['token'])
            ->postJson("/api/assistant/voice/turns/{$turnId}/delivery", [
                ...$payload,
                'timing' => [...$payload['timing'], 'controller_generation' => 8],
            ])
            ->assertStatus(409);
        $this->withToken($fixture['token'])
            ->postJson("/api/assistant/voice/turns/{$turnId}/delivery", $payload)
            ->assertOk();

        $turn->refresh();
        $this->assertNotNull(data_get($turn->metadata, 'playback_stop_directive.acknowledged_at'));
        $this->assertSame(1, $turn->events()->where('event_type', 'playback_stop_directive_acknowledged')->count());
    }

    /** @return array{token:string,user:User,conversation:ConversationSession,realtime:VoiceRealtimeSession} */
    private function fixture(string $email, bool $ready = true): array
    {
        $token = $this->apiToken($email);
        $user = User::query()->where('email', $email)->firstOrFail();
        $conversation = ConversationSession::query()->where('user_id', $user->id)->firstOrFail();
        $sessions = app(RealtimeVoiceSessionService::class);
        $realtime = $sessions->createPending($user, $conversation, 'gpt-realtime-test', 'alloy', 7, [
            'provider_connection_generation' => 3,
        ]);
        $realtime = $sessions->bindProviderCall($realtime, 'call_'.str_replace('@', '_', $email));
        if ($ready) {
            $leased = $sessions->acquireLease($realtime, 'api-test-daemon', 30);
            $this->assertNotNull($leased);
            $realtime = $sessions->markReady($leased, 'api-test-daemon');
        }

        return compact('token', 'user', 'conversation', 'realtime');
    }

    /** @return array<string, mixed> */
    private function admission(
        ConversationSession $conversation,
        VoiceRealtimeSession $realtime,
        string $turnId,
    ): array {
        return [
            'turn_id' => $turnId,
            'session_id' => $conversation->id,
            'realtime_session_id' => $realtime->public_id,
            'controller_generation' => 7,
            'provider_connection_generation' => 3,
            'input_generation' => 0,
            'conversation_context' => ['mode' => 'new_conversation', 'epoch' => 1],
            'client_milestones' => [
                'wake_detected_at_ms' => 100,
                'pre_admission_started_at_ms' => 120,
                'capture_started_at_ms' => 130,
            ],
        ];
    }
}
