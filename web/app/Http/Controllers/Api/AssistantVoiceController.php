<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Services\AgentProfileService;
use App\Services\OpenAiVoiceService;
use App\Services\WorkspaceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class AssistantVoiceController extends Controller
{
    public function __construct(
        private readonly OpenAiVoiceService $voice,
        private readonly WorkspaceService $workspaces,
    ) {}

    public function voices(): JsonResponse
    {
        return response()->json(['data' => [
            'provider' => 'openai_realtime',
            'default_voice' => OpenAiVoiceService::DEFAULT_VOICE,
            'voices' => $this->voice->availableVoices(),
        ]]);
    }

    public function realtimeSession(Request $request): JsonResponse
    {
        $data = $request->validate([
            'workspace_id' => ['sometimes', 'nullable', 'integer', 'exists:workspaces,id'],
            'timezone' => ['sometimes', 'nullable', 'string', 'max:80'],
        ]);

        $workspace = $this->workspaces->resolveWorkspace($request->user(), $data['workspace_id'] ?? null);
        $profile = app(AgentProfileService::class)->ensureForWorkspace($workspace, $request->user());

        return response()->json(['data' => $this->voice->createRealtimeSession($profile, [
            'timezone' => $data['timezone'] ?? null,
        ])]);
    }

    public function realtimeTool(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'arguments' => ['sometimes', 'array'],
            'session_id' => ['sometimes', 'nullable', 'integer', 'exists:conversation_sessions,id'],
        ]);

        if ($data['name'] !== 'send_bean_request') {
            return response()->json(['message' => 'Unsupported realtime voice tool.'], 422);
        }

        $arguments = $data['arguments'] ?? [];
        $content = trim((string) data_get($arguments, 'request', ''));
        if ($content === '') {
            return response()->json(['message' => 'Realtime voice tool request is required.'], 422);
        }

        if (! empty($data['session_id'])) {
            ConversationSession::query()
                ->where('user_id', $request->user()->id)
                ->findOrFail($data['session_id']);
        }

        // The browser already owns the normal authenticated chat/run lifecycle and has the
        // active session id. This endpoint is intentionally a narrow validation boundary for
        // Realtime tool calls; the client then routes the approved request through /runs so
        // existing Laravel auth, workspace scoping, action execution, persistence, dashboard
        // refresh, and audit behavior remain the source of truth.
        return response()->json(['data' => [
            'name' => 'send_bean_request',
            'request' => $content,
            'approved' => true,
            'route' => 'assistant_runs',
        ]]);
    }

    public function realtimeEvents(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:120'],
            'events' => ['required', 'array', 'max:50'],
            'events.*' => ['array'],
        ]);

        Log::info('Bean voice orchestrator diagnostic.', [
            'user_id' => $request->user()->id,
            'reason' => $data['reason'],
            'events' => $data['events'],
        ]);

        return response()->json(['data' => ['accepted' => true]]);
    }

    public function realtimeTurn(Request $request): JsonResponse
    {
        $data = $request->validate([
            'session_id' => ['required', 'integer', 'exists:conversation_sessions,id'],
            'user_text' => ['required', 'string', 'max:12000'],
            'assistant_text' => ['sometimes', 'nullable', 'string', 'max:12000'],
            'outcome' => ['sometimes', 'string', 'in:accepted,completed,cancelled,interrupted,failed,timed_out,superseded'],
            'failure_reason' => ['sometimes', 'nullable', 'string', 'max:500'],
            'metadata' => ['sometimes', 'array'],
        ]);

        $session = ConversationSession::query()
            ->where('user_id', $request->user()->id)
            ->findOrFail($data['session_id']);

        $metadata = array_merge($data['metadata'] ?? [], [
            'source' => 'openai_realtime_voice',
            'voice_request' => true,
        ]);
        $assistantText = trim((string) ($data['assistant_text'] ?? ''));
        $requestedOutcome = strtolower(trim((string) ($data['outcome'] ?? '')));
        if ($requestedOutcome === '') {
            $requestedOutcome = $assistantText !== '' ? 'completed' : 'accepted';
        }
        if ($requestedOutcome === 'completed' && $assistantText === '') {
            throw ValidationException::withMessages([
                'assistant_text' => 'A completed voice turn requires assistant text.',
            ]);
        }
        if ($requestedOutcome !== 'completed' && $assistantText !== '') {
            throw ValidationException::withMessages([
                'assistant_text' => 'Assistant text may only be stored for a completed voice turn.',
            ]);
        }

        $clientTurnId = trim((string) (
            data_get($metadata, 'client_turn_id')
            ?: data_get($metadata, 'client_request_id')
        ));
        $clientTurnId = $clientTurnId !== '' ? mb_substr($clientTurnId, 0, 120) : null;
        if ($clientTurnId !== null) {
            $metadata['client_turn_id'] = $clientTurnId;
        }
        if ($clientTurnId === null && $requestedOutcome !== 'completed') {
            throw ValidationException::withMessages([
                'metadata.client_turn_id' => 'A stable client turn id is required before a voice turn completes.',
            ]);
        }

        [$userMessage, $assistantMessage, $effectiveOutcome] = DB::transaction(function () use ($request, $session, $metadata, $clientTurnId, $data, $assistantText, $requestedOutcome): array {
            $shared = [
                'user_id' => $request->user()->id,
                'conversation_session_id' => $session->id,
                'client_turn_id' => $clientTurnId,
            ];

            if ($clientTurnId === null) {
                $completedMetadata = $this->voiceTurnMetadata($metadata, [], 'completed', null);
                $session->update(['last_activity_at' => now()]);

                return [
                    ConversationMessage::create([
                        ...$shared,
                        'role' => 'user',
                        'content' => trim($data['user_text']),
                        'metadata' => $completedMetadata,
                    ]),
                    ConversationMessage::create([
                        ...$shared,
                        'role' => 'assistant',
                        'content' => $assistantText,
                        'metadata' => $completedMetadata,
                    ]),
                    'completed',
                ];
            }

            $userMessage = ConversationMessage::firstOrCreate([
                'conversation_session_id' => $session->id,
                'client_turn_id' => $clientTurnId,
                'role' => 'user',
            ], [
                'user_id' => $request->user()->id,
                'content' => trim($data['user_text']),
                'metadata' => $metadata,
            ]);
            $userWasRecentlyCreated = $userMessage->wasRecentlyCreated;
            $userMessage = ConversationMessage::query()
                ->whereKey($userMessage->id)
                ->lockForUpdate()
                ->firstOrFail();
            $userMessage->wasRecentlyCreated = $userWasRecentlyCreated;
            $assistantMessage = ConversationMessage::query()->where([
                'conversation_session_id' => $session->id,
                'client_turn_id' => $clientTurnId,
                'role' => 'assistant',
            ])->first();

            $existingMetadata = is_array($userMessage->metadata) ? $userMessage->metadata : [];
            $existingOutcome = strtolower(trim((string) data_get($existingMetadata, 'voice_turn_outcome.status', '')));
            $terminalOutcomes = ['completed', 'cancelled', 'interrupted', 'failed', 'timed_out', 'superseded', 'abandoned'];
            $terminalAlreadyRecorded = $assistantMessage instanceof ConversationMessage
                || in_array($existingOutcome, $terminalOutcomes, true);
            $effectiveOutcome = $assistantMessage instanceof ConversationMessage
                ? 'completed'
                : ($terminalAlreadyRecorded ? $existingOutcome : $requestedOutcome);

            if (! $terminalAlreadyRecorded) {
                $turnMetadata = $this->voiceTurnMetadata(
                    $metadata,
                    $existingMetadata,
                    $effectiveOutcome,
                    $data['failure_reason'] ?? null,
                );
                $userMessage->update(['metadata' => $turnMetadata]);
            } elseif ($assistantMessage instanceof ConversationMessage && $existingOutcome !== 'completed') {
                // Repair an inconsistent legacy pair without allowing a late request to
                // overwrite the first terminal turn's quality or lifecycle metadata.
                $turnMetadata = $this->voiceTurnMetadata([], $existingMetadata, 'completed', null);
                $userMessage->update(['metadata' => $turnMetadata]);
            } else {
                $turnMetadata = $existingMetadata;
            }

            if ($effectiveOutcome === 'completed' && $requestedOutcome === 'completed') {
                $assistantMessage = ConversationMessage::firstOrCreate([
                    'conversation_session_id' => $session->id,
                    'client_turn_id' => $clientTurnId,
                    'role' => 'assistant',
                ], [
                    'user_id' => $request->user()->id,
                    'content' => $assistantText,
                    'metadata' => $turnMetadata,
                ]);
            }

            $session->update(['last_activity_at' => now()]);

            return [$userMessage->refresh(), $assistantMessage?->refresh(), $effectiveOutcome];
        });

        return response()->json(['data' => [
            'session' => $session->refresh(),
            'user_message' => $userMessage,
            'assistant_message' => $assistantMessage,
            'outcome' => $effectiveOutcome,
        ]], $userMessage->wasRecentlyCreated || ($assistantMessage?->wasRecentlyCreated ?? false) ? 201 : 200);
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    private function voiceTurnMetadata(array $incoming, array $existing, string $outcome, ?string $failureReason): array
    {
        $now = now()->toIso8601String();
        $currentLifecycle = is_array(data_get($existing, 'voice_turn_outcome'))
            ? data_get($existing, 'voice_turn_outcome')
            : [];
        $lifecycle = array_merge($currentLifecycle, [
            'status' => $outcome,
            'accepted_at' => $currentLifecycle['accepted_at'] ?? $now,
            'updated_at' => $now,
        ]);
        if ($outcome !== 'accepted') {
            $lifecycle['terminal_at'] = $currentLifecycle['terminal_at'] ?? $now;
        }
        if ($failureReason !== null && trim($failureReason) !== '') {
            $lifecycle['reason'] = trim($failureReason);
        }

        $metadata = array_replace_recursive($existing, $incoming);
        $metadata['voice_turn_outcome'] = $lifecycle;

        return $metadata;
    }
}
