<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AiUsageAlert;
use App\Models\AiUsageLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function test_authenticated_user_can_create_transcription_and_speech_only_realtime_call_through_same_origin(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.openai.realtime_model', 'gpt-realtime-test');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('voice-realtime@example.com');

        $this->withToken($token)->patchJson('/api/auth/me', ['voice' => 'shimmer'])->assertOk();

        Http::fake([
            'api.openai.test/v1/realtime/calls' => Http::response(
                "v=0\r\no=- 123 456 IN IP4 127.0.0.1\r\n",
                201,
                ['Content-Type' => 'application/sdp', 'Location' => '/v1/realtime/calls/call_test_123'],
            ),
        ]);

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', [
            'timezone' => 'America/New_York',
            'sdp' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n",
        ])->assertOk()
            ->assertJsonPath('data.provider', 'openai_realtime')
            ->assertJsonPath('data.model', 'gpt-realtime-test')
            ->assertJsonPath('data.voice', 'shimmer')
            ->assertJsonPath('data.sdp', "v=0\r\no=- 123 456 IN IP4 127.0.0.1\r\n")
            ->assertJsonPath('data.session_id', 'call_test_123')
            ->assertJsonStructure(['data' => ['usage_session_id']])
            ->assertJsonPath('data.tools', [])
            ->assertJsonMissing(['test-openai-key', 'client_secret', 'realtime_url']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => User::where('email', 'voice-realtime@example.com')->firstOrFail()->id,
            'request_type' => 'voice_realtime_session',
            'status' => 'opened',
        ]);

        Http::assertSent(function ($request): bool {
            $body = $request->body();
            preg_match('/name="sdp"\r\nContent-Type: application\/sdp\r\n\r\n(.*?)\r\n--/s', $body, $sdpMatch);
            preg_match('/name="session"\r\nContent-Type: application\/json\r\n\r\n(.*?)\r\n--/s', $body, $sessionMatch);
            $sdp = (string) ($sdpMatch[1] ?? '');
            $session = json_decode((string) ($sessionMatch[1] ?? ''), true);

            return $request->url() === 'https://api.openai.test/v1/realtime/calls'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && $request->hasHeader('OpenAI-Safety-Identifier')
                && str_starts_with((string) $request->header('Content-Type')[0], 'multipart/form-data; boundary=----BeanRealtime')
                && str_ends_with($sdp, "\r\n")
                && str_contains($sdp, 'm=audio 9 UDP/TLS/RTP/SAVPF 111')
                && data_get($session, 'type') === 'realtime'
                && data_get($session, 'model') === 'gpt-realtime-test'
                && data_get($session, 'audio.output.voice') === 'shimmer'
                && data_get($session, 'audio.input.format.type') === 'audio/pcm'
                && data_get($session, 'audio.input.format.rate') === 24000
                && data_get($session, 'audio.input.transcription.model') === 'gpt-4o-mini-transcribe'
                && data_get($session, 'audio.input.transcription.language') === 'en'
                && data_get($session, 'audio.input.turn_detection.silence_duration_ms') === 2000
                && data_get($session, 'audio.input.turn_detection.create_response') === false
                && data_get($session, 'audio.input.turn_detection.interrupt_response') === false
                && str_contains((string) data_get($session, 'instructions'), 'US English transcription and speech surface')
                && str_contains((string) data_get($session, 'instructions'), 'Never call tools or independently answer microphone input');
        });
    }

    public function test_realtime_provider_failure_is_terminal_sanitized_and_does_not_open_usage_session(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        Http::fake([
            'api.openai.test/v1/realtime/calls' => Http::response(['error' => ['message' => 'provider detail']], 503),
        ]);
        Log::spy();
        $token = $this->apiToken('voice-realtime-failure@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', [
            'sdp' => "v=0\r\nm=audio 9 UDP/TLS/RTP/SAVPF 111\r\n",
        ])->assertStatus(502)
            ->assertJsonPath('error.code', 'realtime_connection_failed')
            ->assertJsonPath('message', 'Bean couldn’t connect voice right now. Tap Bean to try again.')
            ->assertJsonMissing(['provider detail', 'test-openai-key']);

        $this->assertDatabaseMissing('ai_usage_logs', [
            'user_id' => User::where('email', 'voice-realtime-failure@example.com')->firstOrFail()->id,
            'request_type' => 'voice_realtime_session',
        ]);
        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Browser Voice v2 provider connection failed.'
                && $context['stage'] === 'realtime_sdp',
        );
    }

    public function test_authenticated_browser_synthesizes_the_exact_server_owned_text_and_meters_it_once(): void
    {
        config()->set('services.openai.server_api_key', 'test-openai-key');
        config()->set('services.openai.speech_model', 'tts-1-test');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        $token = $this->apiToken('voice-speech@example.com');
        $text = 'Done—I created the note “Meal Plans” with five recipes.';
        Http::fake([
            'api.openai.test/v1/audio/speech' => Http::response('ID3-fake-mp3', 200, ['Content-Type' => 'audio/mpeg']),
        ]);
        $payload = [
            'turn_id' => 'speech-clarification-turn-0001',
            'speech_item_id' => 'speech-clarification-turn-0001:clarification',
            'purpose' => 'clarification',
            'text' => $text,
        ];

        $first = $this->withToken($token)->postJson('/api/assistant/voice/speech', $payload);
        $first->assertOk()
            ->assertHeader('Content-Type', 'audio/mpeg')
            ->assertHeader('X-Bean-Speech-Text-Sha256', hash('sha256', $text));
        $this->assertSame('ID3-fake-mp3', $first->getContent());
        $this->withToken($token)->postJson('/api/assistant/voice/speech', $payload)->assertOk();

        Http::assertSent(fn ($request): bool => $request->url() === 'https://api.openai.test/v1/audio/speech'
            && $request['model'] === 'tts-1-test'
            && $request['input'] === $text
            && $request['response_format'] === 'mp3'
            && $request->hasHeader('Authorization', 'Bearer test-openai-key')
            && $request->hasHeader('OpenAI-Safety-Identifier'));
        $user = User::where('email', 'voice-speech@example.com')->firstOrFail();
        $this->assertSame(1, AiUsageLog::where('user_id', $user->id)->where('request_type', 'voice_speech')->count());
        $usage = AiUsageLog::where('user_id', $user->id)->where('request_type', 'voice_speech')->firstOrFail();
        $this->assertSame(mb_strlen($text), data_get($usage->metadata, 'characters'));
        $this->assertSame(['speech_synthesis'], $usage->action_types);
    }

    public function test_final_speech_rejects_text_that_does_not_match_the_durable_server_final(): void
    {
        $token = $this->apiToken('voice-speech-mismatch@example.com');
        Http::fake();

        $this->withToken($token)->postJson('/api/assistant/voice/speech', [
            'turn_id' => 'missing-final-turn-0001',
            'speech_item_id' => 'missing-final-turn-0001:final',
            'purpose' => 'final',
            'text' => 'I cannot create notes.',
        ])->assertStatus(409)
            ->assertJsonPath('error.code', 'voice_speech_text_mismatch');

        Http::assertNothingSent();
    }

    public function test_base_premium_and_pro_voice_sessions_stop_at_each_users_daily_plan_limit_while_admin_remains_unlimited(): void
    {
        config()->set('services.ai_usage.realtime_session_minimum_cost_usd', 0.001);
        config()->set('services.ai_usage.limits.base_cost_limit', 0.01);
        config()->set('services.ai_usage.limits.premium_cost_limit', 0.02);
        config()->set('services.ai_usage.limits.pro_cost_limit', 0.03);
        Http::fake(['*' => Http::response("v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\n", 201)]);

        foreach (['base' => 0.01, 'premium' => 0.02, 'pro' => 0.03] as $tier => $cost) {
            $email = "voice-limit-{$tier}@example.com";
            $token = $this->apiToken($email);
            $user = User::where('email', $email)->firstOrFail();
            $user->forceFill(['subscription_tier' => $tier])->save();
            $this->completedUsage($user, $cost);

            $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', ['sdp' => 'v=0'])
                ->assertStatus(402)
                ->assertJsonPath('error.code', 'subscription_limit_reached')
                ->assertJsonPath('error.cta_label', 'View plans')
                ->assertJsonPath('error.plan_tier', $tier)
                ->assertJsonPath('message', 'You’ve reached today’s AI usage limit for your current plan. Upgrade for more voice usage, or try again tomorrow.');
        }

        $adminToken = $this->apiToken('voice-limit-admin@example.com');
        $admin = User::where('email', 'voice-limit-admin@example.com')->firstOrFail();
        $admin->forceFill(['is_admin' => true, 'subscription_tier' => 'base'])->save();
        $this->completedUsage($admin, 999);
        $this->withToken($adminToken)->postJson('/api/assistant/voice/realtime/session', ['sdp' => 'v=0'])
            ->assertOk()
            ->assertJsonPath('data.provider', 'openai_realtime');
    }

    public function test_realtime_usage_is_charged_once_and_returns_an_upgrade_action_when_the_event_reaches_the_plan_limit(): void
    {
        config()->set('services.ai_usage.realtime_session_minimum_cost_usd', 0.000001);
        config()->set('services.ai_usage.limits.base_cost_limit', 0.0001);
        config()->set('services.ai_usage.realtime_pricing_per_million.gpt-4o-mini-transcribe', [
            'audio_input' => 1.25,
            'text_output' => 5.00,
        ]);
        config()->set('services.openai.realtime_transcription_model', 'gpt-4o-mini-transcribe');
        Http::fake(['*' => Http::response(
            "v=0\r\no=- 1 1 IN IP4 127.0.0.1\r\n",
            201,
            ['Location' => '/v1/realtime/calls/sess_metered_123'],
        )]);
        $token = $this->apiToken('voice-metered@example.com');
        $session = $this->withToken($token)->postJson('/api/assistant/voice/realtime/session', ['sdp' => 'v=0'])->assertOk();
        $usageSessionId = $session->json('data.usage_session_id');
        $payload = [
            'usage_session_id' => $usageSessionId,
            'provider_event_id' => 'transcription:event-metered-1',
            'event_type' => 'transcription',
            'usage' => [
                'total_tokens' => 101,
                'input_tokens' => 100,
                'output_tokens' => 1,
                'input_token_details' => ['text_tokens' => 0, 'audio_tokens' => 100],
            ],
        ];

        $otherToken = $this->apiToken('voice-metered-other@example.com');
        $this->withToken($otherToken)->postJson('/api/assistant/voice/realtime/usage', $payload)
            ->assertNotFound();
        $this->withToken($token)->postJson('/api/assistant/voice/realtime/usage', $payload)
            ->assertStatus(402)
            ->assertJsonPath('error.code', 'subscription_limit_reached')
            ->assertJsonPath('error.cta_label', 'View plans')
            ->assertJsonPath('error.limit_type', 'daily_ai_usage');
        $this->withToken($token)->postJson('/api/assistant/voice/realtime/usage', $payload)
            ->assertStatus(402);

        $user = User::where('email', 'voice-metered@example.com')->firstOrFail();
        $this->assertSame(1, AiUsageLog::where('user_id', $user->id)->where('request_type', 'voice_realtime')->count());
        $usageLog = AiUsageLog::where('user_id', $user->id)->where('request_type', 'voice_realtime')->firstOrFail();
        $this->assertSame(101, $usageLog->total_tokens);
        $this->assertArrayNotHasKey('transcript', $usageLog->metadata ?? []);

        $alert = AiUsageAlert::where('user_id', $user->id)->firstOrFail();
        $this->assertSame('daily_cost_hard_limit', $alert->alert_type);
        $this->assertSame('voice_realtime', data_get($alert->metadata, 'lane'));
        $this->assertSame($usageSessionId, data_get($alert->metadata, 'usage_session_id'));
        $this->assertSame('sess_metered_123', data_get($alert->metadata, 'provider_session_id'));

        $adminToken = $this->apiToken('voice-metered-admin@example.com');
        User::where('email', 'voice-metered-admin@example.com')->firstOrFail()
            ->forceFill(['is_admin' => true])
            ->save();
        $this->withToken($adminToken)->getJson('/api/admin/usage/alerts')
            ->assertOk()
            ->assertJsonPath('data.0.user.email', 'voice-metered@example.com')
            ->assertJsonPath('data.0.metadata.lane', 'voice_realtime')
            ->assertJsonPath('data.0.metadata.usage_session_id', $usageSessionId);
    }

    public function test_authenticated_browser_can_record_a_sanitized_pre_turn_local_wake_failure(): void
    {
        Log::spy();
        $token = $this->apiToken('voice-client-failure@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/client-failures', [
            'stage' => 'local_wake',
            'code' => 'gate_open_failed',
            'message' => 'The local wake gate could not open safely.',
            'cause_chain' => [
                ['code' => 'gate_open_failed', 'message' => 'The local wake gate could not open safely.'],
                ['code' => 'transport_failed', 'message' => 'Realtime transcription disconnected.'],
            ],
        ])->assertOk()->assertJsonPath('data.recorded', true);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Browser Voice v2 client failure.'
                && $context['code'] === 'gate_open_failed'
                && count($context['cause_chain']) === 2,
        );
    }

    public function test_authenticated_browser_can_record_a_sanitized_startup_failure(): void
    {
        Log::spy();
        $token = $this->apiToken('voice-startup-failure@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/client-failures', [
            'stage' => 'startup',
            'code' => 'AbortError',
            'message' => 'signal is aborted without reason',
            'cause_chain' => [
                ['code' => 'AbortError', 'message' => 'signal is aborted without reason'],
            ],
        ])->assertOk()->assertJsonPath('data.recorded', true);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Browser Voice v2 client failure.'
                && $context['stage'] === 'startup'
                && $context['code'] === 'AbortError',
        );
    }

    public function test_authenticated_browser_can_record_a_sanitized_admission_failure(): void
    {
        Log::spy();
        $token = $this->apiToken('voice-admission-failure@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/client-failures', [
            'stage' => 'admission',
            'code' => 'AbortError',
            'message' => 'The durable turn request timed out.',
            'cause_chain' => [
                ['code' => 'AbortError', 'message' => 'The durable turn request timed out.'],
            ],
        ])->assertOk()->assertJsonPath('data.recorded', true);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Browser Voice v2 client failure.'
                && $context['stage'] === 'admission'
                && $context['code'] === 'AbortError',
        );
    }

    public function test_authenticated_browser_can_record_a_sanitized_usage_accounting_failure(): void
    {
        Log::spy();
        $token = $this->apiToken('voice-usage-accounting-failure@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/client-failures', [
            'stage' => 'usage_accounting',
            'code' => 'usage_transport_failed',
            'message' => 'Realtime usage reporting exhausted its retry schedule.',
            'cause_chain' => [[
                'code' => 'usage_transport_failed',
                'message' => 'Realtime usage reporting exhausted its retry schedule.',
            ]],
        ])->assertOk()->assertJsonPath('data.recorded', true);

        Log::shouldHaveReceived('warning')->once()->withArgs(
            fn (string $message, array $context): bool => $message === 'Browser Voice v2 client failure.'
                && $context['stage'] === 'usage_accounting'
                && $context['code'] === 'usage_transport_failed',
        );
    }

    private function completedUsage(User $user, float $cost): AiUsageLog
    {
        return AiUsageLog::create([
            'user_id' => $user->id,
            'provider' => 'openai',
            'model' => 'gpt-test',
            'route_tier' => 'test',
            'request_type' => 'text',
            'status' => 'completed',
            'estimated_cost_usd' => $cost,
        ]);
    }
}
