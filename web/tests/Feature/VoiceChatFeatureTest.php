<?php

namespace Tests\Feature;

use App\Models\AgentProfile;
use App\Models\AiUsageAlert;
use App\Models\AiUsageLog;
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

    public function test_authenticated_user_can_create_transcription_and_speech_only_realtime_session(): void
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
            ->assertJsonStructure(['data' => ['usage_session_id']])
            ->assertJsonPath('data.realtime_url', 'https://api.openai.test/v1/realtime/calls')
            ->assertJsonPath('data.tools', [])
            ->assertJsonMissing(['test-openai-key']);

        $this->assertDatabaseHas('ai_usage_logs', [
            'user_id' => User::where('email', 'voice-realtime@example.com')->firstOrFail()->id,
            'request_type' => 'voice_realtime_session',
            'status' => 'opened',
        ]);

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/realtime/client_secrets'
                && $request->hasHeader('Authorization', 'Bearer test-openai-key')
                && data_get($payload, 'session.type') === 'realtime'
                && data_get($payload, 'session.model') === 'gpt-realtime-test'
                && data_get($payload, 'session.audio.output.voice') === 'shimmer'
                && data_get($payload, 'session.audio.input.format.type') === 'audio/pcm'
                && data_get($payload, 'session.audio.input.format.rate') === 24000
                && data_get($payload, 'session.audio.input.transcription.language') === 'en'
                && str_contains((string) data_get($payload, 'session.audio.input.transcription.prompt'), 'background noise')
                && data_get($payload, 'session.audio.input.turn_detection.type') === 'server_vad'
                && data_get($payload, 'session.audio.input.turn_detection.silence_duration_ms') === 2000
                && data_get($payload, 'session.audio.input.turn_detection.create_response') === false
                && data_get($payload, 'session.audio.input.turn_detection.interrupt_response') === false
                && data_get($payload, 'session.tools') === []
                && data_get($payload, 'session.tool_choice') === 'none'
                && str_contains((string) data_get($payload, 'session.instructions'), 'US English transcription and speech surface')
                && str_contains((string) data_get($payload, 'session.instructions'), 'Never call tools')
                && str_contains((string) data_get($payload, 'session.instructions'), 'Never call tools or independently answer microphone input');
        });
    }

    public function test_base_premium_and_pro_voice_sessions_stop_at_each_users_daily_plan_limit_while_admin_remains_unlimited(): void
    {
        config()->set('services.ai_usage.realtime_session_minimum_cost_usd', 0.001);
        config()->set('services.ai_usage.limits.base_cost_limit', 0.01);
        config()->set('services.ai_usage.limits.premium_cost_limit', 0.02);
        config()->set('services.ai_usage.limits.pro_cost_limit', 0.03);
        Http::fake([
            '*' => Http::response([
                'value' => 'admin-ephemeral-secret',
                'session' => ['id' => 'sess_admin_unlimited'],
            ]),
        ]);

        foreach (['base' => 0.01, 'premium' => 0.02, 'pro' => 0.03] as $tier => $cost) {
            $email = "voice-limit-{$tier}@example.com";
            $token = $this->apiToken($email);
            $user = User::where('email', $email)->firstOrFail();
            $user->forceFill(['subscription_tier' => $tier])->save();
            $this->completedUsage($user, $cost);

            $this->withToken($token)->postJson('/api/assistant/voice/realtime/session')
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
        $this->withToken($adminToken)->postJson('/api/assistant/voice/realtime/session')
            ->assertOk()
            ->assertJsonPath('data.session_id', 'sess_admin_unlimited');
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
        Http::fake([
            '*' => Http::response([
                'value' => 'metered-ephemeral-secret',
                'session' => ['id' => 'sess_metered_123'],
            ]),
        ]);
        $token = $this->apiToken('voice-metered@example.com');
        $session = $this->withToken($token)->postJson('/api/assistant/voice/realtime/session')->assertOk();
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
