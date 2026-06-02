<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AdminCommandRunService;
use App\Services\HermesMaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AdminHermesController extends Controller
{
    public function show(HermesMaintenanceService $maintenance): JsonResponse
    {
        return response()->json(['data' => $maintenance->status()]);
    }

    public function update(Request $request, AdminCommandRunService $commands): JsonResponse
    {
        $run = $commands->start('hermes_update', $request->user());

        return response()->json([
            'message' => 'Hermes update started.',
            'data' => $commands->payload($run),
        ], 202);
    }
}
