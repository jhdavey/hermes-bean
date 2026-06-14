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
            ->assertSee('HeyBean is currently in Beta.', false)
            ->assertSee('href="/register">Sign up here</a> to join the waitlist.', false)
            ->assertSee('class="public-beta-banner"', false)
            ->assertDontSee('First 100 early-access invites now opening', false)
            ->assertDontSee('● First 100 early-access invites now opening', false)
            ->assertDontSee('Your AI operating system for the real-world', false)
            ->assertDontSee('Live your best life, let Bean handle the rest', false)
            ->assertDontSee('Bean is your new AI executive assistant.', false)
            ->assertSee('Bean turns quick requests into organized follow-through', false)
            ->assertSee('without constant app-switching', false)
            ->assertSee('Get Early Access', false)
            ->assertDontSee('See how Bean works', false)
            ->assertDontSee('Use your voice:', false)
            ->assertSee('class="hero-voice"', false)
            ->assertSee('Just say “Hey, Bean…!”', false)
            ->assertSee('.hero-voice{font-size:clamp(30px,4.8vw,54px)!important', false)
            ->assertSee('Voice-first control', false)
            ->assertSee('Speak or type from the mobile app', false)
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
            ->assertDontSee('Calendar sync-ready', false)
            ->assertDontSee('Google Calendar-ready', false)
            ->assertSee('Approval guardrails', false)
            ->assertDontSee('Private + shared dashboard', false)
            ->assertDontSee('Work + home spaces', false)
            ->assertSee('“Add dinner with Lauren Friday at 7.”', false)
            ->assertSee('“Remind me to take out the trash tonight.”', false)
            ->assertSee('“What’s the weather like right now?”', false)
            ->assertSee('“When is my dentist appointment?”', false)
            ->assertSee('Bean manages the logistics, so you stay ahead', false)
            ->assertDontSee('Your schedule changes faster than your tools.', false)
            ->assertSee('Everything Bean needs to help run your day.', false)
            ->assertSee('The app gives Bean a clear place for every commitment', false)
            ->assertSee('class="capability-map"', false)
            ->assertSee('Command center for the moving pieces.', false)
            ->assertSee('Plan your day with calendars that show the whole load.', false)
            ->assertSee('Capture what matters with tasks and reminders.', false)
            ->assertSee('Keep context together in workspaces for each part of life.', false)
            ->assertDontSee('class="capability-kicker"', false)
            ->assertDontSee('class="capability-outcomes"', false)
            ->assertDontSee('class="lane-label"', false)
            ->assertDontSee('class="capability-tags"', false)
            ->assertSee('Start with one request. Bean turns it into the next right action.', false)
            ->assertDontSee('<div class="grid"><div class="card"><b>Bean assistant</b>', false)
            ->assertSee('Bean turns “Hey Bean…” voice requests into a structured day', false)
            ->assertSee('Hey Bean, move my focus block to 3', false)
            ->assertSee('Bean planning chat mockup', false)
            ->assertSee('Bean chat mockup matching real app screen', false)
            ->assertDontSee('Current Bean chat messages over real app screen', false)
            ->assertDontSee('class="chat-bean-button-icon"', false)
            ->assertDontSee('width="790" height="790"', false)
            ->assertDontSee('images/bean-chat-app-screen.jpg', false)
            ->assertSee('images/bean-logistics-conversation.png', false)
            ->assertSee('width="500" height="1080"', false)
            ->assertSee('object-fit:contain', false)
            ->assertDontSee('Working… checking calendar, tasks, reminders, and household context.', false)
            ->assertDontSee('images/bean-chat-screen-base.jpg', false)
            ->assertDontSee('Hey Bean, plan next week around school drop-off', false)
            ->assertDontSee('I mapped the week, protected Friday afternoon', false)
            ->assertDontSee('Approve calendar changes', false)
            ->assertSee('class="logistics-layout"', false)
            ->assertDontSee('Type or speak “Hey Bean…”', false)
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
            ->assertSee('images/bean-hero-conversation.png', false)
            ->assertSee('images/bean-hero-conversation.png?v=', false)
            ->assertSee('.hero-phone.image-mockup{width:min(263px,27vw)', false)
            ->assertSee('.hero-phone.image-mockup{width:min(288px,77vw)', false)
            ->assertSee('.hero-phone.image-mockup{width:min(270px,76vw)', false)
            ->assertSee('.chat-phone{width:min(369px,90%)', false)
            ->assertSee('.chat-phone{width:min(324px,79vw)', false)
            ->assertDontSee('images/heybean-mobile-today-calendar.png', false)
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
            ->assertSee('class="navlinks"', false)
            ->assertSee('href="/">Home</a>', false)
            ->assertSee('href="/#how">How it works</a>', false)
            ->assertSee('href="/#features">Features</a>', false)
            ->assertSee('href="/pricing">Pricing</a>', false)
            ->assertSee('href="/login">Log in</a>', false)
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

    public function test_browser_app_auth_routes_render_the_heybean_app_shell(): void
    {
        foreach (['/login', '/register', '/subscribe', '/forgot-password', '/app', '/dashboard'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('id="heybean-web-app"', false)
                ->assertSee('/build/assets/app-', false)
                ->assertSee('class="heybean-app-body"', false);
        }
    }

    public function test_register_route_shows_beta_waitlist_banner_and_copy(): void
    {
        $this->get('/register')
            ->assertOk()
            ->assertSee('HeyBean is currently in Beta.', false)
            ->assertSee('href="/register">Sign up here</a> to join the waitlist.', false)
            ->assertSee('data-auth-mode="register"', false);

        $appJs = file_get_contents(resource_path('js/app.js'));

        $this->assertStringContainsString('We are currently onboarding beta users.', $appJs);
        $this->assertStringContainsString("Sign up for early access and we'll let you know as soon as we are ready to onboard you!", $appJs);
        $this->assertStringContainsString('Sign up for early access', $appJs);
        $this->assertStringContainsString('Thank you for signing up for early access!', $appJs);
        $this->assertStringContainsString('We look forward to onboarding you soon!', $appJs);
        $this->assertStringContainsString('data-register-early-access-home', $appJs);
        $this->assertStringNotContainsString('/subscribe?plan=', $appJs);
    }

    public function test_pricing_page_shows_plans_and_trial_ctas(): void
    {
        $this->get('/pricing')
            ->assertOk()
            ->assertSee('<h1>Pricing</h1>', false)
            ->assertSee('HeyBean is currently in Beta.', false)
            ->assertSee('href="/register">Sign up here</a> to join the waitlist.', false)
            ->assertSee('class="public-beta-banner"', false)
            ->assertSee('More context, more coordination, more Bean.', false)
            ->assertSee('Why upgrade', false)
            ->assertDontSee('Start light', false)
            ->assertDontSee('Simple tiers that scale with usage.', false)
            ->assertDontSee('Bean usage limits are enforced by plan.', false)
            ->assertDontSee('Why usage limits matter', false)
            ->assertDontSee('Low daily Bean cost cap', false)
            ->assertSee('7-day free trial', false)
            ->assertSee('Base', false)
            ->assertSee('Premium', false)
            ->assertSee('Pro', false)
            ->assertSee('Enterprise', false)
            ->assertSee('Most popular', false)
            ->assertSee('$4.99', false)
            ->assertSee('$19.99', false)
            ->assertSee('$49.99', false)
            ->assertSee('Contact us', false)
            ->assertSee('href="/register?plan=base"', false)
            ->assertSee('Start Premium trial', false)
            ->assertSee('href="/register?plan=premium"', false)
            ->assertSee('href="/register?plan=pro"', false)
            ->assertDontSee('During beta', false)
            ->assertDontSee('Beta note', false)
            ->assertDontSee('post-beta', false);
    }

    public function test_pricing_page_shows_flutter_upgrade_instruction_when_opened_from_app(): void
    {
        $this->get('/pricing?source=flutter')
            ->assertOk()
            ->assertSee('After upgrading on the site, close and reopen the Flutter app to apply your upgrade.', false);
    }

    public function test_register_route_preserves_selected_plan_for_spa(): void
    {
        $this->get('/register?plan=base')
            ->assertOk()
            ->assertSee('data-selected-plan="base"', false);
    }

    public function test_subscribe_route_renders_subscription_app_step(): void
    {
        $this->get('/subscribe?plan=premium&checkout=success')
            ->assertOk()
            ->assertSee('data-auth-mode="subscribe"', false)
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
