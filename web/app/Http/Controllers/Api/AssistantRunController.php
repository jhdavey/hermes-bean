<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\AdminSettingsService;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantRunController extends Controller
{
    public function __construct(
        private readonly AssistantRunService $runs,
        private readonly AiUsageService $usage,
        private readonly AdminSettingsService $settings,
        private readonly HermesRuntimeService $runtime,
    ) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);
        $user = User::findOrFail($ownedSession->user_id);
        $source = (string) ($data['source'] ?? 'http');
        $preflight = $this->usage->preflightDirect(
            $user,
            $ownedSession->workspace_id,
            $this->settings->mainModel(),
            $this->usage->estimateTokens($data['content']),
            (int) config('services.ai_usage.reserve_output_tokens', 1200),
            null,
            $source === 'realtime' ? 'voice_background' : 'text',
            ['session' => $ownedSession],
        );
        if (! $preflight['allowed']) {
            return response()->json([
                'message' => $preflight['reason'],
                'code' => 'bean_usage_limit',
            ], 429);
        }

        $queued = $this->runs->queueRun(
            $ownedSession,
            $data['content'],
            $data['metadata'] ?? [],
            $source
        );

        return response()->json(['data' => [
            'status' => 'queued',
            'session' => $ownedSession->refresh(),
            'run' => $queued['run']->refresh(),
            'user_message' => $queued['user_message'],
            'events' => [$queued['event']],
        ]], 202);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->with(['session', 'userMessage', 'assistantMessage'])
            ->findOrFail($run);

        return response()->json([
            'data' => $this->runs
                ->recoverStaleRun($ownedRun, $this->runtime)
                ->load(['session', 'userMessage', 'assistantMessage']),
        ]);
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($run);

        return response()->json(['data' => $this->runs->cancelRun($ownedRun)], 202);
    }
}
