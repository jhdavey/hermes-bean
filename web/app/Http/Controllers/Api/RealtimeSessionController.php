<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Services\AiUsageService;
use App\Services\AdminSettingsService;
use App\Services\AgentProfileService;
use App\Services\AssistantRunService;
use App\Services\DashboardContextSnapshotService;
use App\Services\HermesRuntimeService;
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
                'message' => 'Realtime voice could not be started.',
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
                'message' => 'Realtime voice could not connect.',
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

        return response()->json(['data' => [
            'snapshot' => $this->dashboardContext->snapshot($user, $workspace, $clientContext),
            'prompt_text' => $this->dashboardContext->promptText($user, $workspace, $clientContext),
            'instructions' => $session ? $this->realtimeInstructions($session) : null,
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
            $content = trim((string) ($arguments['content'] ?? $arguments['transcript'] ?? ''));
            if ($content === '') {
                return response()->json(['data' => [
                    'ok' => false,
                    'message' => 'No work content was provided.',
                ]], 422);
            }
            $preflight = $this->usageService->preflightDirect(
                $request->user(),
                $session->workspace_id,
                $this->adminSettings->mainModel(),
                $this->usageService->estimateTokens($content),
                (int) config('services.ai_usage.reserve_output_tokens', 1200),
                null,
                'voice_background',
                ['session' => $session],
            );
            if (! $preflight['allowed']) {
                return response()->json([
                    'message' => $preflight['reason'],
                    'code' => 'bean_usage_limit',
                    'data' => [
                        'ok' => false,
                        'message' => $preflight['reason'],
                    ],
                ], 429);
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

        return response()->json(['data' => ['ok' => true]]);
    }

    public function usage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'model' => ['nullable', 'string', 'max:120'],
            'response_id' => ['nullable', 'string', 'max:255'],
            'usage' => ['required', 'array'],
            'usage.input_tokens' => ['sometimes', 'integer', 'min:0'],
            'usage.output_tokens' => ['sometimes', 'integer', 'min:0'],
            'usage.input_token_details' => ['sometimes', 'array'],
            'usage.output_token_details' => ['sometimes', 'array'],
            'usage.input_token_details.audio_tokens' => ['sometimes', 'integer', 'min:0'],
            'usage.output_token_details.audio_tokens' => ['sometimes', 'integer', 'min:0'],
            'voice_seconds' => ['sometimes', 'numeric', 'min:0', 'max:300'],
            'tool_call_count' => ['sometimes', 'integer', 'min:0', 'max:50'],
            'action_types' => ['sometimes', 'array', 'max:20'],
            'action_types.*' => ['string', 'max:100'],
        ]);

        $user = $request->user();
        $session = ConversationSession::where('user_id', $user->id)->findOrFail($data['session_id']);
        $workspaceId = $session->workspace_id;
        $usage = $this->usageService->usageFromOpenAiResponse([
            'usage' => $data['usage'],
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
            'limit_reason' => $preflight['allowed'] ? null : $preflight['reason'],
        ], array_values($data['action_types'] ?? ['realtime_voice']), $status);

        return response()->json(['data' => [
            'ok' => $preflight['allowed'],
            'usage_log_id' => $log->id,
            'message' => $preflight['reason'],
        ]], $preflight['allowed'] ? 201 : 429);
    }

    private function realtimeInstructions(ConversationSession $session): string
    {
        $clientContext = json_encode(data_get($session->metadata ?? [], 'client_context', []), JSON_UNESCAPED_SLASHES);
        $user = User::findOrFail($session->user_id);
        $workspace = $session->workspace ?: $this->workspaces->resolveWorkspace($user, $session->workspace_id);
        $dashboardClientContext = is_array(data_get($session->metadata ?? [], 'client_context'))
            ? data_get($session->metadata ?? [], 'client_context')
            : null;
        $dashboardContext = $this->dashboardContext->promptText($user, $workspace, $dashboardClientContext);

        return <<<PROMPT
You are Bean, the realtime voice interface for HeyBean.

Speak naturally and briefly. Use realtime conversation for clarification, acknowledgement, and fast answers.
Never mention tools, models, system messages, connections, or voice mechanics to the user.
Only respond when the user is clearly talking to Bean, usually by saying "Hey Bean" or continuing an active Bean conversation. If speech is not addressed to Bean, stay silent.
For simple conversational inputs, greetings, mic checks, current time/date questions, or questions like "can you hear me?", answer immediately in one short sentence. Do not call tools for those.
If the user asks whether you can hear them, say "Yes, I can hear you." Never say "I can read you" during a voice conversation.
If the user asks what time it is, answer from the client temporal context below. Do not call tools for current time/date questions.
Use the dashboard context snapshot below to answer simple read-only questions about the current dashboard, calendar, tasks, and reminders immediately. Do not call queue_bean_work if the answer is clearly present in the snapshot.
Call queue_bean_work when the answer is not in the snapshot, the user asks to use live external data, or the user asks to create, update, delete, plan, remember, schedule, or otherwise change app data. Trash, garbage, recycling, and household pickup questions may be answered from the snapshot if present; otherwise queue background work because they may be stored as tasks or reminders.
When queue_bean_work is needed, first acknowledge naturally in one short sentence, then call the tool. Do not claim the task is complete until the app sends completion context later.
After calling queue_bean_work, do not add another filler response. Stay quiet until completion context or progress context arrives. When completion context arrives, answer with the completed result.
If the user asks for live external data that depends on current information outside HeyBean, call queue_bean_work after acknowledging so the main Bean agent can explain the current browsing limitation. Do not leave the user with only an acknowledgement.
If a user message is JSON with realtime_progress_update=true, it is an internal progress prompt. Speak one brief natural update, do not call tools, and do not repeat anything in already_spoken.
If a user message is JSON with realtime_background_complete=true, it is an internal completion prompt. Continue naturally with the result, do not call tools, and do not repeat or paraphrase anything in already_spoken. If the result is long, give a concise spoken summary and say the full details are in chat.
Laravel owns workspace access, approvals, validation, calendar/task/reminder writes, durable memory, and usage guardrails. Never invent ids or app-state changes.

Local session id: {$session->id}
Workspace id: {$session->workspace_id}
Client temporal context: {$clientContext}
{$dashboardContext}
PROMPT;
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
                    ],
                    'turn_detection' => [
                        'type' => 'server_vad',
                        'threshold' => 0.45,
                        'prefix_padding_ms' => 250,
                        'silence_duration_ms' => 350,
                        'create_response' => false,
                        'interrupt_response' => false,
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
                'description' => 'Queue a HeyBean background agent run when the user asks for app data not present in the dashboard context snapshot, live external data, app-data changes, or durable work such as creating tasks/reminders/events, updating calendar data, remembering preferences, or planning a schedule. Trash, garbage, recycling, and household pickup questions may be answered from the snapshot when present; otherwise use this tool because they may be stored as tasks or reminders. Do not use for greetings, mic checks, current time/date questions, quick factual answers, conversational acknowledgements, or dashboard questions already answered by the snapshot.',
                'parameters' => [
                    'type' => 'object',
                    'properties' => [
                        'content' => ['type' => 'string', 'description' => 'The user request to execute.'],
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
