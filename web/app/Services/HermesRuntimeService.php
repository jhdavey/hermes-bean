<?php

namespace App\Services;

use App\Models\ConversationSession;

interface HermesRuntimeService
{
    public function startSession(array $attributes = []): ConversationSession;

    public function resumeSession(ConversationSession $session): ConversationSession;

    /**
     * @return array{status:string, session:ConversationSession, user_message:mixed, assistant_message:mixed|null, events:mixed, blocker:mixed|null}
     */
    public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array;
}
