<?php

namespace App\Console\Commands;

use App\Services\RealtimeVoiceSidebandRestartSignal;
use Illuminate\Console\Command;

class RestartRealtimeVoiceSidebands extends Command
{
    protected $signature = 'voice:realtime-sidebands-restart';

    protected $description = 'Signal all realtime voice sideband daemons to reconnect through durable leases';

    public function handle(RealtimeVoiceSidebandRestartSignal $restart): int
    {
        $restart->request();
        $this->info('Realtime voice sideband restart requested.');

        return self::SUCCESS;
    }
}
