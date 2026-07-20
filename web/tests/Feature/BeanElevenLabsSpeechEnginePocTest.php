<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BeanElevenLabsSpeechEnginePocTest extends TestCase
{
    use RefreshDatabase;

    public function test_conversation_token_endpoint_is_disabled_by_default(): void
    {
        Http::fake();
        $token = $this->apiToken('bean-elevenlabs-disabled@example.com');

        $this->withToken($token)
            ->postJson('/api/bean/elevenlabs/conversation-token')
            ->assertNotFound()
            ->assertJson(['message' => 'ElevenLabs Speech Engine is not enabled.']);

        Http::assertNothingSent();
    }

    public function test_conversation_token_endpoint_mints_private_elevenlabs_webrtc_token(): void
    {
        config()->set('services.elevenlabs.speech_engine_enabled', true);
        config()->set('services.elevenlabs.api_key', 'test-elevenlabs-key');
        config()->set('services.elevenlabs.speech_engine_id', 'seng_test123');
        config()->set('services.elevenlabs.speech_engine_environment', 'poc');
        config()->set('services.elevenlabs.speech_engine_branch_id', 'branch_test');
        config()->set('services.elevenlabs.voice_bridge_secret', 'bridge-secret');

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['token' => 'webrtc-token-test'], 200),
        ]);

        $token = $this->apiToken('bean-elevenlabs-token@example.com');

        $this->withToken($token)
            ->postJson('/api/bean/elevenlabs/conversation-token')
            ->assertOk()
            ->assertJsonPath('data.token', 'webrtc-token-test')
            ->assertJsonPath('data.speech_engine_id', 'seng_test123');

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('xi-api-key', 'test-elevenlabs-key')
                && str_contains($request->url(), 'https://api.elevenlabs.io/v1/convai/conversation/token')
                && str_contains($request->url(), 'agent_id=seng_test123')
                && str_contains($request->url(), 'participant_name=bean-user-')
                && str_contains($request->url(), 'environment=poc')
                && str_contains($request->url(), 'branch_id=branch_test');
        });
    }

    public function test_conversation_token_endpoint_hides_elevenlabs_error_details(): void
    {
        config()->set('services.elevenlabs.speech_engine_enabled', true);
        config()->set('services.elevenlabs.api_key', 'test-elevenlabs-key');
        config()->set('services.elevenlabs.speech_engine_id', 'seng_test123');
        config()->set('services.elevenlabs.voice_bridge_secret', 'bridge-secret');

        Http::fake([
            'api.elevenlabs.io/*' => Http::response(['detail' => 'private provider details'], 500),
        ]);

        $token = $this->apiToken('bean-elevenlabs-error@example.com');

        $this->withToken($token)
            ->postJson('/api/bean/elevenlabs/conversation-token')
            ->assertStatus(502)
            ->assertJson(['message' => 'Could not create ElevenLabs conversation token.'])
            ->assertJsonMissing(['detail' => 'private provider details']);
    }
}
