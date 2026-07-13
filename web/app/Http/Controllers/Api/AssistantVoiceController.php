<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\AgentProfileService;
use App\Services\AiUsageService;
use App\Services\OpenAiVoiceService;
use App\Services\PlanLimitService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantVoiceController extends Controller
{
    public function __construct(
        private readonly OpenAiVoiceService $voice,
        private readonly WorkspaceService $workspaces,
        private readonly AiUsageService $usage,
        private readonly PlanLimitService $planLimits,
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
            'sdp' => ['required', 'string', 'max:200000'],
        ]);

        $workspace = $this->workspaces->resolveWorkspace($request->user(), $data['workspace_id'] ?? null);
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $request->user());
        $preflight = $this->usage->preflightRealtimeSession($request->user(), $workspace->id);
        if (! $preflight['allowed']) {
            $message = 'You’ve reached today’s AI usage limit for your current plan. Upgrade for more voice usage, or try again tomorrow.';
            $this->usage->recordDirectCall(
                $request->user(),
                $workspace->id,
                'voice_realtime',
                (string) config('services.openai.realtime_model', 'gpt-realtime'),
                metadata: ['reason' => $preflight['reason'], 'limit_stage' => 'session_preflight'],
                actionTypes: ['voice_realtime_session'],
                status: 'blocked',
            );

            return $this->planLimits->limitResponse($message, [
                'limit_type' => 'daily_ai_usage',
                'plan_tier' => $request->user()->subscriptionTier(),
            ]);
        }

        try {
            $session = $this->voice->createRealtimeCall(
                $profile,
                $data['sdp'],
                ['timezone' => $data['timezone'] ?? null],
                hash_hmac('sha256', (string) $request->user()->id, (string) config('app.key')),
            );
        } catch (Throwable $error) {
            Log::warning('Browser Voice v2 provider connection failed.', [
                'user_id' => $request->user()->id,
                'workspace_id' => $workspace->id,
                'stage' => 'realtime_sdp',
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'Bean couldn’t connect voice right now. Tap Bean to try again.',
                'error' => ['code' => 'realtime_connection_failed'],
            ], 502);
        }
        $usageSession = $this->usage->recordRealtimeSessionOpened(
            $request->user(),
            $workspace->id,
            isset($session['session_id']) ? (string) $session['session_id'] : null,
            (string) $session['model'],
            (string) config('services.openai.realtime_transcription_model', 'gpt-4o-mini-transcribe'),
        );

        return response()->json(['data' => [
            ...$session,
            'usage_session_id' => $usageSession->usage_session_id,
        ]]);
    }

    public function realtimeUsage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'usage_session_id' => ['required', 'uuid'],
            'provider_event_id' => ['required', 'string', 'max:191'],
            'event_type' => ['required', 'in:transcription,speech'],
            'usage' => ['required', 'array'],
            'usage.total_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.input_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.output_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.input_token_details' => ['nullable', 'array'],
            'usage.input_token_details.text_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.input_token_details.audio_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.input_token_details.cached_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.input_token_details.cached_tokens_details' => ['nullable', 'array'],
            'usage.input_token_details.cached_tokens_details.text_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.input_token_details.cached_tokens_details.audio_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.output_token_details' => ['nullable', 'array'],
            'usage.output_token_details.text_tokens' => ['nullable', 'integer', 'min:0'],
            'usage.output_token_details.audio_tokens' => ['nullable', 'integer', 'min:0'],
        ]);
        $result = $this->usage->recordRealtimeUsage(
            $request->user(),
            $data['usage_session_id'],
            $data['provider_event_id'],
            $data['event_type'],
            $data['usage'],
        );
        $availability = $result['availability'];
        if (! $availability['allowed']) {
            return $this->planLimits->limitResponse((string) $availability['reason'], [
                'limit_type' => 'daily_ai_usage',
                'plan_tier' => $availability['tier'],
                'used_usd' => $availability['used_usd'],
                'limit_usd' => $availability['limit_usd'],
            ]);
        }

        return response()->json(['data' => [
            'accepted' => true,
            'duplicate' => $result['duplicate'],
            'remaining' => $availability,
        ]]);
    }

    public function clientFailure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', 'in:local_wake,startup,admission'],
            'code' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:240'],
            'cause_chain' => ['present', 'array', 'max:4'],
            'cause_chain.*.code' => ['nullable', 'string', 'max:80'],
            'cause_chain.*.message' => ['nullable', 'string', 'max:240'],
        ]);
        $user = $request->user();
        Log::warning('Browser Voice v2 client failure.', [
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            'stage' => $data['stage'],
            'code' => $data['code'],
            'message' => $data['message'],
            'cause_chain' => $data['cause_chain'],
        ]);

        return response()->json(['data' => ['recorded' => true]]);
    }
}
