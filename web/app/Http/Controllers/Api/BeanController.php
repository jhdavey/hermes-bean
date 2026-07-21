<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BeanActivityEvent;
use App\Models\BeanConfirmationRequest;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\BeanVoiceEvent;
use App\Rules\ClientTimezone;
use App\Services\Bean\BeanRuntimeService;
use App\Services\Bean\BeanUsageMeterService;
use App\Services\Bean\DashboardContextBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpFoundation\StreamedResponse;

class BeanController extends Controller
{
    public function __construct(
        private readonly BeanRuntimeService $runtime,
        private readonly DashboardContextBuilder $dashboardContext,
        private readonly BeanUsageMeterService $usageMeter,
    ) {}

    public function storeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'client_timezone' => ['nullable', new ClientTimezone],
            'client_location' => ['nullable', 'array'],
            'client_location.latitude' => ['required_with:client_location', 'numeric', 'between:-90,90'],
            'client_location.longitude' => ['required_with:client_location', 'numeric', 'between:-180,180'],
            'client_location.accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'client_location.source' => ['nullable', 'string', 'max:40'],
            'client_location.captured_at' => ['nullable', 'date'],
        ]);

        return response()->json(['data' => $this->runtime->createSession($request->user(), $data['workspace_id'] ?? null, $data['client_timezone'] ?? null, $data['client_location'] ?? null)], 201);
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
            'client_location' => ['nullable', 'array'],
            'client_location.latitude' => ['required_with:client_location', 'numeric', 'between:-90,90'],
            'client_location.longitude' => ['required_with:client_location', 'numeric', 'between:-180,180'],
            'client_location.accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'client_location.source' => ['nullable', 'string', 'max:40'],
            'client_location.captured_at' => ['nullable', 'date'],
            'source' => ['nullable', 'string', 'in:elevenlabs_agent'],
        ]);

        return response()->json(['data' => $this->runtime->handleMessage($request->user(), $data['content'], $data['session_id'] ?? null, $data['workspace_id'] ?? null, $data['client_timezone'] ?? null, $data['source'] ?? null, $data['client_location'] ?? null)]);
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
        $this->usageMeter->recordElevenLabsVoiceSession($event);

        return response()->json(['data' => ['id' => $event->id]], 201);
    }

    public function elevenLabsConversationToken(Request $request): JsonResponse
    {
        if (! config('services.elevenlabs.agent_enabled')) {
            return response()->json(['message' => 'ElevenLabs Agent voice is not enabled.'], 404);
        }

        $data = $request->validate([
            'session_id' => ['nullable', 'integer', 'exists:bean_sessions,id'],
            'workspace_id' => ['nullable', 'integer', 'exists:workspaces,id'],
            'client_timezone' => ['nullable', new ClientTimezone],
            'client_location' => ['nullable', 'array'],
            'client_location.latitude' => ['required_with:client_location', 'numeric', 'between:-90,90'],
            'client_location.longitude' => ['required_with:client_location', 'numeric', 'between:-180,180'],
            'client_location.accuracy' => ['nullable', 'numeric', 'min:0', 'max:100000'],
            'client_location.source' => ['nullable', 'string', 'max:40'],
            'client_location.captured_at' => ['nullable', 'date'],
        ]);

        $apiKey = (string) config('services.elevenlabs.api_key');
        $agentId = (string) config('services.elevenlabs.agent_id');

        if ($apiKey === '' || $agentId === '') {
            return response()->json(['message' => 'ElevenLabs Agent voice is not configured.'], 503);
        }

        $beanSession = null;
        if (isset($data['session_id'])) {
            $beanSession = BeanSession::query()
                ->where('user_id', $request->user()->id)
                ->where('id', $data['session_id'])
                ->firstOrFail();
        } else {
            $beanSession = $this->runtime->createSession($request->user(), $data['workspace_id'] ?? null, $data['client_timezone'] ?? null, $data['client_location'] ?? null);
        }
        if (isset($data['client_location'])) {
            $this->runtime->rememberClientLocation($beanSession, $data['client_location']);
            $beanSession = $beanSession->refresh();
        }

        $query = array_filter([
            'agent_id' => $agentId,
            'participant_name' => 'bean-user-'.$request->user()->id,
            'environment' => config('services.elevenlabs.agent_environment'),
            'branch_id' => config('services.elevenlabs.agent_branch_id'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $response = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout(10)
            ->get('https://api.elevenlabs.io/v1/convai/conversation/token', $query);

        if (! $response->successful()) {
            return response()->json(['message' => 'Could not create ElevenLabs Agent conversation token.'], 502);
        }

        $token = (string) data_get($response->json(), 'token');
        if ($token === '') {
            return response()->json(['message' => 'ElevenLabs conversation token response was empty.'], 502);
        }

        return response()->json(['data' => [
            'token' => $token,
            'agent_id' => $agentId,
            'bean_session_id' => $beanSession->id,
            'dashboard_context' => $this->dashboardContext->build($request->user(), $beanSession, $data['client_timezone'] ?? null),
            'transport' => 'elevenlabs_agent',
        ]]);
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
