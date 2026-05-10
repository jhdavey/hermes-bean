<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\Blocker;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class HermesCliRuntimeService implements HermesRuntimeService
{
    public function startSession(array $attributes = []): ConversationSession
    {
        return DB::transaction(function () use ($attributes): ConversationSession {
            $session = ConversationSession::create([
                'user_id' => $attributes['user_id'] ?? auth()->id(),
                'title' => $attributes['title'] ?? null,
                'status' => 'active',
                'runtime_mode' => $attributes['runtime_mode'] ?? 'cli',
                'metadata' => $attributes['metadata'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($session, 'runtime.session_started', [
                'runtime_mode' => $session->runtime_mode,
            ]);

            return $session->refresh();
        });
    }

    public function resumeSession(ConversationSession $session): ConversationSession
    {
        $session->update(['last_activity_at' => now()]);

        $this->recordEvent($session, 'runtime.session_resumed');

        return $session->refresh();
    }

    public function progressEvents(ConversationSession $session): Collection
    {
        return $session->activityEvents()->orderBy('id')->get();
    }

    public function sendMessage(ConversationSession $session, string $content, array $metadata = []): array
    {
        [$userMessage, $received] = DB::transaction(function () use ($session, $content, $metadata): array {
            $userMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'user',
                'content' => $content,
                'metadata' => $metadata ?: null,
            ]);

            $received = $this->recordEvent($session, 'runtime.message_received', [
                'message_id' => $userMessage->id,
            ]);

            return [$userMessage, $received];
        });

        $cliPath = (string) config('services.hermes_runtime.cli_path', '');
        if ($cliPath === '' || ! is_file($cliPath) || ! is_executable($cliPath)) {
            return $this->failClosed($session, $userMessage, collect([$received]), 'Hermes CLI executable is not configured or is not executable.', [
                'failure_type' => 'missing_cli',
                'cli_path' => $cliPath,
            ]);
        }

        $command = [$cliPath];
        $profile = config('services.hermes_runtime.profile');
        if (filled($profile)) {
            $command[] = '--profile';
            $command[] = (string) $profile;
        }

        $started = $this->recordEvent($session, 'runtime.hermes_cli_started', [
            'message_id' => $userMessage->id,
            'command' => basename($cliPath),
            'workdir' => config('services.hermes_runtime.workdir'),
            'profile' => $profile,
        ], 'hermes.cli', 'started');

        $process = new Process(
            $command,
            $this->configuredWorkdir(),
            $this->configuredEnvironment(),
            $this->payloadFor($session, $userMessage),
            (float) config('services.hermes_runtime.timeout', 30)
        );

        try {
            $process->run();
        } catch (ProcessTimedOutException) {
            return $this->failClosed($session, $userMessage, collect([$received, $started]), 'Hermes CLI invocation timed out.', [
                'failure_type' => 'timeout',
                'timeout' => (float) config('services.hermes_runtime.timeout', 30),
            ]);
        }

        if (! $process->isSuccessful()) {
            return $this->failClosed($session, $userMessage, collect([$received, $started]), 'Hermes CLI invocation failed.', [
                'failure_type' => 'non_zero_exit',
                'exit_code' => $process->getExitCode(),
                'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
                'stdout' => mb_substr($process->getOutput(), 0, 2000),
            ]);
        }

        return DB::transaction(function () use ($session, $userMessage, $received, $started, $process): array {
            $assistantContent = $this->assistantContentFrom($process->getOutput());

            $completed = $this->recordEvent($session, 'runtime.hermes_cli_completed', [
                'message_id' => $userMessage->id,
                'stdout_bytes' => strlen($process->getOutput()),
                'stderr_bytes' => strlen($process->getErrorOutput()),
            ], 'hermes.cli', 'succeeded');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $assistantContent,
                'metadata' => [
                    'runtime' => 'cli',
                    'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => collect([$received, $started, $completed, $messageCompleted]),
                'blocker' => null,
            ];
        });
    }

    private function configuredWorkdir(): ?string
    {
        $workdir = config('services.hermes_runtime.workdir');

        return filled($workdir) ? (string) $workdir : null;
    }

    private function configuredEnvironment(): array
    {
        $environment = [
            'HERMES_RUNTIME_MODE' => 'cli',
            'APP_ENV' => (string) app()->environment(),
        ];

        if (env('PATH')) {
            $environment['PATH'] = env('PATH');
        }

        $configured = config('services.hermes_runtime.environment', []);
        if (is_array($configured)) {
            foreach ($configured as $key => $value) {
                if (is_string($key) && str_starts_with($key, 'HERMES_')) {
                    $environment[$key] = (string) $value;
                }
            }
        }

        return $environment;
    }

    private function payloadFor(ConversationSession $session, ConversationMessage $message): string
    {
        $user = User::find($session->user_id);

        return json_encode([
            'session' => [
                'id' => $session->id,
                'title' => $session->title,
                'runtime_mode' => $session->runtime_mode,
                'metadata' => $session->metadata,
            ],
            'user' => [
                'id' => $user?->id,
                'email' => $user?->email,
                'name' => $user?->name,
            ],
            'message' => [
                'id' => $message->id,
                'content' => $message->content,
                'metadata' => $message->metadata,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function assistantContentFrom(string $stdout): string
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return '';
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                foreach (['content', 'message', 'assistant_message', 'response'] as $key) {
                    if (isset($decoded[$key]) && is_string($decoded[$key])) {
                        return $decoded[$key];
                    }
                }
            }
        } catch (\JsonException) {
            // Plain-text CLI output is a valid assistant response.
        }

        return $trimmed;
    }

    private function failClosed(ConversationSession $session, ConversationMessage $userMessage, Collection $events, string $reason, array $context): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events, $reason, $context): array {
            $failed = $this->recordEvent($session, 'runtime.hermes_cli_failed', [
                'message_id' => $userMessage->id,
                'reason' => $reason,
                ...$context,
            ], 'hermes.cli', 'failed');

            $blocker = Blocker::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'reason' => $reason,
                'status' => 'open',
                'context' => [
                    'message_id' => $userMessage->id,
                    ...$context,
                ],
            ]);

            $blocked = $this->recordEvent($session, 'runtime.blocked', [
                'blocker_id' => $blocker->id,
                'reason' => $blocker->reason,
            ]);

            $session->update(['status' => 'blocked', 'last_activity_at' => now()]);

            return [
                'status' => 'blocked',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => null,
                'events' => $events->push($failed)->push($blocked),
                'blocker' => $blocker,
            ];
        });
    }

    private function recordEvent(ConversationSession $session, string $type, array $payload = [], ?string $toolName = null, string $status = 'recorded'): ActivityEvent
    {
        return ActivityEvent::create([
            'user_id' => $session->user_id,
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }
}
