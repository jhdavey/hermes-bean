<?php

namespace App\Services;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\Approval;
use App\Models\Blocker;
use App\Models\CalendarEvent;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\EventCategory;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

class HermesCliRuntimeService implements HermesRuntimeService
{
    public function __construct(
        private readonly StructuredHermesActionService $actionService,
        private readonly AgentProfileService $agentProfileService,
        private readonly WorkspaceService $workspaceService,
    ) {}

    public function startSession(array $attributes = []): ConversationSession
    {
        return DB::transaction(function () use ($attributes): ConversationSession {
            $user = User::findOrFail($attributes['user_id'] ?? auth()->id());
            $workspace = $this->workspaceService->resolveWorkspace($user, $attributes['workspace_id'] ?? null);
            $this->agentProfileService->ensureForWorkspace($workspace, $user);

            $session = ConversationSession::create([
                'user_id' => $user->id,
                'workspace_id' => $workspace->id,
                'created_by_user_id' => $user->id,
                'title' => $attributes['title'] ?? null,
                'status' => 'active',
                'runtime_mode' => $attributes['runtime_mode'] ?? 'cli',
                'metadata' => $attributes['metadata'] ?? null,
                'last_activity_at' => now(),
            ]);

            $this->recordEvent($session, 'runtime.session_started', [
                'runtime_mode' => $session->runtime_mode,
                'workspace_id' => $workspace->id,
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

    public function cancelSession(ConversationSession $session): ConversationSession
    {
        if (in_array($session->status, ['running', 'cancelling'], true)) {
            $session->update(['status' => 'cancelling', 'last_activity_at' => now()]);

            $this->recordEvent($session, 'runtime.cancel_requested');
        }

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
        $session->update(['status' => 'running', 'last_activity_at' => now()]);

        $process = new Process(
            $command,
            $this->configuredWorkdir(),
            $this->configuredEnvironment($session),
            null,
            (float) config('services.hermes_runtime.timeout', 30)
        );

        try {
            $process->start();
            while ($process->isRunning()) {
                $process->checkTimeout();
                $session->refresh();
                if ($session->status === 'cancelling') {
                    $process->stop(0.2);

                    return $this->cancelled($session, $userMessage, collect([$received, $started]));
                }
                usleep(100_000);
            }
        } catch (ProcessTimedOutException) {
            return $this->failClosed($session, $userMessage, collect([$received, $started]), 'Hermes CLI invocation timed out.', [
                'failure_type' => 'timeout',
                'timeout' => (float) config('services.hermes_runtime.timeout', 30),
            ]);
        }

        $session->refresh();
        if ($session->status === 'cancelling') {
            return $this->cancelled($session, $userMessage, collect([$received, $started]));
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

    private function cancelled(ConversationSession $session, ConversationMessage $userMessage, Collection $events): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events): array {
            $cancelled = $this->recordEvent($session, 'runtime.hermes_cli_cancelled', [
                'message_id' => $userMessage->id,
            ], 'hermes.cli', 'cancelled');

            $messageCancelled = $this->recordEvent($session, 'runtime.message_cancelled', [
                'message_id' => $userMessage->id,
            ]);

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'cancelled',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => null,
                'events' => $events->push($cancelled)->push($messageCancelled),
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

        $profile = $this->profileForSession($session);
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
      "type": "task.create|task.update|task.delete|reminder.create|reminder.update|reminder.delete|calendar_event.create|calendar_event.update|calendar_event.delete|event_category.create|event_category.update|event_category.delete|approval.create|approval.update|approval.approve|approval.deny|approval.delete|blocker.create|blocker.update|blocker.resolve|blocker.delete|scheduler_job.create|scheduler_job.update|scheduler_job.delete|agent_profile.update|workspace_memory.note|conversation_session.update|activity_event.create|email.send|payment.create|deployment.run|account.delete",
      "risk": "low|medium|high",
      "title": "approval title for risky actions",
      "description": "optional approval description",
      "parameters": {}
    }
  ]
}

Rules:
- You are allowed to complete complex multi-step app-control requests by emitting multiple ordered actions in one response.
- Low-risk internal dashboard CRUD/control actions may be emitted with risk "low": tasks, reminders, calendar events, event categories, approvals, blockers, scheduler job records, agent profile settings, conversation session metadata, and activity events.
- Existing dashboard resources are listed in dashboard_state; use their numeric id when updating, deleting, approving, denying, or resolving them.
- When the user explicitly asks to create/update/delete an item in another accessible workspace, include `parameters.workspace_id` or `parameters.target_workspace_id` with that workspace id from `accessible_sync_targets`; otherwise actions apply to the current session workspace.
- Use `workspace_memory.note` only when the user clearly asks you to remember a durable fact for a named accessible workspace. Include `parameters.workspace_id`/`target_workspace_id` and `parameters.note`.
- Risky external, destructive outside the dashboard, mail, payment, deployment, and account actions must be emitted with risk "high" so the app queues an approval.
- If no concrete action is needed, return an empty actions array.
- If `user.onboard_complete` is false or `agent_profile.settings.onboarding.completed` is false, treat the conversation as Bean onboarding: ask the user to introduce themself, then ask follow-up questions to learn preferred Bean style/personality, top priorities, and any useful life/context constraints. When the user provides enough onboarding/preferences, emit a low-risk `agent_profile.update` action with `parameters.settings.personality_type`, `parameters.settings.onboarding.completed: true`, `parameters.settings.onboarding.priorities`, and `parameters.settings.onboarding.context` so the app saves settings and updates Bean memory.
- When the user changes Bean preferences from Settings, the message metadata includes `settings_update: true`; acknowledge the update and emit a low-risk `agent_profile.update` action with the supplied preferences so runtime memory stays synchronized.
- Adapt your tone to `agent_profile.settings.personality_prompt` when present, and use `agent_profile.settings.onboarding.priorities` plus `agent_profile.settings.onboarding.context` and `agent_profile.settings.memory.user_preferences.summary` to understand what the user cares about.
- Workspace memory files live under `agent_profile.runtime_home` and are isolated per workspace. Use USER.md, MEMORY.md, HOUSEHOLD.md, and PREFERENCES.md for durable markdown notes when file tools are available; keep notes concise and declarative.
- In Personal workspaces, save only the signed-in user's personal preferences/routines. In household workspaces, save shared household routines and household-relevant member preferences. Never copy memory between Personal and households or between households unless the user explicitly asks.
- App-managed sections in those markdown files are wrapped in `HERMES-BEAN MANAGED` comments; preserve those sections and add agent-learned facts under the non-managed headings.
- Use ISO-8601 timestamps for dates when you create or update reminders, calendar events, and scheduler jobs. For relative dates/times such as "today", "tomorrow", "later", or "1:45 PM", use `temporal_context.client_context` when present: interpret times in the user's device-local time and emit ISO-8601 timestamps with that local UTC offset (for example `2026-05-18T13:45:00-04:00`), not a bare `Z` UTC timestamp unless the user explicitly asks for UTC. Your visible message must describe the same local time that the created/updated dashboard resource will show.
- For user-facing requests to schedule, plan, book, block time, add an appointment, create a reminder, or create a task: emit visible dashboard resources (`calendar_event.*`, `reminder.*`, and/or `task.*`) so the item appears in `/api/today`. Do not use `scheduler_job.*` for ordinary user calendar/reminder/task planning.
- Only use `scheduler_job.*` for internal/background agent automation. If you do emit `scheduler_job.create` with `scheduled_for` for a user-visible scheduled plan, the app will mirror it to a calendar event unless `parameters.kind` or `parameters.payload.kind` is `internal_automation`, `background_job`, or `system_job`.
- Update/delete action parameters must include the existing resource `id` from dashboard_state whenever possible. Task parameters support `id`, `title`, `type`, `status`, `notes`, `category`, `color`, `is_critical`, `due_at`, and `metadata`. Reminder parameters support `id`, `title`, `notes`, `category`, `color`, `is_critical`, `remind_at`, `status`, `calendar_event_id`, and `metadata`. Calendar event parameters support `id`, `match_title`, `from_date`, `title`, `description`, `location`, `category`, `color`, `is_critical`, `recurrence`, `starts_at`, `ends_at`, `status`, and `metadata`. Event category parameters support `id`, `name`, `color`, and `metadata`; for requests like "create a Maintenance category and make it yellow", emit `event_category.create` with risk "low" and a yellow hex color. For natural moves like “move lunch tomorrow to next Monday at 12”, use the existing lunch event id; if you cannot confidently choose one, include `match_title: "lunch"` and `from_date` for the source day rather than pretending the move succeeded.
- To add a reminder for a task, emit an ordered `task.create` or `task.update` plus a `reminder.create` or `reminder.update`; link them by putting `task_id` and `task_title` in reminder `metadata` when the task id is known, or by using matching titles when creating both in one response.
- Ask useful follow-up questions when a request is underspecified. For task creation, ask whether the user wants a reminder time, due date, category, critical/starred status, recurrence, notes, or any other sensible detail unless the user's request already makes the answer clear.
- If the user asks for a named event on multiple weekdays without explicitly saying recurring, weekly, every week, repeats, or recurrence: create one-off calendar events for the next matching days in the current week, then ask a follow-up about whether it should recur. Only set recurrence metadata when the user explicitly requests recurrence.

Runtime payload:
PROMPT.$this->payloadFor($session, $message);
    }

    private function payloadFor(ConversationSession $session, ConversationMessage $message): string
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);
        $profile = $workspace ? $this->agentProfileService->ensureForWorkspace($workspace, $user) : $this->profileForSession($session);
        $workspaceId = $workspace?->id;
        $accessibleWorkspaces = $user
            ? $this->workspaceService->accessibleWorkspaces($user)->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'role' => $workspace->getAttribute('role'),
            ])->values()
            : collect();

        return json_encode([
            'session' => [
                'id' => $session->id,
                'workspace_id' => $workspaceId,
                'title' => $session->title,
                'runtime_mode' => $session->runtime_mode,
                'metadata' => $session->metadata,
            ],
            'user' => [
                'id' => $user?->id,
                'email' => $user?->email,
                'name' => $user?->name,
                'onboard_complete' => (bool) ($user?->onboard_complete ?? false),
            ],
            'agent_profile' => $profile ? [
                'id' => $profile->id,
                'workspace_id' => $profile->workspace_id,
                'slug' => $profile->slug,
                'provider' => $profile->provider,
                'model' => $profile->model,
                'runtime_home' => $profile->runtime_home,
                'tool_policy' => $profile->tool_policy,
                'approval_policy' => $profile->approval_policy,
                'settings' => $profile->settings,
            ] : null,
            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'settings' => $workspace->settings,
            ] : null,
            'accessible_sync_targets' => $accessibleWorkspaces,
            'temporal_context' => [
                'server_now_utc' => now()->utc()->toIso8601String(),
                'server_today' => now()->toDateString(),
                'profile_timezone' => data_get($profile?->settings ?? [], 'timezone'),
                'client_context' => is_array(data_get($message->metadata ?? [], 'client_context'))
                    ? data_get($message->metadata ?? [], 'client_context')
                    : null,
            ],
            'dashboard_state' => [
                'tasks' => $this->workspaceScopedQuery(Task::query()->where('user_id', $session->user_id), $workspaceId)->latest('updated_at')->limit(50)->get(['id', 'workspace_id', 'title', 'type', 'status', 'notes', 'category', 'color', 'is_critical', 'due_at', 'metadata']),
                'reminders' => $this->workspaceScopedQuery(Reminder::query()->where('user_id', $session->user_id), $workspaceId)->latest('updated_at')->limit(50)->get(['id', 'workspace_id', 'title', 'notes', 'status', 'category', 'color', 'is_critical', 'remind_at', 'metadata']),
                'calendar_events' => $this->workspaceScopedQuery(CalendarEvent::query()->where('user_id', $session->user_id), $workspaceId)->latest('updated_at')->limit(50)->get(['id', 'workspace_id', 'title', 'description', 'location', 'category', 'color', 'recurrence', 'status', 'starts_at', 'ends_at', 'metadata']),
                'event_categories' => $this->workspaceScopedQuery(EventCategory::query()->where('user_id', $session->user_id), $workspaceId)->latest('updated_at')->limit(50)->get(['id', 'workspace_id', 'name', 'color', 'metadata']),
                'approvals' => $this->workspaceScopedQuery(Approval::query()->where('user_id', $session->user_id), $workspaceId)->latest('updated_at')->limit(50)->get(['id', 'workspace_id', 'title', 'status', 'description', 'payload']),
                'blockers' => $this->workspaceScopedQuery(Blocker::query()->where('user_id', $session->user_id), $workspaceId)->latest('updated_at')->limit(50)->get(['id', 'workspace_id', 'reason', 'status', 'context']),
            ],
            'allowed_action_schema' => [
                'low_risk' => [
                    'task.create', 'task.update', 'task.delete',
                    'reminder.create', 'reminder.update', 'reminder.delete',
                    'calendar_event.create', 'calendar_event.update', 'calendar_event.delete',
                    'event_category.create', 'event_category.update', 'event_category.delete',
                    'approval.create', 'approval.update', 'approval.approve', 'approval.deny', 'approval.delete',
                    'blocker.create', 'blocker.update', 'blocker.resolve', 'blocker.delete',
                    'scheduler_job.create', 'scheduler_job.update', 'scheduler_job.delete',
                    'agent_profile.update', 'workspace_memory.note', 'conversation_session.update', 'activity_event.create',
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

    private function profileForSession(ConversationSession $session): ?AgentProfile
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);

        if ($workspace) {
            return $this->agentProfileService->ensureForWorkspace($workspace, $user);
        }

        return AgentProfile::where('user_id', $session->user_id)->first();
    }

    private function workspaceForSession(ConversationSession $session, ?User $user = null): ?Workspace
    {
        $user ??= User::find($session->user_id);
        if (! $user) {
            return null;
        }

        return $this->workspaceService->resolveWorkspace($user, $session->workspace_id ?: null);
    }

    private function workspaceScopedQuery(mixed $query, ?int $workspaceId): mixed
    {
        return $workspaceId ? $query->where('workspace_id', $workspaceId) : $query;
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
                'workspace_id' => $session->workspace_id,
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
            'workspace_id' => $session->workspace_id,
            'conversation_session_id' => $session->id,
            'event_type' => $type,
            'tool_name' => $toolName,
            'status' => $status,
            'payload' => $payload ?: null,
        ]);
    }
}
