<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\ConversationMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VoiceChatFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_auth_me_exposes_default_realtime_voice_settings_and_available_openai_voices(): void
    {
        $token = $this->apiToken('voice-default@example.com');

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.provider', 'openai_realtime')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.voice', 'alloy')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.realtime_model', 'gpt-realtime')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.available_voices.0.key', 'alloy')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.available_voices.0.label', 'Alloy');
    }

    public function test_user_can_update_bean_voice_preference_per_active_workspace(): void
    {
        $token = $this->apiToken('voice-update@example.com');
        $user = User::where('email', 'voice-update@example.com')->firstOrFail();

        $this->withToken($token)->patchJson('/api/auth/me', [
            'voice' => 'nova',
        ])->assertOk()
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.provider', 'openai_realtime')
            ->assertJsonPath('data.active_workspace_agent_profile.settings.voice.voice', 'nova');

        $profile = AgentProfile::where('workspace_id', $user->fresh()->default_workspace_id)->firstOrFail();
        $this->assertSame('nova', data_get($profile->settings, 'voice.voice'));

        $this->withToken($token)->patchJson('/api/auth/me', [
            'voice' => 'not-a-real-openai-voice',
        ])->assertUnprocessable();
    }

    public function test_authenticated_user_can_create_openai_realtime_voice_session_without_exposing_server_key(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.openai.realtime_model', 'gpt-realtime-test');
        config()->set('services.openai.realtime_webrtc_url', 'https://api.openai.test/v1/realtime/calls');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('voice-realtime@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', ['voice' => 'shimmer'])->assertOk();

        Http::fake([
            'api.openai.test/v1/realtime/client_secrets' => Http::response([
                'value' => 'ephemeral-client-secret',
                'expires_at' => 1893456000,
                'session' => [
                    'id' => 'sess_test_123',
                ],
            ], 200),
        ]);

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', [
            'timezone' => 'America/New_York',
        ])->assertOk()
            ->assertJsonPath('data.provider', 'openai_realtime')
            ->assertJsonPath('data.model', 'gpt-realtime-test')
            ->assertJsonPath('data.voice', 'shimmer')
            ->assertJsonPath('data.client_secret', 'ephemeral-client-secret')
            ->assertJsonPath('data.session_id', 'sess_test_123')
            ->assertJsonPath('data.realtime_url', 'https://api.openai.test/v1/realtime/calls')
            ->assertJsonMissing(['test-openai-key']);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/realtime/client_secrets'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && data_get($payload, 'session.type') === 'realtime'
                && data_get($payload, 'session.model') === 'gpt-realtime-test'
                && data_get($payload, 'session.audio.output.voice') === 'shimmer'
                && data_get($payload, 'session.audio.input.turn_detection.type') === 'server_vad'
                && data_get($payload, 'session.audio.input.turn_detection.create_response') === false
                && data_get($payload, 'session.audio.input.turn_detection.interrupt_response') === true
                && collect(data_get($payload, 'session.tools', []))->contains(fn (array $tool): bool => $tool['name'] === 'send_bean_request')
                && str_contains((string) data_get($payload, 'session.instructions'), 'Hey Bean')
                && str_contains((string) data_get($payload, 'session.instructions'), 'remain wake-only')
                && str_contains((string) data_get($payload, 'session.instructions'), 'current external information');
        });
    }

    public function test_realtime_tool_bridge_validates_supported_laravel_tool_calls(): void
    {
        $token = $this->apiToken('voice-tool@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/tool', [
            'name' => 'send_bean_request',
            'arguments' => [
                'request' => 'Mark preschool tuition done.',
                'reason' => 'Task completion needs Laravel tools.',
            ],
        ])->assertOk()
            ->assertJsonPath('data.approved', true)
            ->assertJsonPath('data.route', 'assistant_runs')
            ->assertJsonPath('data.request', 'Mark preschool tuition done.');

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/tool', [
            'name' => 'unsupported_tool',
            'arguments' => ['request' => 'anything'],
        ])->assertUnprocessable();
    }

    public function test_realtime_turn_endpoint_persists_voice_transcript_without_running_rest_tts_or_transcription(): void
    {
        $token = $this->apiToken('voice-turn@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            'session_id' => $sessionId,
            'user_text' => 'Hey Bean, what is today?',
            'assistant_text' => 'Today is Wednesday.',
        ])->assertCreated()
            ->assertJsonPath('data.user_message.role', 'user')
            ->assertJsonPath('data.assistant_message.role', 'assistant')
            ->assertJsonPath('data.assistant_message.metadata.source', 'openai_realtime_voice')
            ->assertJsonPath('data.assistant_message.metadata.voice_request', true);

        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'user',
            'content' => 'Hey Bean, what is today?',
        ]);
        $this->assertDatabaseHas('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
            'content' => 'Today is Wednesday.',
        ]);
        $this->assertSame(2, ConversationMessage::where('conversation_session_id', $sessionId)->count());
    }

    public function test_realtime_turn_persistence_is_atomic_and_idempotent_for_a_client_turn(): void
    {
        $token = $this->apiToken('voice-turn-idempotent@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $payload = [
            'session_id' => $sessionId,
            'user_text' => 'Hey Bean, give me a short greeting.',
            'assistant_text' => 'Good morning!',
            'metadata' => [
                'client_request_id' => 'web-realtime-stable-turn-1',
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'direct',
                    'transcript_to_audio_start_ms' => 640,
                ],
            ],
        ];

        $first = $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', $payload)
            ->assertCreated()
            ->assertJsonPath('data.user_message.client_turn_id', 'web-realtime-stable-turn-1')
            ->assertJsonPath('data.assistant_message.client_turn_id', 'web-realtime-stable-turn-1')
            ->assertJsonPath('data.assistant_message.metadata.voice_quality.route', 'direct')
            ->assertJsonPath('data.assistant_message.metadata.voice_quality.transcript_to_audio_start_ms', 640);

        $second = $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', $payload)
            ->assertOk();

        $this->assertSame($first->json('data.user_message.id'), $second->json('data.user_message.id'));
        $this->assertSame($first->json('data.assistant_message.id'), $second->json('data.assistant_message.id'));
        $this->assertSame(2, ConversationMessage::where('conversation_session_id', $sessionId)->count());
        $this->assertDatabaseCount('conversation_messages', 2);
    }

    public function test_realtime_turn_persists_acceptance_and_terminal_interruption_without_an_assistant_message(): void
    {
        $token = $this->apiToken('voice-turn-interrupted@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $base = [
            'session_id' => $sessionId,
            'user_text' => 'Tell me a short story.',
            'metadata' => [
                'client_turn_id' => 'web-realtime-interrupted-1',
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'direct',
                ],
            ],
        ];

        $accepted = $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$base,
            'outcome' => 'accepted',
        ])->assertCreated()
            ->assertJsonPath('data.outcome', 'accepted')
            ->assertJsonPath('data.assistant_message', null)
            ->assertJsonPath('data.user_message.metadata.voice_turn_outcome.status', 'accepted');

        $interrupted = $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$base,
            'outcome' => 'interrupted',
            'failure_reason' => 'barge_in',
            'metadata' => [
                ...$base['metadata'],
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'direct',
                    'response_duration_ms' => 310,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'interrupted')
            ->assertJsonPath('data.assistant_message', null)
            ->assertJsonPath('data.user_message.metadata.voice_turn_outcome.status', 'interrupted')
            ->assertJsonPath('data.user_message.metadata.voice_turn_outcome.reason', 'barge_in');

        $this->assertSame($accepted->json('data.user_message.id'), $interrupted->json('data.user_message.id'));
        $this->assertDatabaseCount('conversation_messages', 1);
        $this->assertDatabaseMissing('conversation_messages', [
            'conversation_session_id' => $sessionId,
            'role' => 'assistant',
        ]);

        // A late provider completion cannot turn an already interrupted response into a
        // completed durable assistant answer.
        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$base,
            'outcome' => 'completed',
            'assistant_text' => 'This answer arrived too late.',
            'metadata' => [
                ...$base['metadata'],
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'direct',
                    'response_duration_ms' => 999,
                ],
            ],
        ])->assertOk()
            ->assertJsonPath('data.outcome', 'interrupted')
            ->assertJsonPath('data.assistant_message', null)
            ->assertJsonPath('data.user_message.metadata.voice_quality.response_duration_ms', 310)
            ->assertJsonPath('data.user_message.metadata.voice_turn_outcome.reason', 'barge_in');
        $this->assertDatabaseCount('conversation_messages', 1);
    }

    public function test_realtime_turn_attaches_assistant_text_only_after_completed_playback(): void
    {
        $token = $this->apiToken('voice-turn-completed-lifecycle@example.com');
        $sessionId = $this->withToken($token)->postJson('/api/assistant/sessions')
            ->assertCreated()
            ->json('data.id');
        $base = [
            'session_id' => $sessionId,
            'user_text' => 'Give me a short greeting.',
            'metadata' => [
                'client_turn_id' => 'web-realtime-completed-1',
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'status',
                ],
            ],
        ];

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$base,
            'outcome' => 'accepted',
        ])->assertCreated();

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/turn', [
            ...$base,
            'outcome' => 'completed',
            'assistant_text' => 'Good morning!',
            'metadata' => [
                ...$base['metadata'],
                'voice_quality' => [
                    'schema_version' => 1,
                    'route' => 'status',
                    'transcript_to_audio_start_ms' => 620,
                ],
            ],
        ])->assertCreated()
            ->assertJsonPath('data.outcome', 'completed')
            ->assertJsonPath('data.assistant_message.content', 'Good morning!')
            ->assertJsonPath('data.assistant_message.metadata.voice_turn_outcome.status', 'completed')
            ->assertJsonPath('data.user_message.metadata.voice_quality.transcript_to_audio_start_ms', 620);

        $this->assertDatabaseCount('conversation_messages', 2);
    }
}
