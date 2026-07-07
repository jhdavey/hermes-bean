<?php

namespace Tests\Feature;

use App\Models\CouponCode;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CouponCodeTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_list_and_delete_coupon_codes(): void
    {
        $adminToken = $this->apiToken('coupon-admin@example.com');
        $userToken = $this->apiToken('coupon-user@example.com');
        User::where('email', 'coupon-admin@example.com')->firstOrFail()
            ->forceFill(['is_admin' => true])
            ->save();

        $this->withToken($userToken)->postJson('/api/admin/coupon-codes', [
            'code' => '123456',
            'months_free_base' => 3,
        ])->assertForbidden();

        $couponId = $this->withToken($adminToken)->postJson('/api/admin/coupon-codes', [
            'code' => '123456',
            'months_free_base' => 3,
        ])->assertCreated()
            ->assertJsonPath('data.code', '123456')
            ->assertJsonPath('data.months_free_base', 3)
            ->assertJsonPath('data.used', false)
            ->json('data.id');

        $this->withToken($adminToken)->postJson('/api/admin/coupon-codes', [
            'months_free_base' => 1,
        ])->assertCreated()
            ->assertJson(fn ($json) => $json
                ->where('data.months_free_base', 1)
                ->where('data.used', false)
                ->whereType('data.code', 'string')
                ->etc());

        $this->withToken($adminToken)->getJson('/api/admin/coupon-codes')
            ->assertOk()
            ->assertJsonFragment(['code' => '123456']);

        $this->withToken($adminToken)->deleteJson("/api/admin/coupon-codes/{$couponId}")
            ->assertOk()
            ->assertJsonPath('data.deleted', true);

        $this->assertSoftDeleted('coupon_codes', ['id' => $couponId]);
    }

    public function test_user_can_redeem_coupon_once_for_free_base_access(): void
    {
        $adminToken = $this->apiToken('coupon-owner@example.com');
        $userToken = $this->apiToken('coupon-recipient@example.com');
        $otherToken = $this->apiToken('coupon-other@example.com');
        User::where('email', 'coupon-owner@example.com')->firstOrFail()
            ->forceFill(['is_admin' => true])
            ->save();

        $this->withToken($adminToken)->postJson('/api/admin/coupon-codes', [
            'code' => '654321',
            'months_free_base' => 2,
        ])->assertCreated();

        $response = $this->withToken($userToken)->postJson('/api/billing/coupon-codes/redeem', [
            'code' => '654321',
        ])->assertOk()
            ->assertJsonPath('data.coupon.used', true)
            ->assertJsonPath('data.subscription.tier', 'base')
            ->assertJsonPath('data.subscription.status', 'active');

        $expiresAt = $response->json('data.subscription.base_comp_expires_at');
        $this->assertNotEmpty($expiresAt);

        $recipient = User::where('email', 'coupon-recipient@example.com')->firstOrFail();
        $coupon = CouponCode::where('code', '654321')->firstOrFail();

        $this->assertSame($recipient->id, $coupon->redeemed_by_user_id);
        $this->assertNotNull($coupon->redeemed_at);
        $this->assertNotNull($recipient->fresh()->base_comp_expires_at);
        $this->assertSame('active', $recipient->fresh()->subscription_status);

        $this->withToken($otherToken)->postJson('/api/billing/coupon-codes/redeem', [
            'code' => '654321',
        ])->assertUnprocessable()
            ->assertJsonPath('message', 'Coupon code has already been used.');
    }

    public function test_expired_coupon_base_access_no_longer_counts_as_active(): void
    {
        $token = $this->apiToken('coupon-expired@example.com');
        $user = User::where('email', 'coupon-expired@example.com')->firstOrFail();
        $user->forceFill([
            'subscription_tier' => 'base',
            'subscription_status' => 'active',
            'subscription_current_period_end' => now()->subDay(),
            'base_comp_expires_at' => now()->subDay(),
        ])->save();

        $this->withToken($token)->getJson('/api/billing/subscription')
            ->assertOk()
            ->assertJsonPath('data.tier', 'base')
            ->assertJsonPath('data.status', 'canceled');
    }
}
