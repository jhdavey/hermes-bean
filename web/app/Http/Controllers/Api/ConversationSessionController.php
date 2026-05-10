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
            'metadata' => ['nullable', 'array'],
        ]);

        return response()->json(['data' => $this->runtime->startSession($data)], 201);
    }

    public function show(ConversationSession $session): JsonResponse
    {
        return response()->json(['data' => $this->runtime->resumeSession($session)]);
    }
}
