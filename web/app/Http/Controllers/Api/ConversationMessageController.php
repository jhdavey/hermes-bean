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

    public function store(Request $request, ConversationSession $session): JsonResponse
    {
        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $result = $this->runtime->sendMessage($session, $data['content'], $data['metadata'] ?? []);

        return response()->json(['data' => $result], $result['status'] === 'blocked' ? 202 : 201);
    }
}
