<?php

namespace App\Enums;

enum VoiceRealtimeSessionStatus: string
{
    case Pending = 'pending';
    case Connecting = 'connecting';
    case Ready = 'ready';
    case Reconnecting = 'reconnecting';
    case Closing = 'closing';
    case Closed = 'closed';
    case Failed = 'failed';

    public function isTerminal(): bool
    {
        return in_array($this, [self::Closed, self::Failed], true);
    }

    public function mayConnect(): bool
    {
        return in_array($this, [self::Pending, self::Connecting, self::Ready, self::Reconnecting], true);
    }
}
