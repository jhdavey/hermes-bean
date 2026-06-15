<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\SubscriptionReceiptNotification;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class StripeBillingService
{
    private const PLAN_RANKS = [
        'base' => 0,
        'free' => 0,
        'premium' => 1,
        'pro' => 2,
    ];

    public function subscriptionSummary(User $user): array
    {
        return [
            'tier' => $user->subscriptionTier(),
            'status' => $user->subscription_status,
            'current_period_end' => $user->subscription_current_period_end?->toIso8601String(),
            'trial_ends_at' => $user->subscription_trial_ends_at?->toIso8601String(),
            'cancel_at_period_end' => (bool) $user->subscription_cancel_at_period_end,
            'can_upgrade' => $user->subscriptionTier() !== 'pro',
        ];
    }

    public function paymentMethodSummary(User $user): array
    {
        if (! $user->stripe_customer_id) {
            return ['payment_method' => null];
        }

        $paymentMethodId = $this->defaultPaymentMethodId($user);
        if (! $paymentMethodId) {
            return ['payment_method' => null];
        }

        $paymentMethod = $this->stripeGet('/payment_methods/'.$paymentMethodId)->json();

        return ['payment_method' => $this->paymentMethodDisplay($paymentMethod, $user)];
    }

    public function createCheckoutSession(User $user, string $plan, ?string $source = null): array
    {
        $this->assertCheckoutPlan($plan);
        $priceId = $this->priceId($plan);
        $customerId = $this->ensureCustomer($user);
        $source = $this->cleanSource($source);
        $returnPath = in_array($source, ['register', 'signup', 'subscribe'], true) ? '/subscribe' : '/pricing';
        if ($source === 'settings') {
            $successUrl = url('/app?billing=plan_success&plan='.$plan);
            $cancelUrl = url('/app?billing=plan_cancel&plan='.$plan);
        } else {
            $successUrl = url($returnPath.'?checkout=success&plan='.$plan.($source ? '&source='.$source : ''));
            $cancelUrl = url($returnPath.'?checkout=cancel&plan='.$plan.($source ? '&source='.$source : ''));
        }

        $payload = [
            'mode' => 'subscription',
            'customer' => $customerId,
            'line_items' => [[
                'price' => $priceId,
                'quantity' => 1,
            ]],
            'success_url' => $successUrl,
            'cancel_url' => $cancelUrl,
            'metadata' => [
                'heybean_user_id' => (string) $user->id,
                'plan' => $plan,
                'source' => $source ?: 'web',
            ],
            'subscription_data' => [
                'trial_period_days' => max(0, (int) config('services.stripe.trial_days', 7)),
                'metadata' => [
                    'heybean_user_id' => (string) $user->id,
                    'plan' => $plan,
                    'source' => $source ?: 'web',
                ],
            ],
        ];

        $session = $this->stripePost('/checkout/sessions', $payload)->json();

        return [
            'id' => $session['id'] ?? null,
            'url' => $session['url'] ?? null,
            'plan' => $plan,
            'status' => $session['status'] ?? 'created',
        ];
    }

    public function createPaymentMethodCheckoutSession(User $user): array
    {
        $customerId = $this->ensureCustomer($user);

        $session = $this->stripePost('/checkout/sessions', [
            'mode' => 'setup',
            'customer' => $customerId,
            'payment_method_types' => ['card'],
            'success_url' => url('/app?billing=payment_success'),
            'cancel_url' => url('/app?billing=payment_cancel'),
            'metadata' => [
                'heybean_user_id' => (string) $user->id,
                'purpose' => 'payment_method_update',
                'source' => 'web',
            ],
            'setup_intent_data' => [
                'metadata' => [
                    'heybean_user_id' => (string) $user->id,
                    'purpose' => 'payment_method_update',
                    'source' => 'web',
                ],
            ],
        ])->json();

        return [
            'id' => $session['id'] ?? null,
            'url' => $session['url'] ?? null,
            'status' => $session['status'] ?? 'created',
        ];
    }

    public function createMobileSubscriptionSetup(User $user, string $plan): array
    {
        $this->assertCheckoutPlan($plan);

        return $this->createSetupIntentResponse($user, [
            'purpose' => 'subscription',
            'plan' => $plan,
        ], ['plan' => $plan]);
    }

    public function confirmMobileSubscription(User $user, string $plan, string $setupIntentId): array
    {
        $this->assertCheckoutPlan($plan);
        $setupIntent = $this->verifiedSetupIntent($user, $setupIntentId);
        $paymentMethodId = $this->paymentMethodIdFromSetupIntent($setupIntent);

        $this->setCustomerDefaultPaymentMethod($user, $paymentMethodId);

        if ($user->stripe_subscription_id && $user->stripe_subscription_item_id) {
            $subscription = $this->stripePost('/subscriptions/'.$user->stripe_subscription_id, [
                'cancel_at_period_end' => false,
                'default_payment_method' => $paymentMethodId,
                'items' => [[
                    'id' => $user->stripe_subscription_item_id,
                    'price' => $this->priceId($plan),
                ]],
                'proration_behavior' => 'always_invoice',
                'payment_behavior' => 'allow_incomplete',
                'metadata' => [
                    'heybean_user_id' => (string) $user->id,
                    'plan' => $plan,
                    'source' => 'flutter',
                ],
            ])->json();
        } else {
            $subscription = $this->stripePost('/subscriptions', [
                'customer' => $user->stripe_customer_id,
                'default_payment_method' => $paymentMethodId,
                'items' => [[
                    'price' => $this->priceId($plan),
                    'quantity' => 1,
                ]],
                'trial_period_days' => max(0, (int) config('services.stripe.trial_days', 7)),
                'payment_behavior' => 'allow_incomplete',
                'payment_settings' => [
                    'save_default_payment_method' => 'on_subscription',
                ],
                'metadata' => [
                    'heybean_user_id' => (string) $user->id,
                    'plan' => $plan,
                    'source' => 'flutter',
                ],
            ])->json();
        }

        $this->syncSubscription($subscription, $user);
        $freshUser = $user->fresh();

        return [
            'plan' => $plan,
            'subscription' => $this->subscriptionSummary($freshUser),
            'payment_method' => $this->paymentMethodDisplayFromSetupIntent($setupIntent, $freshUser),
        ];
    }

    public function createPaymentMethodSetup(User $user): array
    {
        return $this->createSetupIntentResponse($user, [
            'purpose' => 'payment_method_update',
        ]);
    }

    public function confirmPaymentMethodSetup(User $user, string $setupIntentId): array
    {
        $setupIntent = $this->verifiedSetupIntent($user, $setupIntentId);
        $paymentMethodId = $this->paymentMethodIdFromSetupIntent($setupIntent);

        $this->setCustomerDefaultPaymentMethod($user, $paymentMethodId);
        if ($user->stripe_subscription_id) {
            $this->stripePost('/subscriptions/'.$user->stripe_subscription_id, [
                'default_payment_method' => $paymentMethodId,
            ]);
        }

        return [
            'payment_method' => $this->paymentMethodDisplayFromSetupIntent($setupIntent, $user),
        ];
    }

    public function upgradeSubscription(User $user, string $plan): array
    {
        $this->assertPaidPlan($plan);
        if (! $user->stripe_subscription_id || ! $user->stripe_subscription_item_id) {
            throw new InvalidArgumentException('No active Stripe subscription was found for this account.');
        }

        $currentRank = self::PLAN_RANKS[$user->subscriptionTier()] ?? 0;
        $nextRank = self::PLAN_RANKS[$plan] ?? 0;
        if ($nextRank <= $currentRank) {
            throw new InvalidArgumentException('Choose a higher plan to upgrade.');
        }

        $subscription = $this->stripePost('/subscriptions/'.$user->stripe_subscription_id, [
            'items' => [[
                'id' => $user->stripe_subscription_item_id,
                'price' => $this->priceId($plan),
            ]],
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'allow_incomplete',
            'metadata' => [
                'heybean_user_id' => (string) $user->id,
                'plan' => $plan,
            ],
        ])->json();

        $this->syncSubscription($subscription, $user);

        return [
            'plan' => $plan,
            'status' => $subscription['status'] ?? $user->subscription_status,
            'subscription' => $this->subscriptionSummary($user->fresh()),
        ];
    }

    public function changeSubscriptionPlan(User $user, string $plan): array
    {
        $this->assertCheckoutPlan($plan);
        if (! $user->stripe_subscription_id || ! $user->stripe_subscription_item_id) {
            return $this->createCheckoutSession($user, $plan, 'settings');
        }

        if ($user->subscriptionTier() === $plan) {
            throw new InvalidArgumentException('That plan is already active for this account.');
        }

        $subscription = $this->stripePost('/subscriptions/'.$user->stripe_subscription_id, [
            'cancel_at_period_end' => false,
            'items' => [[
                'id' => $user->stripe_subscription_item_id,
                'price' => $this->priceId($plan),
            ]],
            'proration_behavior' => 'always_invoice',
            'payment_behavior' => 'allow_incomplete',
            'metadata' => [
                'heybean_user_id' => (string) $user->id,
                'plan' => $plan,
                'source' => 'web',
            ],
        ])->json();

        $this->syncSubscription($subscription, $user);

        return [
            'plan' => $plan,
            'status' => $subscription['status'] ?? $user->subscription_status,
            'subscription' => $this->subscriptionSummary($user->fresh()),
        ];
    }

    public function cancelSubscription(User $user): array
    {
        if (! $user->stripe_subscription_id) {
            throw new InvalidArgumentException('No active Stripe subscription was found for this account.');
        }

        $subscription = $this->stripePost('/subscriptions/'.$user->stripe_subscription_id, [
            'cancel_at_period_end' => true,
            'metadata' => [
                'heybean_user_id' => (string) $user->id,
                'plan' => $user->subscriptionTier(),
                'source' => 'flutter',
            ],
        ])->json();

        $this->syncSubscription($subscription, $user);

        return [
            'subscription' => $this->subscriptionSummary($user->fresh()),
        ];
    }

    public function handleWebhook(string $payload, ?string $signature): void
    {
        $event = $this->verifiedWebhookEvent($payload, $signature);
        $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        if (! is_array($object)) {
            return;
        }

        match ($type) {
            'checkout.session.completed' => $this->handleCheckoutCompleted($object),
            'customer.subscription.created', 'customer.subscription.updated' => $this->syncSubscription($object),
            'customer.subscription.deleted' => $this->markSubscriptionDeleted($object),
            default => null,
        };
    }

    private function handleCheckoutCompleted(array $session): void
    {
        if (($session['mode'] ?? null) === 'setup' || ($session['metadata']['purpose'] ?? null) === 'payment_method_update') {
            $this->handlePaymentMethodCheckoutCompleted($session);

            return;
        }

        $subscriptionId = $session['subscription'] ?? null;
        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        $subscription = $this->stripeGet('/subscriptions/'.$subscriptionId)->json();
        $this->syncSubscription($subscription);
    }

    private function handlePaymentMethodCheckoutCompleted(array $session): void
    {
        $setupIntentId = $session['setup_intent'] ?? null;
        if (! is_string($setupIntentId) || $setupIntentId === '') {
            return;
        }

        $user = $this->userForSubscription([
            'customer' => $session['customer'] ?? null,
            'metadata' => $session['metadata'] ?? [],
        ]);
        if (! $user) {
            return;
        }

        $setupIntent = $this->verifiedSetupIntent($user, $setupIntentId);
        $paymentMethodId = $this->paymentMethodIdFromSetupIntent($setupIntent);
        $this->setCustomerDefaultPaymentMethod($user, $paymentMethodId);
        if ($user->stripe_subscription_id) {
            $this->stripePost('/subscriptions/'.$user->stripe_subscription_id, [
                'default_payment_method' => $paymentMethodId,
            ]);
        }
    }

    private function syncSubscription(array $subscription, ?User $fallbackUser = null): void
    {
        $user = $this->userForSubscription($subscription, $fallbackUser);
        if (! $user) {
            return;
        }

        $item = $subscription['items']['data'][0] ?? [];
        $priceId = $item['price']['id'] ?? null;
        $plan = $this->planForSubscription($subscription, $priceId);
        $previousPlan = $user->subscriptionTier();
        $previousSubscriptionId = $user->stripe_subscription_id;
        $previousCancelAtPeriodEnd = (bool) $user->subscription_cancel_at_period_end;

        $user->forceFill([
            'subscription_tier' => $plan,
            'stripe_customer_id' => $subscription['customer'] ?? $user->stripe_customer_id,
            'stripe_subscription_id' => $subscription['id'] ?? $user->stripe_subscription_id,
            'stripe_subscription_item_id' => $item['id'] ?? $user->stripe_subscription_item_id,
            'stripe_price_id' => $priceId ?? $user->stripe_price_id,
            'subscription_status' => $subscription['status'] ?? $user->subscription_status,
            'subscription_current_period_end' => $this->timestampToCarbon($subscription['current_period_end'] ?? null),
            'subscription_trial_ends_at' => $this->timestampToCarbon($subscription['trial_end'] ?? null),
            'subscription_cancel_at_period_end' => (bool) ($subscription['cancel_at_period_end'] ?? false),
        ])->save();

        $freshUser = $user->fresh();
        if ($freshUser) {
            $this->sendSubscriptionReceiptIfNeeded(
                $freshUser,
                $previousPlan,
                $previousSubscriptionId,
                $previousCancelAtPeriodEnd,
            );
        }
    }

    private function sendSubscriptionReceiptIfNeeded(
        User $user,
        string $previousPlan,
        ?string $previousSubscriptionId,
        bool $previousCancelAtPeriodEnd,
    ): void {
        if (! $user->stripe_subscription_id) {
            return;
        }

        $type = null;
        if (! $previousSubscriptionId) {
            $type = 'signup';
        } elseif (! $previousCancelAtPeriodEnd && (bool) $user->subscription_cancel_at_period_end) {
            $type = 'cancellation';
        } elseif ($this->planRank($user->subscriptionTier()) > $this->planRank($previousPlan)) {
            $type = 'upgrade';
        }

        if (! $type) {
            return;
        }

        try {
            $user->notify(new SubscriptionReceiptNotification(
                $type,
                $user->subscriptionTier(),
                $user->subscription_current_period_end,
                $user->subscription_trial_ends_at,
            ));
        } catch (Throwable $exception) {
            Log::warning('Subscription receipt notification failed.', [
                'user_id' => $user->id,
                'type' => $type,
                'subscription_id' => $user->stripe_subscription_id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function planRank(string $plan): int
    {
        return self::PLAN_RANKS[strtolower($plan)] ?? 0;
    }

    private function markSubscriptionDeleted(array $subscription): void
    {
        $user = $this->userForSubscription($subscription);
        if (! $user) {
            return;
        }

        $user->forceFill([
            'subscription_tier' => 'base',
            'subscription_status' => $subscription['status'] ?? 'canceled',
            'subscription_current_period_end' => $this->timestampToCarbon($subscription['current_period_end'] ?? null),
            'subscription_trial_ends_at' => null,
            'subscription_cancel_at_period_end' => false,
        ])->save();
    }

    private function userForSubscription(array $subscription, ?User $fallbackUser = null): ?User
    {
        $userId = $subscription['metadata']['heybean_user_id'] ?? null;
        if ($userId) {
            return User::find($userId);
        }

        $customerId = $subscription['customer'] ?? null;
        if (is_string($customerId) && $customerId !== '') {
            return User::where('stripe_customer_id', $customerId)->first();
        }

        return $fallbackUser;
    }

    private function planForSubscription(array $subscription, ?string $priceId): string
    {
        $metadataPlan = $subscription['metadata']['plan'] ?? null;
        if (is_string($metadataPlan) && array_key_exists($metadataPlan, self::PLAN_RANKS)) {
            return $metadataPlan === 'free' ? 'base' : $metadataPlan;
        }

        foreach (config('services.stripe.prices', []) as $plan => $configuredPriceId) {
            if ($configuredPriceId && $configuredPriceId === $priceId) {
                return $plan;
            }
        }

        return 'base';
    }

    private function ensureCustomer(User $user): string
    {
        if ($user->stripe_customer_id) {
            return $user->stripe_customer_id;
        }

        $customer = $this->stripePost('/customers', [
            'name' => $user->name,
            'email' => $user->email,
            'metadata' => ['heybean_user_id' => (string) $user->id],
        ])->json();

        $customerId = $customer['id'] ?? null;
        if (! is_string($customerId) || $customerId === '') {
            throw new RuntimeException('Stripe did not return a customer id.');
        }

        $user->forceFill(['stripe_customer_id' => $customerId])->save();

        return $customerId;
    }

    private function createSetupIntentResponse(User $user, array $metadata, array $extra = []): array
    {
        $customerId = $this->ensureCustomer($user);
        $publishableKey = $this->publishableKey();
        $ephemeralKey = $this->stripePost('/ephemeral_keys', [
            'customer' => $customerId,
        ], [
            'Stripe-Version' => $this->apiVersion(),
        ])->json();
        $setupIntent = $this->stripePost('/setup_intents', [
            'customer' => $customerId,
            'usage' => 'off_session',
            'payment_method_types' => ['card'],
            'metadata' => [
                'heybean_user_id' => (string) $user->id,
                'source' => 'flutter',
                ...$metadata,
            ],
        ])->json();

        $setupIntentSecret = $setupIntent['client_secret'] ?? null;
        $ephemeralKeySecret = $ephemeralKey['secret'] ?? null;
        if (! is_string($setupIntentSecret) || $setupIntentSecret === '' || ! is_string($ephemeralKeySecret) || $ephemeralKeySecret === '') {
            throw new RuntimeException('Stripe did not return mobile payment setup details.');
        }

        return [
            'publishable_key' => $publishableKey,
            'customer_id' => $customerId,
            'customer_ephemeral_key_secret' => $ephemeralKeySecret,
            'setup_intent_id' => $setupIntent['id'] ?? null,
            'setup_intent_client_secret' => $setupIntentSecret,
            ...$extra,
        ];
    }

    private function verifiedSetupIntent(User $user, string $setupIntentId): array
    {
        $setupIntent = $this->stripeGet('/setup_intents/'.$setupIntentId, [
            'expand' => ['payment_method'],
        ])->json();

        if (($setupIntent['customer'] ?? null) !== $user->stripe_customer_id) {
            throw new InvalidArgumentException('That payment setup does not belong to this account.');
        }

        if (($setupIntent['status'] ?? null) !== 'succeeded') {
            throw new InvalidArgumentException('Payment setup is not complete yet.');
        }

        return $setupIntent;
    }

    private function paymentMethodIdFromSetupIntent(array $setupIntent): string
    {
        $paymentMethod = $setupIntent['payment_method'] ?? null;
        $paymentMethodId = is_array($paymentMethod) ? ($paymentMethod['id'] ?? null) : $paymentMethod;
        if (! is_string($paymentMethodId) || $paymentMethodId === '') {
            throw new InvalidArgumentException('Stripe did not attach a payment method to this setup.');
        }

        return $paymentMethodId;
    }

    private function setCustomerDefaultPaymentMethod(User $user, string $paymentMethodId): void
    {
        $this->stripePost('/customers/'.$this->ensureCustomer($user), [
            'invoice_settings' => [
                'default_payment_method' => $paymentMethodId,
            ],
        ]);
    }

    private function defaultPaymentMethodId(User $user): ?string
    {
        if ($user->stripe_subscription_id) {
            $subscription = $this->stripeGet('/subscriptions/'.$user->stripe_subscription_id)->json();
            $subscriptionPaymentMethod = $subscription['default_payment_method'] ?? null;
            if (is_string($subscriptionPaymentMethod) && $subscriptionPaymentMethod !== '') {
                return $subscriptionPaymentMethod;
            }
        }

        $customer = $this->stripeGet('/customers/'.$user->stripe_customer_id)->json();
        $customerPaymentMethod = $customer['invoice_settings']['default_payment_method'] ?? null;

        return is_string($customerPaymentMethod) && $customerPaymentMethod !== '' ? $customerPaymentMethod : null;
    }

    private function paymentMethodDisplayFromSetupIntent(array $setupIntent, User $user): ?array
    {
        $paymentMethod = $setupIntent['payment_method'] ?? null;
        if (is_array($paymentMethod)) {
            return $this->paymentMethodDisplay($paymentMethod, $user);
        }

        if (is_string($paymentMethod) && $paymentMethod !== '') {
            return $this->paymentMethodDisplay($this->stripeGet('/payment_methods/'.$paymentMethod)->json(), $user);
        }

        return null;
    }

    private function paymentMethodDisplay(array $paymentMethod, User $user): ?array
    {
        if (($paymentMethod['customer'] ?? $user->stripe_customer_id) !== $user->stripe_customer_id) {
            return null;
        }

        $card = $paymentMethod['card'] ?? null;
        if (! is_array($card)) {
            return null;
        }

        return [
            'id' => $paymentMethod['id'] ?? null,
            'type' => $paymentMethod['type'] ?? 'card',
            'brand' => $card['brand'] ?? null,
            'last4' => $card['last4'] ?? null,
            'exp_month' => $card['exp_month'] ?? null,
            'exp_year' => $card['exp_year'] ?? null,
        ];
    }

    private function stripeGet(string $path, array $payload = []): Response
    {
        return $this->stripeRequest('get', $path, $payload);
    }

    private function stripePost(string $path, array $payload, array $headers = []): Response
    {
        return $this->stripeRequest('post', $path, $payload, $headers);
    }

    private function stripeRequest(string $method, string $path, array $payload = [], array $headers = []): Response
    {
        $secret = (string) config('services.stripe.secret', '');
        if ($secret === '') {
            throw new RuntimeException('Stripe is not configured.');
        }

        $headers = ['Stripe-Version' => $this->apiVersion(), ...$headers];

        $request = Http::withToken($secret)
            ->withHeaders($headers)
            ->asForm()
            ->acceptJson()
            ->baseUrl('https://api.stripe.com/v1');

        $response = $method === 'get'
            ? $request->get($path, $payload)
            : $request->post($path, $payload);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: 'Stripe request failed.';
            Log::warning('Stripe billing request failed.', [
                'method' => $method,
                'path' => $path,
                'status' => $response->status(),
                'message' => $message,
            ]);

            if ($response->clientError()) {
                throw new InvalidArgumentException($message);
            }

            throw new RuntimeException($message);
        }

        return $response;
    }

    private function publishableKey(): string
    {
        $key = (string) config('services.stripe.publishable_key', '');
        if ($key === '') {
            throw new RuntimeException('Stripe publishable key is not configured.');
        }

        return $key;
    }

    private function apiVersion(): string
    {
        $version = (string) config('services.stripe.api_version', '');

        return $version !== '' ? $version : '2026-05-27.dahlia';
    }

    private function priceId(string $plan): string
    {
        $priceId = (string) config('services.stripe.prices.'.$plan, '');
        if ($priceId === '') {
            throw new RuntimeException('Stripe price is not configured for '.$plan.'.');
        }

        return $priceId;
    }

    private function assertPaidPlan(string $plan): void
    {
        if (! in_array($plan, ['premium', 'pro'], true)) {
            throw new InvalidArgumentException('Choose Premium or Pro to start a paid plan.');
        }
    }

    private function assertCheckoutPlan(string $plan): void
    {
        if (! in_array($plan, ['base', 'premium', 'pro'], true)) {
            throw new InvalidArgumentException('Choose Base, Premium, or Pro to start a plan.');
        }
    }

    private function verifiedWebhookEvent(string $payload, ?string $signature): array
    {
        $secret = (string) config('services.stripe.webhook_secret', '');
        if ($secret !== '') {
            $this->verifyWebhookSignature($payload, $signature, $secret);
        }

        $event = json_decode($payload, true);
        if (! is_array($event)) {
            throw new RuntimeException('Invalid Stripe webhook payload.');
        }

        return $event;
    }

    private function verifyWebhookSignature(string $payload, ?string $signature, string $secret): void
    {
        if (! $signature) {
            throw new RuntimeException('Missing Stripe webhook signature.');
        }

        $parts = collect(explode(',', $signature))
            ->mapWithKeys(function (string $part): array {
                [$key, $value] = array_pad(explode('=', $part, 2), 2, null);

                return $key && $value ? [$key => $value] : [];
            });

        $timestamp = $parts->get('t');
        $expected = hash_hmac('sha256', $timestamp.'.'.$payload, $secret);
        $actual = $parts->get('v1');
        if (! is_string($timestamp) || ! is_string($actual) || ! hash_equals($expected, $actual)) {
            throw new RuntimeException('Invalid Stripe webhook signature.');
        }
    }

    private function timestampToCarbon(mixed $timestamp): ?Carbon
    {
        return is_numeric($timestamp) ? Carbon::createFromTimestamp((int) $timestamp) : null;
    }

    private function cleanSource(?string $source): ?string
    {
        $source = $source ? Str::of($source)->lower()->replaceMatches('/[^a-z0-9_-]+/', '-')->trim('-')->toString() : null;

        return $source !== '' ? $source : null;
    }
}
