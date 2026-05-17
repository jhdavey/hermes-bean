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
            ->assertDontSee('Use your voice:', false)
            ->assertSee('Just say “Hey, Bean…!”', false)
            ->assertSee('Voice-first requests', false)
            ->assertSee('Calendar sync-ready', false)
            ->assertDontSee('Google Calendar-ready', false)
            ->assertSee('Approval guardrails', false)
            ->assertSee('Private + shared dashboard', false)
            ->assertDontSee('Work + home spaces', false)
            ->assertSee('Bean manages the logistics, so you can stay ahead', false)
            ->assertDontSee('Your schedule changes faster than your tools.', false)
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
            ->assertSee('images/heybean-mobile-today-calendar.png', false)
            ->assertDontSee('Apple-style', false)
            ->assertDontSee('Google Calendar', false)
            ->assertDontSee('iOS', false)
            ->assertSee('<nav class="navlinks" aria-label="Primary navigation"><a href="#how">How it works</a><a href="#features">Features</a></nav>', false)
            ->assertSee('<div class="mobile-menu-panel">', false)
            ->assertDontSee('<a href="/privacy">Privacy</a>', false)
            ->assertDontSee('<a href="/terms">Terms</a>', false)
            ->assertSee('/privacy', false)
            ->assertSee('/terms', false)
            ->assertSee('/support', false)
            ->assertDontSee('/account-deletion', false)
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
        $response->assertSessionHas('early_access_status', 'Thank you for signing up! We’ll send you an email as soon as we can share the app with you! We look forward to your help with making Bean great!');

        $this->assertDatabaseHas('early_access_signups', [
            'email' => 'harley@example.com',
            'name' => null,
            'use_case' => null,
        ]);
    }

    public function test_early_access_thank_you_modal_renders_after_signup(): void
    {
        $this->withSession([
            'early_access_status' => 'Thank you for signing up! We’ll send you an email as soon as we can share the app with you! We look forward to your help with making Bean great!',
        ])->get('/')
            ->assertOk()
            ->assertSee('role="dialog"', false)
            ->assertSee('Thank you for signing up!', false)
            ->assertSee('We’ll send you an email as soon as we can share the app with you!', false)
            ->assertSee('We look forward to your help with making Bean great!', false)
            ->assertSee('Sounds good', false);
    }
}
