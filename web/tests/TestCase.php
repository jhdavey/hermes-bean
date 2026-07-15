<?php

namespace Tests;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AgentProfileService;
use App\Services\WelcomeConversationService;
use App\Services\WorkspaceService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

    }

    protected function apiToken(string $email = 'test@example.com'): string
    {
        $user = User::factory()->create([
            'name' => str($email)->before('@')->title()->toString(),
            'email' => $email,
            'password' => 'correct-horse-battery-staple',
        ]);
        app(AgentProfileService::class)->ensureForUser($user);
        app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        app(WelcomeConversationService::class)->ensureForUser($user);

        $token = bin2hex(random_bytes(32));
        PersonalAccessToken::create([
            'user_id' => $user->id,
            'name' => 'api',
            'token' => hash('sha256', $token),
            'expires_at' => now()->addDays(config('security.api_token_ttl_days', 90)),
        ]);

        return $token;
    }

    protected function premiumApiToken(string $email): string
    {
        $token = $this->apiToken($email);

        User::where('email', $email)->firstOrFail()
            ->forceFill(['subscription_tier' => 'premium'])
            ->save();

        return $token;
    }
}
