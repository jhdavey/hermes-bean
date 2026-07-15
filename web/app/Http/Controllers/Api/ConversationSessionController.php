<?php

namespace App\Http\Controllers\Api;

use App\Enums\ConversationSessionKind;
use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Rules\ClientTimezone;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\PlanHistoryService;
use App\Services\PlanLimitService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationSessionController extends Controller
{
    public function __construct(
        private readonly HermesRuntimeService $runtime,
        private readonly PlanHistoryService $history,
        private readonly PlanLimitService $planLimits,
        private readonly AssistantRunService $runs,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'date' => ['nullable', 'required_with:timezone', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'required_with:date', 'string', 'max:80', new ClientTimezone],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->where('session_kind', ConversationSessionKind::Conversation->value)
            ->with('latestMessage')
            ->withCount(['messages' => fn ($query) => $query->where('display_mode', 'chat')]);

        if (! empty($data['workspace_id'])) {
            $query->where('workspace_id', $data['workspace_id']);
        }

        $sessions = (clone $query)
            ->orderByRaw('COALESCE(last_activity_at, updated_at, created_at) desc')
            ->latest('id')
            ->limit((int) ($data['limit'] ?? 30))
            ->get()
            ->filter(fn (ConversationSession $session): bool => $this->history->sessionWithinHistory($session, $request->user()))
            ->values();

        $todaySession = null;
        if (! empty($data['date'])) {
            $timezone = (string) $data['timezone'];
            $start = CarbonImmutable::createFromFormat('Y-m-d', $data['date'], $timezone)->startOfDay()->utc();
            $end = $start->setTimezone($timezone)->endOfDay()->utc();
            $todaySession = (clone $query)
                ->whereBetween('created_at', [$start, $end])
                ->orderByRaw('COALESCE(last_activity_at, updated_at, created_at) desc')
                ->latest('id')
                ->first();
            if ($todaySession && ! $this->history->sessionWithinHistory($todaySession, $request->user())) {
                $todaySession = null;
            }
        }

        return response()->json([
            'data' => [
                'sessions' => $sessions,
                'today_session' => $todaySession,
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'metadata' => ['nullable', 'array'],
        ]);

        $data['user_id'] = $request->user()->id;

        return response()->json(['data' => $this->runtime->startSession($data)], 201);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        if (! $this->history->sessionWithinHistory($ownedSession, $request->user())) {
            $days = $this->planLimits->historyDaysFor($request->user());

            return $this->planLimits->limitResponse($days === null
                ? 'This conversation is outside your available history.'
                : "Your current plan includes {$days} days of Bean conversation history.");
        }

        $this->runs->prepareSessionRunsForResponse($ownedSession);

        $session = $this->runtime->resumeSession($ownedSession)
            ->load([
                'messages' => function ($query) use ($request): void {
                    $cutoff = $this->history->cutoffFor($request->user());
                    $query->where('display_mode', 'chat')
                        ->when($cutoff !== null, fn ($query) => $query->where('created_at', '>=', $cutoff))
                        ->orderBy('id');
                },
                'activityEvents' => function ($query) use ($request): void {
                    $cutoff = $this->history->cutoffFor($request->user());
                    $query->when($cutoff !== null, fn ($query) => $query->where('created_at', '>=', $cutoff))
                        ->orderBy('id');
                },
                'assistantRuns' => function ($query): void {
                    $query->whereNull('voice_turn_id')
                        ->whereIn('status', ['queued', 'running'])
                        ->with(['userMessage', 'assistantMessage'])
                        ->orderBy('id');
                },
            ]);

        return response()->json(['data' => $session]);
    }

    public function cancel(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'client_request_id' => ['nullable', 'string', 'max:120'],
        ]);
        $clientRequestId = trim((string) ($data['client_request_id'] ?? '')) ?: null;

        return response()->json(['data' => $this->runs->cancelSession($ownedSession, $clientRequestId)], 202);
    }
}
