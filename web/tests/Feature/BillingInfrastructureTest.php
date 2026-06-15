<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SubscriptionReceiptNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
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

    public function test_mobile_subscription_setup_returns_stripe_client_secrets_without_card_data(): void
    {
        $this->configureStripe();
        $token = $this->apiToken('mobile-setup@example.com');

        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://api.stripe.com/v1/customers') {
                return Http::response(['id' => 'cus_mobile_123'], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/ephemeral_keys') {
                $this->assertSame('cus_mobile_123', $request->data()['customer']);
                $this->assertSame('2026-05-27.dahlia', $request->header('Stripe-Version')[0] ?? null);

                return Http::response(['id' => 'ephkey_test_123', 'secret' => 'ek_test_secret_123'], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/setup_intents') {
                $data = $request->data();
                $this->assertSame('cus_mobile_123', $data['customer']);
                $this->assertSame('off_session', $data['usage']);
                $this->assertSame(['card'], $data['payment_method_types']);
                $this->assertSame('subscription', $data['metadata']['purpose']);
                $this->assertSame('premium', $data['metadata']['plan']);

                return Http::response([
                    'id' => 'seti_mobile_123',
                    'client_secret' => 'seti_mobile_123_secret_abc',
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->withToken($token)->postJson('/api/billing/mobile-subscriptions/setup', [
            'plan' => 'premium',
        ])
            ->assertCreated()
            ->assertJsonPath('data.publishable_key', 'pk_test_123')
            ->assertJsonPath('data.customer_id', 'cus_mobile_123')
            ->assertJsonPath('data.customer_ephemeral_key_secret', 'ek_test_secret_123')
            ->assertJsonPath('data.setup_intent_id', 'seti_mobile_123')
            ->assertJsonPath('data.setup_intent_client_secret', 'seti_mobile_123_secret_abc')
            ->assertJsonPath('data.plan', 'premium');
    }

    public function test_mobile_subscription_confirmation_creates_subscription_with_verified_payment_method(): void
    {
        $this->configureStripe();
        Notification::fake();
        $token = $this->apiToken('mobile-confirm@example.com');
        $user = User::where('email', 'mobile-confirm@example.com')->firstOrFail();
        $user->forceFill(['stripe_customer_id' => 'cus_mobile_confirm_123'])->save();

        Http::fake(function (HttpRequest $request) use ($user) {
            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/setup_intents/seti_mobile_123')) {
                $this->assertSame(['payment_method'], $request->data()['expand']);

                return Http::response([
                    'id' => 'seti_mobile_123',
                    'customer' => 'cus_mobile_confirm_123',
                    'status' => 'succeeded',
                    'payment_method' => [
                        'id' => 'pm_card_visa',
                        'customer' => 'cus_mobile_confirm_123',
                        'type' => 'card',
                        'card' => [
                            'brand' => 'visa',
                            'last4' => '4242',
                            'exp_month' => 12,
                            'exp_year' => 2032,
                        ],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/customers/cus_mobile_confirm_123') {
                $this->assertSame('pm_card_visa', $request->data()['invoice_settings']['default_payment_method']);

                return Http::response(['id' => 'cus_mobile_confirm_123'], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/subscriptions') {
                $data = $request->data();
                $this->assertSame('cus_mobile_confirm_123', $data['customer']);
                $this->assertSame('pm_card_visa', $data['default_payment_method']);
                $this->assertSame('price_premium_test', $data['items'][0]['price']);
                $this->assertSame(1, $data['items'][0]['quantity']);
                $this->assertSame(7, $data['trial_period_days']);
                $this->assertSame('on_subscription', $data['payment_settings']['save_default_payment_method']);

                return Http::response([
                    'id' => 'sub_mobile_123',
                    'customer' => 'cus_mobile_confirm_123',
                    'status' => 'trialing',
                    'current_period_end' => now()->addDays(7)->timestamp,
                    'trial_end' => now()->addDays(7)->timestamp,
                    'cancel_at_period_end' => false,
                    'metadata' => [
                        'heybean_user_id' => (string) $user->id,
                        'plan' => 'premium',
                    ],
                    'items' => ['data' => [[
                        'id' => 'si_mobile_123',
                        'price' => ['id' => 'price_premium_test'],
                    ]]],
                ], 200);
            }

            return Http::response([], 404);
        });

        $this->withToken($token)->postJson('/api/billing/mobile-subscriptions/confirm', [
            'plan' => 'premium',
            'setup_intent_id' => 'seti_mobile_123',
        ])
            ->assertOk()
            ->assertJsonPath('data.plan', 'premium')
            ->assertJsonPath('data.subscription.tier', 'premium')
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.payment_method.brand', 'visa')
            ->assertJsonPath('data.payment_method.last4', '4242');

        $user->refresh();
        $this->assertSame('premium', $user->subscription_tier);
        $this->assertSame('sub_mobile_123', $user->stripe_subscription_id);
        $this->assertSame('si_mobile_123', $user->stripe_subscription_item_id);

        Notification::assertSentTo($user, SubscriptionReceiptNotification::class, function (SubscriptionReceiptNotification $notification) use ($user): bool {
            $html = (string) $notification->toMail($user)->render();

            return str_contains($html, 'Subscription started')
                && str_contains($html, 'Your HeyBean Premium subscription is set up.');
        });
    }

    public function test_mobile_subscription_confirmation_rejects_setup_intent_for_another_customer(): void
    {
        $this->configureStripe();
        $token = $this->apiToken('wrong-customer@example.com');
        User::where('email', 'wrong-customer@example.com')->firstOrFail()
            ->forceFill(['stripe_customer_id' => 'cus_right_123'])
            ->save();

        Http::fake([
            'https://api.stripe.com/v1/setup_intents/seti_wrong_123*' => Http::response([
                'id' => 'seti_wrong_123',
                'customer' => 'cus_wrong_123',
                'status' => 'succeeded',
                'payment_method' => 'pm_wrong_123',
            ], 200),
        ]);

        $this->withToken($token)->postJson('/api/billing/mobile-subscriptions/confirm', [
            'plan' => 'premium',
            'setup_intent_id' => 'seti_wrong_123',
        ])
            ->assertStatus(422)
            ->assertJsonPath('message', 'That payment setup does not belong to this account.');
    }

    public function test_user_can_retrieve_and_update_safe_payment_method_summary(): void
    {
        $this->configureStripe();
        $token = $this->apiToken('payment-method@example.com');
        $user = User::where('email', 'payment-method@example.com')->firstOrFail();
        $user->forceFill([
            'stripe_customer_id' => 'cus_pm_123',
            'stripe_subscription_id' => 'sub_pm_123',
        ])->save();

        Http::fake(function (HttpRequest $request) {
            if ($request->url() === 'https://api.stripe.com/v1/subscriptions/sub_pm_123') {
                if (($request->method() ?? 'GET') === 'GET') {
                    return Http::response(['id' => 'sub_pm_123', 'default_payment_method' => 'pm_old_123'], 200);
                }

                $this->assertSame('pm_new_123', $request->data()['default_payment_method']);

                return Http::response(['id' => 'sub_pm_123'], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/payment_methods/pm_old_123') {
                return Http::response([
                    'id' => 'pm_old_123',
                    'customer' => 'cus_pm_123',
                    'type' => 'card',
                    'card' => ['brand' => 'mastercard', 'last4' => '4444', 'exp_month' => 10, 'exp_year' => 2031],
                ], 200);
            }

            if (str_starts_with($request->url(), 'https://api.stripe.com/v1/setup_intents/seti_pm_123')) {
                return Http::response([
                    'id' => 'seti_pm_123',
                    'customer' => 'cus_pm_123',
                    'status' => 'succeeded',
                    'payment_method' => [
                        'id' => 'pm_new_123',
                        'customer' => 'cus_pm_123',
                        'type' => 'card',
                        'card' => ['brand' => 'visa', 'last4' => '4242', 'exp_month' => 11, 'exp_year' => 2032],
                    ],
                ], 200);
            }

            if ($request->url() === 'https://api.stripe.com/v1/customers/cus_pm_123') {
                $this->assertSame('pm_new_123', $request->data()['invoice_settings']['default_payment_method']);

                return Http::response(['id' => 'cus_pm_123'], 200);
            }

            return Http::response([], 404);
        });

        $this->withToken($token)->getJson('/api/billing/payment-method')
            ->assertOk()
            ->assertJsonPath('data.payment_method.brand', 'mastercard')
            ->assertJsonPath('data.payment_method.last4', '4444');

        $this->withToken($token)->postJson('/api/billing/payment-method/confirm', [
            'setup_intent_id' => 'seti_pm_123',
        ])
            ->assertOk()
            ->assertJsonPath('data.payment_method.brand', 'visa')
            ->assertJsonPath('data.payment_method.last4', '4242');
    }

    public function test_existing_subscription_upgrade_charges_proration_for_current_cycle(): void
    {
        $this->configureStripe();
        Notification::fake();
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

        Notification::assertSentTo($user, SubscriptionReceiptNotification::class, function (SubscriptionReceiptNotification $notification) use ($user): bool {
            $html = (string) $notification->toMail($user)->render();

            return str_contains($html, 'Subscription upgraded')
                && str_contains($html, 'Your HeyBean subscription is now on the Pro plan.');
        });
    }

    public function test_existing_subscription_cancel_sets_renewal_to_period_end(): void
    {
        $this->configureStripe();
        Notification::fake();
        $token = $this->apiToken('cancel@example.com');
        $user = User::where('email', 'cancel@example.com')->firstOrFail();
        $user->forceFill([
            'subscription_tier' => 'base',
            'stripe_customer_id' => 'cus_cancel_123',
            'stripe_subscription_id' => 'sub_cancel_123',
            'stripe_subscription_item_id' => 'si_cancel_123',
            'stripe_price_id' => 'price_base_test',
            'subscription_status' => 'trialing',
        ])->save();

        Http::fake(function (HttpRequest $request) use ($user) {
            $this->assertSame('https://api.stripe.com/v1/subscriptions/sub_cancel_123', $request->url());
            $this->assertSame('2026-05-27.dahlia', $request->header('Stripe-Version')[0] ?? null);
            $data = $request->data();
            $this->assertTrue($data['cancel_at_period_end']);
            $this->assertSame((string) $user->id, $data['metadata']['heybean_user_id']);
            $this->assertSame('base', $data['metadata']['plan']);
            $this->assertSame('flutter', $data['metadata']['source']);

            return Http::response([
                'id' => 'sub_cancel_123',
                'customer' => 'cus_cancel_123',
                'status' => 'trialing',
                'current_period_end' => now()->addDays(7)->timestamp,
                'trial_end' => now()->addDays(7)->timestamp,
                'cancel_at_period_end' => true,
                'metadata' => [
                    'heybean_user_id' => (string) $user->id,
                    'plan' => 'base',
                ],
                'items' => ['data' => [[
                    'id' => 'si_cancel_123',
                    'price' => ['id' => 'price_base_test'],
                ]]],
            ], 200);
        });

        $this->withToken($token)->postJson('/api/billing/subscription/cancel')
            ->assertOk()
            ->assertJsonPath('data.subscription.tier', 'base')
            ->assertJsonPath('data.subscription.status', 'trialing')
            ->assertJsonPath('data.subscription.cancel_at_period_end', true);

        $user->refresh();
        $this->assertSame('trialing', $user->subscription_status);
        $this->assertTrue($user->subscription_cancel_at_period_end);

        Notification::assertSentTo($user, SubscriptionReceiptNotification::class, function (SubscriptionReceiptNotification $notification) use ($user): bool {
            $html = (string) $notification->toMail($user)->render();

            return str_contains($html, 'Renewal canceled')
                && str_contains($html, 'Renewal has been canceled for your Base plan.');
        });
    }

    public function test_stripe_webhook_updates_user_subscription_tier(): void
    {
        $this->configureStripe();
        Notification::fake();
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

        Notification::assertSentTo($user, SubscriptionReceiptNotification::class, function (SubscriptionReceiptNotification $notification) use ($user): bool {
            $html = (string) $notification->toMail($user)->render();

            return str_contains($html, 'Subscription started')
                && str_contains($html, 'Your HeyBean Premium subscription is set up.');
        });
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
        config()->set('services.stripe.publishable_key', 'pk_test_123');
        config()->set('services.stripe.api_version', '2026-05-27.dahlia');
        config()->set('services.stripe.webhook_secret', null);
        config()->set('services.stripe.trial_days', 7);
        config()->set('services.stripe.prices.base', 'price_base_test');
        config()->set('services.stripe.prices.premium', 'price_premium_test');
        config()->set('services.stripe.prices.pro', 'price_pro_test');
    }
}
