<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use App\Services\HermesRuntimeService;
use Illuminate\Http\JsonResponse;

class ActivityEventController extends Controller
{
    public function __construct(private readonly HermesRuntimeService $runtime) {}

    public function index(ConversationSession $session): JsonResponse
    {
        return response()->json([
            'data' => $this->runtime->progressEvents($session),
        ]);
    }
}
