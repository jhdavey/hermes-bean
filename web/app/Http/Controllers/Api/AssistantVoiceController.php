<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
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
            'provider' => 'openai',
            'default_voice' => OpenAiVoiceService::DEFAULT_VOICE,
            'voices' => $this->voice->availableVoices(),
        ]]);
    }

    public function speech(Request $request): JsonResponse
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $workspace = $this->workspaces->resolveWorkspace($request->user(), $data['workspace_id'] ?? null);
        $profile = app(\App\Services\AgentProfileService::class)->ensureForWorkspace($workspace, $request->user());

        return response()->json(['data' => $this->voice->synthesizeSpeech($data['text'], $profile)]);
    }

    public function transcriptions(Request $request): JsonResponse
    {
        $data = $request->validate([
            'audio' => ['required', 'file', 'max:25600'],
        ]);

        return response()->json(['data' => $this->voice->transcribe($data['audio'])]);
    }
}
