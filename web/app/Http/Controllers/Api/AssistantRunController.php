<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessAssistantRun;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Services\AssistantRunService;
use App\Services\BeanIntentRouter;
use App\Services\FastCalendarReadService;
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
        private readonly BeanIntentRouter $intentRouter,
        private readonly FastCalendarReadService $fastCalendarReads,
    ) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);
        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
            'source' => ['nullable', 'string', 'max:50'],
        ]);
        validator($data['metadata'] ?? [], [
            'client_request_id' => ['nullable', 'string', 'max:120'],
            'supersedes_client_request_id' => ['nullable', 'string', 'max:120'],
        ])->validate();
        $data['metadata'] = $this->runs->sanitizeClientMetadata($data['metadata'] ?? []);
        $source = (string) ($data['source'] ?? data_get($data, 'metadata.source', 'http'));
        $clientRequestId = trim((string) data_get($data, 'metadata.client_request_id', ''));
        $intentRoute = $this->intentRouter->route($data['content']);
        if ($clientRequestId !== '') {
            $existingRun = $this->existingClientRequestRun($ownedSession, $clientRequestId);

            if ($existingRun instanceof AssistantRun) {
                if (($intentRoute['lane'] ?? null) === BeanIntentRouter::NEEDS_APP_READ
                    && $existingRun->userMessage instanceof ConversationMessage) {
                    $fastAnswer = $this->fastCalendarReads->resolve(
                        $ownedSession,
                        $data['content'],
                        $data['metadata'] ?? [],
                    );
                    if ($fastAnswer !== null) {
                        $completed = $this->runs->completeExistingMessageImmediately(
                            $ownedSession,
                            $existingRun->userMessage,
                            $fastAnswer,
                            [
                                ...($data['metadata'] ?? []),
                                'bean_intent' => $intentRoute,
                                'bean_intent_lane' => $intentRoute['lane'],
                            ],
                            $source,
                        );

                        return response()->json(['data' => [
                            'status' => $completed['run']->status,
                            'session' => $ownedSession->refresh(),
                            'run' => $completed['run'],
                            'user_message' => $completed['user_message'],
                            'assistant_message' => $completed['assistant_message'],
                            'events' => $completed['events'],
                            'intent' => $intentRoute,
                        ]], $this->runResponseStatus($completed['run']));
                    }
                }

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
                if (($intentRoute['lane'] ?? null) === BeanIntentRouter::NEEDS_APP_READ) {
                    $fastAnswer = $this->fastCalendarReads->resolve(
                        $ownedSession,
                        $data['content'],
                        $data['metadata'] ?? [],
                    );
                    if ($fastAnswer !== null) {
                        $completed = $this->runs->completeExistingMessageImmediately(
                            $ownedSession,
                            $existingUserMessage,
                            $fastAnswer,
                            [
                                ...($data['metadata'] ?? []),
                                'bean_intent' => $intentRoute,
                                'bean_intent_lane' => $intentRoute['lane'],
                            ],
                            $source,
                        );

                        return response()->json(['data' => [
                            'status' => 'completed',
                            'session' => $ownedSession->refresh(),
                            'run' => $completed['run'],
                            'user_message' => $completed['user_message'],
                            'assistant_message' => $completed['assistant_message'],
                            'events' => $completed['events'],
                            'intent' => $intentRoute,
                        ]], 200);
                    }
                }

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
                    if ($this->sourcePrefersAsyncPendingFallback($source)) {
                        $existingRun = $this->existingClientRequestRun($ownedSession, $clientRequestId);
                        if ($existingRun instanceof AssistantRun) {
                            return $this->existingQueuedRunFallbackResponse($ownedSession, $existingRun, $exception, 'existing_message_queue_failed');
                        }

                        return $this->asyncPendingFallbackResponse(
                            $ownedSession->refresh(),
                            $existingUserMessage,
                            $exception,
                            'existing_message_queue_failed'
                        );
                    }

                    return $this->directRuntimeFallbackResponse(
                        $ownedSession->refresh(),
                        $existingUserMessage,
                        $exception,
                        'existing_message_queue_failed'
                    );
                }

                $run = $queued['run']->refresh();

                return response()->json(['data' => [
                    'status' => $run->status,
                    'session' => $ownedSession->refresh(),
                    'run' => $run,
                    'user_message' => $queued['user_message'],
                    'events' => $queued['events'] ?? [$queued['event']],
                ]], $this->runResponseStatus($run));
            }
        }

        $metadata = $data['metadata'] ?? [];
        $metadata['bean_intent'] = $intentRoute;
        $metadata['bean_intent_lane'] = $intentRoute['lane'];

        $supersedesClientRequestId = trim((string) data_get($metadata, 'supersedes_client_request_id', ''));
        if ($supersedesClientRequestId !== '') {
            try {
                $queued = $this->runs->queueSupersedingRun(
                    $ownedSession,
                    $supersedesClientRequestId,
                    $data['content'],
                    $metadata,
                    $source,
                );
            } catch (\DomainException $exception) {
                return response()->json([
                    'message' => $exception->getMessage(),
                    'code' => 'assistant_run_supersession_conflict',
                ], 409);
            }

            $run = $queued['run']->load(['session', 'userMessage', 'assistantMessage']);

            return response()->json(['data' => [
                ...$this->runResponsePayload($run, $ownedSession),
                'events' => $queued['events'] ?? [$queued['event']],
                'intent' => $intentRoute,
            ]], $this->runResponseStatus($run));
        }

        // Realtime voice corrections need a run-scoped cancellation token even for an
        // otherwise fast response. Direct/status voice replies never enter this endpoint.
        $voiceRequest = (bool) data_get($metadata, 'voice_request', false);
        if (! $voiceRequest && ! $this->intentRouter->shouldQueue($intentRoute)) {
            $userMessage = $this->findOrCreateQueuedFallbackUserMessage(
                $ownedSession->refresh(),
                $data['content'],
                $metadata
            );

            try {
                $result = $this->runtime->sendExistingMessage($ownedSession->refresh(), $userMessage);

                return response()->json(['data' => [
                    ...$result,
                    'run' => null,
                    'intent' => $intentRoute,
                ]], 201);
            } catch (Throwable $exception) {
                Log::warning('Fast routed Bean message failed; queueing background run.', [
                    'session_id' => $ownedSession->id,
                    'message_id' => $userMessage->id,
                    'lane' => $intentRoute['lane'],
                    'exception' => $exception->getMessage(),
                ]);
                $metadata['fast_route_fallback'] = true;
                $metadata['fast_route_fallback_reason'] = $exception->getMessage();
            }
        }

        try {
            $queued = $this->runs->queueRun(
                $ownedSession,
                $data['content'],
                $metadata,
                $source
            );
        } catch (Throwable $exception) {
            if ($this->sourcePrefersAsyncPendingFallback($source) && $clientRequestId !== '') {
                $existingRun = $this->existingClientRequestRun($ownedSession, $clientRequestId);
                if ($existingRun instanceof AssistantRun) {
                    return $this->existingQueuedRunFallbackResponse($ownedSession, $existingRun, $exception, 'queue_run_failed');
                }
            }

            $userMessage = $this->findOrCreateQueuedFallbackUserMessage(
                $ownedSession->refresh(),
                $data['content'],
                $metadata
            );

            if ($this->sourcePrefersAsyncPendingFallback($source)) {
                return $this->asyncPendingFallbackResponse(
                    $ownedSession->refresh(),
                    $userMessage,
                    $exception,
                    'queue_run_failed'
                );
            }

            return $this->directRuntimeFallbackResponse(
                $ownedSession->refresh(),
                $userMessage,
                $exception,
                'queue_run_failed'
            );
        }

        $run = $queued['run']->refresh();

        return response()->json(['data' => [
            'status' => $run->status,
            'session' => $ownedSession->refresh(),
            'run' => $run,
            'user_message' => $queued['user_message'],
            'events' => $queued['events'] ?? [$queued['event']],
            'intent' => $intentRoute,
        ]], $this->runResponseStatus($run));
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
            $intentRoute = $this->intentRouter->route($existingRun->input);
            if (($intentRoute['lane'] ?? null) === BeanIntentRouter::NEEDS_APP_READ
                && $existingRun->userMessage instanceof ConversationMessage) {
                $fastAnswer = $this->fastCalendarReads->resolve(
                    $ownedSession,
                    $existingRun->input,
                    is_array($existingRun->metadata) ? $existingRun->metadata : [],
                );
                if ($fastAnswer !== null) {
                    $completed = $this->runs->completeExistingMessageImmediately(
                        $ownedSession,
                        $existingRun->userMessage,
                        $fastAnswer,
                        [
                            ...(is_array($existingRun->metadata) ? $existingRun->metadata : []),
                            'bean_intent' => $intentRoute,
                            'bean_intent_lane' => $intentRoute['lane'],
                        ],
                        $existingRun->source,
                    );

                    return response()->json(
                        ['data' => $this->runResponsePayload($completed['run']->load(['session', 'userMessage', 'assistantMessage']), $ownedSession)],
                        200,
                    );
                }
            }

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

    private function existingClientRequestRun(ConversationSession $session, string $clientRequestId): ?AssistantRun
    {
        return AssistantRun::query()
            ->where('user_id', $session->user_id)
            ->where('conversation_session_id', $session->id)
            ->where('metadata->client_request_id', $clientRequestId)
            ->with(['session', 'userMessage', 'assistantMessage'])
            ->latest('id')
            ->first();
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

    private function sourcePrefersAsyncPendingFallback(string $source): bool
    {
        return in_array($source, [
            'flutter',
            'flutter_routed_chat',
            'web_queued_chat',
            'web_queued_voice',
            'web_routed_chat',
            'production_smoke',
        ], true);
    }

    private function asyncPendingFallbackResponse(
        ConversationSession $session,
        ConversationMessage $userMessage,
        Throwable $queueException,
        string $fallbackSource
    ): JsonResponse {
        Log::warning('Bean async run queue failed; returning pending state for client retry.', [
            'session_id' => $session->id,
            'message_id' => $userMessage->id,
            'fallback_source' => $fallbackSource,
            'exception' => $queueException->getMessage(),
        ]);

        $session->update([
            'status' => 'queued',
            'last_activity_at' => now(),
        ]);

        return response()->json(['data' => [
            'status' => 'queued',
            'session' => $session->refresh(),
            'run' => null,
            'user_message' => $userMessage->refresh(),
            'assistant_message' => null,
            'events' => [],
            'blocker' => null,
        ]], 202);
    }

    private function existingQueuedRunFallbackResponse(
        ConversationSession $session,
        AssistantRun $run,
        Throwable $queueException,
        string $fallbackSource
    ): JsonResponse {
        Log::warning('Bean async run queue returned after creating run; returning existing run for client polling.', [
            'session_id' => $session->id,
            'run_id' => $run->id,
            'message_id' => $run->user_message_id,
            'fallback_source' => $fallbackSource,
            'exception' => $queueException->getMessage(),
        ]);

        if (in_array($run->status, ['queued', 'running'], true)) {
            try {
                ProcessAssistantRun::dispatchAfterResponse($run->id);
            } catch (Throwable $dispatchException) {
                Log::warning('Bean async run redispatch failed after queue fallback.', [
                    'session_id' => $session->id,
                    'run_id' => $run->id,
                    'fallback_source' => $fallbackSource,
                    'exception' => $dispatchException->getMessage(),
                ]);
            }
        }

        $run = $this->runs->prepareRunForBackgroundResponse($run->refresh())
            ->load(['session', 'userMessage', 'assistantMessage']);

        return response()->json(
            ['data' => $this->runResponsePayload($run, $session)],
            $this->runResponseStatus($run)
        );
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
