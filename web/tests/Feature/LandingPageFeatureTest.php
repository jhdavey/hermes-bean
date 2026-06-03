<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_is_the_heybean_beta_landing_page(): void
    {
        $this->assertFileExists(public_path('images/bean-logo.png'));

        $response = $this->get('/');

        $response->assertOk()
            ->assertSee('Meet Bean, your new assistant for real-life', false)
            ->assertDontSee('First 100 early-access invites now opening', false)
            ->assertDontSee('● First 100 early-access invites now opening', false)
            ->assertDontSee('Your AI operating system for the real-world', false)
            ->assertDontSee('Live your best life, let Bean handle the rest', false)
            ->assertDontSee('Bean is your new AI executive assistant.', false)
            ->assertSee('Bean helps you manage your calendar, keeps track of tasks, set reminders', false)
            ->assertSee('keeps you moving instead of getting stuck in the weeds', false)
            ->assertSee('Get Early Access', false)
            ->assertDontSee('See how Bean works', false)
            ->assertDontSee('Use your voice:', false)
            ->assertSee('class="hero-voice"', false)
            ->assertSee('Just say “Hey, Bean…!”', false)
            ->assertSee('.hero-voice{font-size:clamp(30px,4.8vw,54px)!important', false)
            ->assertSee('Voice-first control', false)
            ->assertSee('Hold the button and say “Hey, Bean…!”', false)
            ->assertSee('class="pointer">👇</span>', false)
            ->assertDontSee('↘️ Hold the button and say “Hey, Bean…!”', false)
            ->assertSee('.c2{left:-24px;bottom:132px}', false)
            ->assertSee('.c2{left:-14px;bottom:102px}', false)
            ->assertSee('.c2 .pointer{display:inline-block;margin-left:6px', false)
            ->assertSee('.c2 .pointer{margin-left:5px;font-size:17px}', false)
            ->assertDontSee('.c2 .pointer{position:absolute', false)
            ->assertDontSee('bottom:-32px', false)
            ->assertDontSee('bottom:-20px', false)
            ->assertDontSee('.c2{left:-10px;bottom:145px}', false)
            ->assertDontSee('.c2{left:2px;bottom:118px}', false)
            ->assertDontSee('.c2{left:-10px;bottom:160px}', false)
            ->assertDontSee('Voice-firt control', false)
            ->assertSee('Calendar sync-ready', false)
            ->assertDontSee('Google Calendar-ready', false)
            ->assertSee('Approval guardrails', false)
            ->assertSee('Private + shared dashboard', false)
            ->assertDontSee('Work + home spaces', false)
            ->assertSee('Bean manages the logistics, so you stay ahead', false)
            ->assertDontSee('Your schedule changes faster than your tools.', false)
            ->assertSee('Built for the real day', false)
            ->assertSee('Bean turns “Hey Bean…” voice requests into a structured day', false)
            ->assertSee('Hey Bean, move my focus block to 3', false)
            ->assertSee('Bean planning chat mockup', false)
            ->assertSee('Bean chat mockup matching real app screen', false)
            ->assertSee('Current Bean chat messages over real app screen', false)
            ->assertDontSee('class="chat-bean-button-icon"', false)
            ->assertDontSee('width="790" height="790"', false)
            ->assertSee('images/bean-chat-app-screen.jpg', false)
            ->assertSee('width="589" height="1280"', false)
            ->assertSee('aspect-ratio:589/1280', false)
            ->assertSee('object-fit:contain', false)
            ->assertSee('.chat-overlay{position:absolute;left:4.75%;right:4.75%;top:20%;bottom:31%', false)
            ->assertSee('.bubble{min-width:0;max-width:100%;border-radius:18px;padding:8px 9px;line-height:1.22', false)
            ->assertDontSee('.chat-overlay{position:absolute;left:4.75%;right:4.75%;top:20%;bottom:22.8%', false)
            ->assertSee('Working… checking calendar, tasks, reminders, and household context.', false)
            ->assertDontSee('images/bean-chat-screen-base.jpg', false)
            ->assertSee('Hey Bean, plan next week around school drop-off', false)
            ->assertSee('I mapped the week, protected Friday afternoon', false)
            ->assertSee('Approve calendar changes', false)
            ->assertSee('class="logistics-layout"', false)
            ->assertSee('Type or speak “Hey Bean…”', false)
            ->assertSee('class="mobile-menu"', false)
            ->assertSee('class="mobile-menu-icon"', false)
            ->assertSee('<summary aria-label="Open menu"><span class="mobile-menu-icon" aria-hidden="true"><span></span><span></span><span></span></span></summary>', false)
            ->assertDontSee('<summary aria-label="Open menu">Menu</summary>', false)
            ->assertSee('images/bean-logo.png', false)
            ->assertDontSee('images/bean-logo-color.png', false)
            ->assertDontSee('Voice shortcut:', false)
            ->assertDontSee('talk through changes hands-free', false)
            ->assertDontSee('say “Hey Bean, add…”', false)
            ->assertDontSee('Say: <span style="white-space: nowrap;">“Hey Bean…”</span> then say what you need.', false)
            ->assertDontSee('risky changes', false)
            ->assertSee('images/heybean-mobile-today-calendar.png', false)
            ->assertSee('images/heybean-mobile-today-calendar.png?v=', false)
            ->assertSee('.phone img{display:block;width:min(306px,60vw);height:auto;max-height:665px;aspect-ratio:auto;object-fit:contain', false)
            ->assertSee('.phone img{width:min(286px,76vw);height:auto;max-height:620px;max-width:82vw}', false)
            ->assertSee('.callout{display:block;transform:scale(.86)', false)
            ->assertDontSee('.callout{display:none}', false)
            ->assertDontSee('height:min(700px,112vw);width:auto;max-width:min(360px,70vw)', false)
            ->assertDontSee('height:min(620px,150vw);width:auto;max-width:82vw', false)
            ->assertDontSee('.phone img{display:block;width:min(390px,70vw);height:auto;aspect-ratio:1320/2868;object-fit:contain', false)
            ->assertDontSee('.phone img{display:block;width:min(430px,76vw);height:auto;border-radius:42px}', false)
            ->assertDontSee('Apple-style', false)
            ->assertDontSee('Google Calendar', false)
            ->assertDontSee('iOS', false)
            ->assertSee('<nav class="navlinks" aria-label="Primary navigation"><a href="#how">How it works</a><a href="#features">Features</a><a href="/pricing">Pricing</a><a href="/login">Log in</a></nav>', false)
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

        $html = $response->getContent();
        $this->assertLessThan(strpos($html, 'Voice-first requests'), strpos($html, 'No spam. Private beta invites only.'));
        $this->assertLessThan(strpos($html, 'Just say “Hey, Bean…!”'), strpos($html, 'Private by design'));
    }

    public function test_browser_app_auth_routes_render_the_heybean_app_shell(): void
    {
        foreach (['/login', '/register', '/forgot-password', '/app', '/dashboard'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('id="heybean-web-app"', false)
                ->assertSee('/build/assets/app-', false)
                ->assertSee('class="heybean-app-body"', false);
        }
    }

    public function test_pricing_page_shows_plans_and_trial_ctas(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Pick how much help Bean can give your day.', false)
            ->assertSee('7-day free trial on paid plans', false)
            ->assertSee('Free', false)
            ->assertSee('Premium', false)
            ->assertSee('Pro', false)
            ->assertSee('Most popular', false)
            ->assertSee('$10', false)
            ->assertSee('$25', false)
            ->assertSee('Start Premium trial', false)
            ->assertSee('href="/register?plan=premium"', false)
            ->assertSee('href="/register?plan=pro"', false)
            ->assertSee('Billing starts on day 8 until canceled', false);
    }

    public function test_register_route_preserves_selected_plan_for_spa(): void
    {
        $this->get('/register?plan=premium')
            ->assertOk()
            ->assertSee('data-selected-plan="premium"', false);
    }

    public function test_visitors_can_request_early_access(): void
    {
        $response = $this->post(route('early-access.store'), [
            'email' => 'harley@example.com',
            'plan' => 'premium',
        ]);

        $response->assertRedirect('/#early-access');
        $response->assertSessionHas('early_access_status', 'Thank you for signing up! We’ll send you an email as soon as we can share the app with you! We look forward to your help with making Bean great!');

        $this->assertDatabaseHas('early_access_signups', [
            'email' => 'harley@example.com',
            'name' => null,
            'use_case' => null,
            'requested_plan' => 'premium',
            'source' => 'pricing',
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
