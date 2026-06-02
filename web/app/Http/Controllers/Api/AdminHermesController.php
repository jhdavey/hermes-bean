<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\HermesMaintenanceService;
use Illuminate\Http\JsonResponse;

class AdminHermesController extends Controller
{
    public function show(HermesMaintenanceService $maintenance): JsonResponse
    {
        return response()->json(['data' => $maintenance->status()]);
    }

    public function update(HermesMaintenanceService $maintenance): JsonResponse
    {
        $result = $maintenance->update();

        return response()->json([
            'message' => $result['status'] === 'completed'
                ? 'Hermes update completed.'
                : 'Hermes update failed. Review the CLI output below.',
            'data' => $result,
        ], $result['status'] === 'completed' ? 200 : 502);
    }
}
