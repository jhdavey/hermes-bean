<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class QuickVoiceReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('services.hermes_runtime.api_key', 'test-key');
        config()->set('services.hermes_runtime.api_base', 'https://api.openai.test/v1');
        config()->set('services.hermes_runtime.quick_reply_model', 'gpt-quick-test');
        config()->set('services.hermes_runtime.quick_reply_timeout', 4);
    }

    public function test_quick_voice_reply_uses_fast_model_without_tools(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-quick',
                'model' => 'gpt-quick-test',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'For dinner, I would start with something easy and filling, like tacos or pasta.',
                    ],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('quick-voice@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'what should we have for dinner tonight?',
            'client_context' => [
                'timezone' => 'America/New_York',
            ],
        ])->assertOk()
            ->assertJsonPath('data.text', 'For dinner, I would start with something easy and filling, like tacos or pasta.')
            ->assertJsonPath('data.model', 'gpt-quick-test');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $payload['model'] === 'gpt-quick-test'
                && ! array_key_exists('tools', $payload)
                && data_get($payload, 'messages.0.role') === 'system'
                && str_contains((string) data_get($payload, 'messages.0.content'), 'normal conversational question')
                && data_get($payload, 'messages.1.role') === 'system'
                && str_contains((string) data_get($payload, 'messages.1.content'), 'America/New_York')
                && data_get($payload, 'messages.2.role') === 'user'
                && data_get($payload, 'messages.2.content') === 'what should we have for dinner tonight?';
        });
    }

    public function test_quick_voice_reply_failure_returns_502_for_frontend_skip(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response(['error' => ['message' => 'nope']], 500),
        ]);

        $token = $this->apiToken('quick-voice-fail@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'what is on my calendar today?',
        ])->assertStatus(502)
            ->assertJsonPath('code', 'openai_quick_voice_failed');
    }
}
