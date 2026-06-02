<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use App\Models\User;
use App\Services\AdminSettingsService;
use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimit
{
    public function __construct(
        private readonly RateLimiter $limiter,
        private readonly AdminSettingsService $settings,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $actorUser = $this->actorUser($request);
        $maxAttempts = $this->maxAttempts($actorUser);
        $decaySeconds = (int) config('security.rate_limits.decay_seconds', 60);
        $key = $this->key($request, $actorUser);

        if ($this->limiter->tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = $this->limiter->availableIn($key);

            return $this->rateLimitedResponse($maxAttempts, $retryAfter);
        }

        $this->limiter->hit($key, $decaySeconds);

        $response = $next($request);
        $remaining = max(0, $maxAttempts - $this->limiter->attempts($key));

        $response->headers->set('X-RateLimit-Limit', (string) $maxAttempts);
        $response->headers->set('X-RateLimit-Remaining', (string) $remaining);

        return $response;
    }

    private function key(Request $request, ?User $actorUser): string
    {
        $userId = $actorUser?->getAuthIdentifier();
        $actor = $userId ? 'user:'.$userId : 'ip:'.$request->ip();

        return 'api-rate-limit:'.$actor.'|'.$request->method().'|'.$request->path();
    }

    private function maxAttempts(?User $user): int
    {
        if ($user && ! $user->isAdmin() && $this->isActiveBetaUser($user)) {
            return $this->settings->betaApiPerMinute();
        }

        return (int) config('security.rate_limits.api_per_minute', 60);
    }

    private function actorUser(Request $request): ?User
    {
        $user = $request->user();
        if ($user instanceof User) {
            return $user->loadMissing('betaUser');
        }

        $plainToken = $request->bearerToken();
        if (! $plainToken) {
            return null;
        }

        return PersonalAccessToken::query()
            ->where('token', hash('sha256', $plainToken))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('user.betaUser')
            ->first()
            ?->user;
    }

    private function isActiveBetaUser(User $user): bool
    {
        if ($user->relationLoaded('betaUser')) {
            return $user->betaUser?->status === 'active';
        }

        return $user->betaUser()->where('status', 'active')->exists();
    }

    private function rateLimitedResponse(int $maxAttempts, int $retryAfter): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'rate_limited',
                'message' => 'Too many requests.',
            ],
        ], 429)->withHeaders([
            'Retry-After' => (string) $retryAfter,
            'X-RateLimit-Limit' => (string) $maxAttempts,
            'X-RateLimit-Remaining' => '0',
        ]);
    }
}
