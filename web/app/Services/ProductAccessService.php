<?php

namespace App\Services;

use App\Models\EarlyAccessSignup;
use App\Models\User;

class ProductAccessService
{
    public function state(User $user, ?EarlyAccessSignup $signup = null): string
    {
        $signup ??= EarlyAccessSignup::query()->where('email', $user->email)->first();

        // Accounts that predate the rollout table remain usable. Every public
        // registration now creates an explicit admission record.
        if (! $signup) {
            return 'active';
        }

        if ($signup?->status === EarlyAccessService::STATUS_WAITLISTED) {
            return 'waitlisted';
        }

        if ($user->isAdmin() || $signup?->status === EarlyAccessService::STATUS_INTERNAL || $user->subscriptionTier() === 'enterprise') {
            return 'active';
        }

        return in_array(strtolower((string) $user->subscription_status), ['active', 'trialing'], true)
            ? 'active'
            : 'subscription_required';
    }

    public function canAccess(User $user): bool
    {
        return $this->state($user) === 'active';
    }
}
