<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class HermesCliRuntimeService implements HermesRuntimeService
{
    public function __construct(private readonly StructuredHermesActionService $actionService) {}

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

        $command = $this->commandFor($cliPath, $session, $userMessage);
        $profile = config('services.hermes_runtime.profile');

        $started = $this->recordEvent($session, 'runtime.hermes_cli_started', [
            'message_id' => $userMessage->id,
            'command' => basename($cliPath),
            'workdir' => config('services.hermes_runtime.workdir'),
            'profile' => $profile,
        ], 'hermes.cli', 'started');

        $process = new Process(
            $command,
            $this->configuredWorkdir(),
            $this->configuredEnvironment($session),
            null,
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
            $structuredOutput = $this->structuredOutputFrom($process->getOutput());
            $assistantContent = $this->assistantContentFrom($process->getOutput(), $structuredOutput);

            $completed = $this->recordEvent($session, 'runtime.hermes_cli_completed', [
                'message_id' => $userMessage->id,
                'stdout_bytes' => strlen($process->getOutput()),
                'stderr_bytes' => strlen($process->getErrorOutput()),
            ], 'hermes.cli', 'succeeded');

            $domainEvents = $structuredOutput ? $this->actionService->applyEnvelope($session, $structuredOutput) : collect();

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
                'events' => collect([$received, $started, $completed])->concat($domainEvents)->push($messageCompleted),
                'blocker' => null,
            ];
        });
    }

    private function commandFor(string $cliPath, ConversationSession $session, ConversationMessage $message): array
    {
        $command = [$cliPath];
        $profile = config('services.hermes_runtime.profile');
        if (filled($profile)) {
            $command[] = '--profile';
            $command[] = (string) $profile;
        }

        $command[] = 'chat';
        $command[] = '-q';
        $command[] = $this->promptFor($session, $message);
        $command[] = '-Q';

        $provider = config('services.hermes_runtime.default_provider');
        if (filled($provider)) {
            $command[] = '--provider';
            $command[] = (string) $provider;
        }

        $model = config('services.hermes_runtime.default_model');
        if (filled($model)) {
            $command[] = '-m';
            $command[] = (string) $model;
        }

        return $command;
    }

    private function configuredWorkdir(): ?string
    {
        $workdir = config('services.hermes_runtime.workdir');

        return filled($workdir) ? (string) $workdir : null;
    }

    private function configuredEnvironment(ConversationSession $session): array
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

        $profile = AgentProfile::where('user_id', $session->user_id)->first();
        if ($profile?->runtime_home) {
            $environment['HERMES_HOME'] = (string) $profile->runtime_home;
        }

        return $environment;
    }

    private function promptFor(ConversationSession $session, ConversationMessage $message): string
    {
        return <<<'PROMPT'
You are the server-hosted Hermes runtime for Hey Bean. Respond only as strict JSON, with no markdown or prose outside the JSON object.

Schema:
{
  "message": "short user-facing summary",
  "actions": [
    {
      "type": "task.create|task.update|task.delete|reminder.create|reminder.update|reminder.delete|calendar_event.create|calendar_event.update|calendar_event.delete|approval.create|approval.update|approval.approve|approval.deny|approval.delete|blocker.create|blocker.update|blocker.resolve|blocker.delete|scheduler_job.create|scheduler_job.update|scheduler_job.delete|agent_profile.update|conversation_session.update|activity_event.create|email.send|payment.create|deployment.run|account.delete",
      "risk": "low|medium|high",
      "title": "approval title for risky actions",
      "description": "optional approval description",
      "parameters": {}
    }
  ]
}

Rules:
- You are allowed to complete complex multi-step app-control requests by emitting multiple ordered actions in one response.
- Low-risk internal dashboard CRUD/control actions may be emitted with risk "low": tasks, reminders, calendar events, approvals, blockers, scheduler job records, agent profile settings, conversation session metadata, and activity events.
- Existing dashboard resources are listed in dashboard_state; use their numeric id when updating, deleting, approving, denying, or resolving them.
- Risky external, destructive outside the dashboard, mail, payment, deployment, and account actions must be emitted with risk "high" so the app queues an approval.
- If no concrete action is needed, return an empty actions array.
- Use ISO-8601 timestamps for dates when you create or update reminders, calendar events, and scheduler jobs.
- For user-facing requests to schedule, plan, book, block time, add an appointment, create a reminder, or create a task: emit visible dashboard resources (`calendar_event.*`, `reminder.*`, and/or `task.*`) so the item appears in `/api/today`. Do not use `scheduler_job.*` for ordinary user calendar/reminder/task planning.
- Only use `scheduler_job.*` for internal/background agent automation. If you do emit `scheduler_job.create` with `scheduled_for` for a user-visible scheduled plan, the app will mirror it to a calendar event unless `parameters.kind` or `parameters.payload.kind` is `internal_automation`, `background_job`, or `system_job`.
- Calendar event parameters support `title`, `description`, `location`, `category`, `color`, `recurrence`, `starts_at`, `ends_at`, `status`, and `metadata`. Reminder metadata can include recurrence details. Task due dates use `due_at`.
- If the user asks for a named event on multiple weekdays without explicitly saying recurring, weekly, every week, repeats, or recurrence: create one-off calendar events for the next matching days in the current week, then ask a follow-up about whether it should recur. Only set recurrence metadata when the user explicitly requests recurrence.

Runtime payload:
PROMPT.$this->payloadFor($session, $message);
    }

    private function payloadFor(ConversationSession $session, ConversationMessage $message): string
    {
        $user = User::find($session->user_id);
        $profile = AgentProfile::where('user_id', $session->user_id)->first();

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
            'agent_profile' => $profile ? [
                'id' => $profile->id,
                'slug' => $profile->slug,
                'provider' => $profile->provider,
                'model' => $profile->model,
                'runtime_home' => $profile->runtime_home,
                'tool_policy' => $profile->tool_policy,
                'approval_policy' => $profile->approval_policy,
            ] : null,
            'dashboard_state' => [
                'tasks' => Task::where('user_id', $session->user_id)->latest('updated_at')->limit(50)->get(['id', 'title', 'type', 'status', 'notes', 'due_at', 'metadata']),
                'reminders' => Reminder::where('user_id', $session->user_id)->latest('updated_at')->limit(50)->get(['id', 'title', 'notes', 'status', 'remind_at', 'metadata']),
                'calendar_events' => CalendarEvent::where('user_id', $session->user_id)->latest('updated_at')->limit(50)->get(['id', 'title', 'description', 'location', 'status', 'starts_at', 'ends_at', 'metadata']),
                'approvals' => Approval::where('user_id', $session->user_id)->latest('updated_at')->limit(50)->get(['id', 'title', 'status', 'description', 'payload']),
                'blockers' => Blocker::where('user_id', $session->user_id)->latest('updated_at')->limit(50)->get(['id', 'reason', 'status', 'context']),
            ],
            'allowed_action_schema' => [
                'low_risk' => [
                    'task.create', 'task.update', 'task.delete',
                    'reminder.create', 'reminder.update', 'reminder.delete',
                    'calendar_event.create', 'calendar_event.update', 'calendar_event.delete',
                    'approval.create', 'approval.update', 'approval.approve', 'approval.deny', 'approval.delete',
                    'blocker.create', 'blocker.update', 'blocker.resolve', 'blocker.delete',
                    'scheduler_job.create', 'scheduler_job.update', 'scheduler_job.delete',
                    'agent_profile.update', 'conversation_session.update', 'activity_event.create',
                ],
                'approval_required' => ['email.send', 'payment.create', 'deployment.run', 'account.delete', 'destructive_actions_outside_dashboard'],
            ],
            'message' => [
                'id' => $message->id,
                'content' => $message->content,
                'metadata' => $message->metadata,
            ],
        ], JSON_THROW_ON_ERROR);
    }

    private function structuredOutputFrom(string $stdout): ?array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return null;
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        if (! is_array($decoded)) {
            return null;
        }

        foreach (['content', 'message', 'assistant_message', 'response'] as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                $nested = trim($decoded[$key]);
                if ($nested === '') {
                    continue;
                }

                try {
                    $nestedDecoded = json_decode($nested, true, flags: JSON_THROW_ON_ERROR);
                } catch (\JsonException) {
                    continue;
                }

                if (is_array($nestedDecoded) && array_key_exists('actions', $nestedDecoded)) {
                    return $nestedDecoded;
                }
            }
        }

        return $decoded;
    }

    private function assistantContentFrom(string $stdout, ?array $structuredOutput = null): string
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return '';
        }

        $decoded = $structuredOutput;
        if ($decoded === null) {
            try {
                $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                return $trimmed;
            }
        }

        if (is_array($decoded)) {
            foreach (['content', 'message', 'assistant_message', 'response'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key])) {
                    return $this->normalizeAssistantContent($decoded[$key]);
                }
            }
        }

        return $this->normalizeAssistantContent($trimmed);
    }

    private function normalizeAssistantContent(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $trimmed;
        }

        if (is_array($decoded)) {
            foreach (['content', 'message', 'assistant_message', 'response'] as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                    return $this->normalizeAssistantContent($decoded[$key]);
                }
            }
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
