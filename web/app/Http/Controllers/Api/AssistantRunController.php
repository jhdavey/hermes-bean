<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Services\AssistantRunService;
use App\Services\HermesRuntimeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class AssistantRunController extends Controller
{
    public function __construct(
        private readonly AssistantRunService $runs,
        private readonly HermesRuntimeService $runtime,
    ) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);
        $source = (string) ($data['source'] ?? 'http');
        $clientRequestId = trim((string) data_get($data, 'metadata.client_request_id', ''));
        if ($clientRequestId !== '') {
            $existingRun = AssistantRun::query()
                ->where('user_id', $ownedSession->user_id)
                ->where('conversation_session_id', $ownedSession->id)
                ->where('metadata->client_request_id', $clientRequestId)
                ->with(['session', 'userMessage', 'assistantMessage'])
                ->latest('id')
                ->first();

            if ($existingRun instanceof AssistantRun) {
                $existingRun = $this->runs->prepareRunForBackgroundResponse($existingRun)
                    ->load(['session', 'userMessage', 'assistantMessage']);

                return response()->json(
                    ['data' => $this->runResponsePayload($existingRun, $ownedSession)],
                    $this->runResponseStatus($existingRun)
                );
            }

            $existingUserMessage = ConversationMessage::query()
                ->where('user_id', $ownedSession->user_id)
                ->where('conversation_session_id', $ownedSession->id)
                ->where('role', 'user')
                ->where('metadata->client_request_id', $clientRequestId)
                ->latest('id')
                ->first();

            if ($existingUserMessage instanceof ConversationMessage) {
                $assistantMessage = ConversationMessage::query()
                    ->where('user_id', $ownedSession->user_id)
                    ->where('conversation_session_id', $ownedSession->id)
                    ->where('role', 'assistant')
                    ->where('id', '>', $existingUserMessage->id)
                    ->orderBy('id')
                    ->first();

                if ($assistantMessage instanceof ConversationMessage) {
                    return response()->json(['data' => [
                        'status' => 'completed',
                        'session' => $ownedSession->refresh(),
                        'run' => null,
                        'user_message' => $existingUserMessage,
                        'assistant_message' => $assistantMessage,
                        'events' => [],
                    ]], 200);
                }

                try {
                    $queued = $this->runs->queueExistingMessage(
                        $ownedSession,
                        $existingUserMessage,
                        $data['metadata'] ?? [],
                        $source
                    );
                } catch (Throwable $exception) {
                    return $this->directRuntimeFallbackResponse(
                        $ownedSession->refresh(),
                        $existingUserMessage,
                        $exception,
                        'existing_message_queue_failed'
                    );
                }

                return response()->json(['data' => [
                    'status' => 'queued',
                    'session' => $ownedSession->refresh(),
                    'run' => $queued['run']->refresh(),
                    'user_message' => $queued['user_message'],
                    'events' => [$queued['event']],
                ]], 202);
            }
        }

        try {
            $queued = $this->runs->queueRun(
                $ownedSession,
                $data['content'],
                $data['metadata'] ?? [],
                $source
            );
        } catch (Throwable $exception) {
            $userMessage = $this->findOrCreateQueuedFallbackUserMessage(
                $ownedSession->refresh(),
                $data['content'],
                $data['metadata'] ?? []
            );

            return $this->directRuntimeFallbackResponse(
                $ownedSession->refresh(),
                $userMessage,
                $exception,
                'queue_run_failed'
            );
        }

        return response()->json(['data' => [
            'status' => 'queued',
            'session' => $ownedSession->refresh(),
            'run' => $queued['run']->refresh(),
            'user_message' => $queued['user_message'],
            'events' => [$queued['event']],
        ]], 202);
    }

    public function lookup(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'client_request_id' => ['required', 'string', 'max:120'],
        ]);

        $clientRequestId = trim((string) $data['client_request_id']);
        $existingRun = AssistantRun::query()
            ->where('user_id', $ownedSession->user_id)
            ->where('conversation_session_id', $ownedSession->id)
            ->where('metadata->client_request_id', $clientRequestId)
            ->with(['session', 'userMessage', 'assistantMessage'])
            ->latest('id')
            ->first();

        if ($existingRun instanceof AssistantRun) {
            $existingRun = $this->runs->prepareRunForBackgroundResponse($existingRun)
                ->load(['session', 'userMessage', 'assistantMessage']);

            return response()->json(
                ['data' => $this->runResponsePayload($existingRun, $ownedSession)],
                $this->runResponseStatus($existingRun)
            );
        }

        $existingUserMessage = ConversationMessage::query()
            ->where('user_id', $ownedSession->user_id)
            ->where('conversation_session_id', $ownedSession->id)
            ->where('role', 'user')
            ->where('metadata->client_request_id', $clientRequestId)
            ->latest('id')
            ->first();

        if ($existingUserMessage instanceof ConversationMessage) {
            $assistantMessage = ConversationMessage::query()
                ->where('user_id', $ownedSession->user_id)
                ->where('conversation_session_id', $ownedSession->id)
                ->where('role', 'assistant')
                ->where('id', '>', $existingUserMessage->id)
                ->orderBy('id')
                ->first();

            if ($assistantMessage instanceof ConversationMessage) {
                return response()->json(['data' => [
                    'status' => 'completed',
                    'session' => $ownedSession->refresh(),
                    'run' => null,
                    'user_message' => $existingUserMessage,
                    'assistant_message' => $assistantMessage,
                    'events' => [],
                ]]);
            }
        }

        return response()->json(['data' => [
            'status' => 'queued',
            'session' => $ownedSession->refresh(),
            'run' => null,
            'user_message' => $existingUserMessage,
            'assistant_message' => null,
            'events' => [],
            'blocker' => null,
        ]], 202);
    }

    public function show(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->with(['session', 'userMessage', 'assistantMessage'])
            ->findOrFail($run);

        $preparedRun = $this->runs->prepareRunForBackgroundResponse($ownedRun)
            ->load(['session', 'userMessage', 'assistantMessage']);

        return response()->json(['data' => $preparedRun], $this->runResponseStatus($preparedRun));
    }

    public function cancel(Request $request, string $run): JsonResponse
    {
        $ownedRun = AssistantRun::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($run);

        return response()->json(['data' => $this->runs->cancelRun($ownedRun)], 202);
    }

    private function runResponsePayload(AssistantRun $run, ConversationSession $fallbackSession): array
    {
        return [
            'status' => $run->status,
            'session' => $run->session?->refresh() ?? $fallbackSession->refresh(),
            'run' => $run,
            'user_message' => $run->userMessage,
            'assistant_message' => $run->assistantMessage,
            'events' => [],
        ];
    }

    private function runResponseStatus(AssistantRun $run): int
    {
        return in_array($run->status, ['completed', 'failed', 'cancelled'], true) ? 200 : 202;
    }

    private function findOrCreateQueuedFallbackUserMessage(ConversationSession $session, string $content, array $metadata): ConversationMessage
    {
        $clientRequestId = trim((string) data_get($metadata, 'client_request_id', ''));
        if ($clientRequestId !== '') {
            $existing = ConversationMessage::query()
                ->where('user_id', $session->user_id)
                ->where('conversation_session_id', $session->id)
                ->where('role', 'user')
                ->where('metadata->client_request_id', $clientRequestId)
                ->latest('id')
                ->first();

            if ($existing instanceof ConversationMessage) {
                return $existing;
            }
        }

        return ConversationMessage::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'role' => 'user',
            'content' => $content,
            'metadata' => $metadata ?: null,
        ]);
    }

    private function directRuntimeFallbackResponse(
        ConversationSession $session,
        ConversationMessage $userMessage,
        Throwable $queueException,
        string $fallbackSource
    ): JsonResponse {
        Log::warning('Bean async run queue failed; trying direct runtime fallback.', [
            'session_id' => $session->id,
            'message_id' => $userMessage->id,
            'fallback_source' => $fallbackSource,
            'exception' => $queueException->getMessage(),
        ]);

        try {
            $result = $this->runtime->sendExistingMessage($session->refresh(), $userMessage);

            return response()->json(['data' => $result], 201);
        } catch (Throwable $runtimeException) {
            Log::error('Bean direct runtime fallback failed after async queue failure.', [
                'session_id' => $session->id,
                'message_id' => $userMessage->id,
                'fallback_source' => $fallbackSource,
                'queue_exception' => $queueException->getMessage(),
                'runtime_exception' => $runtimeException->getMessage(),
            ]);

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.',
                'metadata' => [
                    'runtime' => 'async_queue_bridge',
                    'fallback_source' => $fallbackSource,
                    'queue_error' => str($queueException->getMessage())->limit(1000, '')->toString(),
                    'runtime_error' => str($runtimeException->getMessage())->limit(1000, '')->toString(),
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

    private function missingRunBridgeMessage(ConversationSession $session, string $clientRequestId): ConversationMessage
    {
        $existing = ConversationMessage::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->where('role', 'assistant')
            ->where('metadata->runtime', 'missing_run_bridge')
            ->where('metadata->client_request_id', $clientRequestId)
            ->latest('id')
            ->first();

        if ($existing instanceof ConversationMessage) {
            return $existing;
        }

        $message = ConversationMessage::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'role' => 'assistant',
            'content' => 'I didn’t receive that request cleanly. Please send it once more and I’ll take it from there.',
            'metadata' => [
                'runtime' => 'missing_run_bridge',
                'client_request_id' => $clientRequestId,
            ],
        ]);

        $session->update([
            'status' => 'active',
            'last_activity_at' => now(),
        ]);

        return $message;
    }
}
