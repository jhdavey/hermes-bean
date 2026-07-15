<?php

namespace App\Services;

use App\Data\AssistantRunExecutionClaim;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;

interface HermesRuntimeService
{
    public function startSession(array $attributes = []): ConversationSession;

    public function resumeSession(ConversationSession $session): ConversationSession;

    /**
     * @return array{status:string, session:ConversationSession, user_message:mixed, assistant_message:mixed|null, assistant_content?:string, events:mixed, blocker:mixed|null}
     */
    public function sendExistingMessage(
        ConversationSession $session,
        ConversationMessage $userMessage,
        AssistantRunExecutionClaim $claim,
    ): array;
}
