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
            ->assertSee('Your AI operating system for the real-world', false)
            ->assertDontSee('Live your best life, let Bean handle the rest', false)
            ->assertSee('your new AI executive assistant', false)
            ->assertSee('manages your calendar, keeps track of tasks, sets reminders', false)
            ->assertSee('keeps you moving instead of getting stuck in the weeds', false)
            ->assertSee('Join the first 100', false)
            ->assertDontSee('Use your voice:', false)
            ->assertSee('Just say “Hey, Bean…!”', false)
            ->assertSee('Voice-first control', false)
            ->assertSee('Hold the button and say “Hey, Bean…!” 👇', false)
            ->assertDontSee('↘️ Hold the button and say “Hey, Bean…!”', false)
            ->assertSee('.c2{left:-10px;bottom:145px}', false)
            ->assertDontSee('.c2{left:-10px;bottom:160px}', false)
            ->assertDontSee('Voice-firt control', false)
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
            ->assertSee('Bean planning chat mockup', false)
            ->assertSee('Bean chat mockup matching real app screen', false)
            ->assertSee('Current Bean chat messages over real app screen', false)
            ->assertSee('class="chat-bean-button-icon"', false)
            ->assertSee('images/bean-logo.png', false)
            ->assertSee('width="790" height="790"', false)
            ->assertSee('images/bean-chat-app-screen.jpg', false)
            ->assertSee('width="589" height="1280"', false)
            ->assertSee('aspect-ratio:589/1280', false)
            ->assertSee('object-fit:contain', false)
            ->assertSee('Working… checking calendar, tasks, reminders, and household context.', false)
            ->assertDontSee('images/bean-chat-screen-base.jpg', false)
            ->assertSee('Hey Bean, plan next week around school drop-off', false)
            ->assertSee('I mapped the week, protected Friday afternoon', false)
            ->assertSee('Approve calendar changes', false)
            ->assertSee('class="logistics-layout"', false)
            ->assertSee('Type or speak “Hey Bean…”', false)
            ->assertSee('class="mobile-menu"', false)
            ->assertSee('images/bean-logo.png', false)
            ->assertDontSee('images/bean-logo-color.png', false)
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
