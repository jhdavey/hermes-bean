<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\OutlookCalendarSyncService;
use App\Services\PlanLimitService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Throwable;

class OutlookCalendarController extends Controller
{
    public function __construct(
        private readonly OutlookCalendarSyncService $sync,
        private readonly PlanLimitService $planLimits,
    ) {}

    public function status(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->sync->status($request->user(), ! $request->boolean('cached'))]);
    }

    public function authUrl(Request $request): JsonResponse
    {
        if (! $request->user()->outlookCalendarConnection) {
            if ($response = $this->planLimits->enforceConnectedAccountLimit($request->user(), 0)) {
                return $response;
            }
        }

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
        } catch (Throwable) {
            return response("Outlook calendar connection failed. You can close this tab and try again from HeyBean.\n", 422)
                ->header('Content-Type', 'text/plain');
        }

        return response("Outlook calendar connected. You can close this tab and return to HeyBean.\n")
            ->header('Content-Type', 'text/plain');
    }

    public function sync(Request $request): JsonResponse
    {
        $workspace = app(WorkspaceService::class)->resolveWorkspace($request->user(), $request->input('workspace_id'));

        try {
            return response()->json(['data' => $this->sync->sync($request->user(), $workspace)]);
        } catch (Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'outlook_calendar_sync_failed',
                    'message' => 'Outlook calendar sync failed. Please try again.',
                ],
            ], 422);
        }
    }

    public function calendars(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'selected_calendar_ids' => ['required', 'array', 'min:1'],
            'selected_calendar_ids.*' => ['required', 'string'],
            'default_calendar_id' => ['nullable', 'string'],
        ]);
        if ($response = $this->planLimits->enforceCalendarSelectionLimit($request->user(), count(array_unique($validated['selected_calendar_ids'])))) {
            return $response;
        }

        return response()->json(['data' => $this->sync->updateSelectedCalendars(
            $request->user(),
            $validated['selected_calendar_ids'],
            $validated['default_calendar_id'] ?? null,
        )]);
    }

    public function disconnect(Request $request): JsonResponse
    {
        $this->sync->disconnect($request->user());

        return response()->json(['data' => $this->sync->status($request->user())]);
    }
}
