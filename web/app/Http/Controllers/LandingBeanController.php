<?php

namespace App\Http\Controllers;

use App\Rules\ClientTimezone;
use App\Services\Bean\LandingBeanRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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
        ]);
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
        $data = $request->validate([
            'content' => ['required', 'string', 'max:1200'],
            'page_path' => ['nullable', 'string', 'max:160', 'regex:/^\/[A-Za-z0-9_\-\/]*$/'],
        ]);
        $visitorId = $this->visitorId($request);
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

        return response()->json(['data' => ['answer' => $result['answer']]]);
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
