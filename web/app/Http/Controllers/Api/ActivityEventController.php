<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\PlanHistoryService;
use App\Services\PlanLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityEventController extends Controller
{
    public function __construct(
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

        $after = max(0, (int) $request->query('after', 0));
        $limit = min(max((int) $request->query('limit', 100), 1), 250);
        $waitSeconds = min(max((int) $request->query('wait', 0), 0), 10);
        $deadline = microtime(true) + $waitSeconds;

        do {
            $events = $this->history->filterActivityEvents(
                $ownedSession->activityEvents()
                    ->when($after > 0, fn ($query) => $query->where('id', '>', $after))
                    ->orderBy('id')
                    ->limit($limit)
                    ->get(),
                $request->user()
            );

            if ($events->isNotEmpty() || $waitSeconds === 0 || microtime(true) >= $deadline) {
                break;
            }

            usleep(250_000);
        } while (true);

        return response()->json([
            'data' => $events,
            'meta' => [
                'after' => $after,
                'latest_id' => (int) ($events->max('id') ?? $after),
            ],
        ]);
    }
}
