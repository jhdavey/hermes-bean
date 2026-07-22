<?php

namespace Tests\Feature;

use App\Models\EarlyAccessSignup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EarlyAccessRolloutTest extends TestCase
{
    use RefreshDatabase;

    public function test_public_admission_reserves_a_real_slot_but_keeps_displayed_scarcity_static(): void
    {
        $this->postJson('/api/early-access', [
            'email' => 'first@example.com',
            'source' => 'test',
        ])->assertOk()
            ->assertJsonPath('data.status', 'admitted')
            ->assertJsonPath('data.display_remaining', 24)
            ->assertJsonPath('data.capacity', 100);

        $this->postJson('/api/early-access', [
            'email' => 'second@example.com',
            'source' => 'test',
        ])->assertOk()->assertJsonPath('data.display_remaining', 24);

        $this->assertDatabaseHas('early_access_rollouts', [
            'key' => 'public_beta',
            'admitted_count' => 2,
        ]);
    }

    public function test_full_rollout_creates_waitlist_record_without_creating_an_account(): void
    {
        DB::table('early_access_rollouts')->where('key', 'public_beta')->update(['admitted_count' => 100]);

        $this->postJson('/api/auth/register', [
            'name' => 'Waiting Person',
            'email' => 'waiting@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertAccepted()
            ->assertJsonPath('code', 'early_access_waitlisted')
            ->assertJsonPath('data.display_remaining', 24);

        $this->assertDatabaseMissing('users', ['email' => 'waiting@example.com']);
        $this->assertDatabaseHas('early_access_signups', [
            'email' => 'waiting@example.com',
            'status' => 'waitlisted',
        ]);
    }

    public function test_admitted_account_cannot_use_product_api_until_subscription_is_trialing(): void
    {
        $token = $this->postJson('/api/auth/register', [
            'name' => 'Trial User',
            'email' => 'trial@example.com',
            'password' => 'correct-horse-battery-staple',
            'password_confirmation' => 'correct-horse-battery-staple',
        ])->assertCreated()
            ->assertJsonPath('data.user.access_state', 'subscription_required')
            ->assertJsonPath('data.user.trial_days', 7)
            ->json('data.token');

        $this->withToken($token)->getJson('/api/tasks')
            ->assertPaymentRequired()
            ->assertJsonPath('code', 'subscription_required');

        User::where('email', 'trial@example.com')->firstOrFail()
            ->forceFill(['subscription_status' => 'trialing'])
            ->save();

        $this->withToken($token)->getJson('/api/tasks')->assertOk();
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertJsonPath('data.access_state', 'active')
            ->assertJsonPath('data.is_early_access', true);
    }

    public function test_pre_rollout_account_remains_usable_without_consuming_public_capacity(): void
    {
        $token = $this->apiToken('internal@example.com');

        $this->withToken($token)->getJson('/api/tasks')->assertOk();
        $this->assertSame(0, (int) DB::table('early_access_rollouts')->where('key', 'public_beta')->value('admitted_count'));
        $this->assertNull(EarlyAccessSignup::where('email', 'internal@example.com')->first());
    }
}
