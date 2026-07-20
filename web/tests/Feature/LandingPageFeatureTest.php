<?php

namespace Tests\Feature;

use App\Models\User;
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
            ->assertSee('Run your day with Bean', false)
            ->assertSee('Easy calendar, task, and reminder management with Bean', false)
            ->assertSee('Approve sensitive changes', false)
            ->assertSee('Ask once. Bean organizes the follow-through.', false)
            ->assertSee('Bean is checking your calendar...', false)
            ->assertSee('Done - dinner is on your calendar.', false)
            ->assertSee('Keep every calendar moving.', false)
            ->assertSee('Turn loose ends into managed tasks.', false)
            ->assertSee('See the day Bean is helping you run.', false)
            ->assertSee('images/heybean-landing-daily-control.png', false)
            ->assertSee('Get Early Access', false)
            ->assertSee(route('early-access.store'), false)
            ->assertSee('href="/pricing"', false)
            ->assertSee('href="/login"', false);

        $this->get('/pricing')
            ->assertOk()
            ->assertSee('Organized Your Days With Less Effort', false)
            ->assertSee('Most popular', false)
            ->assertSee('Unlimited Notes', false)
            ->assertSee('Start 7 day free trial', false);
    }

    public function test_proof_count_adds_registered_users_to_seed_count(): void
    {
        User::factory()->count(3)->create();

        $this->get('/')->assertSee('Used by <strong>1,125</strong>', false);
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
