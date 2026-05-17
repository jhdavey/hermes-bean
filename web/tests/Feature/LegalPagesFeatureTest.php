<?php

namespace Tests\Feature;

use Tests\TestCase;

class LegalPagesFeatureTest extends TestCase
{
    public function test_public_legal_and_support_pages_are_available(): void
    {
        foreach (['/privacy', '/terms', '/support', '/account-deletion'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertSee('HeyBean', false)
                ->assertSee('images/bean-logo.png', false)
                ->assertDontSee('images/bean-logo-color.png', false)
                ->assertSee('support@heybean.org', false)
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Permissions-Policy', 'camera=(), microphone=(), geolocation=()')
                ->assertHeader('Content-Security-Policy');
        }
    }

    public function test_privacy_policy_covers_app_store_privacy_topics(): void
    {
        $this->get('/privacy')
            ->assertOk()
            ->assertSee('Privacy Policy', false)
            ->assertSee('Connected calendar data', false)
            ->assertDontSee('Google Calendar', false)
            ->assertSee('AI processing', false)
            ->assertSee('account deletion', false)
            ->assertSee('export account data', false)
            ->assertSee('Effective date', false);
    }

    public function test_terms_and_account_deletion_pages_cover_account_handling(): void
    {
        $this->get('/terms')
            ->assertOk()
            ->assertSee('Terms of Use', false)
            ->assertSee('AI assistant limitations', false)
            ->assertSee('Account deletion', false);

        $this->get('/support')
            ->assertOk()
            ->assertSee('/account-deletion', false)
            ->assertSee('account deletion instructions', false);

        $this->get('/account-deletion')
            ->assertOk()
            ->assertSee('Type DELETE', false)
            ->assertSee('data export', false)
            ->assertSee('permanently deletes', false);
    }
}
