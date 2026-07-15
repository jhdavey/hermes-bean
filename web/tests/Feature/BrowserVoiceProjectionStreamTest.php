<?php

namespace Tests\Feature;

use App\Enums\VoiceRealtimeCommandStatus;
use App\Enums\VoiceRealtimeCommandType;
use App\Enums\VoiceTurnSideEffectStatus;
use App\Enums\VoiceTurnState;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\VoiceRealtimeCommand;
use App\Models\VoiceRealtimeSession;
use App\Models\VoiceTurn;
use App\Models\VoiceTurnEvent;
use App\Services\BrowserVoiceProjectionService;
use App\Services\RealtimeVoiceSessionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrowserVoiceProjectionStreamTest extends TestCase
{
    use RefreshDatabase;

    private const TRANSCRIPT_SECRET = 'TRANSCRIPT-SECRET-DO-NOT-PROJECT';

    private const SEMANTIC_SECRET = 'SEMANTIC-SECRET-DO-NOT-PROJECT';

    private const ACKNOWLEDGEMENT_SECRET = 'ACK-SECRET-DO-NOT-PROJECT';

    private const FINAL_SECRET = 'FINAL-SECRET-DO-NOT-PROJECT';

    private const CLARIFICATION_SECRET = 'CLARIFICATION-SECRET-DO-NOT-PROJECT';

    private const FAILURE_SECRET = 'FAILURE-SECRET-DO-NOT-PROJECT';

    private const PLAYBACK_CAPABILITY = 'ProjectionPlaybackCapability0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ';

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('features.browser_voice_v2', true);
        config()->set('services.voice_realtime.sse_stream_seconds', 0.12);
        config()->set('services.voice_realtime.sse_heartbeat_seconds', 0.04);
        config()->set('services.voice_realtime.sse_poll_seconds', 0.02);
    }

    public function test_projection_is_voice_only_and_exposes_only_safe_state_events_jobs_and_speech_binding(): void
    {
        $fixture = $this->fixture('voice-projection-safe@example.com');

        $projection = app(BrowserVoiceProjectionService::class)->forSession($fixture['session'], 0);
        $serialized = json_encode($projection, JSON_THROW_ON_ERROR);

        $this->assertSame(BrowserVoiceProjectionService::SOURCE, $projection['source']);
        $this->assertArrayNotHasKey('messages', $projection);
        foreach ($this->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $serialized);
        }

        $turn = $projection['turns'][0];
        $this->assertSame('browser_voice_realtime', $turn['source']);
        $this->assertSame('voice_only', $turn['display_mode']);
        $this->assertFalse($turn['visible_in_chat']);
        $this->assertSame($fixture['realtime']->public_id, $turn['realtime_session_id']);
        $this->assertTrue($turn['acknowledgement_required']);
        $this->assertSame('provider_timeout', $turn['failure']['category']);
        $this->assertTrue($turn['clarification_pending']);
        foreach ([
            'transcript',
            'sanitized_transcript',
            'semantic_input',
            'acknowledgement_text',
            'final_text',
            'clarification',
            'user_message_id',
            'final_assistant_message_id',
        ] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $turn);
        }
        $this->assertArrayNotHasKey('message', $turn['failure']);

        $job = $projection['jobs'][0];
        $this->assertSame('Task work', $job['label']);
        $this->assertSame('app.task.update', $job['tool']);
        foreach (['handler', 'input', 'result', 'error', 'resource_lock_key'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $job);
        }

        $event = $projection['events'][1];
        $this->assertIsInt($event['cursor']);
        $this->assertSame($event['cursor'], $event['id']);
        $this->assertSame('browser_voice_realtime', $event['source']);
        $this->assertArrayNotHasKey('payload', $event);
        $this->assertSame([
            'authorization_id' => 'authorize-projection-final',
            'turn_id' => $fixture['turn']->turn_id,
            'purpose' => 'final',
            'speech_item_id' => 'speech-projection-final',
            'realtime_session_id' => $fixture['realtime']->public_id,
            'controller_generation' => 7,
            'provider_connection_generation' => 11,
            'timing' => [
                'latency_ms' => 180,
                'controller_generation' => 7,
            ],
        ], $event['metadata']);

        $authorization = $projection['speech_authorizations'][0];
        $this->assertSame('authorize-projection-final', $authorization['authorization_id']);
        $this->assertSame($fixture['turn']->turn_id, $authorization['turn_id']);
        $this->assertSame($fixture['realtime']->public_id, $authorization['realtime_session_id']);
        $this->assertSame('response.create', $authorization['type']);
        $this->assertSame('final', $authorization['purpose']);
        $this->assertSame('speech-projection-final', $authorization['speech_item_id']);
        $this->assertSame(7, $authorization['controller_generation']);
        $this->assertSame(11, $authorization['provider_connection_generation']);
        $this->assertSame(hash('sha256', self::FINAL_SECRET), $authorization['approved_text_sha256']);
        $this->assertSame(self::PLAYBACK_CAPABILITY, $authorization['playback_capability']);
        $this->assertSame($fixture['expires_at'], $authorization['expires_at']);
        $this->assertNull($authorization['provider_response_id']);
        $this->assertSame('queued', $authorization['status']);
        $this->assertTrue($authorization['authorized']);
        $this->assertFalse($authorization['consumed']);
        $this->assertTrue($authorization['single_use']);
        foreach (['payload', 'error', 'approved_text', 'approved_text_hash', 'capability_id'] as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $authorization);
        }
    }

    public function test_started_playback_removes_the_single_use_speech_authorization(): void
    {
        $fixture = $this->fixture('voice-projection-consumed@example.com');
        VoiceTurnEvent::create([
            'voice_turn_id' => $fixture['turn']->id,
            'user_id' => $fixture['user']->id,
            'workspace_id' => $fixture['session']->workspace_id,
            'conversation_session_id' => $fixture['session']->id,
            'sequence' => 3,
            'event_type' => 'final_audio_started',
            'from_state' => VoiceTurnState::AwaitingClarification->value,
            'to_state' => VoiceTurnState::AwaitingClarification->value,
            'version' => 4,
            'source' => 'browser',
            'payload' => [
                'purpose' => 'final',
                'speech_item_id' => 'speech-projection-final',
                'provider_response_id' => 'response-projection-final',
            ],
        ]);

        $authorizations = app(BrowserVoiceProjectionService::class)
            ->forTurn($fixture['turn']->fresh())['speech_authorizations'];

        $this->assertSame([], $authorizations);
    }

    public function test_expired_speech_authorization_is_not_projected(): void
    {
        $fixture = $this->fixture(
            'voice-projection-expired@example.com',
            now()->subSecond()->toIso8601String(),
        );

        $this->assertSame(
            [],
            app(BrowserVoiceProjectionService::class)
                ->forTurn($fixture['turn']->fresh())['speech_authorizations'],
        );
    }

    public function test_authenticated_sse_and_polling_return_the_identical_safe_projection_with_heartbeat(): void
    {
        $fixture = $this->fixture('voice-projection-stream@example.com');

        $this->get("/api/assistant/voice/stream?session_id={$fixture['session']->id}")
            ->assertUnauthorized();

        $otherToken = $this->apiToken('voice-projection-stream-other@example.com');
        $this->withToken($otherToken)
            ->get("/api/assistant/voice/stream?session_id={$fixture['session']->id}")
            ->assertNotFound();

        $polling = $this->withToken($fixture['token'])
            ->getJson("/api/assistant/voice/state?session_id={$fixture['session']->id}&cursor=0")
            ->assertOk()
            ->json('data');
        $stream = $this->withToken($fixture['token'])
            ->get("/api/assistant/voice/stream?session_id={$fixture['session']->id}&cursor=0")
            ->assertOk()
            ->assertHeader('Content-Type', 'text/event-stream; charset=utf-8')
            ->assertHeader('Cache-Control', 'no-store, private');
        $content = $stream->streamedContent();
        $streamedProjection = $this->firstProjection($content);

        $this->assertSame($polling, $streamedProjection);
        $this->assertStringContainsString('event: voice-state', $content);
        $this->assertStringContainsString('id: '.$polling['cursor'], $content);
        $this->assertStringContainsString(': heartbeat '.$polling['cursor'], $content);
        foreach ($this->secrets() as $secret) {
            $this->assertStringNotContainsString($secret, $content);
        }
    }

    public function test_sse_resumes_from_the_greatest_valid_last_event_id_or_cursor(): void
    {
        $fixture = $this->fixture('voice-projection-resume@example.com');
        $events = VoiceTurnEvent::query()
            ->where('voice_turn_id', $fixture['turn']->id)
            ->orderBy('id')
            ->get();
        $firstCursor = (int) $events[0]->id;
        $lastCursor = (int) $events[1]->id;

        $fromHeader = $this->withToken($fixture['token'])
            ->withHeader('Last-Event-ID', (string) $firstCursor)
            ->get("/api/assistant/voice/stream?session_id={$fixture['session']->id}&cursor=0");
        $headerProjection = $this->firstProjection($fromHeader->streamedContent());
        $this->assertSame($lastCursor, $headerProjection['cursor']);
        $this->assertCount(1, $headerProjection['events']);
        $this->assertSame($lastCursor, $headerProjection['events'][0]['cursor']);

        $fromQuery = $this->withToken($fixture['token'])
            ->withHeader('Last-Event-ID', (string) $firstCursor)
            ->get("/api/assistant/voice/stream?session_id={$fixture['session']->id}&cursor={$lastCursor}");
        $queryProjection = $this->firstProjection($fromQuery->streamedContent());
        $this->assertSame($lastCursor, $queryProjection['cursor']);
        $this->assertSame([], $queryProjection['events']);

        $this->withToken($fixture['token'])
            ->withHeader('Last-Event-ID', 'not-an-integer')
            ->getJson("/api/assistant/voice/stream?session_id={$fixture['session']->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Last-Event-ID');
        $this->withToken($fixture['token'])
            ->getJson("/api/assistant/voice/stream?session_id={$fixture['session']->id}&cursor=-1")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('cursor');
        $this->withToken($fixture['token'])
            ->withHeader('Last-Event-ID', '9007199254740992')
            ->getJson("/api/assistant/voice/stream?session_id={$fixture['session']->id}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors('Last-Event-ID');
    }

    /**
     * @return array{
     *     token:string,
     *     user:User,
     *     session:ConversationSession,
     *     realtime:VoiceRealtimeSession,
     *     turn:VoiceTurn,
     *     expires_at:string
     * }
     */
    private function fixture(string $email, ?string $expiresAt = null): array
    {
        $expiresAt ??= now()->addMinutes(2)->toIso8601String();
        $token = $this->apiToken($email);
        $user = User::query()->where('email', $email)->firstOrFail();
        $session = ConversationSession::create([
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'created_by_user_id' => $user->id,
            'title' => 'Projection fixture',
            'status' => 'active',
            'session_kind' => 'conversation',
            'last_activity_at' => now(),
        ]);
        $realtime = app(RealtimeVoiceSessionService::class)->createPending(
            $user,
            $session,
            'gpt-realtime-test',
            'cedar',
            7,
            [
                'provider_connection_generation' => 11,
                'playback_capability' => self::PLAYBACK_CAPABILITY,
                'transport' => 'webrtc_sideband',
            ],
        );
        $userMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'client_turn_id' => 'projection-turn-0001',
            'role' => 'user',
            'origin' => 'spoken_voice',
            'display_mode' => 'voice_only',
            'content' => self::TRANSCRIPT_SECRET,
            'metadata' => ['semantic_input' => self::SEMANTIC_SECRET],
        ]);
        $assistantMessage = ConversationMessage::create([
            'user_id' => $user->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'origin' => 'spoken_voice',
            'display_mode' => 'voice_only',
            'content' => self::FINAL_SECRET,
            'metadata' => ['response_text' => self::FINAL_SECRET],
        ]);
        $turn = VoiceTurn::create([
            'turn_id' => 'projection-turn-0001',
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'realtime_session_id' => $realtime->id,
            'provider_input_item_id' => 'item-projection-0001',
            'user_message_id' => $userMessage->id,
            'final_assistant_message_id' => $assistantMessage->id,
            'source' => 'browser_voice_realtime',
            'client_kind' => 'browser_voice',
            'display_mode' => 'voice_only',
            'transcript' => self::TRANSCRIPT_SECRET,
            'sanitized_transcript' => self::TRANSCRIPT_SECRET,
            'semantic_input' => self::SEMANTIC_SECRET,
            'state' => VoiceTurnState::AwaitingClarification,
            'version' => 3,
            'idempotency_key' => 'projection-turn-0001',
            'acknowledgement_required' => true,
            'acknowledgement_text' => self::ACKNOWLEDGEMENT_SECRET,
            'accepted_at' => now(),
            'started_at' => now(),
            'first_progress_at' => now(),
            'hard_deadline_at' => now()->addMinutes(5),
            'no_progress_deadline_at' => now()->addMinutes(2),
            'failure_category' => 'provider_timeout',
            'internal_failure_detail' => self::FAILURE_SECRET,
            'user_facing_failure_text' => self::FAILURE_SECRET,
            'side_effect_status' => VoiceTurnSideEffectStatus::None,
            'retry_count' => 0,
            'metadata' => [
                'origin' => 'spoken_voice',
                'display_mode' => 'voice_only',
                'controller_generation' => 7,
                'provider_connection_generation' => 11,
                'input_generation' => 13,
                'semantic_sequence' => 2,
                'awaiting_provider_input' => false,
                'clarification_sequence' => 1,
                'clarification_question' => self::CLARIFICATION_SECRET,
                'semantic_input' => self::SEMANTIC_SECRET,
                'response_text' => self::FINAL_SECRET,
                'response_directives' => [
                    'close_after_response' => false,
                    'response_expected' => true,
                ],
            ],
        ]);
        AssistantRun::create([
            'voice_turn_id' => $turn->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'source' => 'browser_voice_realtime',
            'lane' => 'app_write',
            'handler' => 'semantic.operation',
            'label' => self::TRANSCRIPT_SECRET,
            'priority' => 50,
            'resource_lock_key' => self::SEMANTIC_SECRET,
            'idempotency_key' => 'projection-turn-0001:operation',
            'hard_deadline_at' => now()->addMinutes(1),
            'last_progress_at' => now(),
            'status' => 'running',
            'input' => json_encode(['arguments' => ['query' => self::TRANSCRIPT_SECRET]], JSON_THROW_ON_ERROR),
            'metadata' => [
                'role' => 'operation',
                'semantic_operation_id' => 'update_task',
                'semantic_tool' => 'app.task.update',
                'dependency_run_ids' => [],
                'response_text' => self::FINAL_SECRET,
            ],
            'result' => ['final_text' => self::FINAL_SECRET],
            'error' => self::FAILURE_SECRET,
        ]);
        VoiceTurnEvent::create([
            'voice_turn_id' => $turn->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'sequence' => 1,
            'event_type' => 'turn_pre_admitted',
            'from_state' => null,
            'to_state' => VoiceTurnState::Accepted->value,
            'version' => 1,
            'source' => 'admission',
            'payload' => [
                'realtime_session_id' => $realtime->public_id,
                'controller_generation' => 7,
                'transcript' => self::TRANSCRIPT_SECRET,
                'semantic_input' => self::SEMANTIC_SECRET,
            ],
        ]);
        VoiceTurnEvent::create([
            'voice_turn_id' => $turn->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'sequence' => 2,
            'event_type' => 'speech_authorized',
            'from_state' => VoiceTurnState::Accepted->value,
            'to_state' => VoiceTurnState::AwaitingClarification->value,
            'version' => 3,
            'source' => 'realtime_sideband',
            'payload' => [
                'authorization_id' => 'authorize-projection-final',
                'turn_id' => $turn->turn_id,
                'purpose' => 'final',
                'speech_item_id' => 'speech-projection-final',
                'realtime_session_id' => $realtime->public_id,
                'controller_generation' => 7,
                'provider_connection_generation' => 11,
                'approved_text_sha256' => hash('sha256', self::FINAL_SECRET),
                'playback_capability' => self::PLAYBACK_CAPABILITY,
                'expires_at' => $expiresAt,
                'transcript' => self::TRANSCRIPT_SECRET,
                'semantic_input' => self::SEMANTIC_SECRET,
                'acknowledgement_text' => self::ACKNOWLEDGEMENT_SECRET,
                'final_text' => self::FINAL_SECRET,
                'clarification_question' => self::CLARIFICATION_SECRET,
                'message' => self::FAILURE_SECRET,
                'timing' => [
                    'latency_ms' => 180,
                    'controller_generation' => 7,
                    'response_text' => self::FINAL_SECRET,
                ],
            ],
        ]);
        VoiceRealtimeCommand::create([
            'voice_realtime_session_id' => $realtime->id,
            'voice_turn_id' => $turn->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'command_id' => 'authorize-projection-final',
            'command_type' => VoiceRealtimeCommandType::ResponseCreate,
            'purpose' => 'final',
            'speech_item_id' => 'speech-projection-final',
            'controller_generation' => 7,
            'approved_text_hash' => hash('sha256', self::FINAL_SECRET),
            'payload' => [
                'event_id' => 'authorize-projection-final',
                'type' => 'response.create',
                'response' => [
                    'instructions' => self::FINAL_SECRET,
                    'metadata' => [
                        'bean_command_id' => 'authorize-projection-final',
                        'authorization_id' => 'authorize-projection-final',
                        'turn_id' => $turn->turn_id,
                        'speech_item_id' => 'speech-projection-final',
                        'purpose' => 'final',
                        'realtime_session_id' => $realtime->public_id,
                        'controller_generation' => '7',
                        'provider_connection_generation' => '11',
                        'approved_text_sha256' => hash('sha256', self::FINAL_SECRET),
                        'playback_capability' => self::PLAYBACK_CAPABILITY,
                        'expires_at' => $expiresAt,
                    ],
                ],
            ],
            'status' => VoiceRealtimeCommandStatus::Queued,
            'attempts' => 0,
            'available_at' => now()->addMilliseconds(350),
            'error' => self::FAILURE_SECRET,
        ]);

        return compact('token', 'user', 'session', 'realtime', 'turn', 'expiresAt') + [
            'expires_at' => $expiresAt,
        ];
    }

    /** @return list<string> */
    private function secrets(): array
    {
        return [
            self::TRANSCRIPT_SECRET,
            self::SEMANTIC_SECRET,
            self::ACKNOWLEDGEMENT_SECRET,
            self::FINAL_SECRET,
            self::CLARIFICATION_SECRET,
            self::FAILURE_SECRET,
        ];
    }

    /** @return array<string, mixed> */
    private function firstProjection(string $content): array
    {
        $this->assertMatchesRegularExpression('/^id: \d+$/m', $content);
        $matched = preg_match('/^data: (.+)$/m', $content, $matches);
        $this->assertSame(1, $matched, $content);

        return json_decode($matches[1], true, flags: JSON_THROW_ON_ERROR);
    }
}
