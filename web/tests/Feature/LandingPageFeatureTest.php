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
            ->assertSee('HeyBean', false)
            ->assertSee('A real-world usable AI agent for your day', false)
            ->assertSee('Ask Bean to schedule workouts, create reminders, plan your calendar, and keep risky actions waiting for your approval.', false)
            ->assertSee('Join the beta', false)
            ->assertSee(route('early-access.store'), false)
            ->assertDontSee('Agent command center', false)
            ->assertDontSee('Flutter + Laravel', false)
            ->assertDontSee('Preview the Laravel screen', false);
    }

    public function test_visitors_can_request_early_access(): void
    {
        $response = $this->post(route('early-access.store'), [
            'name' => 'Harley Davey',
            'email' => 'harley@example.com',
            'use_case' => 'I want Bean to help run tasks, reminders, calendar events, and approvals for my household.',
        ]);

        $response->assertRedirect('/#early-access');
        $response->assertSessionHas('early_access_status', 'You are on the HeyBean early access list. We will reach out when your invite is ready.');

        $this->assertDatabaseHas('early_access_signups', [
            'name' => 'Harley Davey',
            'email' => 'harley@example.com',
            'use_case' => 'I want Bean to help run tasks, reminders, calendar events, and approvals for my household.',
        ]);
    }
}
