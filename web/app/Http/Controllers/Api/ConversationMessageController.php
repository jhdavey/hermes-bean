<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class ConversationMessageController extends Controller
{
    public function __construct(
        private readonly HermesRuntimeService $runtime,
        private readonly AssistantRunService $runs,
    ) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);

        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $metadata = $data['metadata'] ?? [];
        $userMessage = $this->createUserMessage($ownedSession, $data['content'], $metadata);

        try {
            $result = $this->runtime->sendExistingMessage($ownedSession, $userMessage);

            return response()->json(['data' => $result], $result['status'] === 'blocked' ? 429 : 201);
        } catch (Throwable $exception) {
            Log::warning('Direct Bean message failed; queueing background run.', [
                'session_id' => $ownedSession->id,
                'message_id' => $userMessage->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return response()->json(
            ['data' => $this->queuedResponse($ownedSession->refresh(), $userMessage, $metadata, 'direct_fallback')],
            202
        );
    }

    public function branch(Request $request, string $session, string $message): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $ownedMessage = $ownedSession->messages()
            ->where('role', 'user')
            ->findOrFail($message);

        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        DB::transaction(function () use ($ownedSession, $ownedMessage): void {
            ConversationMessage::query()
                ->where('conversation_session_id', $ownedSession->id)
                ->where('id', '>=', $ownedMessage->id)
                ->delete();
        });

        $metadata = $data['metadata'] ?? [];
        $metadata['edited_from_message_id'] = $ownedMessage->id;

        $ownedSession = $ownedSession->refresh();
        $userMessage = $this->createUserMessage($ownedSession, $data['content'], $metadata);

        try {
            $result = $this->runtime->sendExistingMessage($ownedSession, $userMessage);

            return response()->json(['data' => $result], $result['status'] === 'blocked' ? 429 : 201);
        } catch (Throwable $exception) {
            Log::warning('Direct Bean branch failed; queueing background run.', [
                'session_id' => $ownedSession->id,
                'message_id' => $userMessage->id,
                'edited_from_message_id' => $ownedMessage->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        return response()->json(
            ['data' => $this->queuedResponse($ownedSession->refresh(), $userMessage, $metadata, 'branch_fallback')],
            202
        );
    }

    private function createUserMessage(ConversationSession $session, string $content, array $metadata): ConversationMessage
    {
        return DB::transaction(function () use ($session, $content, $metadata): ConversationMessage {
            return ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);
        });
    }

    private function queuedResponse(
        ConversationSession $session,
        ConversationMessage $userMessage,
        array $metadata,
        string $fallbackSource,
    ): array {
        $source = (string) data_get($metadata, 'source', $fallbackSource);
        $queued = $this->runs->queueExistingMessage($session, $userMessage, $metadata, $source);

        return [
            'status' => 'queued',
            'session' => $session->refresh(),
            'run' => $queued['run']->refresh(),
            'user_message' => $queued['user_message'],
            'assistant_message' => null,
            'events' => [$queued['event']],
            'blocker' => null,
        ];
    }
}
