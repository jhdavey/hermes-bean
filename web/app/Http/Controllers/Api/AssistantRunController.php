<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Services\AssistantRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantRunController extends Controller
{
    public function __construct(private readonly AssistantRunService $runs) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);

        $queued = $this->runs->queueRun(
            $ownedSession,
            $data['content'],
            $data['metadata'] ?? [],
            $data['source'] ?? 'http'
        );

        return response()->json(['data' => [
            'status' => 'queued',
            'session' => $ownedSession->refresh(),
            'run' => $queued['run']->refresh(),
            'user_message' => $queued['user_message'],
            'events' => [$queued['event']],
        ]], 202);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->with(['session', 'userMessage', 'assistantMessage'])
            ->findOrFail($run);

        return response()->json(['data' => $ownedRun]);
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($run);

        return response()->json(['data' => $this->runs->cancelRun($ownedRun)], 202);
    }
}
