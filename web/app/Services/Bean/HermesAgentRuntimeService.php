<?php

namespace App\Services\Bean;

use App\Models\BeanConfirmationRequest;
use App\Models\BeanMessage;
use App\Models\BeanRun;
use App\Models\BeanSession;
use App\Models\User;
use App\Services\Bean\Quality\BeanQualityAuditService;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;
use Throwable;

class HermesAgentRuntimeService
{
    private const USER_FACING_FAILURE = 'I could not complete that request.';

    public function __construct(
        private readonly HermesUserHomeService $homes,
        private readonly BeanActivityLogger $activity,
        private readonly BeanTimeContext $timeContext,
    ) {}

    public function handleMessage(User $user, string $content, BeanSession $session, ?string $clientTimezone = null, ?string $source = null): array
    {
        [$home, $hermesSessionName, $hermesSessionId] = $this->homes->ensureForSession($session->refresh());
        $timeContext = $this->timeContext->forSession($session->refresh());

        $run = BeanRun::create([
            'bean_session_id' => $session->id,
            'user_id' => $user->id,
            'workspace_id' => $session->workspace_id,
            'status' => 'running',
            'mode' => 'hermes',
            'input' => $content,
            'metadata' => array_filter([
                'runtime_driver' => 'hermes',
                'hermes_home' => $home,
                'hermes_session_name' => $hermesSessionName,
                'hermes_session_id' => $hermesSessionId,
                'client_timezone' => $timeContext['timezone'],
                'source' => $source,
                'time_context' => $timeContext,
            ]),
            'started_at' => now(),
        ]);

        BeanMessage::create([
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'user_id' => $user->id,
            'role' => 'user',
            'content' => $content,
        ]);
        $this->activity->log($session, $run, 'user_message', $content);
        $this->activity->log($session, $run, 'status', 'Thinking...', ['mode' => 'thinking', 'runtime' => 'hermes']);

        try {
            $contextPath = $this->writeToolContext($home, $user, $session, $run, $clientTimezone);
            [$assistantText, $returnedHermesSessionId] = $this->invokeHermes($home, $hermesSessionId, $content, $contextPath, $source);
            $assistantText = trim($assistantText) !== '' ? trim($assistantText) : 'Done.';
            $assistantTextWasSanitized = $this->looksLikeInternalFailure($assistantText);
            if ($assistantTextWasSanitized) {
                Log::warning('Bean Hermes assistant response contained internal failure details and was sanitized.', [
                    'bean_run_id' => $run->id,
                    'bean_session_id' => $session->id,
                    'user_id' => $user->id,
                    'response' => mb_substr($assistantText, 0, 1000),
                ]);
                $assistantText = self::USER_FACING_FAILURE;
                $this->forgetHermesSessionId($session, $returnedHermesSessionId ?? $hermesSessionId);
                $session = $session->refresh();
                $hermesSessionId = null;
            }
            if (! $assistantTextWasSanitized && $returnedHermesSessionId !== null && $returnedHermesSessionId !== $hermesSessionId) {
                $hermesSessionId = $returnedHermesSessionId;
                $sessionMetadata = is_array($session->metadata) ? $session->metadata : [];
                $session->forceFill(['metadata' => [...$sessionMetadata, 'hermes_session_id' => $hermesSessionId]])->save();
                $session = $session->refresh();
            }

            BeanMessage::create([
                'bean_session_id' => $session->id,
                'bean_run_id' => $run->id,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $assistantText,
                'metadata' => [
                    'runtime_driver' => 'hermes',
                    'hermes_session_name' => $hermesSessionName,
                    'hermes_session_id' => $hermesSessionId,
                ],
            ]);
            $this->activity->log($session, $run, 'assistant_message', $assistantText, ['runtime' => 'hermes']);
            $this->activity->log($session, $run, 'status', 'Done', ['mode' => 'wake_listening', 'runtime' => 'hermes']);

            $waiting = $run->toolCalls()->where('status', 'waiting_confirmation')->exists();
            $metadata = is_array($run->metadata) ? $run->metadata : [];
            $run->update([
                'status' => $assistantTextWasSanitized ? 'failed' : ($waiting ? 'waiting_confirmation' : 'completed'),
                'model' => $this->modelLabel(),
                'output' => $assistantText,
                'metadata' => [...$metadata, 'hermes_session_id' => $hermesSessionId, 'tool_calls_count' => $run->toolCalls()->count()],
                'completed_at' => now(),
            ]);
        } catch (Throwable $exception) {
            Log::error('Bean Hermes runtime failed.', [
                'bean_run_id' => $run->id,
                'bean_session_id' => $session->id,
                'user_id' => $user->id,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $assistantText = self::USER_FACING_FAILURE;
            $run->update([
                'status' => 'failed',
                'model' => $this->modelLabel(),
                'error' => null,
                'output' => $assistantText,
                'completed_at' => now(),
            ]);
            BeanMessage::create([
                'bean_session_id' => $session->id,
                'bean_run_id' => $run->id,
                'user_id' => $user->id,
                'role' => 'assistant',
                'content' => $assistantText,
                'metadata' => ['runtime_driver' => 'hermes'],
            ]);
            $this->activity->log($session, $run, 'error', $assistantText, ['runtime' => 'hermes']);
        } finally {
            app(BeanQualityAuditService::class)->traceRun($run->refresh());
            $session->touch();
        }

        return [
            'session' => $session->refresh(),
            'run' => $run->refresh(),
            'messages' => $session->messages()->latest('id')->limit(20)->get()->reverse()->values(),
            'activity' => $session->activityEvents()->latest('id')->limit(50)->get()->reverse()->values(),
            'confirmations' => BeanConfirmationRequest::query()
                ->where('bean_session_id', $session->id)
                ->where('status', 'pending')
                ->latest('id')
                ->get(),
        ];
    }

    private function writeToolContext(string $home, User $user, BeanSession $session, BeanRun $run, ?string $clientTimezone): string
    {
        $payload = [
            'user_id' => $user->id,
            'bean_session_id' => $session->id,
            'bean_run_id' => $run->id,
            'workspace_id' => $session->workspace_id,
            'client_timezone' => $clientTimezone,
            'expires_at' => now()->addMinutes(30)->timestamp,
        ];
        $payload['signature'] = $this->signature($payload);

        $path = $home.'/tmp/bean-tool-context-'.$run->id.'.json';
        File::put($path, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $path;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function invokeHermes(string $home, ?string $sessionId, string $content, string $contextPath, ?string $source = null): array
    {
        $binary = (string) config('bean.hermes.binary', 'hermes');
        $toolsets = (string) config('bean.hermes.toolsets', 'bean_dashboard,skills,memory,session_search,web');
        $skills = (string) config('bean.hermes.skills', 'bean-dashboard');
        $timeout = (int) config('bean.hermes.timeout_seconds', 120);
        $maxTurns = (string) config('bean.hermes.max_turns', 24);
        $source = (string) config('bean.hermes.source', 'bean');
        $provider = (string) config('bean.hermes.provider', 'custom');
        $model = (string) config('bean.hermes.model', 'gpt-4.1-mini');

        $command = [
            $binary,
            'chat',
        ];
        if (is_string($sessionId) && $sessionId !== '') {
            array_push($command, '--resume', $sessionId);
        }
        array_push(
            $command,
            '--query',
            $content,
            '--quiet',
            '--source',
            $source,
            '--provider',
            $provider,
            '--model',
            $model,
            '--toolsets',
            $toolsets,
            '--skills',
            $skills,
            '--max-turns',
            $maxTurns,
        );

        $process = new Process($command, base_path(), $this->processEnv($home, $contextPath), null, $timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            $error = trim($process->getErrorOutput()) ?: trim($process->getOutput()) ?: 'Hermes exited with status '.$process->getExitCode();
            throw new \RuntimeException($error);
        }

        [$assistantText, $sessionIdFromStdout] = $this->parseHermesOutput($process->getOutput());
        [, $sessionIdFromStderr] = $this->parseHermesOutput($process->getErrorOutput());

        return [$assistantText, $sessionIdFromStdout ?? $sessionIdFromStderr];
    }

    private function looksLikeInternalFailure(string $text): bool
    {
        $lower = mb_strtolower($text);

        return preg_match('/\b(sqlstate|exception|stack trace|traceback|php-fpm|artisan|database|mysql|postgres|redis|server setup|internal problem|configuration|context issue|dashboard tool|tool failed|failed to start|exited with status|no such file|permission denied|timed out|timeout|connection refused)\b/u', $lower) === 1;
    }

    private function forgetHermesSessionId(BeanSession $session, ?string $failedHermesSessionId): void
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        unset($metadata['hermes_session_id']);
        if (is_string($failedHermesSessionId) && $failedHermesSessionId !== '') {
            $metadata['failed_hermes_session_id'] = $failedHermesSessionId;
            $metadata['failed_hermes_session_at'] = now()->toIso8601String();
        }
        $session->forceFill(['metadata' => $metadata])->save();
    }

    private function processEnv(string $home, string $contextPath): array
    {
        $path = collect([
            dirname((string) config('bean.hermes.binary', 'hermes')),
            getenv('HOME') ? getenv('HOME').'/.local/bin' : null,
            getenv('PATH') ?: null,
            '/usr/local/bin',
            '/usr/bin',
            '/bin',
        ])
            ->filter(fn ($value): bool => is_string($value) && $value !== '' && $value !== '.')
            ->unique()
            ->implode(PATH_SEPARATOR);

        $env = [
            'HERMES_HOME' => $home,
            'BEAN_TOOL_CONTEXT' => $contextPath,
            'BEAN_ARTISAN' => base_path('artisan'),
            'BEAN_PHP' => (string) config('bean.hermes.php_binary', 'php'),
            'BEAN_TOOL_TIMEOUT' => '60',
            'HERMES_ACCEPT_HOOKS' => '1',
            'PATH' => $path,
        ];

        $openAiKey = (string) config('services.openai.api_key');
        if ($openAiKey !== '') {
            $env['OPENAI_API_KEY'] = $openAiKey;
        }

        return $env;
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function parseHermesOutput(string $output): array
    {
        $sessionId = null;
        $lines = collect(preg_split('/\R/', trim($output)) ?: [])
            ->map(fn (string $line): string => trim($line))
            ->filter(function (string $line) use (&$sessionId): bool {
                if ($line === '') {
                    return false;
                }
                if (str_starts_with($line, '⚠ tirith security scanner enabled')) {
                    return false;
                }
                if (preg_match('/^(?:Session ID|session_id):\s*(\S+)/i', $line, $matches) === 1) {
                    $sessionId = $matches[1];

                    return false;
                }

                return true;
            })
            ->values();

        return [$lines->implode("\n"), $sessionId];
    }

    private function modelLabel(): string
    {
        return 'hermes:'.((string) config('bean.hermes.provider', 'custom')).'/'.((string) config('bean.hermes.model', 'gpt-4.1-mini'));
    }

    private function signature(array $payload): string
    {
        $signed = $payload;
        unset($signed['signature']);

        return hash_hmac('sha256', json_encode($signed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), (string) config('app.key'));
    }
}
