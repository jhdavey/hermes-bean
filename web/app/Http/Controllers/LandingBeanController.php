<?php

namespace App\Http\Controllers;

use App\Rules\ClientTimezone;
use App\Services\Bean\LandingBeanRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class LandingBeanController extends Controller
{
    public function __construct(private readonly LandingBeanRuntimeService $runtime) {}

    public function conversationToken(Request $request): JsonResponse
    {
        if (! config('services.elevenlabs.agent_enabled')) {
            return response()->json(['message' => 'Bean voice is not enabled.'], 404);
        }

        $data = $request->validate([
            'client_timezone' => ['nullable', new ClientTimezone],
            'page_path' => ['nullable', 'string', 'max:160', 'regex:/^\/[A-Za-z0-9_\-\/]*$/'],
            'turnstile_token' => ['nullable', 'string', 'max:2048'],
        ]);
        if (! $this->passesHumanVerification($request, $data['turnstile_token'] ?? null)) {
            return response()->json(['message' => 'Please complete the verification to talk with Bean.'], 422);
        }
        $apiKey = (string) config('services.elevenlabs.api_key');
        $agentId = (string) config('services.elevenlabs.landing_agent_id');

        if ($apiKey === '' || $agentId === '') {
            return response()->json(['message' => 'Bean voice is not configured for public pages.'], 503);
        }

        $visitorId = $this->visitorId($request);
        $query = array_filter([
            'agent_id' => $agentId,
            'participant_name' => 'bean-visitor-'.substr(hash('sha256', $visitorId), 0, 16),
            'environment' => config('services.elevenlabs.landing_agent_environment'),
            'branch_id' => config('services.elevenlabs.landing_agent_branch_id'),
        ], static fn ($value): bool => $value !== null && $value !== '');

        $response = Http::withHeaders(['xi-api-key' => $apiKey])
            ->timeout(10)
            ->get('https://api.elevenlabs.io/v1/convai/conversation/token', $query);

        if (! $response->successful()) {
            return response()->json(['message' => 'Could not start Bean voice.'], 502);
        }

        $token = (string) data_get($response->json(), 'token');
        if ($token === '') {
            return response()->json(['message' => 'Bean voice returned an empty session.'], 502);
        }

        $request->session()->put('landing_bean.turn_count', 0);
        $request->session()->put('landing_bean.started_at', now()->timestamp);
        $request->session()->forget('landing_bean.hermes_session_id');
        Log::info('Landing Bean demo session issued.', [
            'visitor_id_hash' => hash('sha256', $visitorId),
            'page_path' => $data['page_path'] ?? '/',
        ]);

        return response()->json(['data' => [
            'token' => $token,
            'agent_id' => $agentId,
            'landing_session_id' => substr(hash('sha256', $visitorId), 0, 20),
            'client_timezone' => $data['client_timezone'] ?? null,
            'page_path' => $data['page_path'] ?? '/',
            'transport' => 'elevenlabs_agent',
        ]]);
    }

    public function message(Request $request): JsonResponse
    {
        $maxTurns = max(1, (int) config('bean.landing.max_visitor_turns', 20));
        $turnCount = max(0, (int) $request->session()->get('landing_bean.turn_count', 0));
        if ($turnCount >= $maxTurns) {
            return response()->json([
                'message' => 'That is the end of this Bean demo. You can explore the page or create an account to continue.',
            ], 429);
        }

        $data = $request->validate([
            'content' => ['required', 'string', 'max:500'],
            'page_path' => ['nullable', 'string', 'max:160', 'regex:/^\/[A-Za-z0-9_\-\/]*$/'],
        ]);
        $visitorId = $this->visitorId($request);
        $request->session()->put('landing_bean.turn_count', $turnCount + 1);
        $result = $this->runtime->respond(
            $visitorId,
            $request->session()->get('landing_bean.hermes_session_id'),
            $data['content'],
            $data['page_path'] ?? '/',
        );

        if (is_string($result['hermes_session_id']) && $result['hermes_session_id'] !== '') {
            $request->session()->put('landing_bean.hermes_session_id', $result['hermes_session_id']);
        } else {
            $request->session()->forget('landing_bean.hermes_session_id');
        }

        Log::info('Landing Bean demo turn completed.', [
            'visitor_id_hash' => hash('sha256', $visitorId),
            'turn' => $turnCount + 1,
            'max_turns' => $maxTurns,
            'page_path' => $data['page_path'] ?? '/',
            'ui_action' => $result['ui_action'] ?? null,
        ]);

        return response()->json(['data' => [
            'answer' => $result['answer'],
            'ui_action' => $result['ui_action'] ?? null,
        ]]);
    }

    private function passesHumanVerification(Request $request, ?string $token): bool
    {
        $secret = trim((string) config('services.turnstile.secret_key'));
        if ($secret === '') {
            return true;
        }
        if (! is_string($token) || trim($token) === '') {
            return false;
        }

        $response = Http::asForm()->timeout(8)->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => $secret,
            'response' => $token,
            'remoteip' => $request->ip(),
        ]);
        if (! $response->successful() || ! $response->json('success')) {
            return false;
        }

        $hostname = trim((string) $response->json('hostname'));

        return $hostname === '' || hash_equals(strtolower($request->getHost()), strtolower($hostname));
    }

    private function visitorId(Request $request): string
    {
        $visitorId = $request->session()->get('landing_bean.visitor_id');
        if (! is_string($visitorId) || $visitorId === '') {
            $visitorId = (string) Str::uuid();
            $request->session()->put('landing_bean.visitor_id', $visitorId);
        }

        return $visitorId;
    }
}
