<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\HermesRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationMessageController extends Controller
{
    public function __construct(private readonly HermesRuntimeService $runtime) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);

        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $result = $this->runtime->sendMessage($ownedSession, $data['content'], $data['metadata'] ?? []);

        return response()->json(['data' => $result], $result['status'] === 'blocked' ? 429 : 201);
    }
}
