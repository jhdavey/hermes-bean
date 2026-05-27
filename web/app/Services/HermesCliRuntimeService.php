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
        private readonly AiUsageService $usageService,
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

        $modelRoute = $this->modelRouteFor($userMessage);
        $prompt = $this->promptFor($session, $userMessage, $modelRoute);
        $budget = $this->usageService->preflight($session, $userMessage, $modelRoute, $prompt);
        if (! $budget['allowed']) {
            return $this->budgetBlocked($session, $userMessage, collect([$received]), (string) $budget['reason'], $modelRoute, $budget);
        }

        $command = $this->commandFor($cliPath, $modelRoute['model'], $prompt);
        $profile = config('services.hermes_runtime.profile');

        $started = $this->recordEvent($session, 'runtime.hermes_cli_started', [
            'message_id' => $userMessage->id,
            'command' => basename($cliPath),
            'workdir' => config('services.hermes_runtime.workdir'),
            'profile' => $profile,
            'provider' => config('services.hermes_runtime.default_provider'),
            'model' => $modelRoute['model'],
            'model_route' => $modelRoute,
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

        return DB::transaction(function () use ($session, $userMessage, $received, $started, $process, $modelRoute, $prompt): array {
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
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $modelRoute['model'],
                    'model_route' => $modelRoute,
                    'stderr' => mb_substr($process->getErrorOutput(), 0, 2000),
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $usageLog = $this->usageService->recordCompletion(
                $session,
                $userMessage,
                $assistantMessage,
                $modelRoute,
                $prompt,
                $process->getOutput(),
                $domainEvents
            );

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => collect([$received, $started, $completed])->concat($domainEvents)->push($messageCompleted),
                'usage' => $usageLog,
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

    private function budgetBlocked(ConversationSession $session, ConversationMessage $userMessage, Collection $events, string $reason, array $modelRoute, array $budget): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events, $reason, $modelRoute, $budget): array {
            $blocked = $this->recordEvent($session, 'runtime.usage_budget_blocked', [
                'message_id' => $userMessage->id,
                'reason' => $reason,
                'model_route' => $modelRoute,
                'input_tokens' => $budget['input_tokens'] ?? null,
                'reserved_output_tokens' => $budget['reserved_output_tokens'] ?? null,
                'estimated_cost_usd' => $budget['estimated_cost_usd'] ?? null,
            ], 'ai.usage_budget', 'blocked');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $reason.' I paused this Bean request before calling the AI model so usage stays within budget. Try again after the limit resets, or upgrade once plans are enabled.',
                'metadata' => [
                    'runtime' => 'usage_budget',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'model' => $modelRoute['model'],
                    'model_route' => $modelRoute,
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $usageLog = $this->usageService->recordBlocked($session, $userMessage, $modelRoute, $budget, $reason);
            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'completed',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => $events->push($blocked)->push($messageCompleted),
                'usage' => $usageLog,
                'blocker' => null,
            ];
        });
    }

    private function commandFor(string $cliPath, ?string $model, string $prompt): array
    {
        $command = [$cliPath];
        $profile = config('services.hermes_runtime.profile');
        if (filled($profile)) {
            $command[] = '--profile';
            $command[] = (string) $profile;
        }

        $command[] = 'chat';
        $command[] = '-q';
        $command[] = $prompt;
        $command[] = '-Q';

        $provider = config('services.hermes_runtime.default_provider');
        if (filled($provider)) {
            $command[] = '--provider';
            $command[] = (string) $provider;
        }

        if (filled($model)) {
            $command[] = '-m';
            $command[] = (string) $model;
        }

        return $command;
    }

    /**
     * @return array{mode:string,tier:string,model:string,reason:string}
     */
    private function modelRouteFor(ConversationMessage $message): array
    {
        $mode = (string) config('services.hermes_runtime.router_mode', 'heuristic');
        $defaultModel = (string) config('services.hermes_runtime.default_model', 'gpt-5.5');

        if ($mode !== 'heuristic') {
            return [
                'mode' => $mode,
                'tier' => 'fixed',
                'model' => $defaultModel,
                'context_mode' => 'focused',
                'reason' => 'Fixed router mode uses the configured default model.',
            ];
        }

        $content = trim(mb_strtolower((string) $message->content));
        $tier = $this->messageLooksSimpleConversation($content)
            ? 'simple'
            : ($this->messageLooksSimpleDashboardCrud($content) ? 'standard' : 'complex');

        return [
            'mode' => $mode,
            'tier' => $tier,
            'model' => $this->modelForTier($tier, $defaultModel),
            'context_mode' => $this->contextModeForTier($tier),
            'reason' => match ($tier) {
                'simple' => 'Greeting, thanks, or small-talk response with no app mutation requested.',
                'standard' => 'Straightforward dashboard CRUD request for an internal Bean resource.',
                default => 'Complex, ambiguous, multi-step, external, or high-risk request.',
            },
        ];
    }

    private function modelForTier(string $tier, string $defaultModel): string
    {
        return match ($tier) {
            'simple' => (string) config('services.hermes_runtime.simple_model', 'gpt-5.4-mini'),
            'standard' => (string) config('services.hermes_runtime.standard_model', 'gpt-5.4'),
            default => (string) config('services.hermes_runtime.complex_model', $defaultModel),
        };
    }

    private function contextModeForTier(string $tier): string
    {
        return match ($tier) {
            'simple' => 'minimal',
            'standard' => 'compact',
            default => 'focused',
        };
    }

    private function messageLooksSimpleConversation(string $content): bool
    {
        if ($content === '' || mb_strlen($content) > 180 || $this->containsDashboardMutationVerb($content)) {
            return false;
        }

        return (bool) preg_match('/\b(hi|hello|hey|yo|good morning|good afternoon|good evening|thanks|thank you|appreciate it|how are you|what can you do|who are you|tell me a joke)\b/u', $content);
    }

    private function messageLooksSimpleDashboardCrud(string $content): bool
    {
        if (! $this->containsDashboardMutationVerb($content)) {
            return false;
        }

        if (! (bool) preg_match('/\b(task|todo|reminder|event|calendar|category|blocker|approval)\b/u', $content)) {
            return false;
        }

        return ! (bool) preg_match('/\b(plan|organize|optimize|reschedule|move|recurring|repeat|every|sync|google|email|mail|payment|purchase|deploy|delete account|all future|household|shared|if|when|unless|compare|decide|recommend)\b/u', $content);
    }

    private function containsDashboardMutationVerb(string $content): bool
    {
        return (bool) preg_match('/\b(create|add|make|schedule|book|block|put|remind|update|edit|change|rename|delete|remove|complete|mark|set|clear|cancel)\b/u', $content);
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

    private function promptFor(ConversationSession $session, ConversationMessage $message, array $modelRoute): string
    {
        return <<<'PROMPT'
You are the server-hosted Hermes runtime for Hey Bean. Respond only as strict JSON, with no markdown or prose outside the JSON object.

Response schema:
{
  "visible_response": "natural user-facing text only; no JSON, code fence, action payload, ids, or debug text",
  "message": "optional backwards-compatible alias for visible_response",
  "actions": [
    {
      "type": "task.create|task.update|task.delete|reminder.create|reminder.update|reminder.delete|calendar_event.create|calendar_event.update|calendar_event.delete|event_category.create|event_category.update|event_category.delete|approval.create|approval.update|approval.approve|approval.deny|approval.delete|blocker.create|blocker.update|blocker.resolve|blocker.delete|agent_profile.update|workspace_memory.note|conversation_session.update|activity_event.create|email.send|payment.create|deployment.run|account.delete",
      "risk": "low|medium|high",
      "title": "approval title for risky actions",
      "description": "optional approval description",
      "parameters": {}
    }
  ]
}

Rules:
- Treat `visible_response` and `actions` as separate channels: `visible_response` is what the user reads, while `actions` are machine instructions for the app. Never put JSON, action objects, ids, schema text, or debug output in `visible_response`.
- Sound like a capable human assistant: concise, confident, warm enough, and direct. Acknowledge what you did or ask the single missing question. Do not narrate internal routing, model choice, schemas, token usage, or implementation details.
- You are allowed to complete complex multi-step app-control requests by emitting multiple ordered actions in one response.
- Low-risk internal dashboard CRUD/control actions may be emitted with risk "low": tasks, reminders, calendar events, event categories, approvals, blockers, agent profile settings, conversation session metadata, and activity events.
- Existing dashboard resources are listed in compact dashboard_state; use their numeric id when updating, deleting, approving, denying, or resolving them. If the compact state does not include enough matching context, ask a short follow-up instead of guessing.
- When the user explicitly asks to create/update/delete an item in another accessible workspace, include `parameters.workspace_id` or `parameters.target_workspace_id` with that workspace id from `accessible_sync_targets`; otherwise actions apply to the current session workspace.
- Use `workspace_memory.note` only when the user clearly asks you to remember a durable fact for a named accessible workspace. Include `parameters.workspace_id`/`target_workspace_id` and `parameters.note`.
- Risky external, destructive outside the dashboard, mail, payment, deployment, and account actions must be emitted with risk "high" so the app queues an approval.
- If no concrete action is needed, return an empty actions array.
- If you emit actions, `visible_response` should summarize the user-visible result in one natural sentence unless you need approval or a follow-up.
- If `user.onboard_complete` is false or `agent_profile.settings.onboarding.completed` is false, treat the conversation as a quick Bean onboarding interview: first tell the user this will only take a few questions, then ask them to introduce themself and answer follow-up questions to learn preferred Bean style/personality, top priorities, and any useful life/context constraints. When the user provides enough onboarding/preferences, emit a low-risk `agent_profile.update` action with `parameters.settings.personality_type`, `parameters.settings.onboarding.completed: true`, `parameters.settings.onboarding.priorities`, and `parameters.settings.onboarding.context` so the app saves settings and updates Bean memory; your visible message should clearly say onboarding is saved.
- When the user changes Bean preferences from Settings, the message metadata includes `settings_update: true`; acknowledge the update and emit a low-risk `agent_profile.update` action with the supplied preferences so runtime memory stays synchronized.
- Adapt your tone to `agent_profile.settings.personality_prompt` when present, and use `agent_profile.settings.onboarding.priorities` plus `agent_profile.settings.onboarding.context` and `agent_profile.settings.memory.user_preferences.summary` to understand what the user cares about.
- Workspace memory uses Hermes' built-in memory markdown files under `agent_profile.runtime_home/memories/`, isolated per workspace. Durable app/workspace facts belong in `memories/MEMORY.md`; user identity/preferences belong in `memories/USER.md`. Do not create duplicate root-level USER.md, MEMORY.md, HOUSEHOLD.md, or PREFERENCES.md files.
- In Personal workspaces, save only the signed-in user's personal preferences/routines. In household workspaces, save shared household routines and household-relevant member preferences. Never copy memory between Personal and households or between households unless the user explicitly asks.
- App-managed sections in the built-in memory markdown files are wrapped in `HERMES-BEAN MANAGED` comments; preserve those sections and add agent-learned facts under the non-managed headings.
- Use ISO-8601 timestamps for dates when you create or update reminders and calendar events. For relative dates/times such as "today", "tomorrow", "later", or "1:45 PM", use `temporal_context.client_context` when present: interpret times in the user's device-local time and emit ISO-8601 timestamps with that local UTC offset (for example `2026-05-18T13:45:00-04:00`), not a bare `Z` UTC timestamp unless the user explicitly asks for UTC. Your visible message must describe the same local time that the created/updated dashboard resource will show.
- If the user gives a wall-clock time like "1 PM" in their local timezone, never emit that same clock time with `Z` (for example, do not turn 1 PM local into `13:00:00Z`). Use the client's UTC offset on the timestamp.
- For user-facing requests to schedule, plan, book, block time, add an appointment, create a reminder, or create a task: emit visible dashboard resources (`calendar_event.*`, `reminder.*`, and/or `task.*`) so the item appears in `/api/today`.
- Update/delete action parameters must include the existing resource `id` from dashboard_state whenever possible. Task parameters support `id`, `title`, `type`, `status`, `notes`, `category`, `color`, `is_critical`, `due_at`, and `metadata`. Reminder parameters support `id`, `title`, `notes`, `category`, `color`, `is_critical`, `remind_at`, `status`, `calendar_event_id`, and `metadata`. Calendar event parameters support `id`, `match_title`, `from_date`, `title`, `description`, `location`, `category`, `color`, `is_critical`, `recurrence`, `starts_at`, `ends_at`, `status`, and `metadata`. Required create fields: `task.create` needs `title`; `reminder.create` needs `title` and `remind_at`; `calendar_event.create` needs `title` and `starts_at`; include `ends_at` when the user gives an end time. Event category parameters support `id`, `name`, `color`, and `metadata`; for requests like "create a Maintenance category and make it yellow", emit `event_category.create` with risk "low" and a yellow hex color. For natural moves like “move lunch tomorrow to next Monday at 12”, use the existing lunch event id; if you cannot confidently choose one, include `match_title: "lunch"` and `from_date` for the source day rather than pretending the move succeeded.
- To add a reminder for a task, emit an ordered `task.create` or `task.update` plus a `reminder.create` or `reminder.update`; link them by putting `task_id` and `task_title` in reminder `metadata` when the task id is known, or by using matching titles when creating both in one response.
- Prefer acting on clear scheduling/productivity requests instead of interrogating the user for optional details. If the user says something like "Schedule workout on my calendar today from 6 to 7pm", infer title "Workout", today's local date, start/end times, current workspace/default calendar, no category, not critical, no recurrence, and no notes unless stated.
- Ask a short follow-up only when a required detail is genuinely missing or ambiguous: calendar events need a title plus enough date/time or all-day information; reminders need a title plus reminder date/time; tasks need a title and can default to open status with no due date. Do not ask for optional category, color, recurrence, notes, reminders, workspace, or critical/starred status unless the user requests or implies those fields.
- If the user asks for a named event on multiple weekdays without explicitly saying recurring, weekly, every week, repeats, or recurrence: create one-off calendar events for the next matching days in the current week, then ask a follow-up about whether it should recur. Only set recurrence metadata when the user explicitly requests recurrence.

Runtime payload:
PROMPT.$this->payloadFor($session, $message, $modelRoute);
    }

    private function payloadFor(ConversationSession $session, ConversationMessage $message, array $modelRoute): string
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);
        $profile = $workspace ? $this->agentProfileService->ensureForWorkspace($workspace, $user) : $this->profileForSession($session);
        if ($user && $profile) {
            $user = $this->agentProfileService->syncUserOnboardingFlag($user, $profile);
        }
        $workspaceId = $workspace?->id;
        $accessibleWorkspaces = $user
            ? $this->workspaceService->accessibleWorkspaces($user)->map(fn (Workspace $workspace): array => [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'role' => $workspace->getAttribute('role'),
            ])->values()
            : collect();
        $profileSettings = $profile->settings ?? [];

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
                'settings' => [
                    'timezone' => data_get($profileSettings, 'timezone'),
                    'personality_type' => data_get($profileSettings, 'personality_type'),
                    'personality_prompt' => data_get($profileSettings, 'personality_prompt'),
                    'onboarding' => data_get($profileSettings, 'onboarding'),
                    'memory' => [
                        'user_preferences' => data_get($profileSettings, 'memory.user_preferences'),
                    ],
                ],
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
            'context_policy' => [
                'mode' => $modelRoute['context_mode'] ?? 'focused',
                'route_tier' => $modelRoute['tier'] ?? null,
                'compact' => true,
            ],
            'response_contract' => [
                'visible_response' => 'Natural user-facing sentence only. Never expose JSON, schemas, tool calls, action payloads, ids, or debug text.',
                'actions' => 'Machine-readable app changes only. Required create fields are enforced by the server.',
                'defaulting_policy' => [
                    'calendar_event.create' => 'If title/date/time are clear, create it in the current workspace/default calendar. Category, critical, recurrence, location, and notes default to empty/off unless requested.',
                    'task.create' => 'If title is clear, create an open task. Due date, category, critical, recurrence, notes, and reminder default to empty/off unless requested.',
                    'reminder.create' => 'If title and reminder time are clear, create it. Category, critical, recurrence, and notes default to empty/off unless requested.',
                ],
                'follow_up_policy' => 'Ask one concise follow-up only when a required detail is missing or genuinely ambiguous.',
            ],
            'dashboard_state' => $this->compactDashboardState($session, $message, $workspaceId, (string) ($modelRoute['context_mode'] ?? 'focused')),
            'allowed_action_schema' => [
                'low_risk' => [
                    'task.create', 'task.update', 'task.delete',
                    'reminder.create', 'reminder.update', 'reminder.delete',
                    'calendar_event.create', 'calendar_event.update', 'calendar_event.delete',
                    'event_category.create', 'event_category.update', 'event_category.delete',
                    'approval.create', 'approval.update', 'approval.approve', 'approval.deny', 'approval.delete',
                    'blocker.create', 'blocker.update', 'blocker.resolve', 'blocker.delete',
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

    private function compactDashboardState(ConversationSession $session, ConversationMessage $message, ?int $workspaceId, string $mode): array
    {
        $countQuery = fn (mixed $query) => $this->workspaceScopedQuery($query->where('user_id', $session->user_id), $workspaceId);
        $counts = [
            'tasks' => (clone $countQuery(Task::query()))->count(),
            'reminders' => (clone $countQuery(Reminder::query()))->count(),
            'calendar_events' => (clone $countQuery(CalendarEvent::query()))->count(),
            'event_categories' => (clone $countQuery(EventCategory::query()))->count(),
            'approvals' => (clone $countQuery(Approval::query()))->count(),
            'blockers' => (clone $countQuery(Blocker::query()))->count(),
        ];

        if ($mode === 'minimal') {
            return ['counts' => $counts, 'items' => []];
        }

        $windowDays = $mode === 'compact' ? 14 : 30;
        $limit = $mode === 'compact' ? 20 : 35;
        $from = now()->subDay();
        $to = now()->addDays($windowDays);
        $terms = $this->searchTermsFor($message);

        $tasks = $this->workspaceScopedQuery(Task::query()->where('user_id', $session->user_id), $workspaceId)
            ->where(function ($query) use ($from, $to, $terms): void {
                $query->whereBetween('due_at', [$from, $to])
                    ->orWhereNull('due_at')
                    ->orWhere('status', 'open');
                $this->orWhereTitleMatches($query, $terms);
            })
            ->latest('is_critical')
            ->latest('updated_at')
            ->limit($limit)
            ->get(['id', 'title', 'type', 'status', 'category', 'color', 'is_critical', 'due_at', 'metadata'])
            ->map(fn (Task $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'type' => $task->type,
                'status' => $task->status,
                'category' => $task->category,
                'color' => $task->color,
                'is_critical' => (bool) $task->is_critical,
                'due_at' => $task->due_at?->toIso8601String(),
                'recurrence' => data_get($task->metadata ?? [], 'recurrence'),
            ])->values();

        $reminders = $this->workspaceScopedQuery(Reminder::query()->where('user_id', $session->user_id), $workspaceId)
            ->where(function ($query) use ($from, $to, $terms): void {
                $query->whereBetween('remind_at', [$from, $to])
                    ->orWhere('status', 'scheduled');
                $this->orWhereTitleMatches($query, $terms);
            })
            ->latest('is_critical')
            ->orderBy('remind_at')
            ->limit($limit)
            ->get(['id', 'title', 'status', 'category', 'color', 'is_critical', 'remind_at', 'metadata'])
            ->map(fn (Reminder $reminder): array => [
                'id' => $reminder->id,
                'title' => $reminder->title,
                'status' => $reminder->status,
                'category' => $reminder->category,
                'color' => $reminder->color,
                'is_critical' => (bool) $reminder->is_critical,
                'remind_at' => $reminder->remind_at?->toIso8601String(),
                'recurrence' => data_get($reminder->metadata ?? [], 'recurrence'),
            ])->values();

        $events = $this->workspaceScopedQuery(CalendarEvent::query()->where('user_id', $session->user_id), $workspaceId)
            ->where(function ($query) use ($from, $to, $terms): void {
                $query->whereBetween('starts_at', [$from, $to])
                    ->orWhereBetween('ends_at', [$from, $to])
                    ->orWhere(function ($query) use ($from, $to): void {
                        $query->where('starts_at', '<=', $from)->where('ends_at', '>=', $to);
                    });
                $this->orWhereTitleMatches($query, $terms);
            })
            ->orderBy('starts_at')
            ->limit($limit)
            ->get(['id', 'title', 'category', 'color', 'is_critical', 'recurrence', 'status', 'starts_at', 'ends_at'])
            ->map(fn (CalendarEvent $event): array => [
                'id' => $event->id,
                'title' => $event->title,
                'category' => $event->category,
                'color' => $event->color,
                'is_critical' => (bool) $event->is_critical,
                'recurrence' => $event->recurrence,
                'status' => $event->status,
                'starts_at' => $event->starts_at?->toIso8601String(),
                'ends_at' => $event->ends_at?->toIso8601String(),
            ])->values();

        return [
            'counts' => $counts,
            'window' => ['from' => $from->toDateString(), 'to' => $to->toDateString()],
            'items' => [
                'tasks' => $tasks,
                'reminders' => $reminders,
                'calendar_events' => $events,
                'event_categories' => $this->workspaceScopedQuery(EventCategory::query()->where('user_id', $session->user_id), $workspaceId)->latest('updated_at')->limit(25)->get(['id', 'name', 'color'])->values(),
                'approvals' => $this->workspaceScopedQuery(Approval::query()->where('user_id', $session->user_id), $workspaceId)->where('status', 'pending')->latest('updated_at')->limit(10)->get(['id', 'title', 'status'])->values(),
                'blockers' => $this->workspaceScopedQuery(Blocker::query()->where('user_id', $session->user_id), $workspaceId)->where('status', 'open')->latest('updated_at')->limit(10)->get(['id', 'reason', 'status'])->values(),
            ],
        ];
    }

    private function searchTermsFor(ConversationMessage $message): array
    {
        $stopWords = ['about', 'after', 'before', 'bean', 'calendar', 'create', 'delete', 'event', 'from', 'into', 'move', 'reminder', 'task', 'that', 'this', 'today', 'tomorrow', 'with'];
        preg_match_all('/[\pL\pN][\pL\pN\'-]{3,}/u', mb_strtolower($message->content), $matches);

        return collect($matches[0] ?? [])
            ->map(fn (string $term): string => trim($term, "'-"))
            ->reject(fn (string $term): bool => in_array($term, $stopWords, true))
            ->unique()
            ->take(5)
            ->values()
            ->all();
    }

    private function orWhereTitleMatches(mixed $query, array $terms): void
    {
        foreach ($terms as $term) {
            $query->orWhere('title', 'like', '%'.$term.'%');
        }
    }

    private function structuredOutputFrom(string $stdout): ?array
    {
        $trimmed = trim($stdout);
        if ($trimmed === '') {
            return null;
        }

        $decoded = $this->decodeStructuredJson($trimmed);
        if (! is_array($decoded)) {
            return null;
        }

        foreach ($this->assistantContentKeys() as $key) {
            if (isset($decoded[$key]) && is_string($decoded[$key])) {
                $nested = trim($decoded[$key]);
                if ($nested === '') {
                    continue;
                }
                $nestedDecoded = $this->decodeStructuredJson($nested);
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
            $decoded = $this->decodeStructuredJson($trimmed);
            if (! is_array($decoded)) {
                return $trimmed;
            }
        }

        if (is_array($decoded)) {
            foreach ($this->assistantContentKeys() as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                    return $this->normalizeAssistantContent($decoded[$key]);
                }
            }

            if (array_key_exists('actions', $decoded)) {
                return $this->fallbackStructuredAssistantContent($decoded);
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

        $decoded = $this->decodeStructuredJson($trimmed);
        if (! is_array($decoded)) {
            return $trimmed;
        }

        if (is_array($decoded)) {
            foreach ($this->assistantContentKeys() as $key) {
                if (isset($decoded[$key]) && is_string($decoded[$key]) && trim($decoded[$key]) !== '') {
                    return $this->normalizeAssistantContent($decoded[$key]);
                }
            }

            if (array_key_exists('actions', $decoded)) {
                return $this->fallbackStructuredAssistantContent($decoded);
            }
        }

        return $trimmed;
    }

    /**
     * @return array<int, string>
     */
    private function assistantContentKeys(): array
    {
        return ['visible_response', 'display_message', 'content', 'message', 'assistant_message', 'response'];
    }

    private function fallbackStructuredAssistantContent(array $decoded): string
    {
        $actions = is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [];

        if ($actions === []) {
            return 'Done.';
        }

        if (count($actions) === 1 && is_array($actions[0] ?? null)) {
            $action = $actions[0];
            $title = trim((string) data_get($action, 'parameters.title', ''));
            $name = trim((string) data_get($action, 'parameters.name', ''));

            return match ((string) ($action['type'] ?? '')) {
                'calendar_event.create', 'calendar.create' => $title !== '' ? "I added {$title} to your calendar." : 'I added that to your calendar.',
                'task.create' => $title !== '' ? "I added {$title} to your tasks." : 'I added that to your tasks.',
                'task.update' => $title !== '' ? "I updated {$title}." : 'I updated that task.',
                'reminder.create' => $title !== '' ? "I set the reminder: {$title}." : 'I set that reminder.',
                'event_category.create' => $name !== '' ? "I created {$name}." : 'I created that.',
                default => 'Done.',
            };
        }

        return 'Done.';
    }

    private function decodeStructuredJson(string $content): ?array
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return null;
        }

        $candidates = [$trimmed];

        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/is', $trimmed, $matches) === 1) {
            $candidates[] = trim($matches[1]);
        }

        $firstBrace = strpos($trimmed, '{');
        $lastBrace = strrpos($trimmed, '}');
        if ($firstBrace !== false && $lastBrace !== false && $lastBrace > $firstBrace) {
            $candidates[] = substr($trimmed, $firstBrace, $lastBrace - $firstBrace + 1);
        }

        foreach (array_unique($candidates) as $candidate) {
            try {
                $decoded = json_decode($candidate, true, flags: JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                continue;
            }

            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
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
