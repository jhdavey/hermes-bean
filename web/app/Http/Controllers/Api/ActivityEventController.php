<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationSession;
use Illuminate\Http\JsonResponse;

class ActivityEventController extends Controller
{
    public function index(ConversationSession $session): JsonResponse
    {
        return response()->json([
            'data' => $session->activityEvents()->orderBy('id')->get(),
        ]);
    }
}
