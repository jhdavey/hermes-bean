<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LandingPageFeatureTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_pages_present_the_productivity_product_and_beta_signup(): void
    {
        $this->get('/')
            ->assertOk()
            ->assertSee('Run your day with Bean', false)
            ->assertSee('Easy calendar, task, reminder, note, and workspace management', false)
            ->assertSee('Keep every calendar moving.', false)
            ->assertSee('Turn loose ends into managed tasks.', false)
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
