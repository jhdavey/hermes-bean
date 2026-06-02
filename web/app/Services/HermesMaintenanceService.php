<?php

namespace App\Services;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class HermesMaintenanceService
{
    public function status(): array
    {
        $result = $this->run(['--version'], timeout: 20);
        $output = trim($result['output']);

        return [
            'configured' => $result['exit_code'] === 0,
            'version' => $this->versionLabel($output),
            'version_output' => $output,
            'update_available' => str_contains(strtolower($output), 'update available'),
            'checked_at' => now()->toIso8601String(),
            'cli_path' => $this->cliPath(),
            'workdir' => $this->workdir(),
            'users_home' => (string) config('services.hermes_runtime.users_home', ''),
            'base_home' => (string) config('services.hermes_runtime.base_home', ''),
            'error' => $result['exit_code'] === 0 ? null : trim($result['error'] ?: $output),
        ];
    }

    public function update(): array
    {
        $before = $this->status();
        $result = $this->run(['update', '--yes'], timeout: (float) config('services.hermes_runtime.cli_timeout', 120));
        $after = $this->status();

        $logContext = [
            'exit_code' => $result['exit_code'],
            'cli_path' => $this->cliPath(),
            'workdir' => $this->workdir(),
            'users_home' => config('services.hermes_runtime.users_home'),
            'base_home' => config('services.hermes_runtime.base_home'),
        ];
        if ($result['exit_code'] !== 0) {
            $logContext['output'] = str($result['output'])->limit(4000)->toString();
            $logContext['error'] = str($result['error'])->limit(4000)->toString();
        }

        Log::log($result['exit_code'] === 0 ? 'info' : 'warning', 'Admin Hermes update executed.', $logContext);

        return [
            'status' => $result['exit_code'] === 0 ? 'completed' : 'failed',
            'before' => $before,
            'after' => $after,
            'output' => trim($result['output']),
            'error' => trim($result['error']),
            'exit_code' => $result['exit_code'],
            'ran_at' => now()->toIso8601String(),
        ];
    }

    private function run(array $arguments, float $timeout): array
    {
        $process = new Process([$this->cliPath(), ...$arguments], $this->workdir(), $this->environment());
        $process->setTimeout($timeout);

        try {
            $process->mustRun();
        } catch (ProcessFailedException $exception) {
            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'output' => $process->getOutput(),
                'error' => $process->getErrorOutput() ?: $exception->getMessage(),
            ];
        } catch (\Throwable $exception) {
            return [
                'exit_code' => 1,
                'output' => '',
                'error' => $exception->getMessage(),
            ];
        }

        return [
            'exit_code' => $process->getExitCode() ?? 0,
            'output' => $process->getOutput(),
            'error' => $process->getErrorOutput(),
        ];
    }

    private function environment(): array
    {
        return array_filter([
            'HERMES_USERS_HOME' => config('services.hermes_runtime.users_home'),
            'HERMES_BASE_HOME' => config('services.hermes_runtime.base_home'),
            'OPENAI_PUBLIC_KEY' => config('services.hermes_runtime.api_key'),
        ], fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function cliPath(): string
    {
        $path = trim((string) config('services.hermes_runtime.cli_path', 'hermes'));

        return $path !== '' ? $path : 'hermes';
    }

    private function workdir(): string
    {
        $workdir = trim((string) config('services.hermes_runtime.cli_workdir', base_path()));

        return $workdir !== '' && is_dir($workdir) ? $workdir : base_path();
    }

    private function versionLabel(string $output): ?string
    {
        $firstLine = trim(strtok($output, PHP_EOL) ?: '');

        return $firstLine !== '' ? $firstLine : null;
    }
}
