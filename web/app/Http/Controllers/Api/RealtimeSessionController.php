<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Services\AgentProfileService;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class RealtimeSessionController extends Controller
{
    public function __construct(
        private readonly HermesRuntimeService $runtime,
        private readonly AssistantRunService $runs,
        private readonly WorkspaceService $workspaces,
        private readonly AgentProfileService $profiles,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:255'],
            'runtime_mode' => ['nullable', 'string', 'max:50'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'session_id' => ['nullable', 'integer', 'exists:conversation_sessions,id'],
            'metadata' => ['nullable', 'array'],
            'voice' => ['nullable', 'string', Rule::in(['alloy', 'ash', 'ballad', 'coral', 'echo', 'fable', 'nova', 'onyx', 'sage', 'shimmer', 'verse', 'marin', 'cedar'])],
        ]);

        $user = $request->user();
        $localSession = null;
        if (! empty($data['session_id'])) {
            $localSession = ConversationSession::where('user_id', $user->id)->findOrFail($data['session_id']);
        }

        $workspace = $localSession?->workspace ?: $this->workspaces->resolveWorkspace($user, $data['workspace_id'] ?? null);
        $profile = $this->profiles->ensureForWorkspace($workspace, $user);
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

        $voice = (string) ($data['voice'] ?? data_get($profile->settings ?? [], 'tts.openai_voice', config('services.hermes_realtime.voice', 'marin')));
        $payload = [
            'session' => [
                'type' => 'realtime',
                'model' => (string) config('services.hermes_realtime.model', 'gpt-realtime'),
                'instructions' => $this->realtimeInstructions($localSession),
                'audio' => [
                    'input' => [
                        'noise_reduction' => [
                            'type' => 'near_field',
                        ],
                        'transcription' => [
                            'model' => 'gpt-4o-mini-transcribe',
                            'language' => 'en',
                            'prompt' => 'Hey Bean, Bean, HeyBean, can you hear me, calendar, tasks, reminders.',
                        ],
                        'turn_detection' => [
                            'type' => 'server_vad',
                            'threshold' => 0.45,
                            'prefix_padding_ms' => 250,
                            'silence_duration_ms' => 350,
                            'create_response' => true,
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
                    'group_id' => 'conversation_session:'.$localSession->id,
                    'metadata' => [
                        'user_id' => (string) $user->id,
                        'workspace_id' => (string) $workspace->id,
                    ],
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->acceptJson()
            ->asJson()
            ->timeout(15)
            ->post(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/realtime/client_secrets', $payload);

        if (! $response->successful()) {
            Log::warning('OpenAI realtime client secret creation failed.', [
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'conversation_session_id' => $localSession->id,
                'api_base' => rtrim((string) config('services.hermes_runtime.api_base'), '/'),
                'model' => $payload['session']['model'],
                'voice' => $voice,
                'key_source' => 'OPENAI_PUBLIC_KEY',
                'status' => $response->status(),
                'body' => str($response->body())->limit(500)->toString(),
            ]);

            return response()->json([
                'message' => 'Realtime voice could not be started.',
                'code' => 'openai_realtime_session_failed',
                'status' => $response->status(),
            ], 502);
        }

        return response()->json(['data' => [
            'session' => $localSession->refresh(),
            'client_secret' => $response->json('value') ? $response->json() : $response->json('client_secret'),
            'openai' => [
                'model' => $payload['session']['model'],
                'voice' => $voice,
            ],
            'tools' => collect($this->realtimeTools())->map(fn (array $tool): ?string => data_get($tool, 'name'))->filter()->values()->all(),
        ]], 201);
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

    private function realtimeInstructions(ConversationSession $session): string
    {
        $clientContext = json_encode(data_get($session->metadata ?? [], 'client_context', []), JSON_UNESCAPED_SLASHES);

        return <<<PROMPT
You are Bean, the realtime voice interface for HeyBean.

Speak naturally and briefly. Use realtime conversation for clarification, acknowledgement, and fast answers.
Only respond when the user is clearly talking to Bean, usually by saying "Hey Bean" or continuing an active Bean conversation. If speech is not addressed to Bean, stay silent.
For simple conversational inputs, greetings, mic checks, current time/date questions, or questions like "can you hear me?", answer immediately in one short sentence. Do not call tools for those.
If the user asks whether you can hear them, say "Yes, I can hear you." Never say "I can read you" during a voice conversation.
If the user asks what time it is, answer from the client temporal context below. Do not call tools for current time/date questions.
Call queue_bean_work when the user asks Bean to read current app data, check calendar/tasks/reminders, use live external data, or create, update, delete, plan, remember, schedule, or otherwise change app data. Trash, garbage, recycling, and household pickup questions should be checked through app data because they may be stored as tasks or reminders.
When queue_bean_work is needed, first acknowledge naturally in one short sentence, then call the tool. Do not claim the task is complete until the app sends completion context later.
If the user asks for live external data that depends on current information outside HeyBean, call queue_bean_work after acknowledging so the main Bean agent can explain the current browsing limitation. Do not leave the user with only an acknowledgement.
Laravel owns workspace access, approvals, validation, calendar/task/reminder writes, durable memory, and usage guardrails. Never invent ids or app-state changes.

Local session id: {$session->id}
Workspace id: {$session->workspace_id}
Client temporal context: {$clientContext}
PROMPT;
    }

    private function realtimeTools(): array
    {
        return [
            [
                'type' => 'function',
                'name' => 'queue_bean_work',
                'description' => 'Queue a HeyBean background agent run when the user asks to read current app data, check calendar/tasks/reminders, use live external data, change app data, or perform durable work such as creating tasks/reminders/events, updating calendar data, remembering preferences, or planning a schedule. Trash, garbage, recycling, and household pickup questions may be stored as tasks or reminders and should use this tool. Do not use for greetings, mic checks, current time/date questions, quick factual answers, or conversational acknowledgements.',
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
