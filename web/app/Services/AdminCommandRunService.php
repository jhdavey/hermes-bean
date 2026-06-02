<?php

namespace App\Services;

use App\Models\AdminCommandRun;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class AdminCommandRunService
{
    private const MAX_BUFFER_LENGTH = 240000;

    public function start(string $commandKey, ?User $actor = null): AdminCommandRun
    {
        $definition = $this->definition($commandKey);
        $run = AdminCommandRun::create([
            'user_id' => $actor?->id,
            'command_key' => $commandKey,
            'command_label' => $definition['label'],
            'command' => $definition['command'],
            'status' => 'queued',
            'metadata' => [
                'cwd' => $definition['cwd'],
                'timeout' => $definition['timeout'],
                'command_line' => $this->commandLine($definition['command']),
                'env_keys' => array_keys($definition['env']),
                'started_by_admin' => $actor?->email,
            ],
        ]);

        $this->launchWorker($run);

        return $run->refresh();
    }

    public function execute(AdminCommandRun $run): AdminCommandRun
    {
        if ($run->isTerminal()) {
            return $run;
        }

        $definition = $this->definition($run->command_key);
        $run->forceFill([
            'command_label' => $definition['label'],
            'command' => $definition['command'],
            'status' => 'running',
            'started_at' => now(),
            'metadata' => [
                ...($run->metadata ?? []),
                'cwd' => $definition['cwd'],
                'timeout' => $definition['timeout'],
                'command_line' => $this->commandLine($definition['command']),
                'env_keys' => array_keys($definition['env']),
                'worker_started_at' => now()->toIso8601String(),
            ],
        ])->save();

        $process = new Process($definition['command'], $definition['cwd'], $definition['env']);
        $process->setTimeout((float) $definition['timeout']);
        $exitCode = 1;
        $status = 'failed';

        try {
            $exitCode = $process->run(function (string $type, string $buffer) use ($run): void {
                $this->append($run, $type === Process::ERR ? 'error' : 'output', $buffer);
            });
            $status = $exitCode === 0 ? 'completed' : 'failed';
        } catch (ProcessTimedOutException $exception) {
            $status = 'timed_out';
            $this->append($run, 'error', PHP_EOL.$exception->getMessage().PHP_EOL);
        } catch (\Throwable $exception) {
            $status = 'failed';
            $this->append($run, 'error', PHP_EOL.$exception->getMessage().PHP_EOL);
        }

        $run->forceFill([
            'status' => $status,
            'exit_code' => $exitCode,
            'finished_at' => now(),
            'metadata' => [
                ...($run->metadata ?? []),
                'worker_finished_at' => now()->toIso8601String(),
            ],
        ])->save();

        Log::log($status === 'completed' ? 'info' : 'warning', 'Admin command run finished.', [
            'run_id' => $run->id,
            'command_key' => $run->command_key,
            'status' => $status,
            'exit_code' => $exitCode,
        ]);

        return $run->refresh();
    }

    public function payload(AdminCommandRun $run): array
    {
        return [
            'id' => $run->id,
            'command_key' => $run->command_key,
            'command_label' => $run->command_label,
            'command' => $run->command,
            'status' => $run->status,
            'exit_code' => $run->exit_code,
            'output' => (string) ($run->output ?? ''),
            'error' => (string) ($run->error ?? ''),
            'metadata' => $run->metadata ?? [],
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
        ];
    }

    private function definition(string $commandKey): array
    {
        return match ($commandKey) {
            'hermes_update' => [
                'label' => 'Hermes update',
                'command' => [$this->hermesCliPath(), 'update', '--yes'],
                'cwd' => $this->hermesWorkdir(),
                'timeout' => (float) config('services.hermes_runtime.update_timeout', 600),
                'env' => $this->hermesEnvironment(),
            ],
            default => throw new \InvalidArgumentException('Unsupported admin command: '.$commandKey),
        };
    }

    private function launchWorker(AdminCommandRun $run): void
    {
        if (app()->runningUnitTests()) {
            return;
        }

        $php = escapeshellarg(PHP_BINARY);
        $artisan = escapeshellarg(base_path('artisan'));
        $runId = escapeshellarg((string) $run->id);
        $command = "{$php} {$artisan} admin:run-command {$runId} > /dev/null 2>&1 &";
        exec($command);
    }

    private function commandLine(array $command): string
    {
        return implode(' ', array_map(fn (mixed $part): string => (string) $part, $command));
    }

    private function append(AdminCommandRun $run, string $column, string $buffer): void
    {
        $run->refresh();
        $existing = (string) ($run->getAttribute($column) ?? '');
        $next = $existing.$buffer;
        if (strlen($next) > self::MAX_BUFFER_LENGTH) {
            $next = "... output truncated ...\n".substr($next, -self::MAX_BUFFER_LENGTH);
        }

        $run->forceFill([$column => $next])->save();
    }

    private function hermesEnvironment(): array
    {
        return array_filter([
            'HERMES_USERS_HOME' => config('services.hermes_runtime.users_home'),
            'HERMES_BASE_HOME' => config('services.hermes_runtime.base_home'),
            'OPENAI_PUBLIC_KEY' => config('services.hermes_runtime.api_key'),
        ], fn (mixed $value): bool => is_string($value) && $value !== '');
    }

    private function hermesCliPath(): string
    {
        $path = trim((string) config('services.hermes_runtime.cli_path', 'hermes'));

        return $path !== '' ? $path : 'hermes';
    }

    private function hermesWorkdir(): string
    {
        $workdir = trim((string) config('services.hermes_runtime.cli_workdir', base_path()));

        return $workdir !== '' && is_dir($workdir) ? $workdir : base_path();
    }
}
