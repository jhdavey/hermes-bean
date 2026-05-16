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
            ->assertSee('Meet Bean, the AI executive assistant for real life.', false)
            ->assertSee('HeyBean helps busy people and households stay organized by turning plain-language requests into calendar updates, tasks, reminders, approvals, and daily follow-through.', false)
            ->assertSee('Join the first 100', false)
            ->assertSee('Early access is limited to the first 100 people.', false)
            ->assertSee('Personal and household workspaces', false)
            ->assertSee('Google Calendar sync', false)
            ->assertSee('type="email"', false)
            ->assertSee('name="email"', false)
            ->assertSee(route('early-access.store'), false)
            ->assertDontSee('See what Bean can do', false)
            ->assertDontSee('name="name"', false)
            ->assertDontSee('name="use_case"', false)
            ->assertDontSee('Agent command center', false)
            ->assertDontSee('Flutter + Laravel', false)
            ->assertDontSee('Preview the Laravel screen', false);
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
