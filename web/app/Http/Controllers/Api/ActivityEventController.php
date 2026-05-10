<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\HermesRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ActivityEventController extends Controller
{
    public function __construct(private readonly HermesRuntimeService $runtime) {}

    public function index(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);

        return response()->json([
            'data' => $this->runtime->progressEvents($ownedSession),
        ]);
    }
}
