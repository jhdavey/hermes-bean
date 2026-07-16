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
use App\Services\RealtimeVoiceApplicationEventHandler;
use App\Services\RealtimeVoiceSessionService;
use App\Services\VoiceTurnPrivacyService;
use App\Services\WorkspaceService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
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
            'pcm_ack_activation_pending',
            'pcm_ack_failed',
            'pcm_ack_generation_mismatch',
            'pcm_ack_invalid_audio',
            'pcm_ack_not_ready',
            'pcm_ack_unknown',
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
        'connection' => [
            'realtime_data_channel_closed',
            'realtime_data_channel_failed',
            'realtime_peer_connection_failed',
            'realtime_provider_error',
            'realtime_remote_description_failed',
            'realtime_transport_closed_during_startup',
            'realtime_transport_readiness_failed',
            'realtime_transport_readiness_timeout',
        ],
    ];

    public function __construct(
        private readonly OpenAiVoiceService $voice,
        private readonly WorkspaceService $workspaces,
        private readonly AiUsageService $usage,
        private readonly PlanLimitService $planLimits,
        private readonly VoiceTurnPrivacyService $privacy,
        private readonly RealtimeVoiceSessionService $realtimeSessions,
        private readonly RealtimeVoiceApplicationEventHandler $realtimeEvents,
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
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'controller_generation' => ['required', 'integer', 'min:0'],
            'provider_connection_generation' => ['required', 'integer', 'min:0'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:80'],
            'sdp' => ['required', 'string', 'max:200000'],
        ]);
        $conversation = ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail((int) $data['session_id']);
        $workspace = $this->workspaces->resolveWorkspace(
            $request->user(),
            $data['workspace_id'] ?? $conversation->workspace_id,
        );
        if ((int) $conversation->workspace_id !== (int) $workspace->id) {
            return response()->json(['message' => 'That conversation is not in the selected workspace.'], 422);
        }
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $request->user());
        $preflight = $this->usage->preflightRealtimeSession($request->user(), $workspace->id);
        if (! $preflight['allowed']) {
            $message = 'You’ve reached today’s AI usage limit for your current plan. Upgrade for more voice usage, or try again tomorrow.';
            $this->usage->recordDirectCall(
                $request->user(),
                $workspace->id,
                'voice_realtime',
                (string) config('services.openai.realtime_model', OpenAiVoiceService::DEFAULT_REALTIME_MODEL),
                metadata: ['reason' => $preflight['reason'], 'limit_stage' => 'session_preflight'],
                actionTypes: ['voice_realtime_session'],
                status: 'blocked',
            );

            return $this->planLimits->limitResponse($message, [
                'limit_type' => 'daily_ai_usage',
                'plan_tier' => $request->user()->subscriptionTier(),
            ]);
        }

        $settings = $this->voice->publicSettingsFor($profile);
        $playbackCapability = Str::random(64);
        $ledgerSession = $this->realtimeSessions->createPending(
            $request->user(),
            $conversation,
            (string) $settings['realtime_model'],
            (string) $settings['voice'],
            (int) $data['controller_generation'],
            [
                'timezone' => $data['timezone'] ?? null,
                'provider_connection_generation' => (int) $data['provider_connection_generation'],
                'playback_capability' => $playbackCapability,
                'transport' => 'webrtc_sideband',
            ],
        );

        try {
            $provider = $this->voice->createRealtimeCall(
                $profile,
                $data['sdp'],
                ['timezone' => $data['timezone'] ?? null],
                hash_hmac('sha256', (string) $request->user()->id, (string) config('app.key')),
            );
            $providerCallId = trim((string) ($provider['session_id'] ?? ''));
            if ($providerCallId === '') {
                throw new \RuntimeException('OpenAI did not return the Realtime call identifier required by the sideband.');
            }
            $ledgerSession = $this->realtimeSessions->bindProviderCall($ledgerSession, $providerCallId);
        } catch (Throwable $error) {
            $ledgerSession->delete();
            Log::warning('Realtime browser voice provider connection failed.', [
                'user_id' => $request->user()->id,
                'workspace_id' => $workspace->id,
                'stage' => 'realtime_sdp',
                'exception' => $error::class,
                'cause_code' => $this->realtimeProviderFailureCode($error),
            ]);

            return response()->json([
                'message' => 'Bean couldn’t connect voice right now. Tap Bean to try again.',
                'error' => ['code' => 'realtime_connection_failed'],
            ], 502);
        }
        try {
            $usageSession = $this->usage->recordRealtimeSessionOpened(
                $request->user(),
                $workspace->id,
                (string) $ledgerSession->provider_call_id,
                (string) $provider['model'],
            );
            $ledgerSession->forceFill(['metadata' => [
                ...(is_array($ledgerSession->metadata) ? $ledgerSession->metadata : []),
                'usage_session_id' => $usageSession->usage_session_id,
            ]])->save();
        } catch (Throwable $error) {
            $ledgerSession->delete();
            Log::warning('Browser Voice usage session initialization failed.', [
                'user_id' => $request->user()->id,
                'workspace_id' => $workspace->id,
                'stage' => 'usage_session',
                'exception' => $error::class,
            ]);

            return response()->json([
                'message' => 'Bean couldn’t start a metered voice session. Tap Bean to try again.',
                'error' => ['code' => 'realtime_usage_session_failed'],
            ], 502);
        }
        unset($provider['session_id'], $provider['tools']);

        return response()->json(['data' => [
            ...$provider,
            'realtime_session_id' => $ledgerSession->public_id,
            'playback_capability' => $playbackCapability,
            'sideband_ready' => false,
        ]]);
    }

    public function clientFailure(Request $request): JsonResponse
    {
        $data = $request->validate([
            'failure_id' => ['required', 'string', 'min:8', 'max:191', 'regex:/^[A-Za-z0-9][A-Za-z0-9:._-]+$/'],
            'stage' => ['required', 'in:local_wake,startup,admission,connection,delivery,projection,playback,realtime_sideband'],
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
        $failureId = "browser_voice_realtime:{$data['stage']}:{$failureDigest}";
        $contentNeutralMessage = match ($data['stage']) {
            'local_wake' => 'Private wake detection failed.',
            'startup' => 'Browser voice startup failed.',
            'admission' => 'Browser voice admission failed.',
            'connection' => 'Browser voice connection failed.',
            'delivery' => 'Browser voice delivery reporting failed.',
            'projection' => 'Browser voice state recovery failed.',
            'playback' => 'Browser voice playback failed.',
            'realtime_sideband' => 'Browser voice server control failed.',
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
                'connection' => in_array($code, self::CONTROLLED_CLIENT_FAILURE_CODES['connection'], true)
                    ? $code
                    : 'voice_connection_failure',
                'delivery' => 'voice_delivery_failure',
                'projection' => 'voice_projection_failure',
                'playback' => 'voice_playback_failure',
                'realtime_sideband' => 'voice_sideband_failure',
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
                'event_type' => 'browser_voice_realtime.client_failure',
                'tool_name' => 'browser.voice.client',
                'status' => 'failed',
                'payload' => $diagnostic,
            ],
        );
        if ($event->wasRecentlyCreated) {
            Log::warning('Realtime browser voice client failure.', [
                'user_id' => $user->id,
                'workspace_id' => $event->workspace_id,
                'conversation_session_id' => $event->conversation_session_id,
                'failure_id' => $event->client_event_id,
                ...$diagnostic,
            ]);
        }

        $recordedTurnId = trim((string) data_get($event->payload, 'turn_id', ''));
        $recordedTurn = $recordedTurnId !== '' && $event->conversation_session_id !== null
            ? VoiceTurn::query()
                ->where('user_id', $user->id)
                ->where('conversation_session_id', $event->conversation_session_id)
                ->where('turn_id', $recordedTurnId)
                ->first()
            : null;
        $turnFailureRecovery = $data['stage'] === 'local_wake' && $recordedTurn instanceof VoiceTurn
            ? $this->realtimeEvents->handleClientTurnFailure(
                $recordedTurn,
                (string) $data['stage'],
                (string) data_get($event->payload, 'code', 'local_wake_failure'),
            )
            : null;
        $playbackRecovery = $data['stage'] === 'playback' && $recordedTurn instanceof VoiceTurn
            ? $this->realtimeEvents->handleClientPlaybackFailure($recordedTurn)
            : null;

        return response()->json(['data' => [
            'recorded' => true,
            'duplicate' => ! $event->wasRecentlyCreated,
            'failure_id' => $event->client_event_id,
            'turn_failure_recovery' => $turnFailureRecovery,
            'playback_recovery' => $playbackRecovery,
        ]]);
    }

    private function realtimeProviderFailureCode(Throwable $error): string
    {
        $message = strtolower($error->getMessage());

        if ($error instanceof ConnectionException) {
            return match (true) {
                str_contains($message, 'curl error 28'), str_contains($message, 'timed out') => 'provider_connection_timeout',
                str_contains($message, 'curl error 6'), str_contains($message, 'could not resolve host') => 'provider_dns_failure',
                preg_match('/curl error (35|51|58|60)\b/', $message) === 1 => 'provider_tls_failure',
                default => 'provider_connection_failure',
            };
        }

        return match (true) {
            str_contains($message, 'did not include an sdp answer') => 'provider_sdp_answer_missing',
            str_contains($message, 'did not return the realtime call identifier') => 'provider_call_id_missing',
            str_contains($message, 'provider rejected the realtime call'),
            str_contains($message, 'realtime call request failed with status') => 'provider_call_rejected',
            default => 'provider_session_failure',
        };
    }
}
