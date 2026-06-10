<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BillingInfrastructureTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_create_paid_plan_checkout_session(): void
    {
        $this->configureStripe();
        $token = $this->apiToken('checkout@example.com');

        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://api.stripe.com/v1/customers') {
                return Http::response(['id' => 'cus_test_123'], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                $data = $request->data();
                $this->assertSame('subscription', $data['mode']);
                $this->assertSame('cus_test_123', $data['customer']);
                $this->assertSame('price_premium_test', $data['line_items'][0]['price']);
                $this->assertSame(1, $data['line_items'][0]['quantity']);
                $this->assertSame(7, $data['subscription_data']['trial_period_days']);
                $this->assertSame('premium', $data['subscription_data']['metadata']['plan']);
                $this->assertSame('flutter', $data['subscription_data']['metadata']['source']);
                $this->assertStringContainsString('source=flutter', $data['success_url']);

                return Http::response([
                    'id' => 'cs_test_123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_test_123',
                    'status' => 'open',
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->withToken($token)->postJson('/api/billing/checkout-sessions', [
            'plan' => 'premium',
            'source' => 'flutter',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', 'cs_test_123')
            ->assertJsonPath('data.url', 'https://checkout.stripe.com/c/pay/cs_test_123')
            ->assertJsonPath('data.plan', 'premium');

        $this->assertSame('cus_test_123', User::where('email', 'checkout@example.com')->firstOrFail()->stripe_customer_id);
    }

    public function test_signup_checkout_sessions_return_to_subscription_flow(): void
    {
        $this->configureStripe();
        $token = $this->apiToken('subscribe@example.com');

        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://api.stripe.com/v1/customers') {
                return Http::response(['id' => 'cus_subscribe_123'], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/checkout/sessions') {
                $data = $request->data();
                $this->assertStringContainsString('/subscribe?checkout=success&plan=base&source=subscribe', $data['success_url']);
                $this->assertStringContainsString('/subscribe?checkout=cancel&plan=base&source=subscribe', $data['cancel_url']);
                $this->assertSame('subscribe', $data['metadata']['source']);

                return Http::response([
                    'id' => 'cs_subscribe_123',
                    'url' => 'https://checkout.stripe.com/c/pay/cs_subscribe_123',
                    'status' => 'open',
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->withToken($token)->postJson('/api/billing/checkout-sessions', [
            'plan' => 'base',
            'source' => 'subscribe',
        ])
            ->assertCreated()
            ->assertJsonPath('data.id', 'cs_subscribe_123')
            ->assertJsonPath('data.plan', 'base');
    }

    public function test_existing_subscription_upgrade_charges_proration_for_current_cycle(): void
    {
        $this->configureStripe();
        $token = $this->apiToken('upgrade@example.com');
        $user = User::where('email', 'upgrade@example.com')->firstOrFail();
        $user->forceFill([
            'subscription_tier' => 'premium',
            'stripe_customer_id' => 'cus_test_123',
            'stripe_subscription_id' => 'sub_test_123',
            'stripe_subscription_item_id' => 'si_test_123',
            'stripe_price_id' => 'price_premium_test',
            'subscription_status' => 'active',
        ])->save();

        Http::fake(function (HttpRequest $request) use ($user) {
            $this->assertSame('https://api.stripe.com/v1/subscriptions/sub_test_123', $request->url());
            $data = $request->data();
            $this->assertSame('si_test_123', $data['items'][0]['id']);
            $this->assertSame('price_pro_test', $data['items'][0]['price']);
            $this->assertSame('always_invoice', $data['proration_behavior']);
            $this->assertSame('allow_incomplete', $data['payment_behavior']);

            return Http::response([
                'id' => 'sub_test_123',
                'customer' => 'cus_test_123',
                'status' => 'active',
                'current_period_end' => now()->addDays(15)->timestamp,
                'trial_end' => null,
                'cancel_at_period_end' => false,
                'metadata' => [
                    'heybean_user_id' => (string) $user->id,
                    'plan' => 'pro',
                ],
                'items' => ['data' => [[
                    'id' => 'si_test_123',
                    'price' => ['id' => 'price_pro_test'],
                ]]],
            ], 200);
        });

        $this->withToken($token)->postJson('/api/billing/subscription/upgrade', [
            'plan' => 'pro',
        ])
            ->assertOk()
            ->assertJsonPath('data.plan', 'pro')
            ->assertJsonPath('data.subscription.tier', 'pro')
            ->assertJsonPath('data.subscription.status', 'active');

        $user->refresh();
        $this->assertSame('pro', $user->subscription_tier);
        $this->assertSame('price_pro_test', $user->stripe_price_id);
    }

    public function test_stripe_webhook_updates_user_subscription_tier(): void
    {
        $this->configureStripe();
        config()->set('services.stripe.webhook_secret', 'whsec_test');
        $user = User::factory()->create([
            'email' => 'webhook@example.com',
            'subscription_tier' => 'free',
            'stripe_customer_id' => 'cus_test_123',
        ]);
        $payload = json_encode([
            'id' => 'evt_test_123',
            'type' => 'customer.subscription.updated',
            'data' => ['object' => [
                'id' => 'sub_test_123',
                'customer' => 'cus_test_123',
                'status' => 'active',
                'current_period_end' => 1_800_000_000,
                'trial_end' => null,
                'cancel_at_period_end' => false,
                'metadata' => [
                    'heybean_user_id' => (string) $user->id,
                    'plan' => 'premium',
                ],
                'items' => ['data' => [[
                    'id' => 'si_test_123',
                    'price' => ['id' => 'price_premium_test'],
                ]]],
            ]],
        ], JSON_THROW_ON_ERROR);
        $timestamp = (string) now()->timestamp;
        $signature = 't='.$timestamp.',v1='.hash_hmac('sha256', $timestamp.'.'.$payload, 'whsec_test');

        $this->withHeader('Stripe-Signature', $signature)
            ->postJson('/api/billing/stripe/webhook', json_decode($payload, true, flags: JSON_THROW_ON_ERROR))
            ->assertOk()
            ->assertJsonPath('received', true);

        $user->refresh();
        $this->assertSame('premium', $user->subscription_tier);
        $this->assertSame('sub_test_123', $user->stripe_subscription_id);
        $this->assertSame('si_test_123', $user->stripe_subscription_item_id);
    }

    public function test_missing_stripe_configuration_returns_clear_error(): void
    {
        config()->set('services.stripe.secret', null);
        config()->set('services.stripe.prices.premium', 'price_premium_test');
        $token = $this->apiToken('missing-stripe@example.com');

        $this->withToken($token)->postJson('/api/billing/checkout-sessions', [
            'plan' => 'premium',
        ])
            ->assertStatus(503)
            ->assertJsonPath('message', 'Stripe is not configured.');
    }

    private function configureStripe(): void
    {
        config()->set('services.stripe.secret', 'sk_test_123');
        config()->set('services.stripe.webhook_secret', null);
        config()->set('services.stripe.trial_days', 7);
        config()->set('services.stripe.prices.base', 'price_base_test');
        config()->set('services.stripe.prices.premium', 'price_premium_test');
        config()->set('services.stripe.prices.pro', 'price_pro_test');
    }
}
