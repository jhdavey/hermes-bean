<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\LiveLookupService;
use Illuminate\Http\JsonResponse;

class AdminLiveLookupController extends Controller
{
    public function index(LiveLookupService $lookup): JsonResponse
    {
        return response()->json(['data' => $lookup->providers()]);
    }
}
