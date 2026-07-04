<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiUsageLog;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Services\AdminSettingsService;
use App\Services\AgentProfileService;
use App\Services\AiUsageService;
use App\Services\AssistantRunService;
use App\Services\DashboardContextSnapshotService;
use App\Services\HermesRuntimeService;
use App\Services\RealtimeVoiceQualityService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class RealtimeSessionController extends Controller
{
    private const REALTIME_VOICES = ['alloy', 'ash', 'ballad', 'coral', 'echo', 'sage', 'shimmer', 'verse', 'marin', 'cedar'];

    private const LEGACY_VOICE_MAP = [
        'nova' => 'shimmer',
        'onyx' => 'ash',
        'fable' => 'ballad',
    ];

    public function __construct(
        private readonly HermesRuntimeService $runtime,
        private readonly AssistantRunService $runs,
        private readonly WorkspaceService $workspaces,
        private readonly AgentProfileService $profiles,
        private readonly AiUsageService $usageService,
        private readonly RealtimeVoiceQualityService $voiceQuality,
        private readonly AdminSettingsService $adminSettings,
        private readonly DashboardContextSnapshotService $dashboardContext,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'runtime_mode' => ['nullable', 'string', 'max:50'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'metadata' => ['nullable', 'array'],
            'voice' => ['nullable', 'string', Rule::in(self::REALTIME_VOICES)],
        ]);

        $user = $request->user();
        $localSession = null;
        if (! empty($data['session_id'])) {
            $localSession = ConversationSession::where('user_id', $user->id)->findOrFail($data['session_id']);
        }

        $workspace = $localSession?->workspace ?: $this->workspaces->resolveWorkspace($user, $data['workspace_id'] ?? null);
        $profile = $this->profiles->ensureForWorkspace($workspace, $user);
        $voicePreflight = $this->usageService->preflightDirect($user, $workspace->id, $this->adminSettings->realtimeModel(), 0, 0, 0.0, 'voice_session');
        if (! $voicePreflight['allowed']) {
            return response()->json([
                'message' => $voicePreflight['reason'],
                'code' => 'bean_voice_paused',
            ], 429);
        }
        if ($localSession) {
            $localSession->update([
                'metadata' => [
                    ...($localSession->metadata ?? []),
                    ...($data['metadata'] ?? []),
                    'realtime' => true,
                ],
                'last_activity_at' => now(),
            ]);
            $localSession = $localSession->refresh();
        } else {
            $localSession = $this->runtime->startSession([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'title' => $data['title'] ?? 'Realtime chat',
                'runtime_mode' => $data['runtime_mode'] ?? 'realtime',
                'metadata' => [
                    ...($data['metadata'] ?? []),
                    'realtime' => true,
                ],
            ]);
        }

        $apiKey = (string) config('services.hermes_realtime.api_key', '');
        if ($apiKey === '') {
            return response()->json([
                'message' => 'Realtime is not configured because the OpenAI API key is missing.',
                'code' => 'openai_realtime_not_configured',
            ], 409);
        }

        $requestedVoice = (string) ($data['voice'] ?? data_get($profile->settings ?? [], 'tts.openai_voice', config('services.hermes_realtime.voice', 'marin')));
        $voice = $this->realtimeVoice($requestedVoice);
        $payload = [
            'session' => $this->realtimeSessionConfig($localSession, $user->id, $workspace->id, $voice),
        ];

        $response = Http::withToken($apiKey)
            ->withHeaders($this->openAiServerHeaders($user->id))
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/realtime/client_secrets', $payload);

        if (! $response->successful()) {
            $upstreamStatus = $response->status();
            $retryable = $upstreamStatus === 429 || $upstreamStatus >= 500;
            $upstreamMessage = (string) ($response->json('error.message') ?: 'OpenAI rejected the realtime session request.');
            Log::warning('OpenAI realtime client secret creation failed.', [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $localSession->id,
                'api_base' => rtrim((string) config('services.hermes_runtime.api_base'), '/'),
                'model' => $payload['session']['model'],
                'voice' => $voice,
                'requested_voice' => $requestedVoice,
                'key_source' => 'OPENAI_PUBLIC_KEY',
                'status' => $response->status(),
                'body' => str($response->body())->limit(500)->toString(),
            ]);

            return response()->json([
                'message' => 'Bean voice is reconnecting. Type the request and Bean will handle it in chat.',
                'code' => 'openai_realtime_session_failed',
                'status' => $upstreamStatus,
                'upstream_message' => $upstreamMessage,
                'retryable' => $retryable,
            ], 502);
        }

        return response()->json(['data' => [
            'session' => $localSession->refresh(),
            'client_secret' => $response->json('value') ? $response->json() : $response->json('client_secret'),
            'openai' => [
                'model' => $payload['session']['model'],
                'voice' => $voice,
                'requested_voice' => $requestedVoice,
            ],
            'tools' => collect($this->realtimeTools())->map(fn (array $tool): ?string => data_get($tool, 'name'))->filter()->values()->all(),
        ]], 201);
    }

    public function call(Request $request): mixed
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'sdp' => ['required', 'string', 'max:50000'],
            'voice' => ['nullable', 'string', Rule::in(self::REALTIME_VOICES)],
            'metadata' => ['nullable', 'array'],
        ]);

        $user = $request->user();
        $localSession = ConversationSession::where('user_id', $user->id)->findOrFail($data['session_id']);
        $localSession->update([
            'metadata' => [
                ...($localSession->metadata ?? []),
                ...($data['metadata'] ?? []),
                'realtime' => true,
            ],
            'last_activity_at' => now(),
        ]);
        $localSession = $localSession->refresh();
        $workspace = $localSession->workspace ?: $this->workspaces->resolveWorkspace($user, $localSession->workspace_id);
        $profile = $this->profiles->ensureForWorkspace($workspace, $user);
        $voicePreflight = $this->usageService->preflightDirect($user, $workspace->id, $this->adminSettings->realtimeModel(), 0, 0, 0.0, 'voice_session');
        if (! $voicePreflight['allowed']) {
            return response()->json([
                'message' => $voicePreflight['reason'],
                'code' => 'bean_voice_paused',
            ], 429);
        }
        $apiKey = (string) config('services.hermes_realtime.api_key', '');

        if ($apiKey === '') {
            return response()->json([
                'message' => 'Realtime is not configured because the OpenAI API key is missing.',
                'code' => 'openai_realtime_not_configured',
            ], 409);
        }

        $requestedVoice = (string) ($data['voice'] ?? data_get($profile->settings ?? [], 'tts.openai_voice', config('services.hermes_realtime.voice', 'marin')));
        $voice = $this->realtimeVoice($requestedVoice);
        $sessionConfig = $this->realtimeSessionConfig($localSession, $user->id, $workspace->id, $voice);
        $sdp = $this->normalizeSdp((string) $data['sdp']);
        $sessionJson = json_encode($sessionConfig, JSON_UNESCAPED_SLASHES);

        $response = Http::withToken($apiKey)
            ->withHeaders($this->openAiServerHeaders($user->id))
            ->asMultipart()
            ->timeout(20)
            ->post(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/realtime/calls', [
                [
                    'name' => 'sdp',
                    'contents' => $sdp,
                    'headers' => ['Content-Type' => 'application/sdp'],
                ],
                [
                    'name' => 'session',
                    'contents' => (string) $sessionJson,
                    'headers' => ['Content-Type' => 'application/json'],
                ],
            ]);

        if (! $response->successful()) {
            $upstreamStatus = $response->status();
            $retryable = $upstreamStatus === 429 || $upstreamStatus >= 500;
            $upstreamMessage = (string) ($response->json('error.message') ?: 'OpenAI rejected the realtime call request.');

            Log::warning('OpenAI realtime call creation failed.', [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $localSession->id,
                'api_base' => rtrim((string) config('services.hermes_runtime.api_base'), '/'),
                'model' => $sessionConfig['model'],
                'voice' => $voice,
                'requested_voice' => $requestedVoice,
                'key_source' => 'OPENAI_PUBLIC_KEY',
                'sdp_length' => strlen($sdp),
                'session_length' => strlen((string) $sessionJson),
                'session_type' => $sessionConfig['type'] ?? null,
                'status' => $upstreamStatus,
                'body' => str($response->body())->limit(500)->toString(),
            ]);

            return response()->json([
                'message' => 'Bean voice is reconnecting. Type the request and Bean will handle it in chat.',
                'code' => 'openai_realtime_call_failed',
                'status' => $upstreamStatus,
                'upstream_message' => $upstreamMessage,
                'retryable' => $retryable,
            ], 502);
        }

        return response($response->body(), 200, [
            'Content-Type' => $response->header('Content-Type') ?: 'application/sdp',
            'Cache-Control' => 'no-store',
        ]);
    }

    public function dashboardContext(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
        ]);

        $user = $request->user();
        $session = ! empty($data['session_id'])
            ? ConversationSession::where('user_id', $user->id)->findOrFail($data['session_id'])
            : null;
        $workspace = $session?->workspace ?: $this->workspaces->resolveWorkspace($user, $data['workspace_id'] ?? $session?->workspace_id);
        $clientContext = is_array(data_get($session?->metadata ?? [], 'client_context'))
            ? data_get($session?->metadata ?? [], 'client_context')
            : null;
        $snapshot = $this->dashboardContext->snapshot($user, $workspace, $clientContext);
        $promptText = $this->dashboardContext->promptTextFromSnapshot($snapshot);

        return response()->json(['data' => [
            'snapshot' => $snapshot,
            'prompt_text' => $promptText,
            'instructions' => $session ? $this->realtimeInstructions($session, $promptText) : null,
        ]]);
    }

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'role' => ['required', 'string', Rule::in(['user', 'assistant'])],
            'content' => ['required', 'string', 'max:20000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $session = ConversationSession::where('user_id', $request->user()->id)->findOrFail($data['session_id']);

        $message = ConversationMessage::create([
            'user_id' => $request->user()->id,
            'conversation_session_id' => $session->id,
            'role' => $data['role'],
            'content' => trim((string) $data['content']),
            'metadata' => [
                ...($data['metadata'] ?? []),
                'runtime' => 'realtime',
            ],
        ]);

        $session->update([
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        return response()->json(['data' => $message], 201);
    }

    public function toolCall(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'tool_name' => ['required', 'string'],
            'call_id' => ['nullable', 'string', 'max:255'],
            'arguments' => ['nullable', 'array'],
        ]);

        $session = ConversationSession::where('user_id', $request->user()->id)->findOrFail($data['session_id']);
        $arguments = $data['arguments'] ?? [];

        if ($data['tool_name'] === 'queue_bean_work') {
            $content = trim((string) ($arguments['content'] ?? $arguments['user_request'] ?? $arguments['transcript'] ?? ''));
            if ($content === '') {
                return response()->json(['data' => [
                    'ok' => false,
                    'message' => 'No work content was provided.',
                ]], 422);
            }
            $queued = $this->runs->queueRun($session, $content, [
                'source' => 'realtime',
                'call_id' => $data['call_id'] ?? null,
                'client_context' => is_array($arguments['client_context'] ?? null) ? $arguments['client_context'] : null,
            ], 'realtime');

            return response()->json(['data' => [
                'ok' => true,
                'run_id' => $queued['run']->id,
                'message' => 'Bean is working on that in the background.',
            ]], 202);
        }

        if ($data['tool_name'] === 'cancel_bean_work') {
            $runId = (int) ($arguments['run_id'] ?? 0);
            $run = AssistantRun::where('user_id', $request->user()->id)->find($runId);
            if (! $run) {
                return response()->json(['data' => [
                    'ok' => false,
                    'message' => 'That run was not found.',
                ]], 404);
            }

            $cancelled = $this->runs->cancelRun($run);

            return response()->json(['data' => [
                'ok' => true,
                'run_id' => $cancelled->id,
                'status' => $cancelled->status,
            ]], 202);
        }

        return response()->json(['data' => [
            'ok' => false,
            'message' => 'Unsupported realtime tool.',
        ]], 422);
    }

    public function clientEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:100'],
            'session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'phase' => ['nullable', 'string', 'max:100'],
            'message' => ['nullable', 'string', 'max:500'],
            'details' => ['nullable', 'array'],
        ]);

        $session = null;
        if (! empty($data['session_id'])) {
            $session = ConversationSession::where('user_id', $request->user()->id)->find($data['session_id']);
        }

        Log::warning('Bean realtime voice client event.', [
            'event_type' => $data['event_type'],
            'user_id' => $request->user()->id,
            'conversation_session_id' => $session?->id,
            'workspace_id' => $session?->workspace_id,
            'phase' => $data['phase'] ?? null,
            'message' => $data['message'] ?? null,
            'details' => $data['details'] ?? [],
        ]);

        $this->usageService->recordDirectCall(
            $request->user(),
            $session?->workspace_id,
            'realtime_voice_event',
            $this->adminSettings->realtimeModel(),
            [],
            [
                'conversation_session_id' => $session?->id,
                'event_type' => $data['event_type'],
                'phase' => $data['phase'] ?? null,
                'message' => $data['message'] ?? null,
                'details' => $data['details'] ?? [],
            ],
            ['realtime_voice_event', $data['event_type']],
        );

        return response()->json(['data' => ['ok' => true]]);
    }

    public function quality(Request $request): JsonResponse
    {
        $data = $request->validate([
            'days' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'workspace_id' => ['sometimes', 'integer', 'exists:workspaces,id'],
            'session_id' => ['sometimes', 'integer', 'exists:conversation_sessions,id'],
        ]);

        $user = $request->user();
        $days = (int) ($data['days'] ?? 7);
        $since = now()->subDays($days);

        $sessionId = null;
        if (! empty($data['session_id'])) {
            $sessionId = ConversationSession::where('user_id', $user->id)->findOrFail($data['session_id'])->id;
        }

        $turnQuery = AiUsageLog::query()
            ->where('user_id', $user->id)
            ->where('request_type', 'realtime_voice')
            ->where('created_at', '>=', $since);
        $eventQuery = AiUsageLog::query()
            ->where('user_id', $user->id)
            ->where('request_type', 'realtime_voice_event')
            ->where('created_at', '>=', $since);

        if (! empty($data['workspace_id'])) {
            $turnQuery->where('workspace_id', (int) $data['workspace_id']);
            $eventQuery->where('workspace_id', (int) $data['workspace_id']);
        }
        if ($sessionId !== null) {
            $turnQuery->where('conversation_session_id', $sessionId);
            $eventQuery->where('conversation_session_id', $sessionId);
        }

        $turns = $turnQuery
            ->latest('created_at')
            ->limit(500)
            ->get(['id', 'conversation_session_id', 'model', 'tool_call_count', 'metadata', 'created_at']);
        $events = $eventQuery
            ->latest('created_at')
            ->limit(500)
            ->get(['id', 'conversation_session_id', 'metadata', 'created_at']);

        return response()->json(['data' => $this->voiceQuality->benchmarkSummary(
            $turns,
            $events,
            $days,
            $since->toIso8601String(),
        )]);
    }

    public function usage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'model' => ['nullable', 'string', 'max:120'],
            'response_id' => ['nullable', 'string', 'max:255'],
            'usage' => ['present', 'array'],
            'usage.input_tokens' => ['sometimes', 'integer', 'min:0'],
            'usage.output_tokens' => ['sometimes', 'integer', 'min:0'],
            'usage.input_token_details' => ['sometimes', 'array'],
            'usage.output_token_details' => ['sometimes', 'array'],
            'usage.input_token_details.audio_tokens' => ['sometimes', 'integer', 'min:0'],
            'usage.output_token_details.audio_tokens' => ['sometimes', 'integer', 'min:0'],
            'voice_seconds' => ['sometimes', 'numeric', 'min:0', 'max:300'],
            'transcript_to_response_create_ms' => ['sometimes', 'integer', 'min:0', 'max:300000'],
            'response_create_to_first_assistant_ms' => ['sometimes', 'integer', 'min:0', 'max:300000'],
            'transcript_to_first_assistant_ms' => ['sometimes', 'integer', 'min:0', 'max:300000'],
            'turn_completed_ms' => ['sometimes', 'integer', 'min:0', 'max:300000'],
            'spoken_character_count' => ['sometimes', 'integer', 'min:0', 'max:20000'],
            'spoken_sentence_count' => ['sometimes', 'integer', 'min:0', 'max:200'],
            'spoken_brevity_violation' => ['sometimes', 'boolean'],
            'is_follow_up_turn' => ['sometimes', 'boolean'],
            'is_contextual_follow_up_turn' => ['sometimes', 'boolean'],
            'contextual_follow_up_kind' => ['sometimes', 'nullable', 'string', Rule::in(['confirmation', 'decline', 'correction', 'continuation', 'reference'])],
            'realtime_usage_missing' => ['sometimes', 'boolean'],
            'tool_call_count' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'action_types' => ['sometimes', 'array', 'max:20'],
            'action_types.*' => ['string', 'max:100'],
        ]);

        $user = $request->user();
        $session = ConversationSession::where('user_id', $user->id)->findOrFail($data['session_id']);
        $workspaceId = $session->workspace_id;
        $usage = $this->usageService->usageFromOpenAiResponse([
            'usage' => $data['usage'] ?? [],
        ]);
        $voiceSeconds = (float) ($data['voice_seconds'] ?? 0);
        $model = (string) ($data['model'] ?? $this->adminSettings->realtimeModel());
        $preflight = $this->usageService->preflightDirect(
            $user,
            $workspaceId,
            $model,
            $usage['input_tokens'],
            $usage['output_tokens'],
            $this->usageService->estimatedCostWithAudio($model, $usage['input_tokens'], $usage['output_tokens'], $usage['audio_input_tokens'], $usage['audio_output_tokens']),
            'realtime_voice',
            ['session' => $session, 'voice_seconds' => $voiceSeconds],
        );
        $status = $preflight['allowed'] ? 'completed' : 'blocked';
        $log = $this->usageService->recordDirectCall($user, $workspaceId, 'realtime_voice', $model, [
            ...$usage,
            'tool_call_count' => (int) ($data['tool_call_count'] ?? 0),
        ], [
            'conversation_session_id' => $session->id,
            'response_id' => $data['response_id'] ?? null,
            'voice_seconds' => $voiceSeconds,
            'transcript_to_response_create_ms' => $data['transcript_to_response_create_ms'] ?? null,
            'response_create_to_first_assistant_ms' => $data['response_create_to_first_assistant_ms'] ?? null,
            'transcript_to_first_assistant_ms' => $data['transcript_to_first_assistant_ms'] ?? null,
            'turn_completed_ms' => $data['turn_completed_ms'] ?? null,
            'spoken_character_count' => $data['spoken_character_count'] ?? null,
            'spoken_sentence_count' => $data['spoken_sentence_count'] ?? null,
            'spoken_brevity_violation' => $data['spoken_brevity_violation'] ?? null,
            'is_follow_up_turn' => $data['is_follow_up_turn'] ?? null,
            'is_contextual_follow_up_turn' => $data['is_contextual_follow_up_turn'] ?? null,
            'contextual_follow_up_kind' => $data['contextual_follow_up_kind'] ?? null,
            'realtime_usage_missing' => $data['realtime_usage_missing'] ?? null,
            'limit_reason' => $preflight['allowed'] ? null : $preflight['reason'],
        ], array_values($data['action_types'] ?? ['realtime_voice']), $status);

        return response()->json(['data' => [
            'ok' => $preflight['allowed'],
            'usage_log_id' => $log->id,
            'message' => $preflight['reason'],
        ]], $preflight['allowed'] ? 201 : 429);
    }

    private function realtimeInstructions(ConversationSession $session, ?string $dashboardContext = null): string
    {
        $clientContext = json_encode(data_get($session->metadata ?? [], 'client_context', []), JSON_UNESCAPED_SLASHES);
        $user = User::findOrFail($session->user_id);
        $workspace = $session->workspace ?: $this->workspaces->resolveWorkspace($user, $session->workspace_id);
        $dashboardContext ??= $this->dashboardContext->promptText(
            $user,
            $workspace,
            is_array(data_get($session->metadata ?? [], 'client_context'))
                ? data_get($session->metadata ?? [], 'client_context')
                : null,
        );
        $recentConversationContext = $this->realtimeRecentConversationContext($session);

        return <<<PROMPT
You are Bean, the realtime voice interface for HeyBean.

Speak naturally and briefly. Use realtime conversation for clarification, acknowledgement, and fast answers.
Voice brevity contract: spoken answers should usually be one sentence and at most two short sentences. Do not read long lists, tables, ids, raw JSON, or multi-step explanations aloud. For details-heavy answers, give the shortest useful summary and say the details are in chat; queue background work if chat content needs to be generated.
Never mention tools, models, system messages, connections, or voice mechanics to the user.
Never describe yourself as an AI, language model, bot, virtual assistant, or digital assistant. Speak as Bean.
Never say you cannot access the user's calendar, tasks, reminders, notes, workspace, or HeyBean data. If the snapshot lacks the answer, queue background work or ask one concise clarification.
Only respond when the user is clearly talking to Bean, usually by saying "Hey Bean" or continuing an active Bean conversation. If speech is not addressed to Bean, stay silent.
Treat follow-up questions like "when is that", "where is it", "what time is that", "how long is it", "who's going", and "who else" as part of the same active conversation. Resolve pronouns like "that", "it", "there", and "tomorrow" from the latest user turn and current context when it is safe to do so.
Treat list and choice references like "second one", "last option", "top one", "bottom one", "choose the second one", "pick option B", "the one after", "both", "all of them", and "the first two" as follow-ups to the immediately prior Bean options. If the target is ambiguous, ask one concise clarification instead of guessing.
Treat short date, time, time-window, deadline, duration, time-shift, anchor-time, alert-offset, priority, task-status, recurrence, attendee, and location fragments like "Tuesday at three", "Friday morning", "tomorrow morning", "tomorrow at three", "tonight at seven", "day after tomorrow", "this afternoon", "next weekend", "later today", "after lunch", "during lunch", "lunchtime", "after work", "before school", "on Friday", "at 2 PM", "from 2 to 3", "between 1 and 2", "until 5", "by Friday", "by end of day", "before lunch", "the 15th", "on June 12", "in 20 minutes", "for half an hour", "an hour earlier", "30 minutes later", "a little later", "10 minutes before", "at the start", "no alert", "urgent", "high priority", "low priority", "done", "completed", "still open", "not done yet", "every Friday", "weekdays", "just once", "no repeat", "with Sam", "at the office", and "on Zoom" as follow-up answers to the immediately prior scheduling, reminder, task, or planning question. Use the recent context to resolve the target; if the target is unclear, ask one concise clarification.
Treat short confirmations like "yes", "yes please", "go ahead", "please do", "for sure", "absolutely", "exactly", "correct", "that's right", "you got it", and "that sounds good" as instructions to continue the immediately proposed action only when the prior Bean turn clearly proposed one. If the confirmation is ambiguous, ask one concise clarification instead of guessing.
Treat short declines like "no", "no thanks", "not now", "not right now", "maybe later", "neither", "none of them", and "let's not" as declining the immediately proposed action or offered options. Acknowledge briefly and do not queue work. Only cancel already-running work when the user clearly says "cancel", "never mind", "forget it", or equivalent.
Treat corrections like "wrong one", "that's wrong", "not that one", "no, the other one", "no, Tuesday", "no, at three", "no, make it tomorrow", "try the other one", and "I meant Tuesday at three" as updates to the active request or proposal. Treat undo or reversal follow-ups like "undo that", "take it back", "revert it", and "reverse that" as concrete app-data change requests about the latest safe action; queue work with the exact latest user request unless the target is unclear, then ask one concise clarification.
Treat continuation, elaboration, repeat, and answer-shaping requests like "keep going", "go on", "tell me more", "more details", "shorter", "quick version", "simpler", "what were you saying", "I missed that", "come again", and "say that again" as requests about the immediately prior Bean response. Continue, rephrase, simplify, elaborate, or repeat briefly from recent conversation context; do not call tools unless the user adds a new concrete app-data request.
If the user interrupts while you are speaking, including phrases like "stop talking", "pause for now", "hold that thought", "wait a second", "give me a moment", or "let me stop you", stop the prior answer immediately and respond to the newest user request. Do not resume the interrupted answer unless the user asks you to continue.
For simple conversational inputs, greetings, mic checks, current time/date questions, or questions like "can you hear me?", answer immediately in one short sentence. Do not call tools for those.
If the user asks whether you can hear them, say "Yes, I can hear you." Never say "I can read you" during a voice conversation.
If the user asks what time it is, answer from the client temporal context below. Do not call tools for current time/date questions.
Use the dashboard context snapshot below to answer simple read-only questions about the user's accessible workspaces, calendar, tasks, and reminders immediately. Do not call queue_bean_work if the answer is clearly present in the snapshot.
If dashboard context includes weather_current.ok=true and the user asks current weather without naming a location, answer from weather_current immediately as the default location. Also answer immediately when they name the same place as weather_current.location. Do not call queue_bean_work for warmed current weather unless the user asks for a different location, a broader forecast, or more detail than the snapshot contains.
Call queue_bean_work when the answer is not in the snapshot, the user asks to use live external data, asks about notes, memory, preferences, or prior requests that are not explicitly present in the current context, or the user asks to create, update, delete, undo, revert, plan, remember, schedule, or otherwise change app data. If the user is only asking whether Bean can create, update, delete, undo, revert, schedule, remember, or otherwise do something, answer the capability question directly and do not call queue_bean_work unless they include concrete details that make it an actual request. Trash, garbage, recycling, and household pickup questions may be answered from the snapshot if present; otherwise queue background work because they may be stored as tasks or reminders.
Accuracy contract: do not guess current app state, live external data, ids, times, dates, or completed changes. Answer from the dashboard snapshot only when the answer is explicitly present; otherwise ask one concise clarification or queue background work.
Never infer absence from silence: if the snapshot does not explicitly say there are no events, tasks, reminders, or conflicts, do not say the user's calendar, schedule, or agenda is clear, that the user is free, or that they have nothing.
Conversation contract: if you can fully answer from the dashboard snapshot or ordinary conversation, treat the turn as complete, answer once, and do not call queue_bean_work. If fuller detail is useful after a short spoken summary, give the summary without calling queue_bean_work unless real app work is needed. If real background work is needed, speak only a short acknowledgement, then call queue_bean_work.
When queue_bean_work is needed, first acknowledge naturally in one short sentence, then call the tool with content set to the exact latest user request. Do not summarize or alter the request. Do not claim the task is complete until the app sends completion context later.
After calling queue_bean_work, do not add another filler response. Stay quiet until completion context or progress context arrives. When completion context arrives, answer with the completed result.
If the user asks for live external data that depends on current information outside HeyBean, call queue_bean_work after acknowledging so the main Bean agent can explain the current browsing limitation. Do not leave the user with only an acknowledgement.
If a user message is JSON with realtime_progress_update=true, it is an internal progress prompt. Speak one brief natural update, do not call tools, and do not repeat anything in already_spoken.
If a user message is JSON with realtime_background_complete=true, it is an internal completion prompt. Continue naturally with the result, do not call tools, and do not repeat or paraphrase anything in already_spoken. If the result is long, give a concise spoken summary and say the full details are in chat.
If a user message is JSON with realtime_fresh_context_unavailable=true, fresh app-state context was required but could not be confirmed quickly. Speak one brief acknowledgement, then call queue_bean_work with content set exactly to user_request. Do not summarize or alter user_request. Do not answer calendar, task, reminder, schedule, agenda, availability, notes, memory, preference, or prior-request questions from the stale snapshot for that turn.
Laravel owns workspace access, approvals, validation, calendar/task/reminder writes, durable memory, and usage guardrails. Never invent ids or app-state changes.

Local session id: {$session->id}
Workspace id: {$session->workspace_id}
Client temporal context: {$clientContext}
{$recentConversationContext}
{$dashboardContext}
PROMPT;
    }

    private function realtimeRecentConversationContext(ConversationSession $session): string
    {
        $turns = $session->messages()
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit(10)
            ->get()
            ->sortBy('id')
            ->map(function (ConversationMessage $message): ?string {
                if (! $this->includeRealtimeRecentMessage($message)) {
                    return null;
                }

                $role = $message->role === 'assistant' ? 'Bean' : 'User';
                $content = str((string) $message->content)->squish()->limit(700, '')->toString();

                return $content === '' ? null : "{$role}: {$content}";
            })
            ->filter()
            ->values();

        if ($turns->isEmpty()) {
            return 'Recent conversation turns: none.';
        }

        return "Recent conversation turns from this Bean session, newest last. Use this only to resolve immediate follow-ups, corrections, and pronouns; current dashboard context and tool results override stale app-state claims:\n"
            .$turns->implode("\n");
    }

    private function includeRealtimeRecentMessage(ConversationMessage $message): bool
    {
        $content = str((string) $message->content)->squish()->toString();
        if ($content === '') {
            return false;
        }

        $runtime = (string) data_get($message->metadata ?? [], 'runtime', '');

        return ! in_array($runtime, [
            'missing_run_bridge',
            'direct_queue_bridge',
            'async_queue_bridge',
            'failed_run_bridge',
        ], true);
    }

    private function realtimeVoice(string $voice): string
    {
        $normalized = strtolower(trim($voice));
        $mapped = self::LEGACY_VOICE_MAP[$normalized] ?? $normalized;

        return in_array($mapped, self::REALTIME_VOICES, true)
            ? $mapped
            : (string) config('services.hermes_realtime.voice', 'marin');
    }

    private function realtimeSessionConfig(ConversationSession $session, int $userId, int $workspaceId, string $voice): array
    {
        return [
            'type' => 'realtime',
            'model' => $this->adminSettings->realtimeModel(),
            'instructions' => $this->realtimeInstructions($session),
            'audio' => [
                'input' => [
                    'noise_reduction' => [
                        'type' => 'near_field',
                    ],
                    'transcription' => [
                        'model' => 'gpt-4o-mini-transcribe',
                        'language' => 'en',
                        'prompt' => $this->realtimeTranscriptionPrompt(),
                    ],
                    'turn_detection' => [
                        'type' => 'semantic_vad',
                        'eagerness' => 'high',
                        'create_response' => false,
                        'interrupt_response' => true,
                    ],
                ],
                'output' => [
                    'voice' => $voice,
                ],
            ],
            'tools' => $this->realtimeTools(),
            'tool_choice' => 'auto',
            'tracing' => [
                'workflow_name' => 'heybean-realtime',
                'group_id' => 'conversation_session_'.$session->id,
                'metadata' => [
                    'user_id' => (string) $userId,
                    'workspace_id' => (string) $workspaceId,
                ],
            ],
        ];
    }

    private function realtimeTranscriptionPrompt(): string
    {
        return 'The assistant is named Bean and the app is HeyBean. Prefer the wake phrase "Hey Bean" when the user addresses the assistant; if audio sounds like "Hey Ben", "Hay Bean", "Hay Beans", "Hey Beam", "Hey Beem", "Hey Being", "Hey Dean", "HeyBean", or "Hi Bean", transcribe it as "Hey Bean" when it is clearly an assistant wake phrase. Common HeyBean terms include calendar, event, task, to-do, reminder, note, workspace, approval, blocker, Google Calendar, Outlook, trash, garbage, recycling, weather, plan my day, and what is next.';
    }

    private function openAiServerHeaders(int $userId): array
    {
        return [
            'OpenAI-Safety-Identifier' => hash('sha256', 'heybean-user-'.$userId),
        ];
    }

    private function normalizeSdp(string $sdp): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $sdp);
        $normalized = rtrim($normalized, "\n");

        return str_replace("\n", "\r\n", $normalized)."\r\n";
    }

    private function realtimeTools(): array
    {
        return [
            [
                'type' => 'function',
                'name' => 'queue_bean_work',
                'description' => 'Queue a HeyBean background agent run when the user asks for app data not present in the dashboard context snapshot, live external data, app-data changes, memory, notes, preferences, prior requests, or durable work such as creating tasks/reminders/events, updating or reversing calendar/task/reminder data, remembering preferences, or planning a schedule. The realtime dashboard snapshot covers accessible workspaces only through the next 7 days; use this tool for broader lookups. Trash, garbage, recycling, and household pickup questions may be answered from the snapshot when present; otherwise use this tool because they may be stored as tasks or reminders. Do not use for greetings, mic checks, current time/date questions, quick factual answers, conversational acknowledgements, or dashboard questions already answered by the snapshot.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'The exact latest user request to execute. Preserve user-provided names, dates, times, locations, quantities, and constraints; do not summarize, reinterpret, or omit details.'],
                        'client_context' => ['type' => 'object', 'additionalProperties' => true],
                    ],
                    'required' => ['content'],
                    'additionalProperties' => true,
                ],
            ],
            [
                'type' => 'function',
                'name' => 'cancel_bean_work',
                'description' => 'Cancel a queued or running HeyBean background run.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'run_id' => ['type' => 'integer'],
                    ],
                    'required' => ['run_id'],
                    'additionalProperties' => false,
                ],
            ],
        ];
    }
}
