<?php

namespace App\Console\Commands;

use App\Models\AdminCommandRun;
use App\Services\AdminCommandRunService;
use Illuminate\Console\Command;

class RunAdminCommand extends Command
{
    protected $signature = 'admin:run-command {run : Admin command run id}';

    protected $description = 'Execute an allowlisted admin command run and stream output to storage.';

    public function handle(AdminCommandRunService $commands): int
    {
        $run = AdminCommandRun::find((int) $this->argument('run'));
        if (! $run) {
            $this->error('Admin command run not found.');

            return self::FAILURE;
        }

        $result = $commands->execute($run);

        return $result->status === 'completed' ? self::SUCCESS : self::FAILURE;
    }
}
