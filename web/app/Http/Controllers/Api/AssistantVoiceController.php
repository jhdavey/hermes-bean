<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
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
        $profile = app(\App\Services\AgentProfileService::class)->ensureForWorkspace($workspace, $request->user());

        return response()->json(['data' => $this->voice->createRealtimeSession($profile, [
            'timezone' => $data['timezone'] ?? null,
        ])]);
    }

    public function realtimeTool(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'arguments' => ['sometimes', 'array'],
            'session_id' => ['sometimes', 'nullable', 'integer', 'exists:conversation_sessions,id'],
        ]);

        if ($data['name'] !== 'send_bean_request') {
            return response()->json(['message' => 'Unsupported realtime voice tool.'], 422);
        }

        $arguments = $data['arguments'] ?? [];
        $content = trim((string) data_get($arguments, 'request', ''));
        if ($content === '') {
            return response()->json(['message' => 'Realtime voice tool request is required.'], 422);
        }

        if (! empty($data['session_id'])) {
            ConversationSession::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($data['session_id']);
        }

        // The browser already owns the normal authenticated chat/run lifecycle and has the
        // active session id. This endpoint is intentionally a narrow validation boundary for
        // Realtime tool calls; the client then routes the approved request through /runs so
        // existing Laravel auth, workspace scoping, action execution, persistence, dashboard
        // refresh, and audit behavior remain the source of truth.
        return response()->json(['data' => [
            'name' => 'send_bean_request',
            'request' => $content,
            'approved' => true,
            'route' => 'assistant_runs',
        ]]);
    }

    public function realtimeTurn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'user_text' => ['required', 'string', 'max:12000'],
            'assistant_text' => ['required', 'string', 'max:12000'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $session = ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($data['session_id']);

        $metadata = array_merge($data['metadata'] ?? [], [
            'source' => 'openai_realtime_voice',
            'voice_request' => true,
        ]);

        $userMessage = ConversationMessage::create([
            'user_id' => $request->user()->id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => trim($data['user_text']),
            'metadata' => $metadata,
        ]);

        $assistantMessage = ConversationMessage::create([
            'user_id' => $request->user()->id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => trim($data['assistant_text']),
            'metadata' => $metadata,
        ]);

        return response()->json(['data' => [
            'session' => $session->refresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
        ]], 201);
    }
}
