<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_present_the_bean_assistant_and_beta_signup(): void
    {
        foreach ([
            'images/bean-real-home-screen.png',
            'images/bean-real-calendar-screen.png',
            'images/bean-real-reminders-screen.png',
            'images/heybean-landing-scheduling.png',
            'images/heybean-landing-task-management.png',
            'images/heybean-landing-daily-control.png',
            'images/iphone16promax-template.png',
        ] as $asset) {
            $this->assertFileExists(public_path($asset));
        }

        $this->get('/')
            ->assertOk()
            ->assertSee('<title>HeyBean – AI Executive Assistant for Work and Life</title>', false)
            ->assertSee('meta name="description" content="HeyBean helps busy professionals and parents organize calendars, tasks, reminders, and everyday follow-through across work and home."', false)
            ->assertSee('HeyBean is currently in beta.', false)
            ->assertSee('AI EXECUTIVE ASSISTANCE FOR REAL LIFE', false)
            ->assertSee('Stop carrying every detail yourself.', false)
            ->assertSee('AI executive assistant for busy professionals and parents', false)
            ->assertSee('Built for people balancing a career, a household, and everything between them.', false)
            ->assertSee('Ask once. Bean organizes what happens next.', false)
            ->assertSee('Block 90 minutes Friday to finish the Acme proposal', false)
            ->assertSee('Bean is checking your calendar and organizing the details', false)
            ->assertSee('Focus block: Acme proposal', false)
            ->assertSee('Task: Follow up with Jordan', false)
            ->assertSee('Reminder: Ava’s school form', false)
            ->assertSee('Capture it before it slips away.', false)
            ->assertSee('Keep work and home moving together.', false)
            ->assertSee('Open Bean and know what needs your attention.', false)
            ->assertSee('More than a place to store another list.', false)
            ->assertSee('Bean works for you—and you stay in control.', false)
            ->assertSee('Let Bean take the next few things off your mind.', false)
            ->assertSee('images/heybean-landing-daily-control.png', false)
            ->assertSee('Create your free beta account', false)
            ->assertSee('Create Free Account', false)
            ->assertSee('href="/register"', false)
            ->assertSee('href="/#how-it-works"', false)
            ->assertSee('href="/#features"', false)
            ->assertSee('href="/pricing"', false)
            ->assertSee('href="/login"', false)
            ->assertDontSee('Used by <strong>', false)
            ->assertDontSee('id="reviews"', false)
            ->assertDontSee('Get Early Access', false)
            ->assertSee('AI executive assistance for real life.', false);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Choose the support your life needs.', false)
            ->assertSee('Most popular', false)
            ->assertSee('Unlimited Notes', false)
            ->assertSee('Create your free beta account', false)
            ->assertSee('href="/register?plan=base&billing_interval=monthly"', false)
            ->assertSee('href="/register?plan=premium&billing_interval=monthly"', false)
            ->assertSee('href="/register?plan=pro&billing_interval=monthly"', false)
            ->assertDontSee('Enterprise', false)
            ->assertDontSee('For teams', false);
    }

    public function test_browser_routes_render_the_app_mount(): void
    {
        foreach (['/login', '/register', '/subscribe', '/forgot-password', '/app', '/dashboard', '/admin'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('id="heybean-web-app"', false)
                ->assertSee('/build/assets/app-', false);
        }
    }

    public function test_public_pricing_and_registration_destinations_are_available(): void
    {
        $this->get('/pricing')->assertOk();
        $this->get('/register')->assertOk()->assertSee('id="heybean-web-app"', false);
        $this->get('/register?plan=premium&billing_interval=monthly')
            ->assertOk()
            ->assertSee('data-selected-plan="premium"', false)
            ->assertSee('data-selected-billing-interval="monthly"', false);
    }

    public function test_visitors_can_request_early_access(): void
    {
        $this->post(route('early-access.store'), ['email' => 'harley@example.com'])
            ->assertRedirect('/#early-access');

        $this->assertDatabaseHas('early_access_signups', [
            'email' => 'harley@example.com',
            'source' => 'landing',
        ]);
    }
}
