<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\GoogleCalendarSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class GoogleCalendarController extends Controller
{
    public function __construct(private readonly GoogleCalendarSyncService $sync) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sync->status($request->user())]);
    }

    public function authUrl(Request $request): JsonResponse
    {
        return response()->json(['data' => ['auth_url' => $this->sync->authorizationUrl($request->user())]]);
    }

    public function callback(Request $request): Response
    {
        $request->validate([
            'state' => ['required', 'string'],
            'code' => ['required', 'string'],
        ]);

        try {
            $this->sync->completeOAuthCallback($request->string('state')->toString(), $request->string('code')->toString());
        } catch (Throwable $error) {
            return response("Google Calendar connection failed. You can close this tab and try again from HeyBean.\n", 422)
                ->header('Content-Type', 'text/plain');
        }

        return response("Google Calendar connected. You can close this tab and return to HeyBean.\n")
            ->header('Content-Type', 'text/plain');
    }

    public function sync(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sync->sync($request->user())]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->sync->disconnect($request->user());

        return response()->json(['data' => $this->sync->status($request->user())]);
    }
}
