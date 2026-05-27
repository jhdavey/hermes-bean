<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DashboardChange;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardChangeController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'after' => ['nullable', 'integer', 'min:0'],
            'wait' => ['nullable', 'integer', 'min:0', 'max:30'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $after = (int) ($data['after'] ?? 0);
        $wait = (int) ($data['wait'] ?? 0);
        $limit = (int) ($data['limit'] ?? 50);
        $deadline = microtime(true) + $wait;
        $workspaceIds = app(WorkspaceService::class)->accessibleWorkspaces($request->user())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        do {
            $changes = $this->visibleChanges($request->user()->id, $workspaceIds, $after, $limit);
            if ($changes->isNotEmpty() || $wait <= 0) {
                break;
            }

            usleep(700_000);
        } while (microtime(true) < $deadline);

        $latestId = $changes->max('id') ?: $this->latestVisibleId($request->user()->id, $workspaceIds);

        return response()->json(['data' => [
            'changes' => $changes,
            'latest_id' => (int) $latestId,
        ]]);
    }

    private function visibleChanges(int $userId, array $workspaceIds, int $after, int $limit)
    {
        return DashboardChange::query()
            ->where('id', '>', $after)
            ->where(function ($query) use ($userId, $workspaceIds): void {
                $query->where('user_id', $userId);
                if ($workspaceIds !== []) {
                    $query->orWhereIn('workspace_id', $workspaceIds);
                }
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function latestVisibleId(int $userId, array $workspaceIds): int
    {
        return (int) DashboardChange::query()
            ->where(function ($query) use ($userId, $workspaceIds): void {
                $query->where('user_id', $userId);
                if ($workspaceIds !== []) {
                    $query->orWhereIn('workspace_id', $workspaceIds);
                }
            })
            ->max('id');
    }
}
