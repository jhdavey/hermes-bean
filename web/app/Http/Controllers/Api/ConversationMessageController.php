<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
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
        if ($this->shouldQueueWithoutDirectRuntime($metadata)) {
            return $this->queueFirstMessageResponse($ownedSession, $data['content'], $metadata, 'flutter_direct');
        }

        $userMessage = $this->createUserMessage($ownedSession, $data['content'], $metadata);

        try {
            $result = $this->runtime->sendExistingMessage($ownedSession, $userMessage);

            return response()->json(['data' => $result], 201);
        } catch (Throwable $exception) {
            Log::warning('Direct Bean message failed; queueing background run.', [
                'session_id' => $ownedSession->id,
                'message_id' => $userMessage->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            return response()->json(
                ['data' => $this->queuedResponse($ownedSession->refresh(), $userMessage, $metadata, 'direct_fallback')],
                202
            );
        } catch (Throwable $exception) {
            return $this->bridgeQueuedResponseFailure($ownedSession->refresh(), $userMessage, $exception, 'direct_fallback');
        }
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

        $metadata = $data['metadata'] ?? [];
        $metadata['edited_from_message_id'] = $ownedMessage->id;

        if ($this->shouldQueueWithoutDirectRuntime($metadata)) {
            $existingResponse = $this->existingClientRequestResponse($ownedSession, $metadata);
            if ($existingResponse instanceof JsonResponse) {
                return $existingResponse;
            }
        }

        DB::transaction(function () use ($ownedSession, $ownedMessage): void {
            ConversationMessage::query()
                ->where('conversation_session_id', $ownedSession->id)
                ->where('id', '>=', $ownedMessage->id)
                ->delete();
        });

        $ownedSession = $ownedSession->refresh();
        $userMessage = $this->createUserMessage($ownedSession, $data['content'], $metadata);

        if ($this->shouldQueueWithoutDirectRuntime($metadata)) {
            try {
                return response()->json(
                    ['data' => $this->queuedResponse($ownedSession->refresh(), $userMessage, $metadata, 'flutter_branch')],
                    202
                );
            } catch (Throwable $exception) {
                return $this->bridgeQueuedResponseFailure($ownedSession->refresh(), $userMessage, $exception, 'flutter_branch');
            }
        }

        try {
            $result = $this->runtime->sendExistingMessage($ownedSession, $userMessage);

            return response()->json(['data' => $result], 201);
        } catch (Throwable $exception) {
            Log::warning('Direct Bean branch failed; queueing background run.', [
                'session_id' => $ownedSession->id,
                'message_id' => $userMessage->id,
                'edited_from_message_id' => $ownedMessage->id,
                'exception' => $exception->getMessage(),
            ]);
        }

        try {
            return response()->json(
                ['data' => $this->queuedResponse($ownedSession->refresh(), $userMessage, $metadata, 'branch_fallback')],
                202
            );
        } catch (Throwable $exception) {
            return $this->bridgeQueuedResponseFailure($ownedSession->refresh(), $userMessage, $exception, 'branch_fallback');
        }
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

    private function queueFirstMessageResponse(
        ConversationSession $session,
        string $content,
        array $metadata,
        string $fallbackSource
    ): JsonResponse {
        $existingResponse = $this->existingClientRequestResponse($session, $metadata);
        if ($existingResponse instanceof JsonResponse) {
            return $existingResponse;
        }

        $userMessage = $this->createUserMessage($session, $content, $metadata);

        try {
            return response()->json(
                ['data' => $this->queuedResponse($session->refresh(), $userMessage, $metadata, $fallbackSource)],
                202
            );
        } catch (Throwable $exception) {
            return $this->bridgeQueuedResponseFailure($session->refresh(), $userMessage, $exception, $fallbackSource);
        }
    }

    private function existingClientRequestResponse(ConversationSession $session, array $metadata): ?JsonResponse
    {
        $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
        if ($clientRequestId === '') {
            return null;
        }

        $existingRun = AssistantRun::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->where('metadata->client_request_id', $clientRequestId)
            ->with(['session', 'userMessage', 'assistantMessage'])
            ->latest('id')
            ->first();

        if ($existingRun instanceof AssistantRun) {
            $run = $this->runs->prepareRunForBackgroundResponse($existingRun)
                ->load(['session', 'userMessage', 'assistantMessage']);

            return response()->json(['data' => [
                'status' => $run->status,
                'session' => $run->session?->refresh() ?? $session->refresh(),
                'run' => $run,
                'user_message' => $run->userMessage,
                'assistant_message' => $run->assistantMessage,
                'events' => [],
                'blocker' => null,
            ]], in_array($run->status, ['completed', 'failed', 'cancelled'], true) ? 200 : 202);
        }

        $existingUserMessage = ConversationMessage::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->where('role', 'user')
            ->where('metadata->client_request_id', $clientRequestId)
            ->latest('id')
            ->first();

        if (! $existingUserMessage instanceof ConversationMessage) {
            return null;
        }

        $assistantMessage = ConversationMessage::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->where('role', 'assistant')
            ->where('id', '>', $existingUserMessage->id)
            ->orderBy('id')
            ->first();

        if ($assistantMessage instanceof ConversationMessage) {
            return response()->json(['data' => [
                'status' => 'completed',
                'session' => $session->refresh(),
                'run' => null,
                'user_message' => $existingUserMessage,
                'assistant_message' => $assistantMessage,
                'events' => [],
                'blocker' => null,
            ]], 200);
        }

        try {
            return response()->json(
                ['data' => $this->queuedResponse($session->refresh(), $existingUserMessage, $metadata, 'flutter_existing_message')],
                202
            );
        } catch (Throwable $exception) {
            return $this->bridgeQueuedResponseFailure($session->refresh(), $existingUserMessage, $exception, 'flutter_existing_message');
        }
    }

    private function shouldQueueWithoutDirectRuntime(array $metadata): bool
    {
        return strtolower(trim((string) data_get($metadata, 'source', ''))) === 'flutter';
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

    private function bridgeQueuedResponseFailure(
        ConversationSession $session,
        ConversationMessage $userMessage,
        Throwable $exception,
        string $fallbackSource
    ): JsonResponse {
        Log::error('Direct Bean fallback queue failed; returning bridge message.', [
            'session_id' => $session->id,
            'message_id' => $userMessage->id,
            'fallback_source' => $fallbackSource,
            'exception' => $exception->getMessage(),
        ]);

        $assistantMessage = ConversationMessage::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'I’m checking the latest app state now. If I need one more detail, I’ll ask.',
            'metadata' => [
                'runtime' => 'direct_queue_bridge',
                'fallback_source' => $fallbackSource,
                'queue_error' => str($exception->getMessage())->limit(1000, '')->toString(),
            ],
        ]);

        $session->update([
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        return response()->json(['data' => [
            'status' => 'completed',
            'session' => $session->refresh(),
            'run' => null,
            'user_message' => $userMessage->refresh(),
            'assistant_message' => $assistantMessage,
            'events' => [],
            'blocker' => null,
        ]], 200);
    }
}
