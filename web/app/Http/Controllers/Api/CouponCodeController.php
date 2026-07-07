<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CouponCode;
use App\Services\CouponCodeService;
use App\Services\StripeBillingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

class CouponCodeController extends Controller
{
    public function __construct(
        private readonly CouponCodeService $coupons,
        private readonly StripeBillingService $billing,
    ) {}

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => $this->coupons->list()
                ->map(fn (CouponCode $coupon): array => $this->coupons->payload($coupon))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['sometimes', 'nullable', 'digits:6'],
            'months_free_base' => ['required', 'integer', 'min:1', 'max:60'],
        ]);

        try {
            $coupon = $this->coupons->create($data, $request->user());
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json(['data' => $this->coupons->payload($coupon)], 201);
    }

    public function destroy(CouponCode $couponCode): JsonResponse
    {
        $this->coupons->delete($couponCode);

        return response()->json(['data' => ['deleted' => true]]);
    }

    public function redeem(Request $request): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'digits:6'],
        ]);

        try {
            $result = $this->coupons->redeem($request->user(), $data['code']);
            $user = $result['user'];

            return response()->json([
                'data' => [
                    'coupon' => $this->coupons->payload($result['coupon']),
                    'subscription' => $this->billing->subscriptionSummary($user),
                ],
            ]);
        } catch (InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (RuntimeException $exception) {
            return response()->json(['message' => $exception->getMessage()], 503);
        }
    }
}
