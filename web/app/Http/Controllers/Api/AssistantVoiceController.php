<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentProfileService;
use App\Services\OpenAiVoiceService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantVoiceController extends Controller
{
    public function __construct(
        private readonly OpenAiVoiceService $voice,
        private readonly WorkspaceService $workspaces,
    ) {}

    public function voices(): JsonResponse
    {
        return response()->json(['data' => [
            'provider' => 'openai_realtime',
            'default_voice' => OpenAiVoiceService::DEFAULT_VOICE,
            'voices' => $this->voice->availableVoices(),
        ]]);
    }

    public function realtimeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $workspace = $this->workspaces->resolveWorkspace($request->user(), $data['workspace_id'] ?? null);
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $request->user());

        return response()->json(['data' => $this->voice->createRealtimeSession($profile, [
            'timezone' => $data['timezone'] ?? null,
        ])]);
    }
}
