<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ProductionReadinessSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    public function test_auth_required_routes_return_consistent_json_error_and_security_headers(): void
    {
        $this->getJson('/api/auth/me')
            ->assertUnauthorized()
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('X-Frame-Options', 'DENY')
            ->assertHeader('Referrer-Policy', 'no-referrer')
            ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(self), microphone=(self)')
            ->assertJsonPath('error.code', 'unauthenticated')
            ->assertJsonPath('error.message', 'Unauthenticated.');
    }

    public function test_cors_preflight_allows_configured_owner_origins(): void
    {
        config(['security.cors.allowed_origins' => ['https://app.heybean.example']]);

        $this->withHeaders([
            'Origin' => 'https://app.heybean.example',
            'Access-Control-Request-Method' => 'POST',
        ])->options('/api/auth/login')
            ->assertNoContent()
            ->assertHeader('Access-Control-Allow-Origin', 'https://app.heybean.example')
            ->assertHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, PATCH, DELETE, OPTIONS')
            ->assertHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, X-Requested-With');
    }

    public function test_api_rate_limit_returns_json_error_shape(): void
    {
        config([
            'security.rate_limits.api_per_minute' => 1,
            'security.rate_limits.decay_seconds' => 60,
        ]);

        $this->postJson('/api/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable()
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0');

        $this->postJson('/api/auth/login', [
            'email' => 'missing@example.com',
            'password' => 'incorrect',
        ])->assertTooManyRequests()
            ->assertHeader('Retry-After')
            ->assertHeader('X-RateLimit-Limit', '1')
            ->assertHeader('X-RateLimit-Remaining', '0')
            ->assertJsonPath('error.code', 'rate_limited')
            ->assertJsonPath('error.message', 'Too many requests.');
    }

    public function test_web_pages_receive_security_headers(): void
    {
        $contentSecurityPolicy = "default-src 'self'; script-src 'self'; worker-src 'self'; connect-src 'self' https://api.openai.com wss://api.openai.com; img-src 'self' data:; style-src 'self' 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'self'";

        foreach (['/', '/privacy', '/terms'] as $path) {
            $this->get($path)
                ->assertOk()
                ->assertHeader('X-Content-Type-Options', 'nosniff')
                ->assertHeader('X-Frame-Options', 'DENY')
                ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin')
                ->assertHeader('Permissions-Policy', 'camera=(), geolocation=(self), microphone=(self)')
                ->assertHeader('Content-Security-Policy', $contentSecurityPolicy)
                ->assertHeaderMissing('Cross-Origin-Opener-Policy')
                ->assertHeaderMissing('Cross-Origin-Embedder-Policy');
        }
    }

    public function test_account_deletion_route_is_available_and_requires_authentication(): void
    {
        $this->deleteJson('/api/account')
            ->assertUnauthorized()
            ->assertJsonPath('error.code', 'unauthenticated');

        $token = $this->apiToken('delete-me@example.com');

        $this->withToken($token)->deleteJson('/api/account')
            ->assertNoContent();

        $this->withToken($token)->getJson('/api/auth/me')
            ->assertUnauthorized();
    }
}
