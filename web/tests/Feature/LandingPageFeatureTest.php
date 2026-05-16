<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_is_the_heybean_beta_landing_page(): void
    {
        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Tell <span style="margin-left: .06em;">Bean</span> what changed. <span style="margin-left: .08em;">Bean</span> updates your day.', false)
            ->assertSee('AI executive assistant for busy people and households', false)
            ->assertSee('It plans your calendar, captures tasks, sets reminders', false)
            ->assertSee('keeps home and work moving from one focused conversation', false)
            ->assertSee('Join the first 100', false)
            ->assertSee('Use your voice:', false)
            ->assertSee('Just say “Hey, Bean…!”', false)
            ->assertSee('Voice-first requests', false)
            ->assertSee('Google Calendar-ready', false)
            ->assertSee('Approval guardrails', false)
            ->assertSee('Built for the real day', false)
            ->assertSee('Bean turns “Hey Bean…” voice requests into a structured day', false)
            ->assertSee('Hey Bean, move my focus block to 3', false)
            ->assertSee('Type or speak “Hey Bean…”', false)
            ->assertSee('class="mobile-menu"', false)
            ->assertDontSee('Voice shortcut:', false)
            ->assertDontSee('talk through changes hands-free', false)
            ->assertDontSee('say “Hey Bean, add…”', false)
            ->assertDontSee('Say: <span style="white-space: nowrap;">“Hey Bean…”</span> then say what you need.', false)
            ->assertDontSee('risky changes', false)
            ->assertSee('images/heybean-ios-today-calendar.png', false)
            ->assertSee('/privacy', false)
            ->assertSee('/terms', false)
            ->assertSee('/support', false)
            ->assertSee('/account-deletion', false)
            ->assertSee('type="email"', false)
            ->assertSee('name="email"', false)
            ->assertSee(route('early-access.store'), false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="use_case"', false)
            ->assertDontSee('Flutter + Laravel', false);
    }

    public function test_visitors_can_request_early_access(): void
    {
        $response = $this->post(route('early-access.store'), [
            'email' => 'harley@example.com',
        ]);

        $response->assertRedirect('/#early-access');
        $response->assertSessionHas('early_access_status', 'You are on the HeyBean early access list. We will reach out when your invite is ready.');

        $this->assertDatabaseHas('early_access_signups', [
            'email' => 'harley@example.com',
            'name' => null,
            'use_case' => null,
        ]);
    }
}
