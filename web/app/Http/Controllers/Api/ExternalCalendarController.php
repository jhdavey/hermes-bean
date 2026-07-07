<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AppleCalendarImportService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class ExternalCalendarController extends Controller
{
    public function __construct(
        private readonly AppleCalendarImportService $imports,
    ) {}

    public function providers(): JsonResponse
    {
        return response()->json(['data' => $this->imports->providerPresets()]);
    }

    public function import(Request $request): JsonResponse
    {
        $providerKeys = array_column($this->imports->providerPresets(), 'key');
        $validated = $request->validate([
            'provider_key' => ['required', 'string', Rule::in($providerKeys)],
            'url' => ['required', 'string', 'max:2048'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $workspace = app(WorkspaceService::class)->resolveWorkspace($request->user(), $validated['workspace_id'] ?? null);

        try {
            return response()->json([
                'data' => $this->imports->importFromUrl(
                    $request->user(),
                    $workspace,
                    $validated['url'],
                    $validated['provider_key'],
                ),
            ]);
        } catch (RuntimeException $error) {
            return response()->json([
                'error' => [
                    'code' => 'external_calendar_import_failed',
                    'message' => $error->getMessage(),
                ],
            ], 422);
        } catch (Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'external_calendar_import_failed',
                    'message' => 'External calendar import failed. Please try again.',
                ],
            ], 422);
        }
    }
}
