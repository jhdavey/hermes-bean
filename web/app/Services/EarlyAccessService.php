<?php

namespace App\Services;

use App\Models\EarlyAccessSignup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class EarlyAccessService
{
    public const STATUS_ADMITTED = 'admitted';

    public const STATUS_REGISTERED = 'registered';

    public const STATUS_WAITLISTED = 'waitlisted';

    public const STATUS_INTERNAL = 'internal';

    public function request(string $email, ?string $name = null, ?string $requestedPlan = null, string $source = 'landing'): EarlyAccessSignup
    {
        $email = strtolower(trim($email));

        return DB::transaction(function () use ($email, $name, $requestedPlan, $source): EarlyAccessSignup {
            $existing = EarlyAccessSignup::query()->where('email', $email)->lockForUpdate()->first();
            if ($existing) {
                $existing->fill([
                    'name' => $name ?: $existing->name,
                    'requested_plan' => $requestedPlan ?: $existing->requested_plan,
                    'source' => $source,
                ])->save();

                return $existing->fresh();
            }

            $existingUser = User::query()->where('email', $email)->first();
            if ($existingUser) {
                return EarlyAccessSignup::create([
                    'user_id' => $existingUser->id,
                    'name' => $name ?: $existingUser->name,
                    'email' => $email,
                    'requested_plan' => $requestedPlan,
                    'source' => $source,
                    'status' => self::STATUS_INTERNAL,
                    'registered_at' => now(),
                ]);
            }

            $rollout = DB::table('early_access_rollouts')
                ->where('key', config('early_access.rollout_key', 'public_beta'))
                ->lockForUpdate()
                ->first();
            $capacity = (int) ($rollout->capacity ?? config('early_access.capacity', 100));
            $admittedCount = (int) ($rollout->admitted_count ?? 0);
            $admitted = $admittedCount < $capacity;

            if ($admitted) {
                DB::table('early_access_rollouts')
                    ->where('key', config('early_access.rollout_key', 'public_beta'))
                    ->update(['admitted_count' => $admittedCount + 1, 'updated_at' => now()]);
            }

            return EarlyAccessSignup::create([
                'name' => $name,
                'email' => $email,
                'requested_plan' => $requestedPlan,
                'source' => $source,
                'status' => $admitted ? self::STATUS_ADMITTED : self::STATUS_WAITLISTED,
                'admitted_at' => $admitted ? now() : null,
                'waitlisted_at' => $admitted ? null : now(),
            ]);
        }, 3);
    }

    public function markRegistered(EarlyAccessSignup $signup, User $user): EarlyAccessSignup
    {
        $signup->forceFill([
            'user_id' => $user->id,
            'name' => $user->name,
            'status' => $signup->status === self::STATUS_INTERNAL ? self::STATUS_INTERNAL : self::STATUS_REGISTERED,
            'registered_at' => now(),
        ])->save();

        return $signup->fresh();
    }

    public function admitWaitlisted(string $email, ?int $newCapacity = null): EarlyAccessSignup
    {
        $email = strtolower(trim($email));

        return DB::transaction(function () use ($email, $newCapacity): EarlyAccessSignup {
            $rollout = DB::table('early_access_rollouts')
                ->where('key', config('early_access.rollout_key', 'public_beta'))
                ->lockForUpdate()
                ->first();
            $signup = EarlyAccessSignup::query()->where('email', $email)->lockForUpdate()->firstOrFail();
            if ($signup->status !== self::STATUS_WAITLISTED) {
                return $signup;
            }

            $capacity = max((int) ($rollout->capacity ?? 0), (int) ($newCapacity ?? 0));
            $admittedCount = (int) ($rollout->admitted_count ?? 0);
            if ($admittedCount >= $capacity) {
                throw new InvalidArgumentException('Increase the rollout capacity before admitting another person.');
            }

            DB::table('early_access_rollouts')
                ->where('key', config('early_access.rollout_key', 'public_beta'))
                ->update([
                    'capacity' => $capacity,
                    'admitted_count' => $admittedCount + 1,
                    'updated_at' => now(),
                ]);
            $signup->forceFill([
                'status' => self::STATUS_ADMITTED,
                'admitted_at' => now(),
                'notified_at' => now(),
            ])->save();

            return $signup->fresh();
        }, 3);
    }

    public function payload(EarlyAccessSignup $signup): array
    {
        $waitlisted = $signup->status === self::STATUS_WAITLISTED;

        return [
            'status' => $signup->status,
            'admitted' => ! $waitlisted,
            'waitlisted' => $waitlisted,
            'capacity' => (int) config('early_access.capacity', 100),
            'display_remaining' => (int) config('early_access.display_remaining', 24),
            'message' => $waitlisted
                ? 'We are currently onboarding as fast as possible. You are on the early-access waitlist, and we will let you know as soon as a spot opens.'
                : 'Your early-access spot is reserved. Create your account, choose a plan, and start your seven-day free trial.',
        ];
    }
}
