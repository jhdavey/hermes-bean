<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityEvent;
use App\Models\ConversationSession;
use App\Models\VoiceTurn;
use App\Services\AgentProfileService;
use App\Services\AiUsageService;
use App\Services\OpenAiVoiceService;
use App\Services\PlanLimitService;
use App\Services\VoiceTurnPrivacyService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class AssistantVoiceController extends Controller
{
    public function __construct(
        private readonly OpenAiVoiceService $voice,
        private readonly WorkspaceService $workspaces,
        private readonly AiUsageService $usage,
        private readonly PlanLimitService $planLimits,
        private readonly VoiceTurnPrivacyService $privacy,
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

    public function speech(Request $request): Response|StreamedResponse|JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
            'turn_id' => ['required', 'string', 'max:191'],
            'speech_item_id' => ['required', 'string', 'max:191'],
            'purpose' => ['required', 'in:acknowledgement,final,clarification,interruption,cancellation'],
            'text' => ['required', 'string', 'max:4096'],
        ]);
        $user = $request->user();
        $workspace = $this->workspaces->resolveWorkspace($user, $data['workspace_id'] ?? null);
        $text = trim((string) $data['text']);
        if (in_array($data['purpose'], ['acknowledgement', 'final'], true)) {
            $turn = VoiceTurn::query()
                ->where('user_id', $user->id)
                ->where('workspace_id', $workspace->id)
                ->where('turn_id', $data['turn_id'])
                ->first();
            $expected = $data['purpose'] === 'final'
                ? trim((string) $turn?->finalAssistantMessage()->value('content'))
                : trim((string) $turn?->acknowledgement_text);
            if ($expected === '' || ! hash_equals($expected, $text)) {
                return response()->json([
                    'message' => 'Bean could not verify the response text for speech.',
                    'error' => ['code' => 'voice_speech_text_mismatch'],
                ], 409);
            }
        }

        $preflight = $this->usage->preflightSpeechSynthesis($user, $workspace->id, mb_strlen($text));
        if (! $preflight['allowed']) {
            return $this->planLimits->limitResponse(
                'You’ve reached today’s AI usage limit for your current plan. Upgrade for more voice usage, or try again tomorrow.',
                [
                    'limit_type' => 'daily_ai_usage',
                    'plan_tier' => $user->subscriptionTier(),
                ],
            );
        }

        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $user);
        try {
            $speech = $this->voice->createSpeechStream(
                $profile,
                $text,
                hash_hmac('sha256', (string) $user->id, (string) config('app.key')),
            );
            $this->usage->recordSpeechSynthesis(
                $user,
                $workspace->id,
                (string) $data['speech_item_id'],
                (string) $speech['model'],
                (string) $speech['voice'],
                (int) $speech['characters'],
            );
        } catch (Throwable $error) {
            Log::warning('Browser Voice v2 speech synthesis failed.', [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'turn_id' => $data['turn_id'],
                'speech_item_id' => $data['speech_item_id'],
                'purpose' => $data['purpose'],
                'error' => $error->getMessage(),
            ]);

            return response()->json([
                'message' => 'Bean couldn’t play that response, but the full answer is still in chat.',
                'error' => ['code' => 'voice_speech_failed'],
            ], 502);
        }

        $headers = [
            'Content-Type' => $speech['content_type'],
            'Cache-Control' => 'no-store, private',
            'X-Accel-Buffering' => 'no',
            'X-Bean-Audio-Encoding' => 'pcm_s16le',
            'X-Bean-Audio-Sample-Rate' => (string) $speech['sample_rate'],
            'X-Bean-Speech-Text-Sha256' => hash('sha256', $text),
        ];
        if ($speech['content_length'] !== null) {
            $headers['Content-Length'] = (string) $speech['content_length'];
        }

        return response()->stream(function () use ($speech, $data, $user, $workspace): void {
            $stream = $speech['stream'];
            $bytes = 0;
            try {
                while (! $stream->eof()) {
                    $chunk = $stream->read(16_384);
                    if ($chunk === '') {
                        if ($stream->eof()) {
                            break;
                        }

                        continue;
                    }
                    $bytes += strlen($chunk);
                    echo $chunk;
                    if (ob_get_level() > 0) {
                        @ob_flush();
                    }
                    flush();
                }
                if ($bytes === 0) {
                    throw new \RuntimeException('OpenAI speech stream ended before returning audio.');
                }
            } catch (Throwable $error) {
                Log::warning('Browser Voice v2 speech stream failed.', [
                    'user_id' => $user->id,
                    'workspace_id' => $workspace->id,
                    'turn_id' => $data['turn_id'],
                    'speech_item_id' => $data['speech_item_id'],
                    'purpose' => $data['purpose'],
                    'bytes_streamed' => $bytes,
                    'error' => $error->getMessage(),
                ]);
                // Do not turn a truncated provider stream into a clean EOF.
                // Closing the HTTP response exceptionally lets the browser's
                // single speech transport report playback_error instead of a
                // false completed delivery receipt.
                throw $error;
            } finally {
                $stream->close();
            }
        }, 200, $headers);
    }

    public function clientFailure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'stage' => ['required', 'in:local_wake,startup,admission,clarification,connection,usage_accounting'],
            'code' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:240'],
            'cause_chain' => ['present', 'array', 'max:4'],
            'cause_chain.*.code' => ['nullable', 'string', 'max:80'],
            'cause_chain.*.message' => ['nullable', 'string', 'max:240'],
            'session_id' => ['sometimes', 'nullable', 'integer'],
            'turn_id' => ['sometimes', 'nullable', 'string', 'max:191'],
        ]);
        $user = $request->user();
        $diagnostic = $this->privacy->sanitizeDiagnosticPayload([
            'stage' => $data['stage'],
            'code' => $data['code'],
            'message' => $data['message'],
            'cause_chain' => $data['cause_chain'],
            'turn_id' => $data['turn_id'] ?? null,
        ]);
        $session = isset($data['session_id'])
            ? ConversationSession::query()
                ->where('user_id', $user->id)
                ->whereKey((int) $data['session_id'])
                ->first()
            : null;
        ActivityEvent::create([
            'user_id' => $user->id,
            'workspace_id' => $session?->workspace_id ?? $user->default_workspace_id,
            'conversation_session_id' => $session?->id,
            'event_type' => 'browser_voice_v2.client_failure',
            'tool_name' => 'browser.voice.client',
            'status' => 'failed',
            'payload' => $diagnostic,
        ]);
        Log::warning('Browser Voice v2 client failure.', [
            'user_id' => $user->id,
            'workspace_id' => $user->default_workspace_id,
            ...$diagnostic,
        ]);

        return response()->json(['data' => ['recorded' => true]]);
    }
}
