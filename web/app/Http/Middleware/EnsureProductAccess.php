<?php

namespace App\Http\Middleware;

use App\Services\ProductAccessService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProductAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $state = app(ProductAccessService::class)->state($request->user());
        if ($state === 'active') {
            return $next($request);
        }

        $waitlisted = $state === 'waitlisted';

        return new JsonResponse([
            'message' => $waitlisted
                ? 'We are onboarding as fast as possible. We will let you know as soon as your early-access spot opens.'
                : 'Choose a plan and complete checkout to start your seven-day free trial.',
            'code' => $waitlisted ? 'early_access_waitlisted' : 'subscription_required',
            'data' => [
                'access_state' => $state,
                'trial_days' => 7,
            ],
        ], $waitlisted ? 403 : 402);
    }
}
