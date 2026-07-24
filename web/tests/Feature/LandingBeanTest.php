<?php

namespace Tests\Feature;

use App\Models\BeanUsageRecord;
use App\Services\Bean\LandingBeanRuntimeService;
use App\Services\PlanLimitService;
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
                ->assertSee('Tap to talk')
                ->assertSee('Hey! I\'m over here!', false)
                ->assertDontSee('Bean can help right now.', false)
                ->assertDontSee('data-public-bean-intent', false)
                ->assertSee('Turn your volume on, then allow microphone access.', false)
                ->assertDontSee('audio processed by ElevenLabs')
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

    public function test_landing_voice_session_limits_allow_short_retry_bursts(): void
    {
        $this->assertSame(4, (int) config('bean.landing.sessions_per_minute'));
        $this->assertSame(12, (int) config('bean.landing.sessions_per_hour'));
        $this->assertSame(40, (int) config('bean.landing.sessions_per_day'));
    }

    public function test_home_page_exposes_all_public_bean_tour_targets(): void
    {
        $this->get('/')->assertOk()
            ->assertSee('id="bean-demo"', false)
            ->assertSee('id="tour-command-center"', false)
            ->assertSee('id="tour-calendar-tasks"', false)
            ->assertSee('id="tour-customization"', false)
            ->assertSee('heybean-tour-command-center-bean.png', false)
            ->assertSee('heybean-tour-calendar-tasks.png', false)
            ->assertSee('heybean-tour-customization-themes.png', false)
            ->assertSee('Quick interactive tour', false)
            ->assertSee('Modular dashboard + themes', false);
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
            ->assertJsonPath('data.ui_action', 'features')
            ->assertJsonStructure(['data' => ['runtime_ms']]);

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

    public function test_landing_voice_lifecycle_records_public_elevenlabs_usage(): void
    {
        config([
            'services.elevenlabs.agent_enabled' => true,
            'services.elevenlabs.api_key' => 'landing-test-key',
            'services.elevenlabs.landing_agent_id' => 'landing-agent',
            'bean.usage.elevenlabs_agent_cost_per_minute_usd' => 0.08,
            'bean.usage.elevenlabs_agent_credits_per_minute' => 10000 / 15,
        ]);
        Http::fake([
            'https://api.elevenlabs.io/*' => Http::response(['token' => 'landing-meter-token']),
        ]);
        $startedAt = now();

        $this->postJson('/bean/landing/conversation-token', [
            'client_timezone' => 'America/New_York',
            'page_path' => '/pricing',
        ])->assertOk();

        $this->postJson('/bean/landing/voice-events', [
            'event_type' => 'voice_session_started',
            'client_session_id' => 'landing-client-session-1',
            'page_path' => '/pricing',
            'source' => 'landing_page',
            'payload' => ['transport' => 'elevenlabs_agent'],
            'occurred_at' => $startedAt->toIso8601String(),
            'occurred_at_ms' => 10_000,
        ])->assertCreated();

        $this->postJson('/bean/landing/voice-events', [
            'event_type' => 'voice_session_closed',
            'client_session_id' => 'landing-client-session-1',
            'page_path' => '/pricing',
            'source' => 'landing_page',
            'payload' => [
                'transport' => 'elevenlabs_agent',
                'reason' => 'client_idle_timeout',
                'wake_to_first_speech_ms' => 940,
                'wake_to_first_speech_target_ms' => 1200,
                'wake_to_first_speech_target_met' => true,
                'hermes_runtime_samples_ms' => [812, 704],
            ],
            'occurred_at' => $startedAt->copy()->addSeconds(60)->toIso8601String(),
            'occurred_at_ms' => 70_000,
        ])->assertCreated();

        $usage = BeanUsageRecord::where('provider', 'elevenlabs')->where('source', 'landing_page')->firstOrFail();
        $this->assertNull($usage->user_id);
        $this->assertSame('landing_conversational_ai_agent', $usage->service);
        $this->assertSame('voice_session', $usage->usage_type);
        $this->assertSame(60.0, $usage->quantity);
        $this->assertEqualsWithDelta(666.67, $usage->credits, 0.1);
        $this->assertEqualsWithDelta(0.08, $usage->estimated_cost_usd, 0.0001);
        $this->assertSame('public_landing', $usage->metadata['segment'] ?? null);
        $this->assertSame('/pricing', $usage->metadata['page_path'] ?? null);
        $this->assertSame(940, $usage->metadata['wake_to_first_speech_ms'] ?? null);
        $this->assertTrue($usage->metadata['wake_to_first_speech_target_met'] ?? false);
        $this->assertSame([812, 704], $usage->metadata['hermes_runtime_samples_ms'] ?? null);
    }

    public function test_landing_runtime_creates_an_isolated_tool_free_hermes_home(): void
    {
        $root = storage_path('framework/testing/landing-bean-'.Str::uuid());
        $binary = $root.'/fake-hermes';
        File::ensureDirectoryExists($root);
        File::put($binary, "#!/bin/sh\nprintf 'Hello from Bean. Let me show the command center with Bean.\\n[[BEAN_UI:unsupported]]\\n[[BEAN_UI:command_center]]\\nSession ID: public-session-1\\n'\n");
        chmod($binary, 0755);

        config([
            'bean.hermes.binary' => $binary,
            'bean.landing.visitors_path' => $root.'/visitors',
            'bean.landing.timeout_seconds' => 5,
        ]);
        app(PlanLimitService::class)->updatePlans([
            'base' => ['workspace_limit' => 7],
        ]);

        try {
            $result = app(LandingBeanRuntimeService::class)->respond('visitor-a', null, 'Hey Bean', '/');
            $home = $root.'/visitors/'.hash('sha256', 'visitor-a');

            $this->assertSame('Hello from Bean. Let me show the command center with Bean.', $result['answer']);
            $this->assertSame('public-session-1', $result['hermes_session_id']);
            $this->assertSame('command_center', $result['ui_action']);
            $this->assertFileExists($home.'/skills/heybean-guide/SKILL.md');
            $this->assertStringNotContainsString('bean_dashboard', File::get($home.'/config.yaml'));
            $this->assertStringNotContainsString('session_search', File::get($home.'/config.yaml'));
            $this->assertStringContainsString('memory_enabled: false', File::get($home.'/config.yaml'));
            $this->assertStringContainsString('AI executive assistant for real life', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('busy professionals and parents', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('Do not position HeyBean as a general-purpose chatbot', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('give you a quick tour, answer questions, or help you start signup', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('Do not ask about their use case unless they explicitly ask for a recommendation.', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('Help the visitor experience Bean as quickly as possible', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('not like a nagging salesperson', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('keep it to exactly three short stops', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('make it sound conversational instead of scripted', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('Do not repeat the same question twice', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString("Ok, i'll just get some quick info from you and show you around", File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('Do not say handoff, transfer, another Bean, or explain implementation', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringNotContainsString('walk through features or pricing', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringNotContainsString('hard-coded Bean-guided onboarding can take over', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('Supported values are: `command_center`, `calendar_tasks`, `customization`, `features`, `pricing`, `signup`, `onboarding`, and `how_it_works`', File::get($home.'/skills/heybean-guide/SKILL.md'));
            $this->assertStringContainsString('7 workspaces', File::get($home.'/skills/heybean-guide/SKILL.md'));
            touch($home.'/.last-used', now()->subHours(3)->timestamp);
            $this->assertSame(1, app(LandingBeanRuntimeService::class)->pruneInactive(2));
            $this->assertDirectoryDoesNotExist($home);
        } finally {
            File::deleteDirectory($root);
        }
    }
}
