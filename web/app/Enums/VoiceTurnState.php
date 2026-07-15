<?php

namespace App\Enums;

enum VoiceTurnState: string
{
    case AwaitingClarification = 'awaiting_clarification';
    case Accepted = 'accepted';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Failed, self::Canceled], true);
    }
}
