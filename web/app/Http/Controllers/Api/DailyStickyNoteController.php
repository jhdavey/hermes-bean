<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\DailyStickyNote;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DailyStickyNoteController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'workspace_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $user = $request->user();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $validated['workspace_id'] ?? null);
        $note = DailyStickyNote::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $workspace->id)
            ->whereDate('note_date', $validated['date'])
            ->first();

        return response()->json([
            'data' => [
                'date' => $validated['date'],
                'content' => (string) ($note?->content ?? ''),
                'updated_at' => $note?->updated_at?->toIso8601String(),
            ],
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'content' => ['nullable', 'string', 'max:12000'],
            'workspace_id' => ['sometimes', 'nullable', 'integer'],
        ]);
        $user = $request->user();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $validated['workspace_id'] ?? null);
        $note = DailyStickyNote::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'note_date' => $validated['date'],
            ],
            ['content' => (string) ($validated['content'] ?? '')],
        );

        return response()->json([
            'data' => [
                'date' => $note->note_date->format('Y-m-d'),
                'content' => (string) $note->content,
                'updated_at' => $note->updated_at?->toIso8601String(),
            ],
        ]);
    }
}
