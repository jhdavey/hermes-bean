<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Services\HermesRuntimeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ConversationMessageController extends Controller
{
    public function __construct(private readonly HermesRuntimeService $runtime) {}

    public function store(Request $request, string $session): JsonResponse
    {
        $ownedSession = ConversationSession::where('user_id', $request->user()->id)->findOrFail($session);

        $data = $request->validate([
            'content' => ['required', 'string'],
            'metadata' => ['nullable', 'array'],
        ]);

        $result = $this->runtime->sendMessage($ownedSession, $data['content'], $data['metadata'] ?? []);

        return response()->json(['data' => $result], $result['status'] === 'blocked' ? 429 : 201);
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

        $result = $this->runtime->sendMessage($ownedSession->refresh(), $data['content'], $metadata);

        return response()->json(['data' => $result], $result['status'] === 'blocked' ? 429 : 201);
    }
}
