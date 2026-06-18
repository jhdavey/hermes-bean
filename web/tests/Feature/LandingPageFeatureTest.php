<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_homepage_is_the_heybean_beta_landing_page(): void
    {
        foreach ([
            'images/bean-logo.png',
            'images/bean-real-home-screen.png',
            'images/bean-real-calendar-screen.png',
            'images/bean-real-reminders-screen.png',
            'images/iphone16promax-template.png',
            'images/heybean-review-alex.svg',
            'images/heybean-review-maya.svg',
            'images/heybean-review-sam.svg',
        ] as $asset) {
            $this->assertFileExists(public_path($asset));
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('Plus Jakarta Sans', false)
            ->assertSee('Run your day with Bean', false)
            ->assertSee('Easy calendar, task, and reminder management with Bean', false)
            ->assertSee('aria-label="Calendar"', false)
            ->assertSee('aria-label="Tasks"', false)
            ->assertSee('aria-label="Reminders"', false)
            ->assertSee('aria-label="Voice"', false)
            ->assertSee('M12 2a3 3 0 0 0-3 3v7a3 3 0 0 0 6 0V5a3 3 0 0 0-3-3Z', false)
            ->assertSee('Used by <strong>1,122</strong> busy households and operators', false)
            ->assertSee('Try it for free', false)
            ->assertSee('Ask by voice', false)
            ->assertSee('Coordinate home + work', false)
            ->assertSee('Approve sensitive changes', false)
            ->assertSee('Ask once. Bean organizes the follow-through.', false)
            ->assertSee('class="feature-demo hero-phone image-mockup hero-device"', false)
            ->assertSee('Add dinner with Lauren Friday at 7 and remind me to bring the gift.', false)
            ->assertSee('Bean is checking your calendar...', false)
            ->assertSee('Done - dinner is on your calendar.', false)
            ->assertSee('Friday at 7:00 PM with Lauren', false)
            ->assertSee('Reminder set: bring the gift before you leave.', false)
            ->assertSee('class="bean-demo-tap calendar"', false)
            ->assertSee('class="bean-demo-tap reminders"', false)
            ->assertSee('Calendar navigation tap', false)
            ->assertSee('Reminders navigation tap', false)
            ->assertSee('images/bean-real-calendar-screen.png', false)
            ->assertSee('images/bean-real-reminders-screen.png', false)
            ->assertSee('class="bean-proof-hotspot event"', false)
            ->assertSee('class="bean-proof-hotspot reminder-card"', false)
            ->assertSee('Keep every calendar moving.', false)
            ->assertSee('Turn loose ends into managed tasks.', false)
            ->assertSee('See the day Bean is helping you run.', false)
            ->assertSee('class="mock-card schedule-board product-window"', false)
            ->assertSee('class="mock-card task-board product-window"', false)
            ->assertSee('class="mock-card daily-board product-window"', false)
            ->assertSee('HeyBean is loved by busy people who need fewer loose ends.', false)
            ->assertSee('Alex Rivera', false)
            ->assertSee('Maya Chen', false)
            ->assertSee('Sam Patel', false)
            ->assertSee('Get Early Access', false)
            ->assertSee('type="email"', false)
            ->assertSee('name="email"', false)
            ->assertSee(route('early-access.store'), false)
            ->assertSee('class="public-beta-banner"', false)
            ->assertSee('HeyBean is currently in Beta.', false)
            ->assertSee('href="/register">Sign up here</a> to join the waitlist.', false)
            ->assertSee('class="navlinks"', false)
            ->assertSee('href="/pricing">Pricing</a>', false)
            ->assertSee('href="/#reviews">Reviews</a>', false)
            ->assertSee('href="/#features">Features</a>', false)
            ->assertSee('class="nav-login" href="/login">Login</a>', false)
            ->assertDontSee('How it works', false)
            ->assertDontSee('Platforms', false)
            ->assertDontSee('FAQ', false)
            ->assertDontSee('Blog', false)
            ->assertDontSee('API', false)
            ->assertDontSee('Billing', false);
    }

    public function test_homepage_proof_count_adds_registered_users_to_seed_count(): void
    {
        User::factory()->count(3)->create();

        $this->get('/')
            ->assertOk()
            ->assertSee('Used by <strong>1,125</strong> busy households and operators', false);
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
            ->assertSee('Organized Your Days With Less Effort', false)
            ->assertSee('Monthly', false)
            ->assertSee('Yearly', false)
            ->assertSee('Save up to 17%', false)
            ->assertSee('Free trial', false)
            ->assertSee('Base', false)
            ->assertSee('Premium', false)
            ->assertSee('Pro', false)
            ->assertSee('Most popular', false)
            ->assertSee('<h3>Premium <span class="badge">Most popular</span></h3>', false)
            ->assertDontSee('<h3>Base <span class="badge">Most popular</span></h3>', false)
            ->assertDontSee('Best deal', false)
            ->assertDontSee('More Bean, with less effort.', false)
            ->assertDontSee('Start simple, then give Bean more room for workspaces', false)
            ->assertDontSee('Compare plans', false)
            ->assertDontSee('href="#plans"', false)
            ->assertSee('$4.99', false)
            ->assertSee('$19.99', false)
            ->assertSee('$49.99', false)
            ->assertSee('Start 7 day free trial', false)
            ->assertSee('href="/register?plan=base"', false)
            ->assertSee('href="/register?plan=premium"', false)
            ->assertSee('href="/register?plan=pro"', false)
            ->assertSee('$0.00 due today, cancel anytime', false)
            ->assertSee('Contact us', false)
            ->assertSee('class="public-beta-banner"', false)
            ->assertDontSee('How it works', false)
            ->assertDontSee('FAQ', false)
            ->assertDontSee('Platforms', false)
            ->assertDontSee('Blog', false)
            ->assertDontSee('API', false)
            ->assertDontSee('Billing', false);
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
            ->assertSee('We will send you an email as soon as we can share the app with you.', false)
            ->assertSee('We look forward to your help with making Bean great.', false)
            ->assertSee('Sounds good', false);
    }
}
