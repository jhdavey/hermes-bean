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
    private const CONTROLLED_CLIENT_FAILURE_CODES = [
        'local_wake' => [
            'activated_pcm_delivery_failed',
            'already_started',
            'audio_context_closed',
            'audio_context_resume_timeout',
            'audio_sink_unavailable',
            'decode_failed',
            'dormant_rearm_failed',
            'gate_close_failed',
            'gate_open_failed',
            'incomplete_readiness_barrier',
            'initialization_failed',
            'invalid_audio',
            'invalid_generation',
            'invalid_local_pcm',
            'invalid_message',
            'invalid_message_type',
            'invalid_pcm_ack_sequence',
            'invalid_release_boundary',
            'invalid_sequence',
            'invalid_source_sequence',
            'invalid_utterance_boundary',
            'microphone_stream_required',
            'missing_release_boundary',
            'pcm_ack_timeout',
            'pcm_decode_rejected',
            'pcm_transfer_failed',
            'processor_failed',
            'processor_unavailable',
            'reset_failed',
            'runtime_load_failed',
            'source_sequence_gap',
            'stale_start',
            'start_failed',
            'unhandled_rejection',
            'unsafe_asset_url',
            'unsupported',
            'worker_error',
        ],
        'startup' => [
            'AbortError',
            'NotAllowedError',
            'NotFoundError',
            'NotReadableError',
            'OverconstrainedError',
            'SecurityError',
            'TypeError',
            'audio_context_closed',
            'audio_context_resume_timeout',
            'audio_sink_unavailable',
            'microphone_stream_required',
            'reset_failed',
            'stale_start',
            'start_failed',
            'unsupported',
            'voice_diagnostic_outbox_corrupt',
            'voice_diagnostic_outbox_overflow',
        ],
    ];

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
            'purpose' => ['required', 'in:acknowledgement,final,clarification'],
            'text' => ['required', 'string', 'max:4096'],
        ]);
        $user = $request->user();
        $workspace = $this->workspaces->resolveWorkspace($user, $data['workspace_id'] ?? null);
        $text = (string) $data['text'];
        if (trim($text) === '') {
            return response()->json([
                'message' => 'Speech text may not be blank.',
                'error' => ['code' => 'voice_speech_text_blank'],
            ], 422);
        }
        if (in_array($data['purpose'], ['acknowledgement', 'final', 'clarification'], true)) {
            $turn = VoiceTurn::query()
                ->where('user_id', $user->id)
                ->where('workspace_id', $workspace->id)
                ->where('turn_id', $data['turn_id'])
                ->first();
            $expected = match ($data['purpose']) {
                'final' => (string) $turn?->finalAssistantMessage()->value('content'),
                'clarification' => (string) data_get($turn?->metadata, 'clarification_question', ''),
                default => (string) $turn?->acknowledgement_text,
            };
            if (trim($expected) === '' || ! hash_equals($expected, $text)) {
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
            'failure_id' => ['required', 'string', 'min:8', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9:._-]+$/'],
            'stage' => ['required', 'in:local_wake,startup,admission,clarification,connection,transcription,usage_accounting'],
            'code' => ['required', 'string', 'max:80'],
            'message' => ['required', 'string', 'max:240'],
            'cause_chain' => ['present', 'array', 'max:4'],
            'cause_chain.*.code' => ['nullable', 'string', 'max:80'],
            'cause_chain.*.message' => ['nullable', 'string', 'max:240'],
            'session_id' => ['sometimes', 'nullable', 'integer'],
            'turn_id' => ['sometimes', 'nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9:._-]+$/'],
        ]);
        $user = $request->user();
        $session = isset($data['session_id'])
            ? ConversationSession::query()
                ->where('user_id', $user->id)
                ->whereKey((int) $data['session_id'])
                ->first()
            : null;
        $turn = filled($data['turn_id'] ?? null)
            && (! array_key_exists('session_id', $data) || $session !== null)
            ? VoiceTurn::query()
                ->where('user_id', $user->id)
                ->when($session, fn ($query) => $query->where('conversation_session_id', $session->id))
                ->where('turn_id', $data['turn_id'])
                ->first()
            : null;
        $failureIdentitySource = implode("\0", [
            (string) $user->id,
            $data['stage'],
            $data['failure_id'],
        ]);
        $applicationKey = (string) config('app.key');
        $failureDigest = $applicationKey !== ''
            ? hash_hmac('sha256', $failureIdentitySource, $applicationKey)
            : hash('sha256', $failureIdentitySource);
        $failureId = "browser_voice_v2:{$data['stage']}:{$failureDigest}";
        $contentNeutralMessage = match ($data['stage']) {
            'local_wake' => 'Private wake detection failed.',
            'startup' => 'Browser voice startup failed.',
            'admission' => 'Browser voice admission failed.',
            'clarification' => 'Browser voice clarification failed.',
            'connection' => 'Browser voice connection failed.',
            'transcription' => 'Browser voice transcription failed.',
            'usage_accounting' => 'Browser voice usage accounting failed.',
        };
        $safeCode = static fn (mixed $value): string => mb_substr(
            preg_replace('/[^A-Za-z0-9_.-]+/', '_', (string) $value) ?? '',
            0,
            80,
        );
        $diagnosticCode = static function (mixed $value) use ($data, $safeCode): string {
            $code = $safeCode($value);

            return match ($data['stage']) {
                'admission' => 'voice_admission_failure',
                'clarification' => 'voice_clarification_failure',
                'connection' => 'voice_connection_failure',
                'transcription' => 'voice_transcription_failure',
                'usage_accounting' => 'voice_usage_accounting_failure',
                'local_wake' => in_array($code, self::CONTROLLED_CLIENT_FAILURE_CODES['local_wake'], true)
                    ? $code
                    : 'local_wake_failure',
                'startup' => in_array($code, self::CONTROLLED_CLIENT_FAILURE_CODES['startup'], true)
                    ? $code
                    : 'voice_startup_failure',
                default => $code,
            };
        };
        $diagnostic = $this->privacy->sanitizeDiagnosticPayload([
            'stage' => $data['stage'],
            'code' => $diagnosticCode($data['code']),
            'message' => $contentNeutralMessage,
            'cause_chain' => collect($data['cause_chain'])
                ->take(4)
                ->map(fn (array $cause): array => [
                    'code' => filled($cause['code'] ?? null) ? $diagnosticCode($cause['code']) : null,
                    'message' => $contentNeutralMessage,
                ])->values()->all(),
            'turn_id' => $turn?->turn_id,
        ]);
        $event = ActivityEvent::firstOrCreate(
            [
                'user_id' => $user->id,
                'client_event_id' => $failureId,
            ],
            [
                'workspace_id' => $session?->workspace_id ?? $turn?->workspace_id ?? $user->default_workspace_id,
                'conversation_session_id' => $session?->id ?? $turn?->conversation_session_id,
                'event_type' => 'browser_voice_v2.client_failure',
                'tool_name' => 'browser.voice.client',
                'status' => 'failed',
                'payload' => $diagnostic,
            ],
        );
        if ($event->wasRecentlyCreated) {
            Log::warning('Browser Voice v2 client failure.', [
                'user_id' => $user->id,
                'workspace_id' => $event->workspace_id,
                'conversation_session_id' => $event->conversation_session_id,
                'failure_id' => $event->client_event_id,
                ...$diagnostic,
            ]);
        }

        return response()->json(['data' => [
            'recorded' => true,
            'duplicate' => ! $event->wasRecentlyCreated,
            'failure_id' => $event->client_event_id,
        ]]);
    }
}
