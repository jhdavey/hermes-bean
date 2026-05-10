<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\ConversationSession;
use Illuminate\Support\Collection;

interface HermesRuntimeService
{
    public function startSession(array $attributes = []): ConversationSession;

    public function resumeSession(ConversationSession $session): ConversationSession;

    /**
     * @return Collection<int, ActivityEvent>
     */
    public function progressEvents(ConversationSession $session): Collection;

    /**
     * @return array{status:string, session:ConversationSession, user_message:mixed, assistant_message:mixed|null, events:mixed, blocker:mixed|null}
     */
    public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array;
}
