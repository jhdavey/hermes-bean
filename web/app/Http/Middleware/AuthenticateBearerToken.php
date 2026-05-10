<?php

namespace App\Http\Middleware;

use App\Models\PersonalAccessToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateBearerToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $plainToken = $request->bearerToken();

        if (! $plainToken) {
            return response()->json(['error' => [
                'code' => 'unauthenticated',
                'message' => 'Unauthenticated.',
            ]], 401);
        }

        $accessToken = PersonalAccessToken::query()
            ->where('token', hash('sha256', $plainToken))
            ->where(function ($query): void {
                $query->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->with('user')
            ->first();

        if (! $accessToken?->user) {
            return response()->json(['error' => [
                'code' => 'unauthenticated',
                'message' => 'Unauthenticated.',
            ]], 401);
        }

        $accessToken->forceFill(['last_used_at' => now()])->save();
        $request->setUserResolver(fn () => $accessToken->user);
        app('auth')->setUser($accessToken->user);

        return $next($request);
    }
}
