<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

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

    public function createCheckoutSession(User $user, string $plan, ?string $source = null): array
    {
        $this->assertCheckoutPlan($plan);
        $priceId = $this->priceId($plan);
        $customerId = $this->ensureCustomer($user);
        $source = $this->cleanSource($source);
        $returnPath = in_array($source, ['register', 'signup', 'subscribe'], true) ? '/subscribe' : '/pricing';
        $successUrl = url($returnPath.'?checkout=success&plan='.$plan.($source ? '&source='.$source : ''));
        $cancelUrl = url($returnPath.'?checkout=cancel&plan='.$plan.($source ? '&source='.$source : ''));

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
        $subscriptionId = $session['subscription'] ?? null;
        if (! is_string($subscriptionId) || $subscriptionId === '') {
            return;
        }

        $subscription = $this->stripeGet('/subscriptions/'.$subscriptionId)->json();
        $this->syncSubscription($subscription);
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

    private function stripeGet(string $path): Response
    {
        return $this->stripeRequest('get', $path);
    }

    private function stripePost(string $path, array $payload): Response
    {
        return $this->stripeRequest('post', $path, $payload);
    }

    private function stripeRequest(string $method, string $path, array $payload = []): Response
    {
        $secret = (string) config('services.stripe.secret', '');
        if ($secret === '') {
            throw new RuntimeException('Stripe is not configured.');
        }

        $request = Http::withToken($secret)
            ->asForm()
            ->acceptJson()
            ->baseUrl('https://api.stripe.com/v1');

        $response = $method === 'get'
            ? $request->get($path)
            : $request->post($path, $payload);

        if ($response->failed()) {
            $message = $response->json('error.message') ?: 'Stripe request failed.';
            throw new RuntimeException($message);
        }

        return $response;
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
