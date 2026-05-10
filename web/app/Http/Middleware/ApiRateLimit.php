<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Cache\RateLimiter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ApiRateLimit
{
    public function __construct(private readonly RateLimiter $limiter) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maxAttempts = (int) config('security.rate_limits.api_per_minute', 60);
        $decaySeconds = (int) config('security.rate_limits.decay_seconds', 60);
        $key = $this->key($request);

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

    private function key(Request $request): string
    {
        $userId = $request->user()?->getAuthIdentifier();
        $actor = $userId ? 'user:'.$userId : 'ip:'.$request->ip();

        return 'api-rate-limit:'.$actor.'|'.$request->method().'|'.$request->path();
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
