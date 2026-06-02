<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BetaUser;
use App\Models\IssueReport;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class IssueReportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'max:4000'],
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
            'page_url' => ['sometimes', 'nullable', 'string', 'max:2048'],
            'screenshots' => ['sometimes', 'array', 'max:5'],
            'screenshots.*' => ['file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:8192'],
        ]);

        $user = $request->user();
        $workspace = app(WorkspaceService::class)->resolveWorkspace($user, $data['workspace_id'] ?? null);
        $betaUser = BetaUser::where('user_id', $user->id)->where('status', 'active')->first();
        $screenshots = [];

        foreach ($request->file('screenshots', []) as $file) {
            $path = $file->store('issue-reports/'.now()->format('Y/m'), 'public');
            $screenshots[] = [
                'name' => $file->getClientOriginalName(),
                'path' => $path,
                'url' => Storage::disk('public')->url($path),
                'size' => $file->getSize(),
                'mime' => $file->getMimeType(),
            ];
        }

        $report = IssueReport::create([
            'user_id' => $user->id,
            'workspace_id' => $workspace->id,
            'beta_user_id' => $betaUser?->id,
            'status' => 'open',
            'message' => $data['message'],
            'page_url' => $data['page_url'] ?? null,
            'user_agent' => str((string) $request->userAgent())->limit(1024, '')->toString(),
            'screenshots' => $screenshots,
            'metadata' => [
                'ip' => $request->ip(),
                'is_beta' => $betaUser !== null,
            ],
        ]);

        return response()->json(['data' => $report->load(['user:id,name,email', 'workspace:id,name,type'])], 201);
    }

    public function update(Request $request, IssueReport $issueReport): JsonResponse
    {
        $data = $request->validate([
            'status' => ['required', 'string', Rule::in(['open', 'closed', 'archived'])],
        ]);

        $status = $data['status'];
        $metadata = $issueReport->metadata ?? [];
        $metadata['last_status_changed_by_user_id'] = $request->user()->id;
        $metadata['last_status_changed_at'] = now()->toIso8601String();
        if ($status === 'archived') {
            $metadata['archived_at'] = now()->toIso8601String();
        }

        $issueReport->forceFill([
            'status' => $status,
            'resolved_at' => $status === 'open' ? null : ($issueReport->resolved_at ?: now()),
            'metadata' => $metadata,
        ])->save();

        return response()->json(['data' => $issueReport->load(['user:id,name,email', 'workspace:id,name,type'])]);
    }
}
