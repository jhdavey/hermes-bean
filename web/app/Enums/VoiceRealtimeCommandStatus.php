<?php

namespace App\Enums;

enum VoiceRealtimeCommandStatus: string
{
    case Queued = 'queued';
    case Sending = 'sending';
    case Sent = 'sent';
    case Acknowledged = 'acknowledged';
    case Failed = 'failed';
    case Canceled = 'canceled';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Acknowledged, self::Failed, self::Canceled], true);
    }
}
