<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\HermesRuntimeService;
use App\Services\PlanHistoryService;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityEventController extends Controller
{
    public function __construct(
        private readonly HermesRuntimeService $runtime,
        private readonly PlanHistoryService $history,
        private readonly PlanLimitService $planLimits,
    ) {}

    public function index(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        if (! $this->history->sessionWithinHistory($ownedSession, $request->user())) {
            $days = $this->planLimits->historyDaysFor($request->user());

            return $this->planLimits->limitResponse($days === null
                ? 'This activity is outside your available history.'
                : "Your current plan includes {$days} days of Bean activity history.");
        }

        return response()->json([
            'data' => $this->history->filterActivityEvents($this->runtime->progressEvents($ownedSession), $request->user()),
        ]);
    }
}
