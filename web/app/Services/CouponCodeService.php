<?php

namespace App\Services;

use App\Models\CouponCode;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

class CouponCodeService
{
    public function list(): Collection
    {
        return CouponCode::query()
            ->with(['creator:id,name,email', 'redeemer:id,name,email'])
            ->latest()
            ->get();
    }

    public function create(array $data, ?User $actor = null): CouponCode
    {
        $code = $this->normalizeCode($data['code'] ?? null);
        if ($code === '') {
            $code = $this->generateCode();
        }

        if (CouponCode::withTrashed()->where('code', $code)->exists()) {
            throw new InvalidArgumentException('That coupon code already exists.');
        }

        return CouponCode::create([
            'code' => $code,
            'months_free_base' => max(1, min(60, (int) ($data['months_free_base'] ?? 1))),
            'created_by_user_id' => $actor?->id,
        ])->fresh(['creator:id,name,email', 'redeemer:id,name,email']);
    }

    public function delete(CouponCode $coupon): void
    {
        $coupon->delete();
    }

    public function redeem(User $user, string $code): array
    {
        $normalized = $this->normalizeCode($code);
        if (! preg_match('/^\d{6}$/', $normalized)) {
            throw new InvalidArgumentException('Enter a 6-digit coupon code.');
        }

        return DB::transaction(function () use ($user, $normalized): array {
            $coupon = CouponCode::query()
                ->where('code', $normalized)
                ->lockForUpdate()
                ->first();

            if (! $coupon) {
                throw new InvalidArgumentException('Coupon code was not found.');
            }

            if ($coupon->redeemed_at || $coupon->redeemed_by_user_id) {
                throw new InvalidArgumentException('Coupon code has already been used.');
            }

            $lockedUser = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $start = $lockedUser->base_comp_expires_at instanceof Carbon && $lockedUser->base_comp_expires_at->isFuture()
                ? $lockedUser->base_comp_expires_at->copy()
                : $now->copy();
            $expiresAt = $start->addMonthsNoOverflow(max(1, (int) $coupon->months_free_base));

            $coupon->forceFill([
                'redeemed_by_user_id' => $lockedUser->id,
                'redeemed_at' => $now,
                'base_access_expires_at' => $expiresAt,
            ])->save();

            $userUpdates = [
                'base_comp_expires_at' => $expiresAt,
                'base_comp_source_coupon_code_id' => $coupon->id,
            ];

            if (! $this->hasActiveStripeSubscription($lockedUser)) {
                $userUpdates = [
                    ...$userUpdates,
                    'subscription_tier' => 'base',
                    'subscription_status' => 'active',
                    'subscription_current_period_end' => $expiresAt,
                    'subscription_trial_ends_at' => null,
                    'subscription_cancel_at_period_end' => false,
                ];
            }

            $lockedUser->forceFill($userUpdates)->save();

            return [
                'coupon' => $coupon->fresh(['creator:id,name,email', 'redeemer:id,name,email']),
                'user' => $lockedUser->fresh(),
            ];
        });
    }

    public function syncBaseCompAccess(User $user): User
    {
        $expiresAt = $user->base_comp_expires_at;
        if (! $expiresAt instanceof Carbon) {
            return $user;
        }

        if ($this->hasActiveStripeSubscription($user)) {
            return $user;
        }

        if ($expiresAt->isFuture()) {
            if ($user->subscription_status !== 'active' || $user->subscriptionTier() !== 'base') {
                $user->forceFill([
                    'subscription_tier' => 'base',
                    'subscription_status' => 'active',
                    'subscription_current_period_end' => $expiresAt,
                    'subscription_trial_ends_at' => null,
                    'subscription_cancel_at_period_end' => false,
                ])->save();

                return $user->fresh() ?: $user;
            }

            return $user;
        }

        if (in_array((string) $user->subscription_status, ['active', 'trialing'], true)) {
            $user->forceFill([
                'subscription_tier' => 'base',
                'subscription_status' => 'canceled',
                'subscription_current_period_end' => $expiresAt,
                'subscription_trial_ends_at' => null,
                'subscription_cancel_at_period_end' => false,
            ])->save();

            return $user->fresh() ?: $user;
        }

        return $user;
    }

    public function payload(CouponCode $coupon): array
    {
        return [
            'id' => $coupon->id,
            'code' => $coupon->code,
            'months_free_base' => $coupon->months_free_base,
            'used' => (bool) $coupon->redeemed_at,
            'created_at' => $coupon->created_at?->toIso8601String(),
            'redeemed_at' => $coupon->redeemed_at?->toIso8601String(),
            'base_access_expires_at' => $coupon->base_access_expires_at?->toIso8601String(),
            'creator' => $coupon->creator ? [
                'id' => $coupon->creator->id,
                'name' => $coupon->creator->name,
                'email' => $coupon->creator->email,
            ] : null,
            'redeemer' => $coupon->redeemer ? [
                'id' => $coupon->redeemer->id,
                'name' => $coupon->redeemer->name,
                'email' => $coupon->redeemer->email,
            ] : null,
        ];
    }

    private function generateCode(): string
    {
        for ($attempt = 0; $attempt < 20; $attempt++) {
            $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
            if (! CouponCode::withTrashed()->where('code', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException('Could not generate a unique coupon code. Try again.');
    }

    private function normalizeCode(?string $code): string
    {
        return preg_replace('/\D+/', '', trim((string) $code)) ?? '';
    }

    private function hasActiveStripeSubscription(User $user): bool
    {
        return (bool) $user->stripe_subscription_id
            && in_array((string) $user->subscription_status, ['active', 'trialing'], true);
    }
}
