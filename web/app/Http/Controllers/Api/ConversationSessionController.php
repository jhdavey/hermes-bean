<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\HermesRuntimeService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationSessionController extends Controller
{
    public function __construct(private readonly HermesRuntimeService $runtime) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'timezone' => ['nullable', 'timezone'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]);

        $query = ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->where('runtime_mode', '!=', 'onboarding')
            ->with('latestMessage')
            ->withCount('messages');

        if (! empty($data['workspace_id'])) {
            $query->where('workspace_id', $data['workspace_id']);
        }

        $sessions = (clone $query)
            ->orderByRaw('COALESCE(last_activity_at, updated_at, created_at) desc')
            ->latest('id')
            ->limit((int) ($data['limit'] ?? 30))
            ->get();

        $todaySession = null;
        if (! empty($data['date'])) {
            $timezone = (string) ($data['timezone'] ?? config('app.timezone'));
            $start = CarbonImmutable::createFromFormat('Y-m-d', $data['date'], $timezone)->startOfDay()->utc();
            $end = $start->setTimezone($timezone)->endOfDay()->utc();
            $todaySession = (clone $query)
                ->whereBetween('created_at', [$start, $end])
                ->orderByRaw('COALESCE(last_activity_at, updated_at, created_at) desc')
                ->latest('id')
                ->first();
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
            'runtime_mode' => ['nullable', 'string', 'max:50'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'metadata' => ['nullable', 'array'],
        ]);

        $data['user_id'] = $request->user()->id;

        return response()->json(['data' => $this->runtime->startSession($data)], 201);
    }

    public function show(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);

        $session = $this->runtime->resumeSession($ownedSession)
            ->load(['messages' => fn ($query) => $query->orderBy('id'), 'activityEvents' => fn ($query) => $query->orderBy('id')]);

        return response()->json(['data' => $session]);
    }

    public function cancel(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);

        return response()->json(['data' => $this->runtime->cancelSession($ownedSession)], 202);
    }
}
