<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class RealtimeVoiceSidebandRestartSignal
{
    private const CACHE_KEY = 'voice:realtime-sidebands:restart-signal';

    public function current(): string
    {
        return (string) Cache::get(self::CACHE_KEY, 'initial');
    }

    public function request(): string
    {
        $signal = now()->format('Y-m-d\TH:i:s.uP').':'.Str::uuid();
        Cache::forever(self::CACHE_KEY, $signal);

        return $signal;
    }
}
