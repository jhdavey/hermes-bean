<?php

namespace App\Services;

use App\Enums\ConversationSessionKind;
use App\Models\ConversationSession;
use App\Models\User;

class WelcomeConversationService
{
    public function ensureForUser(User $user): ConversationSession
    {
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);

        return ConversationSession::firstOrCreate(
            [
                'user_id' => $user->id,
                'title' => 'Welcome to Bean',
            ],
            [
                'workspace_id' => $workspaceId,
                'created_by_user_id' => $user->id,
                'status' => 'active',
                'session_kind' => ConversationSessionKind::Onboarding,
                'last_activity_at' => now(),
                'metadata' => ['created_from' => 'welcome_conversation_v1'],
            ]
        );
    }
}
