<?php

namespace App\Contracts;

interface RealtimeVoiceSidebandConnection
{
    public function send(string $payload): bool;

    public function close(int $code = 1000, string $reason = ''): void;
}
