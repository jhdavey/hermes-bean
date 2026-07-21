<?php

namespace Tests\Feature;

use App\Services\Bean\LandingBeanRuntimeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Tests\TestCase;

class LandingBeanTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_render_the_lightweight_bean_control_without_a_chat_panel(): void
    {
        foreach (['/', '/pricing', '/privacy'] as $path) {
            $response = $this->get($path);

            $response->assertOk()
                ->assertSee('data-public-bean', false)
                ->assertSee('Tap to enable')
                ->assertSee('AI voice · audio processed by ElevenLabs')
                ->assertSee('assets/publicBean-', false)
                ->assertDontSee('data-bean-panel', false)
                ->assertDontSee('hb-bean-chat', false);
        }
    }

    public function test_landing_conversation_token_uses_the_dedicated_public_elevenlabs_agent(): void
    {
        config([
            'services.elevenlabs.agent_enabled' => true,
            'services.elevenlabs.api_key' => 'landing-test-key',
            'services.elevenlabs.agent_id' => 'authenticated-agent',
            'services.elevenlabs.landing_agent_id' => 'landing-agent',
        ]);
        Http::fake([
            'https://api.elevenlabs.io/*' => Http::response(['token' => 'landing-conversation-token']),
        ]);

        $this->postJson('/bean/landing/conversation-token', [
            'client_timezone' => 'America/New_York',
            'page_path' => '/pricing',
        ])->assertOk()
            ->assertJsonPath('data.token', 'landing-conversation-token')
            ->assertJsonPath('data.agent_id', 'landing-agent')
            ->assertJsonPath('data.page_path', '/pricing')
            ->assertJsonPath('data.transport', 'elevenlabs_agent');

        Http::assertSent(function ($request): bool {
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

            return $request->hasHeader('xi-api-key', 'landing-test-key')
                && ($query['agent_id'] ?? null) === 'landing-agent'
                && str_starts_with((string) ($query['participant_name'] ?? ''), 'bean-visitor-');
        });
    }

    public function test_landing_message_uses_an_anonymous_session_scoped_runtime(): void
    {
        $this->mock(LandingBeanRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('respond')
                ->once()
                ->withArgs(fn ($visitorId, $sessionId, $content, $pagePath): bool => is_string($visitorId)
                    && Str::isUuid($visitorId)
                    && $sessionId === null
                    && $content === 'Hey Bean'
                    && $pagePath === '/')
                ->andReturn([
                    'answer' => 'Hi, I’m Bean. I can help organize your day. Would you like a quick tour?',
                    'hermes_session_id' => 'landing-hermes-session-1',
                    'ui_action' => 'features',
                ]);
        });

        $this->postJson('/bean/landing/messages', [
            'content' => 'Hey Bean',
            'page_path' => '/',
        ])->assertOk()
            ->assertJsonPath('data.answer', 'Hi, I’m Bean. I can help organize your day. Would you like a quick tour?')
            ->assertJsonPath('data.ui_action', 'features');

        $this->assertSame('landing-hermes-session-1', session('landing_bean.hermes_session_id'));
        $this->assertTrue(Str::isUuid((string) session('landing_bean.visitor_id')));
        $this->assertSame(1, session('landing_bean.turn_count'));
    }

    public function test_landing_message_enforces_a_session_turn_cap(): void
    {
        config(['bean.landing.max_visitor_turns' => 1]);
        $this->mock(LandingBeanRuntimeService::class, function ($mock): void {
            $mock->shouldReceive('respond')->once()->andReturn([
                'answer' => 'Here is the first part of the tour.',
                'hermes_session_id' => 'landing-session',
            ]);
        });

        $this->postJson('/bean/landing/messages', ['content' => 'Give me a tour'])->assertOk();
        $this->postJson('/bean/landing/messages', ['content' => 'Keep going'])
            ->assertStatus(429)
            ->assertJsonPath('message', 'That is the end of this Bean demo. You can explore the page or create an account to continue.');
    }

    public function test_landing_conversation_requires_valid_turnstile_when_configured(): void
    {
        config([
            'services.elevenlabs.agent_enabled' => true,
            'services.elevenlabs.api_key' => 'landing-test-key',
            'services.elevenlabs.landing_agent_id' => 'landing-agent',
            'services.turnstile.secret_key' => 'turnstile-secret',
        ]);
        Http::fake([
            'https://challenges.cloudflare.com/turnstile/*' => Http::response([
                'success' => true,
                'hostname' => 'localhost',
            ]),
            'https://api.elevenlabs.io/*' => Http::response(['token' => 'verified-token']),
        ]);

        $this->postJson('/bean/landing/conversation-token', [
            'turnstile_token' => 'valid-human-token',
            'page_path' => '/',
        ])->assertOk()->assertJsonPath('data.token', 'verified-token');
    }

    public function test_landing_runtime_creates_an_isolated_tool_free_hermes_home(): void
    {
        $root = storage_path('framework/testing/landing-bean-'.Str::uuid());
        $binary = $root.'/fake-hermes';
        File::ensureDirectoryExists($root);
        File::put($binary, "#!/bin/sh\nprintf 'Hello from Bean. Would you like a quick tour?\\n[[BEAN_UI:unsupported]]\\n[[BEAN_UI:features]]\\nSession ID: public-session-1\\n'\n");
        chmod($binary, 0755);

        config([
            'bean.hermes.binary' => $binary,
            'bean.landing.visitors_path' => $root.'/visitors',
            'bean.landing.timeout_seconds' => 5,
        ]);

        try {
            $result = app(LandingBeanRuntimeService::class)->respond('visitor-a', null, 'Hey Bean', '/');
            $home = $root.'/visitors/'.hash('sha256', 'visitor-a');

            $this->assertSame('Hello from Bean. Would you like a quick tour?', $result['answer']);
            $this->assertSame('public-session-1', $result['hermes_session_id']);
            $this->assertSame('features', $result['ui_action']);
            $this->assertFileExists($home.'/skills/heybean-guide/SKILL.md');
            $this->assertStringNotContainsString('bean_dashboard', File::get($home.'/config.yaml'));
            $this->assertStringNotContainsString('session_search', File::get($home.'/config.yaml'));
            $this->assertStringContainsString('memory_enabled: false', File::get($home.'/config.yaml'));
            $this->assertStringContainsString('AI executive assistant for real life', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('busy professionals and parents', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('Do not position HeyBean as a general-purpose chatbot', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('how Bean works, explore the features or pricing, or take a quick tour', File::get($home.'/skills/heybean-guide/SKILL.md'));
            touch($home.'/.last-used', now()->subHours(3)->timestamp);
            $this->assertSame(1, app(LandingBeanRuntimeService::class)->pruneInactive(2));
            $this->assertDirectoryDoesNotExist($home);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
