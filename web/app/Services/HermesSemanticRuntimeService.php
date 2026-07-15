<?php

namespace App\Services;

use App\Data\AssistantRunExecutionClaim;
use App\Data\HermesSemanticComposition;
use App\Data\HermesSemanticCompositionRequest;
use App\Data\HermesSemanticExecutionContext;
use App\Data\HermesSemanticInterpretation;
use App\Data\HermesSemanticInterpretationRequest;
use App\Enums\ConversationSessionKind;
use App\Exceptions\AssistantRunClaimLostException;
use App\Exceptions\HermesSemanticOperationException;
use App\Exceptions\HermesSemanticProviderException;
use App\Exceptions\HermesSemanticUsageLimitException;
use App\Models\ActivityEvent;
use App\Models\AssistantRun;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The generic chat adapter for the same Hermes semantic contract used by
 * voice. It owns no lifecycle state and persists no final response; those
 * responsibilities remain exclusively in AssistantRunService.
 */
class HermesSemanticRuntimeService implements HermesRuntimeService
{
    public function __construct(
        private readonly AgentProfileService $agentProfiles,
        private readonly WorkspaceService $workspaces,
        private readonly AssistantRunService $assistantRuns,
        private readonly HermesSemanticInterpreter $interpreter,
        private readonly HermesSemanticContextService $contexts,
        private readonly HermesSemanticOperationExecutor $operations,
    ) {}

    public function startSession(array $attributes = []): ConversationSession
    {
        return DB::transaction(function () use ($attributes): ConversationSession {
            $user = User::query()->findOrFail($attributes['user_id'] ?? auth()->id());
            $workspace = $this->workspaces->resolveWorkspace($user, $attributes['workspace_id'] ?? null);
            $profile = $this->agentProfiles->ensureForWorkspace($workspace, $user);
            $sessionKind = $this->agentProfiles->needsOnboarding($user, $profile)
                ? ConversationSessionKind::Onboarding
                : ConversationSessionKind::Conversation;

            $session = ConversationSession::query()->create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => $attributes['title'] ?? null,
                'status' => 'active',
                'session_kind' => $sessionKind,
                'metadata' => $attributes['metadata'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($session, 'runtime.session_started', [
                'workspace_id' => $workspace->id,
            ]);

            return $session->refresh();
        });
    }

    public function resumeSession(ConversationSession $session): ConversationSession
    {
        $session->update(['last_activity_at' => now()]);
        $this->recordEvent($session, 'runtime.session_resumed');

        return $session->refresh();
    }

    public function sendExistingMessage(
        ConversationSession $session,
        ConversationMessage $userMessage,
        AssistantRunExecutionClaim $claim,
    ): array {
        $this->assertClaimContext($session, $userMessage, $claim);
        $run = AssistantRun::query()
            ->whereKey($claim->runId)
            ->whereNull('voice_turn_id')
            ->firstOrFail();
        $user = User::query()->findOrFail($run->user_id);
        $metadata = is_array($run->metadata) ? $run->metadata : [];
        $clientContext = data_get($metadata, 'client_context');
        $clientContext = is_array($clientContext) ? $clientContext : [];
        $timezone = $this->trustedTimezone($clientContext);
        $trustedContext = $this->contexts->forAssistantRun($run);
        $executionContext = new HermesSemanticExecutionContext(
            user: $user,
            session: $session,
            stableRequestId: $run->client_request_id,
            timezone: $timezone,
            clientContext: $clientContext,
        );
        $events = collect([
            $this->recordClaimedEvent($claim, 'runtime.semantic_interpretation_started', [
                'message_id' => $userMessage->id,
                'model' => (string) config('services.hermes_runtime.semantic_interpretation_model'),
            ], 'hermes.semantic', 'started'),
        ]);

        try {
            [$interpretation, $sealedOperations] = $this->interpretAndValidate(
                $executionContext,
                $run,
                $userMessage,
                $claim,
                $trustedContext,
            );
        } catch (HermesSemanticUsageLimitException $exception) {
            return $this->terminalResult(
                status: 'blocked',
                session: $session,
                userMessage: $userMessage,
                content: $exception->userFacingText,
                events: $events,
                blocker: $exception->preflight,
            );
        }

        $events->push($this->recordClaimedEvent(
            $claim,
            'runtime.semantic_interpretation_completed',
            [
                'message_id' => $userMessage->id,
                'outcome' => $interpretation->outcome,
                'operation_count' => count($sealedOperations),
            ],
            'hermes.semantic',
            'succeeded',
        ));

        if ($interpretation->outcome === HermesSemanticInterpretation::OUTCOME_RESPOND) {
            return $this->terminalResult(
                status: 'completed',
                session: $session,
                userMessage: $userMessage,
                content: (string) $interpretation->responseText,
                events: $events,
            );
        }

        if ($interpretation->outcome === HermesSemanticInterpretation::OUTCOME_CLARIFY) {
            // Preserve Hermes's exact question. AssistantRunService will create
            // the one durable final message behind the current execution claim.
            return $this->terminalResult(
                status: 'completed',
                session: $session,
                userMessage: $userMessage,
                content: (string) $interpretation->clarificationQuestion,
                events: $events,
            );
        }

        $results = $this->operations->executeGenericPlan(
            $executionContext,
            $run,
            $claim,
            $sealedOperations,
        );
        $this->assertCurrentClaim($claim);
        $compositionRequest = new HermesSemanticCompositionRequest(
            user: $user,
            workspaceId: $run->workspace_id,
            stableTurnId: $run->client_request_id,
            transcript: (string) $userMessage->content,
            currentTime: now('UTC')->toIso8601String(),
            timezone: $timezone,
            interpretation: $interpretation,
            results: $results,
            context: $trustedContext,
            locale: $this->locale($metadata, $clientContext),
            conversationSessionId: $session->id,
            conversationMessageId: $userMessage->id,
        );
        $composition = $this->composeWithOneRetry($compositionRequest, $claim);
        $this->assertCurrentClaim($claim);

        $receiptEvents = ActivityEvent::query()
            ->where('conversation_session_id', $session->id)
            ->where('event_type', 'assistant.semantic_operation.receipt')
            ->where('payload->assistant_run_id', $run->id)
            ->orderBy('id')
            ->get();

        return $this->terminalResult(
            status: 'completed',
            session: $session,
            userMessage: $userMessage,
            content: $composition->responseText,
            events: $events->concat($receiptEvents),
        );
    }

    /**
     * @param  array<string,mixed>  $trustedContext
     * @return array{0:HermesSemanticInterpretation,1:list<array{id:string,tool:string,arguments:array<string,mixed>,dependencies:list<string>}>}
     */
    private function interpretAndValidate(
        HermesSemanticExecutionContext $executionContext,
        AssistantRun $run,
        ConversationMessage $userMessage,
        AssistantRunExecutionClaim $claim,
        array $trustedContext,
    ): array {
        $feedback = null;
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->assertCurrentClaim($claim);
            $requestContext = $feedback === null
                ? $trustedContext
                : [...$trustedContext, 'prior_interpretation_feedback' => $feedback];
            $request = new HermesSemanticInterpretationRequest(
                user: $executionContext->user,
                workspaceId: $run->workspace_id,
                stableTurnId: $run->client_request_id,
                transcript: (string) $userMessage->content,
                currentTime: now('UTC')->toIso8601String(),
                timezone: $executionContext->timezone,
                context: $requestContext,
                locale: $this->locale(
                    is_array($run->metadata) ? $run->metadata : [],
                    $executionContext->clientContext,
                ),
                conversationSessionId: $run->conversation_session_id,
                conversationMessageId: $userMessage->id,
            );

            try {
                $interpretation = $this->interpreter->interpret($request);
            } catch (HermesSemanticProviderException $exception) {
                if (! $exception->retriable || $attempt > 0) {
                    throw $exception;
                }
                $feedback = [
                    'kind' => 'provider_retry',
                    'instruction' => 'Interpret the same request again without changing its meaning.',
                ];

                continue;
            }
            $this->assertCurrentClaim($claim);

            if ($interpretation->outcome !== HermesSemanticInterpretation::OUTCOME_EXECUTE) {
                return [$interpretation, []];
            }

            try {
                $sealedOperations = $this->operations->prepareGenericPlan(
                    $executionContext,
                    $interpretation,
                    $trustedContext,
                );

                return [$interpretation, $sealedOperations];
            } catch (HermesSemanticOperationException $exception) {
                if ($exception->category !== 'invalid_semantic_operation' || $attempt > 0) {
                    throw $exception;
                }
                $feedback = [
                    'kind' => 'deterministic_validation_failure',
                    'detail' => mb_substr($exception->getMessage(), 0, 500),
                    'instruction' => 'Return a corrected schema-valid plan, or ask one specific clarification question if the target or required detail remains unresolved.',
                ];
            }
        }

        throw new HermesSemanticProviderException(
            category: 'invalid_output',
            internalDetail: 'Hermes did not return a valid interpretation after its one allowed retry.',
        );
    }

    private function composeWithOneRetry(
        HermesSemanticCompositionRequest $request,
        AssistantRunExecutionClaim $claim,
    ): HermesSemanticComposition {
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $this->assertCurrentClaim($claim);
            try {
                return $this->interpreter->compose($request);
            } catch (HermesSemanticProviderException $exception) {
                if (! $exception->retriable || $attempt > 0) {
                    throw $exception;
                }
            }
        }

        throw new HermesSemanticProviderException(
            category: 'invalid_output',
            internalDetail: 'Hermes did not compose a valid response after its one allowed retry.',
        );
    }

    private function assertCurrentClaim(AssistantRunExecutionClaim $claim): void
    {
        if (! $this->assistantRuns->executionClaimIsCurrent($claim)) {
            throw new AssistantRunClaimLostException;
        }
    }

    /** @param array<string,mixed> $clientContext */
    private function trustedTimezone(array $clientContext): ?string
    {
        foreach (['timezone', 'timezone_offset'] as $key) {
            $candidate = trim((string) ($clientContext[$key] ?? ''));
            if ($candidate === '') {
                continue;
            }
            try {
                new \DateTimeZone($candidate);

                return $candidate;
            } catch (\Throwable) {
                // Try the next explicit client field. Never substitute UTC.
            }
        }

        $localTime = trim((string) ($clientContext['current_local_time'] ?? ''));
        if (preg_match('/([+-](?:0\d|1\d|2[0-3]):[0-5]\d)$/', $localTime, $matches) === 1) {
            try {
                new \DateTimeZone($matches[1]);

                return $matches[1];
            } catch (\Throwable) {
                return null;
            }
        }

        return null;
    }

    /** @param array<string,mixed> $metadata @param array<string,mixed> $clientContext */
    private function locale(array $metadata, array $clientContext): string
    {
        $locale = trim((string) ($clientContext['locale'] ?? $metadata['locale'] ?? 'en-US'));

        return $locale !== '' ? mb_substr($locale, 0, 35) : 'en-US';
    }

    /**
     * @param  Collection<int,ActivityEvent>  $events
     * @param  array<string,mixed>|null  $blocker
     * @return array<string,mixed>
     */
    private function terminalResult(
        string $status,
        ConversationSession $session,
        ConversationMessage $userMessage,
        string $content,
        Collection $events,
        ?array $blocker = null,
    ): array {
        return [
            'status' => $status,
            'session' => $session->refresh(),
            'user_message' => $userMessage,
            'assistant_message' => null,
            'assistant_content' => $content,
            'events' => $events,
            'blocker' => $blocker,
        ];
    }

    private function recordClaimedEvent(
        AssistantRunExecutionClaim $claim,
        string $type,
        array $payload = [],
        ?string $toolName = null,
        string $status = 'recorded',
    ): ActivityEvent {
        return $this->assistantRuns->withExecutionClaim(
            $claim,
            fn (ConversationSession $session, AssistantRun $run): ActivityEvent => $this->recordEvent(
                $session,
                $type,
                [
                    ...$payload,
                    'assistant_run_id' => $run->id,
                    'execution_generation' => $claim->generation,
                ],
                $toolName,
                $status,
            ),
        );
    }

    private function recordEvent(
        ConversationSession $session,
        string $type,
        array $payload = [],
        ?string $toolName = null,
        string $status = 'recorded',
    ): ActivityEvent {
        return ActivityEvent::query()->create([
            'user_id' => $session->user_id,
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }

    private function assertClaimContext(
        ConversationSession $session,
        ConversationMessage $userMessage,
        AssistantRunExecutionClaim $claim,
    ): void {
        if ((int) $session->id !== $claim->sessionId
            || (int) $userMessage->id !== $claim->userMessageId
            || (int) $userMessage->conversation_session_id !== $claim->sessionId) {
            throw new AssistantRunClaimLostException;
        }
    }
}
