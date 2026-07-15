<?php

namespace App\Contracts;

use App\Models\VoiceRealtimeSession;
use Closure;
use React\Promise\PromiseInterface;

interface RealtimeVoiceSidebandTransport
{
    /**
     * @param  Closure(string): void  $onMessage
     * @param  Closure(int, string): void  $onClose
     * @param  Closure(\Throwable): void  $onError
     * @return PromiseInterface<RealtimeVoiceSidebandConnection>
     */
    public function connect(
        VoiceRealtimeSession $session,
        Closure $onMessage,
        Closure $onClose,
        Closure $onError,
    ): PromiseInterface;
}
