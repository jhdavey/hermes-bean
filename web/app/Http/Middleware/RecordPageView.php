<?php

namespace App\Http\Middleware;

use App\Models\PageViewEvent;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class RecordPageView
{
    private const COOKIE_NAME = 'hb_visitor';

    public function handle(Request $request, Closure $next): Response
    {
        $visitorKey = $request->cookies->get(self::COOKIE_NAME);
        if (! is_string($visitorKey) || $visitorKey === '') {
            $visitorKey = (string) Str::uuid();
            Cookie::queue(Cookie::make(self::COOKIE_NAME, $visitorKey, 60 * 24 * 365, null, null, $request->isSecure(), true, false, 'Lax'));
        }

        $response = $next($request);

        if ($this->shouldRecord($request, $response)) {
            try {
                PageViewEvent::create([
                    'user_id' => $request->user()?->id,
                    'visitor_key' => $visitorKey,
                    'ip_hash' => $request->ip() ? hash('sha256', (string) $request->ip()) : null,
                    'method' => $request->method(),
                    'path' => '/'.ltrim($request->path(), '/'),
                    'route_name' => $request->route()?->getName(),
                    'referrer' => str($request->headers->get('referer', ''))->limit(1024, '')->toString() ?: null,
                    'utm_source' => str($request->query('utm_source', ''))->limit(120, '')->toString() ?: null,
                    'utm_medium' => str($request->query('utm_medium', ''))->limit(120, '')->toString() ?: null,
                    'utm_campaign' => str($request->query('utm_campaign', ''))->limit(160, '')->toString() ?: null,
                    'user_agent' => str($request->userAgent() ?: '')->limit(512, '')->toString() ?: null,
                    'status_code' => $response->getStatusCode(),
                ]);
            } catch (Throwable) {
                // Analytics must never block page rendering.
            }
        }

        return $response;
    }

    private function shouldRecord(Request $request, Response $response): bool
    {
        if (! $request->isMethod('GET') || $request->expectsJson()) {
            return false;
        }

        if ($response->getStatusCode() >= 500) {
            return false;
        }

        $path = '/'.ltrim($request->path(), '/');

        return ! str_starts_with($path, '/up');
    }
}
