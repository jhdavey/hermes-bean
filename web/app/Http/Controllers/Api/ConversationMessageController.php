<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationSession;
use App\Services\AssistantRunService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationMessageController extends Controller
{
    public function __construct(private readonly AssistantRunService $runs) {}

    public function branch(Request $request, string $session, string $message): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['required', 'array'],
        ]);
        validator(['metadata' => $data['metadata']], [
            'metadata.client_request_id' => ['required', 'string', 'max:120'],
        ])->validate();
        $metadata = $this->runs->sanitizeClientMetadata($data['metadata']);

        $queued = $this->runs->queueBranchRun(
            $ownedSession,
            (int) $message,
            $data['content'],
            $metadata,
            'conversation_branch',
        );

        return $this->runResponse(
            $ownedSession,
            $queued['run'],
            $queued['events'] ?? [$queued['event']],
            ! $queued['existing'],
        );
    }

    /** @param array<int, mixed> $events */
    private function runResponse(
        ConversationSession $session,
        AssistantRun $run,
        array $events,
        bool $created = false,
    ): JsonResponse {
        $run = $this->runs->prepareRunForBackgroundResponse($run->refresh())
            ->load(['session', 'userMessage', 'assistantMessage']);

        return response()->json(['data' => [
            'status' => $run->status,
            'session' => $run->session?->refresh() ?? $session->refresh(),
            'run' => $run,
            'user_message' => $run->userMessage,
            'assistant_message' => $run->assistantMessage,
            'events' => $events,
            'blocker' => null,
        ]], $created ? 201 : 200);
    }
}
