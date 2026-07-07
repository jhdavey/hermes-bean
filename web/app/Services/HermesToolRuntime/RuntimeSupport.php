<?php

namespace App\Services\HermesToolRuntime;

use App\Models\ActivityEvent;
use App\Models\AgentProfile;
use App\Models\ConversationMessage;
use App\Models\ConversationSession;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

trait RuntimeSupport
{
    private function providerApiKey(): string
    {
        return (string) config('services.hermes_runtime.api_key', '');
    }

    private function elapsedMs(float $startedAt): int
    {
        return max(0, (int) round((microtime(true) - $startedAt) * 1000));
    }

    private function toolRoutingMode(ConversationMessage $message): string
    {
        $text = mb_strtolower((string) $message->content);
        if ($text === '') {
            return 'full';
        }

        $externalTerms = [
            'weather', 'traffic', 'news', 'price', 'prices', 'stock', 'flight', 'hotel',
            'store hours', 'current', 'latest', 'near me', 'web', 'internet', 'look up',
        ];
        foreach ($externalTerms as $term) {
            if (str_contains($text, $term)) {
                return $this->messageAppearsToRequestAppWrite($message) ? 'full' : 'read_lookup';
            }
        }

        $profileOrMemoryTerms = [
            'remember that', 'forget that', 'forget my', 'bean preference', 'personality',
            'model', 'workspace memory', 'what did i ask', 'what did i say',
        ];
        foreach ($profileOrMemoryTerms as $term) {
            if (str_contains($text, $term)) {
                return 'full';
            }
        }

        $appTerms = [
            'task', 'todo', 'to-do', 'reminder', 'remind', 'calendar', 'schedule',
            'event', 'block', 'appointment', 'meeting', 'note', 'list', 'due',
        ];
        foreach ($appTerms as $term) {
            if (str_contains($text, $term)) {
                return 'app_crud';
            }
        }

        $writeTerms = [
            'add ', 'create ', 'make ', 'set ', 'delete ', 'remove ', 'update ',
            'change ', 'move ', 'reschedule ', 'complete ', 'mark ',
        ];
        foreach ($writeTerms as $term) {
            if (str_contains($text, $term)) {
                return 'app_crud';
            }
        }

        return 'full';
    }

    private function toolPromptFor(ConversationSession $session, ConversationMessage $message, array $modelRoute): string
    {
        return json_encode([
            'runtime_context' => $this->toolContextPayload($session, $message),
            'conversation' => $this->modelConversationMessages($session, $message),
            'message' => $message->content,
            'message_metadata' => $message->metadata,
            'route' => $modelRoute,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<int, array{role:string, content:string}>
     */
    private function modelConversationMessages(ConversationSession $session, ConversationMessage $currentMessage): array
    {
        $history = $session->messages()
            ->where('id', '<', $currentMessage->id)
            ->whereIn('role', ['user', 'assistant'])
            ->latest('id')
            ->limit(12)
            ->get()
            ->sortBy('id')
            ->map(fn (ConversationMessage $message): array => [
                'role' => $message->role === 'assistant' ? 'assistant' : 'user',
                'content' => str($message->content)->squish()->limit(4000, '')->toString(),
            ])
            ->filter(fn (array $message): bool => $message['content'] !== '')
            ->values()
            ->all();

        $history[] = [
            'role' => 'user',
            'content' => str($currentMessage->content)->squish()->limit(4000, '')->toString(),
        ];

        return $history;
    }

    private function toolContextPayload(ConversationSession $session, ConversationMessage $message, string $toolMode = 'full'): array
    {
        $user = User::find($session->user_id);
        $workspace = $this->workspaceForSession($session, $user);
        $profile = $workspace ? $this->agentProfileService->ensureForWorkspace($workspace, $user) : $this->profileForSession($session);
        if ($user && $profile) {
            $user = $this->agentProfileService->syncUserOnboardingFlag($user, $profile);
        }
        $profileSettings = $profile->settings ?? [];
        $memoryContext = ($toolMode !== 'app_crud' && $user && $workspace)
            ? $this->memoryService->runtimeContext($user, $workspace, (string) $message->content, 8)
            : ['items' => [], 'summaries' => []];

        return [
            'session' => [
                'id' => $session->id,
                'workspace_id' => $workspace?->id,
                'title' => $session->title,
                'runtime_mode' => $session->runtime_mode,
            ],
            'user' => [
                'id' => $user?->id,
                'email' => $user?->email,
                'name' => $user?->name,
                'onboard_complete' => (bool) ($user?->onboard_complete ?? false),
            ],
            'workspace' => $workspace ? [
                'id' => $workspace->id,
                'name' => $workspace->name,
                'type' => $workspace->type,
                'settings' => $workspace->settings,
            ] : null,
            'accessible_workspaces' => $user
                ? $this->workspaceService->accessibleWorkspaces($user)->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'type' => $workspace->type,
                    'role' => $workspace->getAttribute('role'),
                ])->values()->all()
                : [],
            'agent_profile' => $profile ? [
                'id' => $profile->id,
                'workspace_id' => $profile->workspace_id,
                'provider' => $profile->provider,
                'model' => $profile->model,
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
            'memory_context' => $memoryContext,
            'recent_assistant_actions' => $this->recentAssistantActionsForContext($session, $toolMode === 'app_crud' ? 5 : 10),
            'temporal_context' => [
                'server_now_utc' => now()->utc()->toIso8601String(),
                'server_today' => now()->toDateString(),
                'profile_timezone' => data_get($profileSettings, 'timezone'),
                'client_context' => is_array(data_get($message->metadata ?? [], 'client_context'))
                    ? data_get($message->metadata ?? [], 'client_context')
                    : null,
            ],
        ];
    }

    private function recentAssistantActionsForContext(ConversationSession $session, int $limit = 10): array
    {
        return $session->activityEvents()
            ->where('event_type', 'like', 'assistant.%')
            ->whereIn('status', ['succeeded', 'recorded'])
            ->latest('id')
            ->limit($limit)
            ->get()
            ->sortBy('id')
            ->map(fn (ActivityEvent $event): array => [
                'id' => $event->id,
                'event_type' => $event->event_type,
                'tool_name' => $event->tool_name,
                'status' => $event->status,
                'payload' => $event->payload,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    private function toolSystemInstructions(): string
    {
        return <<<'PROMPT'
You are Bean, a capable human-like assistant inside the Hey Bean app.

You own intent and conversation. Interpret the user's message naturally, including messy wording, typos, and shorthand. Decide whether to answer directly, call read tools, call write tools, or ask one concise follow-up.

Use recent conversation turns to resolve follow-ups, corrections, and pronouns. If the user corrects the entity type, such as "task, not reminder", apply that correction to the prior request and search/use the corrected tool type.
Use runtime_context.recent_assistant_actions to resolve follow-up confirmations. If the user says only "yes", "sure", or another short confirmation after Bean asked about an additional related action, perform only the unresolved related action. Do not recreate tasks, reminders, notes, or events that recent_assistant_actions shows were already created or updated. When creating a reminder for an event that was just created, pass that event's calendar_event_id from recent_assistant_actions.
Conversation history and recent_assistant_actions can be stale because the user may delete, move, or edit items outside the chat. For any create, update, delete, schedule, move, or reminder request, current app state and tool results override earlier assistant claims. Never say an app change is already done solely because an earlier chat message said it was done; verify current state with tools when needed, and run the write tools if the requested records are missing or still need changes.
When a user asks what/when/where about an item and the type is ambiguous, search the relevant app records before saying it is not found. For task-like words or chores, search tasks; for reminder-like alarms, search reminders; for note, list, idea, writing, or saved text requests, search notes; if unclear, search the likely record types.

Use read tools when you need current app state. Use write tools when app state should change. Do not describe a dashboard change as complete unless a write tool result confirms it succeeded.
For clear create/update/delete requests with multiple independent app changes, emit every necessary write tool call in the same assistant tool response and in the user's requested order. Do not wait for one write result before planning the next unless the later write truly needs a database id that cannot be inferred. For a same-request reminder tied to a same-request calendar event, still plan both changes together with matching titles/times; Laravel can correlate the created records.

Laravel owns app mechanics: workspace access, database writes, validation, syncing, and tool results. Trust tool results. If a read/write tool says not found, ambiguous, or failed, respond naturally from that result.
Timed read-tool *_at timestamps are formatted in the tool result timezone and match the user-visible app. Use display_* fields for dates and times you mention to the user; use *_utc only as canonical instants. For all_day events, ignore midnight wall-clock internals and use display_start_date/display_end_date.
Use external_lookup for live information outside HeyBean, including current store hours, flights, hotel prices, weather, traffic, news, prices, availability, sports scores, or other current web facts. Do not invent current external facts. When external_lookup returns sources or citations, use them to answer concisely, include a brief source title or URL when useful, and mention uncertainty when results are incomplete.
If the user is only asking whether Bean can create, update, delete, schedule, remember, look up, or otherwise do something, answer the capability question directly. Do not call write tools unless the user includes concrete details that make it an actual request.

Prefer acting on clear scheduling/productivity requests instead of asking for optional details. Infer sensible defaults: current workspace, no category, not critical, no recurrence, and no extra notes unless the user says otherwise. For note requests, create/update/delete Notes records with note tools rather than memory unless the user explicitly asks Bean to remember a preference/fact. For durable user preferences, stable constraints, identity facts, project context, or explicit "remember/forget" requests, use search_memory plus remember_memory/update_memory/forget_memory. Do not save ordinary one-off requests as durable memory. For questions about what is scheduled, coming up, left, remaining, or still needs attention today or on a specific date, use get_day_context rather than request history. For "what did I ask/say/request/do" recall questions, use get_request_history or get_activity_timeline instead of guessing from recent context. For relative dates/times, use temporal_context.client_context and emit local ISO-8601 timestamps with the client's UTC offset.
When setting recurrence, always use recurrence as one of: none, daily, weekly, monthly, yearly, specific_days, or interval. For custom intervals like "every 3 days", set recurrence to interval and put interval plus interval_unit in metadata. Never put an object in recurrence.

Use the current workspace unless the user clearly names another accessible workspace. Adapt tone to agent_profile settings and memory. Do not run a first-login onboarding interview in normal chat; guided signup collects account and Bean preferences before the user reaches the dashboard. If the user explicitly asks to change Bean preferences later, use update_agent_profile with the requested settings.

Respond to the user in natural language only. Never output JSON, tool arguments, ids, schema text, routing details, or debug text.
PROMPT;
    }

    private function nativeToolDefinitions(string $toolMode = 'full'): array
    {
        $tools = [
            $this->nativeTool('search_tasks', 'Search tasks in the current or specified workspace. Use this before updating a task when the matching item is not already known.', $this->searchTaskProperties()),
            $this->nativeTool('search_reminders', 'Search reminders in the current or specified workspace.', $this->searchReminderProperties()),
            $this->nativeTool('search_calendar_events', 'Search calendar events in the current or specified workspace.', $this->searchCalendarEventProperties()),
            $this->nativeTool('search_notes', 'Search notes by title, folder, or body text in the current or specified workspace.', $this->searchNoteProperties()),
            $this->nativeTool('search_memory', 'Search durable Bean memory for user preferences, constraints, projects, decisions, routines, and facts.', $this->searchMemoryProperties()),
            $this->nativeTool('get_request_history', 'Recall prior user requests from Bean conversation history by local date, text, or workspace. Use for questions like what did I ask yesterday; do not use for current schedule, remaining-today, or what-is-coming-up questions.', $this->historyProperties()),
            $this->nativeTool('get_activity_timeline', 'Recall Bean activity/tool outcomes by local date, event type, tool, or workspace.', $this->activityTimelineProperties()),
            $this->nativeTool('get_day_context', 'Get tasks, reminders, and calendar events for a specific local date.', $this->dayContextProperties(), ['date']),
            $this->nativeTool('external_lookup', 'Look up current external information outside HeyBean, such as live web facts, store hours, travel, weather, prices, traffic, news, sports, or current availability. Use this instead of guessing when the answer depends on current outside data. When the domain is clear, fill structured fields such as domain, intent, location, and date so fast providers can execute without reparsing the user sentence.', $this->externalLookupProperties(), ['query']),
            $this->nativeTool('create_task', 'Create a visible task in Hey Bean.', $this->taskProperties(), ['title']),
            $this->nativeTool('update_task', 'Update one existing task. Prefer id from search_tasks; otherwise use match_title and Laravel will require a unique match.', $this->taskProperties(requireId: false)),
            $this->nativeTool('delete_task', 'Delete one existing task by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_reminder', 'Create a visible reminder in Hey Bean.', $this->reminderProperties(), ['title', 'remind_at']),
            $this->nativeTool('update_reminder', 'Update one existing reminder by id.', $this->reminderProperties(requireId: true), ['id']),
            $this->nativeTool('delete_reminder', 'Delete one existing reminder by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_calendar_event', 'Create a visible calendar event in Hey Bean.', $this->calendarEventProperties(), ['title', 'starts_at']),
            $this->nativeTool('update_calendar_event', 'Update one existing calendar event. Prefer id; use match_title and from_date only when needed.', $this->calendarEventProperties(requireId: false)),
            $this->nativeTool('delete_calendar_event', 'Delete one existing calendar event by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_note', 'Create a note in Hey Bean Notes.', $this->noteProperties(), ['title']),
            $this->nativeTool('update_note', 'Update one existing note. Prefer id from search_notes; otherwise use match_title and Laravel will require a unique match.', $this->noteProperties(requireId: false)),
            $this->nativeTool('delete_note', 'Delete one existing note by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_note_folder', 'Create a Notes folder.', $this->noteFolderProperties(), ['name']),
            $this->nativeTool('update_note_folder', 'Update a Notes folder by id.', $this->noteFolderProperties(requireId: true), ['id']),
            $this->nativeTool('delete_note_folder', 'Delete a Notes folder by id. Notes inside it are kept and moved to All Notes.', $this->idProperties(), ['id']),
            $this->nativeTool('create_event_category', 'Create or save an event category.', $this->categoryProperties(), ['name']),
            $this->nativeTool('update_event_category', 'Update one event category by id.', $this->categoryProperties(requireId: true), ['id']),
            $this->nativeTool('delete_event_category', 'Delete one event category by id.', $this->idProperties(), ['id']),
            $this->nativeTool('create_blocker', 'Create a blocker or issue for the user.', $this->blockerProperties(), ['reason']),
            $this->nativeTool('update_blocker', 'Update a blocker by id.', $this->blockerProperties(requireId: true), ['id']),
            $this->nativeTool('resolve_blocker', 'Resolve a blocker by id.', $this->idProperties(), ['id']),
            $this->nativeTool('delete_blocker', 'Delete a blocker by id.', $this->idProperties(), ['id']),
            $this->nativeTool('update_agent_profile', 'Update Bean preferences, onboarding, model/profile settings, or memory settings.', $this->agentProfileProperties()),
            $this->nativeTool('remember_memory', 'Save a durable Bean memory only when the user explicitly asks Bean to remember something or the fact is clearly stable and useful.', $this->memoryProperties(), ['content']),
            $this->nativeTool('update_memory', 'Update one existing durable memory. Prefer id from search_memory; otherwise use query/title and Laravel will require a unique match.', $this->memoryProperties(requireId: false)),
            $this->nativeTool('forget_memory', 'Forget/archive one durable memory by id.', $this->idProperties(), ['id']),
            $this->nativeTool('note_workspace_memory', 'Save a durable workspace memory note when the user explicitly asks Bean to remember something.', [
                'note' => ['type' => 'string'],
                'workspace_id' => ['type' => 'integer'],
                'target_workspace_id' => ['type' => 'integer'],
            ], ['note']),
            $this->nativeTool('update_conversation_session', 'Update conversation session metadata.', [
                'title' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'runtime_mode' => ['type' => 'string'],
                'metadata' => ['type' => 'object', 'additionalProperties' => true],
            ]),
            $this->nativeTool('create_activity_event', 'Record a non-resource activity event.', [
                'event_type' => ['type' => 'string'],
                'tool_name' => ['type' => 'string'],
                'status' => ['type' => 'string'],
                'payload' => ['type' => 'object', 'additionalProperties' => true],
            ], ['event_type']),
        ];

        if ($toolMode === 'full') {
            return $tools;
        }

        $allowed = match ($toolMode) {
            'read_lookup' => [
                'search_tasks',
                'search_reminders',
                'search_calendar_events',
                'search_notes',
                'search_memory',
                'get_request_history',
                'get_activity_timeline',
                'get_day_context',
                'external_lookup',
            ],
            'app_crud' => [
                'search_tasks',
                'search_reminders',
                'search_calendar_events',
                'search_notes',
                'get_day_context',
                'create_task',
                'update_task',
                'delete_task',
                'create_reminder',
                'update_reminder',
                'delete_reminder',
                'create_calendar_event',
                'update_calendar_event',
                'delete_calendar_event',
                'create_note',
                'update_note',
                'delete_note',
            ],
            default => [],
        };

        return collect($tools)
            ->filter(fn (array $tool): bool => in_array((string) data_get($tool, 'function.name'), $allowed, true))
            ->values()
            ->all();
    }

    private function nativeTool(string $name, string $description, array $properties, array $required = []): array
    {
        return [
            'type' => 'function',
            'function' => [
                'name' => $name,
                'description' => $description,
                'parameters' => [
                    'type' => 'object',
                    'properties' => $properties,
                    'required' => $required,
                    'additionalProperties' => true,
                ],
            ],
        ];
    }

    private function idProperties(): array
    {
        return ['id' => ['type' => 'integer']];
    }

    private function searchTaskProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Title or natural words to search for.'],
            'status' => ['type' => 'string'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date for due tasks.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'include_completed' => ['type' => 'boolean'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchReminderProperties(): array
    {
        return [
            'query' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date for reminders.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchCalendarEventProperties(): array
    {
        return [
            'query' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date for events overlapping that day.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchNoteProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Title, folder, or body words to search for.'],
            'folder_id' => ['type' => 'integer'],
            'pinned' => ['type' => 'boolean'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function searchMemoryProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Words related to a preference, project, decision, routine, instruction, or fact.'],
            'type' => ['type' => 'string', 'description' => 'preference, identity, relationship, project, routine, constraint, decision, instruction, temporary_context, or fact.'],
            'status' => ['type' => 'string'],
            'include_archived' => ['type' => 'boolean'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function historyProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Optional words to search in prior user requests.'],
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function activityTimelineProperties(): array
    {
        return [
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date.'],
            'from_date' => ['type' => 'string'],
            'to_date' => ['type' => 'string'],
            'event_type' => ['type' => 'string'],
            'tool_name' => ['type' => 'string'],
            'workspace_id' => ['type' => 'integer'],
            'limit' => ['type' => 'integer'],
        ];
    }

    private function dayContextProperties(): array
    {
        return [
            'date' => ['type' => 'string', 'description' => 'YYYY-MM-DD local date.'],
            'workspace_id' => ['type' => 'integer'],
        ];
    }

    private function externalLookupProperties(): array
    {
        return [
            'query' => ['type' => 'string', 'description' => 'Specific external lookup query. Include relevant names, dates, route, city, or domain details from the user request.'],
            'domain' => ['type' => 'string', 'description' => 'Structured lookup domain when clear, such as weather, places, web, news, finance, sports, travel, or general.'],
            'intent' => ['type' => 'string', 'description' => 'Structured lookup intent, such as current_weather, weather_forecast, nearby_place, business_hours, current_fact, or recent_news.'],
            'context' => ['type' => 'string', 'description' => 'Short reason or constraints for the lookup, such as one-way flights tomorrow or store closing time today.'],
            'location' => ['type' => 'string', 'description' => 'Optional user-provided or inferred location hint.'],
            'date' => ['type' => 'string', 'description' => 'Structured target date in YYYY-MM-DD local format when the request refers to a specific date, such as tomorrow or next Friday. Use runtime_context.temporal_context to resolve relative dates.'],
            'date_range' => ['type' => 'string', 'description' => 'Structured target date range when the request spans multiple days.'],
            'time' => ['type' => 'string', 'description' => 'Structured local time or part of day when relevant.'],
        ];
    }

    private function taskProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'match_title' => ['type' => 'string']], [
            'title' => ['type' => 'string'],
            'type' => ['type' => 'string'],
            'status' => ['type' => 'string', 'description' => 'Use completed when marking a task done; use open for active tasks.'],
            'notes' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'is_critical' => ['type' => 'boolean'],
            'due_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp when applicable.'],
            'recurrence' => $this->recurrenceProperty(),
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function reminderProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'title' => ['type' => 'string'],
            'notes' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'is_critical' => ['type' => 'boolean'],
            'remind_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp.'],
            'status' => ['type' => 'string'],
            'calendar_event_id' => ['type' => 'integer'],
            'recurrence' => $this->recurrenceProperty(),
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function calendarEventProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'match_title' => ['type' => 'string'], 'from_date' => ['type' => 'string']], [
            'title' => ['type' => 'string'],
            'description' => ['type' => 'string'],
            'location' => ['type' => 'string'],
            'category' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'is_critical' => ['type' => 'boolean'],
            'recurrence' => $this->recurrenceProperty(),
            'starts_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp.'],
            'ends_at' => ['type' => 'string', 'description' => 'ISO-8601 local timestamp.'],
            'status' => ['type' => 'string'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
            'workspace_id' => ['type' => 'integer'],
            'target_workspace_id' => ['type' => 'integer'],
        ]);
    }

    private function noteProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'match_title' => ['type' => 'string']], [
            'title' => ['type' => 'string'],
            'body_html' => ['type' => 'string'],
            'plain_text' => ['type' => 'string'],
            'folder_name' => ['type' => 'string'],
            'note_folder_id' => ['type' => 'integer'],
            'is_pinned' => ['type' => 'boolean'],
            'pinned' => ['type' => 'boolean'],
            'body_delta' => ['type' => 'object', 'additionalProperties' => true],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
            'workspace_id' => ['type' => 'integer'],
            'target_workspace_id' => ['type' => 'integer'],
        ]);
    }

    private function noteFolderProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'name' => ['type' => 'string'],
            'sort_order' => ['type' => 'integer'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
            'workspace_id' => ['type' => 'integer'],
            'target_workspace_id' => ['type' => 'integer'],
        ]);
    }

    private function memoryProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer'], 'query' => ['type' => 'string'], 'match_title' => ['type' => 'string']], [
            'type' => ['type' => 'string', 'description' => 'preference, identity, relationship, project, routine, constraint, decision, instruction, temporary_context, or fact.'],
            'title' => ['type' => 'string'],
            'content' => ['type' => 'string'],
            'summary' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'visibility' => ['type' => 'string'],
            'confidence' => ['type' => 'integer'],
            'importance' => ['type' => 'integer'],
            'expires_at' => ['type' => 'string'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function recurrenceProperty(): array
    {
        return [
            'type' => ['string', 'null'],
            'enum' => ['none', 'daily', 'weekly', 'monthly', 'yearly', 'specific_days', 'interval', null],
            'description' => 'Use a string only. For "every N days/weeks/months/years", use interval and set metadata.interval plus metadata.interval_unit.',
        ];
    }

    private function categoryProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'name' => ['type' => 'string'],
            'color' => ['type' => 'string'],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function blockerProperties(bool $requireId = false): array
    {
        return array_merge($requireId ? $this->idProperties() : ['id' => ['type' => 'integer']], [
            'reason' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'context' => ['type' => 'object', 'additionalProperties' => true],
        ]);
    }

    private function agentProfileProperties(): array
    {
        return [
            'slug' => ['type' => 'string'],
            'display_name' => ['type' => 'string'],
            'status' => ['type' => 'string'],
            'provider' => ['type' => 'string'],
            'model' => ['type' => 'string'],
            'router_mode' => ['type' => 'string'],
            'runtime_home' => ['type' => 'string'],
            'settings' => ['type' => 'object', 'additionalProperties' => true],
            'tool_policy' => ['type' => 'object', 'additionalProperties' => true],
            'approval_policy' => ['type' => 'object', 'additionalProperties' => true],
            'metadata' => ['type' => 'object', 'additionalProperties' => true],
        ];
    }

    private function toolRuntimeFailed(ConversationSession $session, ConversationMessage $userMessage, Collection $events, string $message, array $context): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events, $message, $context): array {
            $failed = $this->recordEvent($session, 'runtime.tool_model_failed', [
                'message_id' => $userMessage->id,
                'reason' => $message,
                ...$context,
            ], 'hermes.tools', 'failed');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $this->assistantSafeResponseContent($message),
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'failure' => $context,
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
                'events' => $events->push($failed)->push($messageCompleted),
                'blocker' => null,
            ];
        });
    }

    private function toolRuntimeBlocked(ConversationSession $session, ConversationMessage $userMessage, Collection $events, string $message, array $context): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events, $message, $context): array {
            $blocked = $this->recordEvent($session, 'runtime.usage_blocked', [
                'message_id' => $userMessage->id,
                'reason' => $message,
                ...$context,
            ], 'usage.guardrail', 'blocked');

            $assistantMessage = ConversationMessage::create([
                'user_id' => $session->user_id,
                'conversation_session_id' => $session->id,
                'role' => 'assistant',
                'content' => $message,
                'metadata' => [
                    'runtime' => 'tools',
                    'provider' => config('services.hermes_runtime.default_provider'),
                    'blocked' => $context,
                ],
            ]);

            $messageCompleted = $this->recordEvent($session, 'runtime.message_completed', [
                'message_id' => $assistantMessage->id,
            ]);

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'blocked',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => $assistantMessage,
                'events' => $events->push($blocked)->push($messageCompleted),
                'blocker' => null,
            ];
        });
    }

    private function toolRuntimeCancelled(ConversationSession $session, ConversationMessage $userMessage, Collection $events): array
    {
        return DB::transaction(function () use ($session, $userMessage, $events): array {
            $cancelled = $this->recordEvent($session, 'runtime.message_cancelled', [
                'message_id' => $userMessage->id,
            ], 'hermes.tools', 'cancelled');

            $session->update(['status' => 'active', 'last_activity_at' => now()]);

            return [
                'status' => 'cancelled',
                'session' => $session->refresh(),
                'user_message' => $userMessage,
                'assistant_message' => null,
                'events' => $events->push($cancelled),
                'blocker' => null,
            ];
        });
    }

    private function isCancellationRequested(ConversationSession $session): bool
    {
        return ConversationSession::query()
            ->whereKey($session->id)
            ->where('status', 'cancelling')
            ->exists();
    }

    /**
     * @return array{mode:string,tier:string,model:?string,billing_model:string,context_mode:string,reason:string}
     */
    private function modelRouteFor(ConversationSession $session): array
    {
        $defaultModel = $this->adminSettings->mainModel();
        $model = (string) ($this->adminSettings->mainModelOverride() ?: $this->profileForSession($session)?->model ?: $defaultModel);

        return [
            'mode' => 'agent',
            'tier' => 'agent',
            'model' => $model,
            'billing_model' => $model,
            'context_mode' => 'focused',
            'reason' => 'Laravel does not route by keyword; the configured agent model receives native app tools.',
        ];
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

    private function toolOutputsAllSuccessfulWrites(array $toolOutputs): bool
    {
        if ($toolOutputs === []) {
            return false;
        }

        foreach ($toolOutputs as $output) {
            if (! is_array($output) || ($output['ok'] ?? false) !== true || ! filled($output['action_type'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private function expectedWriteActionCount(ConversationMessage $message): int
    {
        if ($this->messageIsCapabilityQuestion($message)) {
            return 0;
        }

        $rawText = str((string) $message->content)->lower()->squish()->toString();
        $text = str($rawText)
            ->lower()
            ->replaceMatches('/[^\pL\pN\s:.-]+/u', ' ')
            ->squish()
            ->toString();
        if ($text === '') {
            return 1;
        }

        $count = 0;
        if (preg_match('/\b(task|tasks|todo|to do|to-do)\b/', $text)) {
            $count++;
        }
        if (preg_match('/\b(reminder|reminders|remind me|remind)\b/', $text)) {
            $count++;
        }
        $hasExplicitCalendarTarget = (bool) preg_match('/\b(calendar|schedule|event|events|appointment|appointments|block)\b/', $text);
        $hasMeetingTarget = (bool) preg_match('/\b(meeting|meetings)\b/', $text)
            && ! (bool) preg_match('/\bmeeting\s+(agenda|prep|notes?)\b/', $text);
        if ($hasExplicitCalendarTarget || $hasMeetingTarget) {
            $count++;
        }
        if (
            preg_match('/\b(note|notes|folder|folders)\b/', $text)
            && (
                preg_match('/\b(create|make|add|write|save|pin|edit|update|delete|remove|move|lock)\s+(?:a\s+|an\s+|the\s+)?(?:note|notes|folder|folders)\b/', $text)
                || preg_match('/\b(?:note|notes|folder|folders)\s+(?:called|titled|named)\b/', $text)
                || preg_match('/\b(?:as|to)\s+(?:a\s+|the\s+)?(?:note|notes|folder|folders)\b/', $text)
            )
        ) {
            $count++;
        }

        if ($count === 0 && preg_match('/\b(add|create|make|set|update|change|move|delete|remove|complete|mark)\b/', $text)) {
            $count = 1;
        }

        $datedItemCount = preg_match_all('/\b(?:\d{1,2}[\/.-]\d{1,2}(?:[\/.-]\d{2,4})?|\d{4}-\d{2}-\d{2})\b/', $rawText);
        if ($datedItemCount > 1 && preg_match('/\b(calendar|schedule|event|events|appointment|meeting|block)\b/', $text)) {
            $count = max($count, $datedItemCount);
        }

        return max(1, min($count, 6));
    }

    private function messageIsCapabilityQuestion(ConversationMessage $message): bool
    {
        $text = str(str_replace('’', "'", (string) $message->content))
            ->lower()
            ->replaceMatches('/[^\pL\pN\s\'?.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($text === '') {
            return false;
        }

        if (
            ! preg_match('/\?$/u', $text)
            && ! preg_match('/^(can|could|would|will|do|does|are|is)\b/u', $text)
        ) {
            return false;
        }

        if (preg_match('/\b(that says|saying|called|titled|named|at\s+\d|on\s+\d|tomorrow|today|tonight|this\s+(morning|afternoon|evening|week|month)|next\s+\w+|from\s+\d|to\s+\d|\d{1,2}:\d{2}|for\s+(tomorrow|today|tonight|next|monday|tuesday|wednesday|thursday|friday|saturday|sunday))\b/u', $text)) {
            return false;
        }

        return (bool) preg_match(
            '/^(can|could|would|will|do|does|are|is)\s+(you|bean)\b.{0,120}\b(create|add|make|schedule|book|set|update|change|move|reschedule|delete|remove|cancel|complete|mark|remember|forget|look up|find|search|sync|manage|handle|help)\b.{0,80}\??$/u',
            $text
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function directExternalLookupArguments(ConversationSession $session, ConversationMessage $message): ?array
    {
        if ($this->messageIsCapabilityQuestion($message) || $this->messageIsRequestHistoryRecall($message)) {
            return null;
        }

        $content = str((string) $message->content)->squish()->toString();
        if ($content === '') {
            return null;
        }

        $text = str(str_replace('’', "'", $content))
            ->lower()
            ->replaceMatches('/[^\pL\pN\s\'?.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($this->directLookupTextLooksLikeAppWrite($text)) {
            return null;
        }

        $weatherArguments = $this->directWeatherLookupArguments($session, $content, $text);
        if ($weatherArguments !== null) {
            return $weatherArguments;
        }

        return $this->directPlacesLookupArguments($content, $text);
    }

    private function directLookupTextLooksLikeAppWrite(string $text): bool
    {
        return (bool) preg_match('/\b(add|create|make|schedule|book|set|move|update|delete|remove|cancel|complete|mark|save|pin|lock)\b/u', $text)
            && (bool) preg_match('/\b(task|tasks|reminder|reminders|note|notes|calendar|event|events|appointment|appointments|workspace|workspaces|folder|folders)\b/u', $text);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function directWeatherLookupArguments(ConversationSession $session, string $content, string $text): ?array
    {
        if (! (bool) config('services.hermes_runtime.weather_lookup_enabled', true)) {
            return null;
        }

        if (! preg_match('/\b(weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b/u', $text)) {
            return null;
        }

        $location = $this->directWeatherLocation($content);
        if ($location === '') {
            return null;
        }

        $timezone = $this->sessionDisplayTimezone($session);
        $date = null;
        $intent = 'current_weather';
        if (preg_match('/\b(tomorrow|forecast)\b/u', $text)) {
            $intent = 'weather_forecast';
            $date = str_contains($text, 'tomorrow')
                ? now($this->validTimezone($timezone) ? $timezone : config('app.timezone'))->addDay()->toDateString()
                : null;
        }

        return array_filter([
            'query' => $content,
            'domain' => 'weather',
            'intent' => $intent,
            'location' => $location,
            'date' => $date,
        ], fn (mixed $value): bool => $value !== null && $value !== '');
    }

    private function directWeatherLocation(string $content): string
    {
        $patterns = [
            '/\b(?:weather|forecast|temperature|temp|rain|raining|storm|storming|snow|snowing|humidity|wind|windy|cloudy|sunny)\b.*?\b(?:in|for|near|at)\s+(.+?)(?:\s+(?:right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening))?\s*[?.!]*$/iu',
            '/\b(?:in|for|near|at)\s+(.+?)(?:\s+(?:right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening))?\s*[?.!]*$/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $content, $match)) {
                $candidate = trim((string) ($match[1] ?? ''));
                $candidate = preg_replace('/\b(right now|currently|now|today|tonight|tomorrow|this morning|this afternoon|this evening)\b/iu', '', $candidate) ?? $candidate;
                $candidate = preg_replace('/^\s*(?:in|for|near|at)\s+/iu', '', $candidate) ?? $candidate;
                $candidate = trim($candidate, " \t\n\r\0\x0B,.?!'\"");
                if ($candidate !== '' && ! preg_match('/\b(weather|forecast|temperature|temp)\b/iu', $candidate)) {
                    return $candidate;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function directPlacesLookupArguments(string $content, string $text): ?array
    {
        if (! (bool) config('services.hermes_runtime.google_places_enabled', true)) {
            return null;
        }

        if (! preg_match('/\b(nearest|closest|nearby|near me|near|around|local)\b/u', $text)) {
            return null;
        }

        if (! preg_match('/\b\d{5}(?:-\d{4})?\b/u', $text) && ! preg_match('/\b(?:near|around|in|to)\s+[a-z][a-z\s,.-]+$/iu', $content)) {
            return null;
        }

        if (preg_match('/\b(weather|forecast|temperature|temp|news|latest|stock|stocks|score|sports|price|prices)\b/u', $text)) {
            return null;
        }

        return [
            'query' => $content,
            'domain' => 'places',
            'intent' => 'nearby_place',
        ];
    }

    private function messageIsRequestHistoryRecall(ConversationMessage $message): bool
    {
        $text = str(str_replace('’', "'", (string) $message->content))
            ->lower()
            ->replaceMatches('/[^\pL\pN\s\'?.-]+/u', ' ')
            ->squish()
            ->toString();

        if ($text === '') {
            return false;
        }

        return (bool) preg_match('/\bwhat\s+(?:did|was)\s+(?:i|my)\b.{0,80}\b(?:ask|asked|request|requested)\b/u', $text)
            || (bool) preg_match('/\bwhat\s+request\s+did\s+i\s+make\b/u', $text)
            || (bool) preg_match('/\bwhich\s+request\s+did\s+i\s+make\b/u', $text)
            || (bool) preg_match('/\bwhat\s+was\s+my\s+earlier\s+request\b/u', $text);
    }

    private function requestHistoryRecallQuery(ConversationMessage $message): string
    {
        $content = str((string) $message->content)->squish()->toString();
        if ($content === '') {
            return '';
        }

        $content = preg_replace('/^\s*REQ-\d{3}\s*:\s*/iu', '', $content) ?: $content;

        if (preg_match_all('/\bREQ-\d{3}\b/iu', $content, $matches) && ! empty($matches[0])) {
            return strtoupper((string) end($matches[0]));
        }

        if (preg_match('/\babout\s+(.+?)(?:\s+earlier\b|\s+in\s+this\b|\s+if\s+any\b|\?|\.|$)/iu', $content, $match)) {
            $query = $this->cleanDeterministicTitle((string) ($match[1] ?? ''));
            if ($query !== '') {
                return $query;
            }
        }

        return $this->cleanDeterministicTitle(
            preg_replace('/\b(?:what|which|request|did|i|make|ask|asked|requested|earlier|smoke|run|include|the|req|number|if|any|there|was|none|say|so|clearly)\b/iu', ' ', $content) ?: $content
        );
    }

    private function capabilityQuestionFallbackContent(ConversationMessage $message): string
    {
        $text = str((string) $message->content)->lower()->toString();

        if (preg_match('/\b(calendar|event|events|schedule|appointment|appointments)\b/u', $text)) {
            return 'Yes - I can create calendar events when you give me the details.';
        }

        if (preg_match('/\b(note|notes)\b/u', $text)) {
            return 'Yes - I can create and manage notes when you give me the details.';
        }

        if (preg_match('/\b(reminder|reminders|remind)\b/u', $text)) {
            return 'Yes - I can create reminders when you give me the details.';
        }

        if (preg_match('/\b(task|tasks|todo|to do)\b/u', $text)) {
            return 'Yes - I can create and manage tasks when you give me the details.';
        }

        if (preg_match('/\b(look up|find|search|weather|store hours|current)\b/u', $text)) {
            return 'Yes - I can look that up when you tell me what you need.';
        }

        return 'Yes - I can help with that when you give me the details.';
    }

    private function nativeActionFallbackContent(array $actions): string
    {
        $combinedDelete = $this->combinedDeleteFallbackContent($actions);
        if ($combinedDelete !== null) {
            return $combinedDelete;
        }

        $phrases = collect($actions)
            ->filter(fn (mixed $action): bool => is_array($action))
            ->map(fn (array $action): ?string => $this->nativeActionSummaryPhrase($action))
            ->filter()
            ->values();

        if ($phrases->isEmpty()) {
            return 'Done.';
        }

        return 'Done - '.$this->joinSummaryPhrases($phrases->all()).'.';
    }

    private function partialCrudPlannerContent(array $successfulActions, array $failedWorkItems, int $totalActionCount): string
    {
        $completedCount = count($successfulActions);
        $failedItems = collect($failedWorkItems)
            ->map(fn (mixed $item): array => is_array($item) ? $item : ['label' => (string) $item])
            ->values();
        $failedLabels = $failedItems
            ->map(fn (array $item): string => trim((string) ($item['label'] ?? '')))
            ->filter()
            ->unique()
            ->values();

        $completed = $completedCount > 0
            ? 'I completed '.$completedCount.' of '.$totalActionCount.' requested change'.($totalActionCount === 1 ? '' : 's').'.'
            : 'I need one more thing before I can make the requested change'.($totalActionCount === 1 ? '' : 's').'.';

        if ($completedCount > 0) {
            $completed .= ' '.$this->nativeActionFallbackContent($successfulActions);
        }

        $upgradeMessage = $this->planUpgradeMessageForFailedWorkItems($failedItems->all());
        if ($upgradeMessage !== null) {
            return $completedCount > 0
                ? $completed.' '.$upgradeMessage
                : $upgradeMessage;
        }

        if ($failedLabels->isEmpty()) {
            return $completed.' I need a little more detail before I can handle the rest.';
        }

        return $completed.' I need a little more detail for '.$this->joinSummaryPhrases($failedLabels->all()).' before I can handle the rest.';
    }

    /**
     * @param  array<int, array<string, mixed>>  $failedWorkItems
     */
    private function planUpgradeMessageForFailedWorkItems(array $failedWorkItems): ?string
    {
        $entitlementFailures = collect($failedWorkItems)
            ->filter(function (array $item): bool {
                $message = (string) ($item['message'] ?? '');
                $errorCode = (string) ($item['error_code'] ?? '');

                return $errorCode === 'subscription_limit_reached'
                    || str_contains($message, 'available on Premium, Pro, and Enterprise plans')
                    || str_contains($message, 'Your current plan includes up to');
            })
            ->values();

        if ($entitlementFailures->isEmpty()) {
            return null;
        }

        $allFailuresAreEntitlements = $entitlementFailures->count() === count($failedWorkItems);
        if (! $allFailuresAreEntitlements) {
            return null;
        }

        $hasNoteFailure = $entitlementFailures->contains(function (array $item): bool {
            $actionType = (string) ($item['action_type'] ?? '');
            $message = (string) ($item['message'] ?? '');
            $label = (string) ($item['label'] ?? '');

            return str_starts_with($actionType, 'note.')
                || str_starts_with($actionType, 'note_folder.')
                || str_contains($message, 'Notes are available')
                || str_contains(mb_strtolower($label), 'note');
        });

        if ($hasNoteFailure) {
            $message = trim((string) ($entitlementFailures->first()['message'] ?? ''));

            return ($message === '' ? 'Your current plan note limit has been reached.' : rtrim($message, '.')).'. Upgrade your plan to create and manage more notes.';
        }

        $message = trim((string) ($entitlementFailures->first()['message'] ?? ''));
        if ($message === '') {
            return 'That feature needs an upgraded plan. Upgrade your plan to keep going.';
        }

        return rtrim($message, '.').'. Upgrade your plan to use this feature.';
    }

    private function combinedDeleteFallbackContent(array $actions): ?string
    {
        $typed = collect($actions)
            ->filter(fn (mixed $action): bool => is_array($action))
            ->map(function (array $action): ?array {
                $type = (string) ($action['type'] ?? '');
                if (! str_ends_with($type, '.delete') && ! in_array($type, ['calendar.delete'], true)) {
                    return null;
                }

                $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
                $kind = match ($type) {
                    'calendar_event.delete', 'calendar.delete' => 'calendar event',
                    'reminder.delete' => 'reminder',
                    'task.delete' => 'task',
                    'note.delete' => 'note',
                    default => null,
                };

                return $kind === null ? null : [
                    'kind' => $kind,
                    'title' => $this->summaryTitle($parameters),
                ];
            })
            ->filter()
            ->values();

        if ($typed->count() < 2 || $typed->count() !== count($actions)) {
            return null;
        }

        $titles = $typed->pluck('title')->filter()->unique()->values();
        if ($titles->count() !== 1) {
            return null;
        }

        $kinds = $typed->pluck('kind')->unique()->values()->all();

        return 'Done - I deleted the '.$this->joinSummaryPhrases($kinds).' for '.$titles->first().'.';
    }

    private function nativeActionSummaryPhrase(array $action): ?string
    {
        $type = (string) ($action['type'] ?? '');
        $parameters = is_array($action['parameters'] ?? null) ? $action['parameters'] : [];
        $title = $this->summaryTitle($parameters);

        return match ($type) {
            'calendar_event.create', 'calendar.create' => 'I added '.$this->summaryObject($title, 'the event').' to your calendar'.$this->calendarTimeSummary($parameters),
            'calendar_event.update', 'calendar.update' => 'I updated '.$this->summaryObject($title, 'the calendar event').$this->calendarTimeSummary($parameters),
            'calendar_event.delete', 'calendar.delete' => 'I deleted '.$this->summaryObject($title, 'the calendar event'),
            'task.create' => 'I added '.$this->summaryObject($title, 'the task').' to your tasks'.$this->singleTimeSummary($parameters['due_at'] ?? null, ' due '),
            'task.update' => 'I updated '.$this->summaryObject($title, 'the task').$this->singleTimeSummary($parameters['due_at'] ?? null, ' due '),
            'task.delete' => 'I deleted '.$this->summaryObject($title, 'the task'),
            'reminder.create' => 'I set '.$this->summaryObject($title, 'the reminder').$this->singleTimeSummary($parameters['remind_at'] ?? null, ' for '),
            'reminder.update' => 'I updated '.$this->summaryObject($title, 'the reminder').$this->singleTimeSummary($parameters['remind_at'] ?? null, ' for '),
            'reminder.delete' => 'I deleted '.$this->summaryObject($title, 'the reminder'),
            'note.create' => 'I created '.$this->summaryObject($title, 'the note'),
            'note.update' => 'I updated '.$this->summaryObject($title, 'the note'),
            'note.delete' => 'I deleted '.$this->summaryObject($title, 'the note'),
            'note_folder.create' => 'I created '.$this->summaryObject($title, 'the folder'),
            'note_folder.update' => 'I updated '.$this->summaryObject($title, 'the folder'),
            'note_folder.delete' => 'I deleted '.$this->summaryObject($title, 'the folder'),
            'event_category.create' => 'I created '.$this->summaryObject($title, 'the event category'),
            'event_category.update' => 'I updated '.$this->summaryObject($title, 'the event category'),
            'event_category.delete' => 'I deleted '.$this->summaryObject($title, 'the event category'),
            'memory.create' => 'I saved that to Bean knowledge',
            'memory.update' => 'I updated Bean knowledge',
            'memory.delete' => 'I removed that from Bean knowledge',
            'agent_profile.update' => 'I updated Bean settings',
            'workspace_memory.note' => 'I saved that workspace knowledge',
            default => null,
        };
    }

    private function summaryTitle(array $parameters): string
    {
        foreach (['title', 'name', 'match_title', 'summary', 'reason', 'content'] as $key) {
            $value = trim((string) ($parameters[$key] ?? ''));
            if ($value !== '') {
                return str($value)->squish()->limit(80, '')->toString();
            }
        }

        return '';
    }

    private function summaryObject(string $title, string $fallback): string
    {
        return $title !== '' ? $title : $fallback;
    }

    private function calendarTimeSummary(array $parameters): string
    {
        $startsAt = $this->summaryDateTime($parameters['starts_at'] ?? $parameters['start_at'] ?? null);
        $endsAt = $this->summaryDateTime($parameters['ends_at'] ?? $parameters['end_at'] ?? null);
        if ($startsAt === null) {
            return '';
        }

        if ($endsAt === null) {
            return ' for '.$startsAt;
        }

        return ' from '.$startsAt.' to '.$endsAt;
    }

    private function singleTimeSummary(mixed $value, string $prefix): string
    {
        $dateTime = $this->summaryDateTime($value);

        return $dateTime === null ? '' : $prefix.$dateTime;
    }

    private function summaryDateTime(mixed $value): ?string
    {
        $raw = trim((string) $value);
        if ($raw === '') {
            return null;
        }

        try {
            return Carbon::parse($raw)->format('M j, g:i A');
        } catch (\Throwable) {
            return str($raw)->squish()->limit(60, '')->toString();
        }
    }

    /**
     * @param  array<int, string>  $phrases
     */
    private function joinSummaryPhrases(array $phrases): string
    {
        $phrases = array_values(array_filter($phrases, fn (string $phrase): bool => trim($phrase) !== ''));
        if (count($phrases) <= 1) {
            return $phrases[0] ?? 'Done';
        }

        if (count($phrases) === 2) {
            return $phrases[0].' and '.$phrases[1];
        }

        $last = array_pop($phrases);

        return implode(', ', $phrases).', and '.$last;
    }

    private function normalizedAssistantContent(mixed $content): string
    {
        $trimmed = trim((string) $content);
        if ($trimmed === '') {
            return '';
        }

        try {
            $decoded = json_decode($trimmed, true, flags: JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return $trimmed;
        }

        if (! is_array($decoded)) {
            return $trimmed;
        }

        foreach (['message', 'content', 'assistant_message', 'response'] as $key) {
            $value = $decoded[$key] ?? null;
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        if (array_key_exists('role', $decoded) || array_key_exists('content', $decoded)) {
            return '';
        }

        return $trimmed;
    }

    private function assistantSafeResponseContent(string $content): string
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return '';
        }

        $normalized = str($trimmed)->lower()->squish()->toString();
        $hardFailures = [
            'bean could not finish',
            'could not finish that request',
            'bean could not complete',
            'could not complete the requested change',
            'i could not complete',
            'i tried to check that live information, but the lookup did not return a usable result',
            'i tried to check that live information',
            'i tried to check live information',
            'i could not get live information',
            'i couldn\'t get live information',
            'i couldn’t get live information',
            'could not get live information',
            'couldn\'t get live information',
            'couldn’t get live information',
            'lookup did not return',
            'lookup didn\'t return',
            'lookup didn’t return',
            'could not get that live lookup back quickly enough',
            'couldn\'t get that live lookup back quickly enough',
            'couldn’t get that live lookup back quickly enough',
            'live lookup back quickly enough',
            'did not return a usable result',
            'no usable result',
        ];

        foreach ($hardFailures as $phrase) {
            if (str_contains($normalized, $phrase)) {
                return 'I’m on it. I’m syncing against the latest app state now, and I’ll ask for one detail if I need it.';
            }
        }

        return $trimmed;
    }

    private function nativeReadFallbackContent(array $toolOutputs): string
    {
        $last = collect($toolOutputs)->reverse()->first(fn (mixed $output): bool => is_array($output));
        if (! is_array($last)) {
            return 'Done.';
        }

        $successfulDayContext = collect($toolOutputs)
            ->reverse()
            ->first(fn (mixed $output): bool => is_array($output)
                && ($output['tool'] ?? null) === 'get_day_context'
                && ($output['ok'] ?? false));

        if (is_array($successfulDayContext)) {
            return $this->dayContextFallbackContent($successfulDayContext);
        }

        if (($last['tool'] ?? null) === 'get_request_history' && ($last['ok'] ?? false)) {
            return $this->requestHistoryFallbackContent($last);
        }

        if (($last['tool'] ?? null) === 'external_lookup') {
            $successfulLookup = collect($toolOutputs)
                ->reverse()
                ->first(fn (mixed $output): bool => is_array($output)
                    && ($output['tool'] ?? null) === 'external_lookup'
                    && filled($output['text'] ?? null));

            if (is_array($successfulLookup)) {
                return (string) $successfulLookup['text'];
            }

            return 'I’m checking live sources now. Send me one more detail if you want me to narrow it down further.';
        }

        if (($last['ok'] ?? false) && array_key_exists('count', $last)) {
            $count = (int) ($last['count'] ?? 0);
            $tool = str_replace('_', ' ', (string) ($last['tool'] ?? 'records'));

            return $count === 0
                ? "I checked {$tool}, but I did not find anything matching that."
                : "I found {$count} matching ".str($tool)->after('search ')->toString().'.';
        }

        return 'I’m checking that now. I’ll ask for one more detail if I need it.';
    }

    private function canUseNativeReadFallback(array $toolOutputs): bool
    {
        if ($toolOutputs === []) {
            return false;
        }

        $last = collect($toolOutputs)->reverse()->first(fn (mixed $output): bool => is_array($output));
        if (! is_array($last) || ($last['ok'] ?? false) !== true) {
            return false;
        }

        if (($last['tool'] ?? null) === 'external_lookup') {
            return filled($last['text'] ?? null);
        }

        return in_array(($last['tool'] ?? null), ['get_day_context', 'get_request_history'], true);
    }

    private function requestHistoryFallbackContent(array $output): string
    {
        $items = collect((array) ($output['items'] ?? []))
            ->filter(fn (mixed $item): bool => is_array($item) && filled($item['content'] ?? null))
            ->values();

        if ($items->isEmpty()) {
            return 'I checked your request history, but I did not find anything matching that.';
        }

        $lines = $items
            ->take(5)
            ->map(function (array $item): string {
                $createdAt = trim((string) ($item['created_at'] ?? ''));
                $content = str((string) ($item['content'] ?? ''))->squish()->limit(180, '')->toString();

                return trim(($createdAt !== '' ? $createdAt.' - ' : '').$content);
            })
            ->filter()
            ->values()
            ->all();

        if (count($lines) === 1) {
            return 'You asked: '.$lines[0];
        }

        return "Here is what I found in your request history:\n- ".implode("\n- ", $lines);
    }

    private function dayContextFallbackContent(array $output): string
    {
        $date = (string) ($output['date'] ?? 'today');
        $items = [];

        foreach ((array) ($output['calendar_events'] ?? []) as $event) {
            if (! is_array($event)) {
                continue;
            }
            $time = trim((string) ($event['display_start_time'] ?? ''));
            $title = trim((string) ($event['title'] ?? 'Untitled event'));
            $items[] = trim(($time !== '' ? "{$time} - " : '')."Event: {$title}");
        }

        foreach ((array) ($output['tasks'] ?? []) as $task) {
            if (! is_array($task)) {
                continue;
            }
            $time = trim((string) ($task['display_due_time'] ?? ''));
            $title = trim((string) ($task['title'] ?? 'Untitled task'));
            $items[] = trim(($time !== '' ? "{$time} - " : '')."Task: {$title}");
        }

        foreach ((array) ($output['reminders'] ?? []) as $reminder) {
            if (! is_array($reminder)) {
                continue;
            }
            $time = trim((string) ($reminder['display_remind_time'] ?? ''));
            $title = trim((string) ($reminder['title'] ?? 'Untitled reminder'));
            $items[] = trim(($time !== '' ? "{$time} - " : '')."Reminder: {$title}");
        }

        $items = collect($items)
            ->map(fn (string $item): string => str($item)->squish()->toString())
            ->filter()
            ->unique(fn (string $item): string => mb_strtolower($item))
            ->values()
            ->all();

        if ($items === []) {
            return "Nothing else is scheduled for {$date}.";
        }

        $limited = array_slice($items, 0, 8);
        $suffix = count($items) > count($limited) ? "\n- Plus ".(count($items) - count($limited)).' more.' : '';

        return "Here is what is coming up for {$date}:\n- ".implode("\n- ", $limited).$suffix;
    }
}
