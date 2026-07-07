<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppleCalendarImportService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class AppleCalendarController extends Controller
{
    public function __construct(
        private readonly AppleCalendarImportService $imports,
    ) {}

    public function import(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'url' => ['required', 'string', 'max:2048'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $workspace = app(WorkspaceService::class)->resolveWorkspace($request->user(), $validated['workspace_id'] ?? null);

        try {
            return response()->json([
                'data' => $this->imports->importFromUrl($request->user(), $workspace, $validated['url'], 'apple'),
            ]);
        } catch (RuntimeException $error) {
            return response()->json([
                'error' => [
                    'code' => 'apple_calendar_import_failed',
                    'message' => $error->getMessage(),
                ],
            ], 422);
        } catch (Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'apple_calendar_import_failed',
                    'message' => 'Apple calendar import failed. Please try again.',
                ],
            ], 422);
        }
    }
}
