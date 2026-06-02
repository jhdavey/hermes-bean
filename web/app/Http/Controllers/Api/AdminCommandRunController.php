<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AdminCommandRun;
use App\Services\AdminCommandRunService;
use Illuminate\Http\JsonResponse;

class AdminCommandRunController extends Controller
{
    public function show(AdminCommandRun $commandRun, AdminCommandRunService $commands): JsonResponse
    {
        return response()->json(['data' => $commands->payload($commandRun)]);
    }
}
