<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BeanActivityEvent;
use App\Models\BeanConfirmationRequest;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\BeanVoiceBridgeSession;
use App\Models\BeanVoiceEvent;
use App\Rules\ClientTimezone;
use App\Services\Bean\BeanRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BeanController extends Controller
{
    public function __construct(private readonly BeanRuntimeService $runtime) {}

    public function storeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'client_timezone' => ['nullable', new ClientTimezone],
        ]);

        return response()->json(['data' => $this->runtime->createSession($request->user(), $data['workspace_id'] ?? null, $data['client_timezone'] ?? null)], 201);
    }

    public function sessions(Request $request): JsonResponse
    {
        $sessions = BeanSession::query()->where('user_id', $request->user()->id)->latest('updated_at')->limit(20)->get();

        return response()->json(['data' => $sessions]);
    }

    public function activity(Request $request, BeanSession $session): JsonResponse
    {
        abort_unless((int) $session->user_id === (int) $request->user()->id, 404);

        return response()->json(['data' => [
            'messages' => $session->messages()->orderBy('id')->limit(100)->get(),
            'activity' => $session->activityEvents()->orderBy('id')->limit(200)->get(),
            'confirmations' => BeanConfirmationRequest::query()->where('bean_session_id', $session->id)->where('status', 'pending')->latest('id')->get(),
        ]]);
    }

    public function message(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:bean_sessions,id'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'content' => ['required', 'string', 'max:8000'],
            'client_timezone' => ['nullable', new ClientTimezone],
        ]);

        return response()->json(['data' => $this->runtime->handleMessage($request->user(), $data['content'], $data['session_id'] ?? null, $data['workspace_id'] ?? null, $data['client_timezone'] ?? null)]);
    }

    public function run(Request $request, BeanRun $run): JsonResponse
    {
        abort_unless((int) $run->user_id === (int) $request->user()->id, 404);
        $run->load(['toolCalls', 'activityEvents']);
        $data = $run->toArray();
        $data['progress'] = data_get($run->metadata, 'progress');
        $data['progress_history'] = data_get($run->metadata, 'progress_history', []);

        return response()->json(['data' => $data]);
    }

    public function approve(Request $request, BeanConfirmationRequest $confirmation): JsonResponse
    {
        abort_unless((int) $confirmation->user_id === (int) $request->user()->id, 404);

        return response()->json(['data' => $this->runtime->approveConfirmation($request->user(), $confirmation->id)]);
    }

    public function voiceEvent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'event_type' => ['required', 'string', 'max:80'],
            'session_id' => ['nullable', 'integer', 'exists:bean_sessions,id'],
            'run_id' => ['nullable', 'integer', 'exists:bean_runs,id'],
            'mode' => ['nullable', 'string', 'max:40'],
            'source' => ['nullable', 'string', 'max:80'],
            'label' => ['nullable', 'string', 'max:240'],
            'payload' => ['nullable', 'array'],
            'occurred_at' => ['nullable', 'date'],
            'occurred_at_ms' => ['nullable', 'integer', 'min:0'],
        ]);

        $sessionId = $data['session_id'] ?? null;
        if ($sessionId !== null) {
            abort_unless(BeanSession::query()->where('user_id', $request->user()->id)->where('id', $sessionId)->exists(), 404);
        }
        $runId = $data['run_id'] ?? null;
        if ($runId !== null) {
            abort_unless(BeanRun::query()->where('user_id', $request->user()->id)->where('id', $runId)->exists(), 404);
        }

        $event = BeanVoiceEvent::create([
            'user_id' => $request->user()->id,
            'bean_session_id' => $sessionId,
            'bean_run_id' => $runId,
            'event_type' => preg_replace('/[^a-z0-9_\.:-]/i', '_', $data['event_type']) ?: 'unknown',
            'mode' => $data['mode'] ?? null,
            'source' => $data['source'] ?? null,
            'label' => isset($data['label']) ? mb_substr($data['label'], 0, 240) : null,
            'payload' => $data['payload'] ?? null,
            'occurred_at' => isset($data['occurred_at']) ? Carbon::parse($data['occurred_at']) : now(),
            'occurred_at_ms' => $data['occurred_at_ms'] ?? null,
        ]);

        return response()->json(['data' => ['id' => $event->id]], 201);
    }

    public function elevenLabsConversationToken(Request $request): JsonResponse
    {
        if (! config('services.elevenlabs.speech_engine_enabled')) {
            return response()->json(['message' => 'ElevenLabs Speech Engine is not enabled.'], 404);
        }

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:bean_sessions,id'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'client_timezone' => ['nullable', new ClientTimezone],
        ]);

        $apiKey = (string) config('services.elevenlabs.api_key');
        $speechEngineId = (string) config('services.elevenlabs.speech_engine_id');
        $bridgeSecret = (string) config('services.elevenlabs.voice_bridge_secret');

        if ($apiKey === '' || $speechEngineId === '' || $bridgeSecret === '') {
            return response()->json(['message' => 'ElevenLabs Speech Engine is not configured.'], 503);
        }

        $beanSession = null;
        if (isset($data['session_id'])) {
            $beanSession = BeanSession::query()
                ->where('user_id', $request->user()->id)
                ->where('id', $data['session_id'])
                ->firstOrFail();
        } else {
            $beanSession = $this->runtime->createSession($request->user(), $data['workspace_id'] ?? null, $data['client_timezone'] ?? null);
        }

        $bridgeSession = BeanVoiceBridgeSession::create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $request->user()->id,
            'bean_session_id' => $beanSession->id,
            'workspace_id' => $beanSession->workspace_id,
            'client_timezone' => $data['client_timezone'] ?? null,
            'status' => 'pending',
            'metadata' => ['transport' => 'elevenlabs_speech_engine'],
            'expires_at' => now()->addHour(),
        ]);

        $query = array_filter([
            'agent_id' => $speechEngineId,
            'participant_name' => 'bean-user-'.$request->user()->id,
            'environment' => config('services.elevenlabs.speech_engine_environment'),
            'branch_id' => config('services.elevenlabs.speech_engine_branch_id'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $response = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout(10)
            ->get('https://api.elevenlabs.io/v1/convai/conversation/token', $query);

        if (! $response->successful()) {
            $bridgeSession->update(['status' => 'token_failed']);

            return response()->json(['message' => 'Could not create ElevenLabs conversation token.'], 502);
        }

        $token = (string) data_get($response->json(), 'token');
        if ($token === '') {
            $bridgeSession->update(['status' => 'token_failed']);

            return response()->json(['message' => 'ElevenLabs conversation token response was empty.'], 502);
        }

        return response()->json(['data' => [
            'token' => $token,
            'speech_engine_id' => $speechEngineId,
            'bridge_session_id' => $bridgeSession->uuid,
            'bean_session_id' => $beanSession->id,
            'transport' => 'elevenlabs_speech_engine',
        ]]);
    }

    public function connectElevenLabsBridgeSession(Request $request, string $bridgeSession): JsonResponse
    {
        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:160'],
        ]);

        $session = BeanVoiceBridgeSession::query()
            ->where('uuid', $bridgeSession)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        if ($session->expires_at !== null && $session->expires_at->isPast()) {
            return response()->json(['message' => 'ElevenLabs voice session expired.'], 410);
        }

        $session->update([
            'conversation_id' => $data['conversation_id'],
            'status' => 'connected',
            'connected_at' => now(),
        ]);

        return response()->json(['data' => [
            'bridge_session_id' => $session->uuid,
            'conversation_id' => $session->conversation_id,
        ]]);
    }

    public function elevenLabsBridgeMessage(Request $request): JsonResponse
    {
        $configuredSecret = (string) config('services.elevenlabs.voice_bridge_secret');
        $providedSecret = (string) $request->header('X-Bean-Voice-Bridge-Secret', '');
        if ($configuredSecret === '' || $providedSecret === '' || ! hash_equals($configuredSecret, $providedSecret)) {
            return response()->json(['message' => 'Unauthorized.'], 401);
        }

        $data = $request->validate([
            'conversation_id' => ['required', 'string', 'max:160'],
            'content' => ['required', 'string', 'max:8000'],
            'client_timezone' => ['nullable', new ClientTimezone],
        ]);

        $bridgeSession = BeanVoiceBridgeSession::query()
            ->with(['user', 'beanSession'])
            ->where('conversation_id', $data['conversation_id'])
            ->whereIn('status', ['connected', 'active'])
            ->first();

        if (! $bridgeSession || ($bridgeSession->expires_at !== null && $bridgeSession->expires_at->isPast())) {
            return response()->json(['message' => 'ElevenLabs voice session is not registered.'], 404);
        }

        $bridgeSession->update([
            'status' => 'active',
            'last_transcript_at' => now(),
        ]);

        $result = $this->runtime->handleMessage(
            $bridgeSession->user,
            $data['content'],
            $bridgeSession->bean_session_id,
            $bridgeSession->workspace_id,
            $data['client_timezone'] ?? $bridgeSession->client_timezone,
        );

        if (($result['session']->id ?? null) && (int) $result['session']->id !== (int) $bridgeSession->bean_session_id) {
            $bridgeSession->update(['bean_session_id' => $result['session']->id]);
        }

        return response()->json(['data' => $result]);
    }

    public function realtimeSession(Request $request): JsonResponse
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return response()->json(['message' => 'OpenAI realtime is not configured.'], 503);
        }

        // The client must perform local wake detection or explicit tap-to-talk first.
        // This endpoint mints a short-lived client secret for an active voice turn only.
        $payload = [
            'session' => [
                'type' => 'realtime',
                'model' => (string) config('services.openai.realtime_model', 'gpt-realtime'),
                'instructions' => 'You are Bean, the HeyBean voice assistant. Always speak English. Keep acknowledgements under five words. Laravel is the source of truth for HeyBean data, private app state, tools, and mutations. Acknowledge quickly, but app data/actions must go through Laravel and should not be invented or mutated directly.',
                'audio' => [
                    'input' => [
                        'transcription' => [
                            'model' => 'gpt-4o-mini-transcribe',
                            'language' => 'en',
                        ],
                        'turn_detection' => [
                            'type' => 'server_vad',
                            'threshold' => (float) config('services.openai.realtime_vad_threshold', 0.75),
                            'prefix_padding_ms' => (int) config('services.openai.realtime_vad_prefix_padding_ms', 300),
                            'silence_duration_ms' => (int) config('services.openai.realtime_vad_silence_duration_ms', 700),
                            'create_response' => false,
                        ],
                    ],
                    'output' => [
                        'voice' => (string) config('services.openai.realtime_voice', 'alloy'),
                    ],
                ],
            ],
        ];

        $response = Http::withToken($apiKey)
            ->timeout(10)
            ->post('https://api.openai.com/v1/realtime/client_secrets', $payload);

        $body = $response->json() ?: ['message' => 'Realtime session error'];
        if ($response->successful()) {
            if (($body['value'] ?? null) && ! isset($body['client_secret'])) {
                $body['client_secret'] = [
                    'value' => $body['value'],
                    'expires_at' => $body['expires_at'] ?? null,
                ];
            }
            $body['model'] = $payload['session']['model'];
            $body['voice'] = $payload['session']['audio']['output']['voice'];
        }

        return response()->json($body, $response->status());
    }

    public function events(Request $request): StreamedResponse
    {
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0'], 'wait' => ['nullable', 'integer', 'min:0', 'max:30']]);
        $after = (int) ($data['after'] ?? 0);
        $wait = (int) ($data['wait'] ?? 25);
        $userId = (int) $request->user()->id;

        return response()->stream(function () use ($after, $wait, $userId): void {
            $deadline = microtime(true) + $wait;
            $last = $after;
            do {
                $events = BeanActivityEvent::query()->where('user_id', $userId)->where('id', '>', $last)->orderBy('id')->limit(50)->get();
                foreach ($events as $event) {
                    $last = (int) $event->id;
                    echo "id: {$event->id}\n";
                    echo 'event: '.str_replace('_', '.', $event->type)."\n";
                    echo 'data: '.json_encode([
                        'id' => $event->id,
                        'type' => $event->type,
                        'label' => $event->label,
                        'payload' => $event->payload,
                        'bean_session_id' => $event->bean_session_id,
                        'bean_run_id' => $event->bean_run_id,
                        'created_at' => optional($event->created_at)->toIso8601String(),
                    ])."\n\n";
                    @ob_flush();
                    @flush();
                }
                if ($events->isNotEmpty() || $wait <= 0) {
                    break;
                }
                usleep(700_000);
            } while (microtime(true) < $deadline);
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
