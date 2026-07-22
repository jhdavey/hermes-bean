<?php

namespace App\Http\Middleware;

use App\Services\ProductAccessService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureEarlyAccessAdmitted
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(ProductAccessService::class)->state($request->user()) !== 'waitlisted') {
            return $next($request);
        }

        return new JsonResponse([
            'message' => 'We are onboarding as fast as possible. We will let you know as soon as your early-access spot opens.',
            'code' => 'early_access_waitlisted',
            'data' => [
                'access_state' => 'waitlisted',
                'trial_days' => 7,
            ],
        ], 403);
    }
}
