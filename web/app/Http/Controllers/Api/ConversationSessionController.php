<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\HermesRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationSessionController extends Controller
{
    public function __construct(private readonly HermesRuntimeService $runtime) {}

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

        return response()->json(['data' => $this->runtime->resumeSession($ownedSession)]);
    }

    public function cancel(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);

        return response()->json(['data' => $this->runtime->cancelSession($ownedSession)], 202);
    }
}
