<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\StripeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use RuntimeException;

class BillingController extends Controller
{
    public function __construct(private readonly StripeBillingService $billing) {}

    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->billing->subscriptionSummary($request->user())]);
    }

    public function paymentMethod(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->billing->paymentMethodSummary($request->user())]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function checkoutSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['base', 'premium', 'pro'])],
            'source' => ['sometimes', 'nullable', 'string', 'max:50'],
        ]);

        try {
            return response()->json(['data' => $this->billing->createCheckoutSession(
                $request->user(),
                $data['plan'],
                $data['source'] ?? null,
            )], 201);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function mobileSubscriptionSetup(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['base', 'premium', 'pro'])],
        ]);

        try {
            return response()->json(['data' => $this->billing->createMobileSubscriptionSetup(
                $request->user(),
                $data['plan'],
            )], 201);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function confirmMobileSubscription(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['base', 'premium', 'pro'])],
            'setup_intent_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            return response()->json(['data' => $this->billing->confirmMobileSubscription(
                $request->user(),
                $data['plan'],
                $data['setup_intent_id'],
            )]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function paymentMethodSetup(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->billing->createPaymentMethodSetup($request->user())], 201);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function paymentMethodCheckoutSession(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->billing->createPaymentMethodCheckoutSession($request->user())], 201);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function confirmPaymentMethod(Request $request): JsonResponse
    {
        $data = $request->validate([
            'setup_intent_id' => ['required', 'string', 'max:255'],
        ]);

        try {
            return response()->json(['data' => $this->billing->confirmPaymentMethodSetup(
                $request->user(),
                $data['setup_intent_id'],
            )]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function upgrade(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['premium', 'pro'])],
        ]);

        try {
            return response()->json(['data' => $this->billing->upgradeSubscription($request->user(), $data['plan'])]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function changePlan(Request $request): JsonResponse
    {
        $data = $request->validate([
            'plan' => ['required', Rule::in(['base', 'premium', 'pro'])],
        ]);

        try {
            return response()->json(['data' => $this->billing->changeSubscriptionPlan($request->user(), $data['plan'])]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function cancel(Request $request): JsonResponse
    {
        try {
            return response()->json(['data' => $this->billing->cancelSubscription($request->user())]);
        } catch (RuntimeException|InvalidArgumentException $exception) {
            return $this->billingError($exception);
        }
    }

    public function webhook(Request $request): JsonResponse
    {
        try {
            $this->billing->handleWebhook($request->getContent(), $request->header('Stripe-Signature'));
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 400);
        }

        return response()->json(['received' => true]);
    }

    private function billingError(RuntimeException|InvalidArgumentException $exception): JsonResponse
    {
        $status = $exception instanceof InvalidArgumentException ? 422 : 503;

        return response()->json(['message' => $exception->getMessage()], $status);
    }
}
