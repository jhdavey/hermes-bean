<?php

namespace App\Enums;

enum ConversationSessionKind: string
{
    case Conversation = 'conversation';
    case Onboarding = 'onboarding';
}
