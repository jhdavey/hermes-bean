<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Models\VoiceTurn;
use App\Services\AssistantRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantRunController extends Controller
{
    public function __construct(private readonly AssistantRunService $runs) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['required', 'array'],
            'source' => ['prohibited'],
        ]);
        validator(['metadata' => $data['metadata']], [
            'metadata.client_request_id' => ['required', 'string', 'max:120'],
        ])->validate();
        $metadata = $this->runs->sanitizeClientMetadata($data['metadata']);

        $queued = $this->runs->queueRun($ownedSession, $data['content'], $metadata, 'assistant_run_api');

        return $this->runResponse(
            $ownedSession,
            $queued['run'],
            $queued['events'] ?? [$queued['event']],
            created: ! $queued['existing'],
        );
    }

    public function lookup(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:120'],
        ]);
        $clientRequestId = trim((string) $data['client_request_id']);
        if (VoiceTurn::query()
            ->where('conversation_session_id', $ownedSession->id)
            ->where('turn_id', $clientRequestId)
            ->exists()) {
            return response()->json([
                'message' => 'This stable turn ID is owned by Browser Voice v2.',
            ], 409);
        }

        $run = $this->runs->findRunForClientRequest($ownedSession, $clientRequestId);
        if (! $run instanceof AssistantRun) {
            return response()->json([
                'message' => 'No durable assistant run exists for that request ID.',
            ], 404);
        }

        return $this->runResponse($ownedSession, $run, []);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('voice_turn_id')
            ->findOrFail($run);

        return $this->runResponse(
            $ownedRun->session()->firstOrFail(),
            $ownedRun,
            [],
            bareRun: true,
        );
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->whereNull('voice_turn_id')
            ->findOrFail($run);

        return response()->json(['data' => $this->runs->cancelRun($ownedRun)], 202);
    }

    /** @param array<int, mixed> $events */
    private function runResponse(
        ConversationSession $session,
        AssistantRun $run,
        array $events,
        bool $bareRun = false,
        ?bool $created = null,
    ): JsonResponse {
        $run = $this->runs->prepareRunForBackgroundResponse($run->refresh())
            ->load(['session', 'userMessage', 'assistantMessage']);
        $status = $created === true
            ? 201
            : ($created === false
                ? 200
                : (in_array($run->status, ['completed', 'blocked', 'failed', 'cancelled'], true) ? 200 : 202));

        if ($bareRun) {
            return response()->json(['data' => $run], $status);
        }

        return response()->json(['data' => [
            'status' => $run->status,
            'session' => $run->session?->refresh() ?? $session->refresh(),
            'run' => $run,
            'user_message' => $run->userMessage,
            'assistant_message' => $run->assistantMessage,
            'events' => $events,
            'blocker' => null,
        ]], $status);
    }
}
