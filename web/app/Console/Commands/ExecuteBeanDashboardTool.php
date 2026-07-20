<?php

namespace App\Console\Commands;

use App\Services\Bean\BeanDashboardToolBridgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class ExecuteBeanDashboardTool extends Command
{
    protected $signature = 'bean:dashboard-tool {context : Path to the signed Bean tool context JSON}';

    protected $description = 'Execute a scoped Bean dashboard tool call for a per-user Hermes agent.';

    public function handle(BeanDashboardToolBridgeService $bridge): int
    {
        $contextPath = (string) $this->argument('context');
        $context = $this->readJsonFile($contextPath);
        if (! is_array($context)) {
            return $this->failJson('Invalid Bean tool context.');
        }

        $payload = json_decode((string) file_get_contents('php://stdin'), true);
        if (! is_array($payload)) {
            return $this->failJson('Invalid Bean tool payload.');
        }

        $result = $bridge->execute($context, $payload);
        $this->line(json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function readJsonFile(string $path): mixed
    {
        if ($path === '' || ! File::exists($path)) {
            return null;
        }

        return json_decode((string) File::get($path), true);
    }

    private function failJson(string $message): int
    {
        $this->error(json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return self::FAILURE;
    }
}
