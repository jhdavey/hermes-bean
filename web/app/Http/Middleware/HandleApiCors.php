<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleApiCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS') && $request->is('api/*')) {
            return $this->withCorsHeaders(response()->noContent(), $request);
        }

        $response = $next($request);

        if ($request->is('api/*')) {
            return $this->withCorsHeaders($response, $request);
        }

        return $response;
    }

    private function withCorsHeaders(Response $response, Request $request): Response
    {
        $allowedOrigins = config('security.cors.allowed_origins', []);
        $origin = $request->headers->get('Origin');

        if ($origin && (in_array('*', $allowedOrigins, true) || in_array($origin, $allowedOrigins, true))) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', trim($response->headers->get('Vary').' Origin'));
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, DELETE, OPTIONS');
        $response->headers->set('Access-Control-Allow-Headers', 'Authorization, Content-Type, Accept, X-Requested-With');
        $response->headers->set('Access-Control-Max-Age', '600');

        return $response;
    }
}
