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
            ->assertJsonPath('data.model', 'gpt-quick-test')
            ->assertJsonPath('data.continue_agent', false)
            ->assertJsonPath('data.response_contract', 'complete');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://api.openai.test/v1/chat/completions'
                && $request->hasHeader('Authorization', 'Bearer test-key')
                && $payload['model'] === 'gpt-quick-test'
                && $payload['max_completion_tokens'] === 90
                && ! array_key_exists('tools', $payload)
                && data_get($payload, 'messages.0.role') === 'system'
                && str_contains((string) data_get($payload, 'messages.0.content'), 'normal conversational question')
                && str_contains((string) data_get($payload, 'messages.0.content'), 'compact complete answer')
                && str_contains((string) data_get($payload, 'messages.0.content'), 'do not ask whether they want you to create')
                && str_contains((string) data_get($payload, 'messages.0.content'), 'Finish complete thoughts')
                && str_contains((string) data_get($payload, 'messages.0.content'), 'warm, friendly, and lightly upbeat')
                && data_get($payload, 'messages.1.role') === 'system'
                && str_contains((string) data_get($payload, 'messages.1.content'), 'America/New_York')
                && data_get($payload, 'messages.2.role') === 'user'
                && data_get($payload, 'messages.2.content') === 'what should we have for dinner tonight?';
        });
    }

    public function test_quick_voice_reply_continues_to_agent_for_live_external_requests(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-flights',
                'model' => 'gpt-quick-test',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will check one-way flights from MCO to Dublin for tomorrow.',
                    ],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('quick-voice-flights@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'can you tell me the cheapest flights from MCO to Dublin for tomorrow one way?',
        ])->assertOk()
            ->assertJsonPath('data.text', "I'll check one-way flights from MCO to Dublin for tomorrow.")
            ->assertJsonPath('data.continue_agent', true)
            ->assertJsonPath('data.response_contract', 'background');
    }

    public function test_quick_voice_reply_continues_to_agent_for_current_weather_requests(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-weather',
                'model' => 'gpt-quick-test',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will check the current weather in Orlando.',
                    ],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('quick-voice-weather@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'Can you tell me what the weather is in Orlando Florida right now?',
        ])->assertOk()
            ->assertJsonPath('data.text', "I'll check the current weather in Orlando.")
            ->assertJsonPath('data.continue_agent', true)
            ->assertJsonPath('data.response_contract', 'background');
    }

    public function test_quick_voice_reply_continues_to_agent_for_generic_live_external_requests(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-live-external',
                'model' => 'gpt-quick-test',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will check what I can access for that current outside information.',
                    ],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('quick-voice-live-external@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'can you tell me when my local store closes today?',
        ])->assertOk()
            ->assertJsonPath('data.text', "I'll check what I can access for that current outside information.")
            ->assertJsonPath('data.continue_agent', true)
            ->assertJsonPath('data.response_contract', 'background');
    }

    public function test_quick_voice_reply_continues_to_agent_for_app_requests(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-calendar',
                'model' => 'gpt-quick-test',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will check your calendar for today.',
                    ],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('quick-voice-calendar@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'what is on my calendar today?',
        ])->assertOk()
            ->assertJsonPath('data.text', "I'll check your calendar for today.")
            ->assertJsonPath('data.continue_agent', true)
            ->assertJsonPath('data.response_contract', 'background');
    }

    public function test_quick_voice_reply_continues_to_agent_for_trash_pickup_questions(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-trash',
                'model' => 'gpt-quick-test',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I will check what trash pickup you are supposed to take out.',
                    ],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('quick-voice-trash@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'when am I supposed to take out the trash?',
        ])->assertOk()
            ->assertJsonPath('data.text', "I'll check what trash pickup you are supposed to take out.")
            ->assertJsonPath('data.continue_agent', true)
            ->assertJsonPath('data.response_contract', 'background');
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

    public function test_quick_voice_reply_can_generate_bridge_without_repeating_spoken_segments(): void
    {
        Http::fake([
            'https://api.openai.test/v1/chat/completions' => Http::response([
                'id' => 'chatcmpl-bridge',
                'model' => 'gpt-quick-test',
                'choices' => [[
                    'finish_reason' => 'stop',
                    'message' => [
                        'role' => 'assistant',
                        'content' => 'I am narrowing that down now.',
                    ],
                ]],
            ], 200),
        ]);

        $token = $this->apiToken('quick-voice-bridge@example.com');

        $this->withToken($token)->postJson('/api/assistant/voice/quick-reply', [
            'content' => 'what should we have for dinner tonight?',
            'stage' => 'bridge',
            'spoken_segments' => ['Tacos could be easy tonight if you want something quick.'],
            'elapsed_ms' => 5200,
        ])->assertOk()
            ->assertJsonPath('data.text', "I'm narrowing that down now.")
            ->assertJsonPath('data.continue_agent', true)
            ->assertJsonPath('data.response_contract', 'bridge');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();
            $context = (string) data_get($payload, 'messages.1.content');

            return str_contains((string) data_get($payload, 'messages.0.content'), 'taking longer')
                && str_contains($context, '"stage":"bridge"')
                && str_contains($context, 'Tacos could be easy tonight')
                && str_contains($context, '"elapsed_ms":5200');
        });
    }
}
