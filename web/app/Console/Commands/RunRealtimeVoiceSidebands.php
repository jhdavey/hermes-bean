<?php

namespace App\Console\Commands;

use App\Services\RealtimeVoiceSidebandDaemon;
use Illuminate\Console\Command;

class RunRealtimeVoiceSidebands extends Command
{
    protected $signature = 'voice:realtime-sidebands {--once : Run one bounded connection/command pass}';

    protected $description = 'Maintain leased server-side connections to active OpenAI Realtime voice calls';

    public function handle(RealtimeVoiceSidebandDaemon $daemon): int
    {
        $daemon->run((bool) $this->option('once'));

        return self::SUCCESS;
    }
}
