<?php

namespace App\Services;

use App\Contracts\RealtimeVoiceSidebandConnection;
use Ratchet\Client\WebSocket;

class PawlRealtimeVoiceSidebandConnection implements RealtimeVoiceSidebandConnection
{
    public function __construct(
        private readonly WebSocket $socket,
    ) {}

    public function send(string $payload): bool
    {
        return $this->socket->send($payload);
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        $this->socket->close($code, mb_substr($reason, 0, 120));
    }
}
