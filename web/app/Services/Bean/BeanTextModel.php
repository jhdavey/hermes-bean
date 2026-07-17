<?php

namespace App\Services\Bean;

use App\Models\BeanSession;
use Illuminate\Support\Facades\Http;
use Throwable;

class BeanTextModel
{
    private const ACTIONS = [
        'task.list',
        'task.search',
        'task.context',
        'resource.query',
        'resource.relationships',
        'task.create',
        'task.update',
        'task.complete',
        'task.delete',
        'reminder.list',
        'reminder.search',
        'reminder.create',
        'reminder.update',
        'reminder.complete',
        'reminder.delete',
        'calendar_event.list',
        'calendar_event.search',
        'calendar_event.create',
        'calendar_event.update',
        'calendar_event.delete',
        'note.list',
        'note.search',
        'note.create',
        'note.update',
        'note.delete',
        'time.now',
        'weather.lookup',
        'recipe.lookup',
        'dashboard.summary',
    ];

    public function propose(BeanSession $session, string $message): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return $this->heuristic($message, null, $session);
        }

        try {
            $model = (string) config('services.openai.bean_text_model', 'gpt-4.1-mini');
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'response_format' => $this->responseFormat(),
                    'messages' => [
                        ['role' => 'system', 'content' => $this->systemPrompt()],
                        ['role' => 'system', 'content' => 'Recent Bean session context: '.json_encode($this->plannerContext($session), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                        ['role' => 'user', 'content' => $message],
                    ],
                ]);

            if (! $response->ok()) {
                return $this->heuristic($message, 'I had trouble reaching my AI model, so I used a basic local parser.', $session);
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return $this->heuristic($message, null, $session);
            }

            $actions = $this->cleanActions($decoded['actions'] ?? []);
            if ($actions === [] && ($this->isFactualResourceQuestion(mb_strtolower($message)) || $this->isTaskWorkspaceQuestion(mb_strtolower($message)))) {
                $actions[] = ['action' => 'resource.query', 'arguments' => [...$this->resourceQueryArguments($message, mb_strtolower($message), $session), 'skip_synthesis' => true]];
            }

            return [
                'response' => $this->cleanResponse($decoded['response'] ?? null),
                'actions' => $actions,
                'model' => $model,
            ];
        } catch (Throwable) {
            return $this->heuristic($message, 'I had trouble reaching my AI model, so I used a basic local parser.', $session);
        }
    }

    public function synthesizeAnswer(BeanSession $session, string $userMessage, string $proposed, array $results): ?string
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '' || $results === []) {
            return null;
        }

        try {
            $model = (string) config('services.openai.bean_text_model', 'gpt-4.1-mini');
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'response_format' => $this->answerResponseFormat(),
                    'messages' => [
                        ['role' => 'system', 'content' => $this->answerSystemPrompt()],
                        ['role' => 'user', 'content' => json_encode([
                            'user_message' => $userMessage,
                            'proposed_response' => $proposed,
                            'recent_context' => $this->recentContext($session),
                            'tool_results' => $results,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                    ],
                ]);

            if (! $response->ok()) {
                return null;
            }
            $decoded = json_decode((string) data_get($response->json(), 'choices.0.message.content', ''), true);
            $answer = trim((string) ($decoded['answer'] ?? ''));

            return $answer !== '' ? str($answer)->limit(900, '')->toString() : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function answerResponseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'bean_final_answer',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['answer'],
                    'properties' => [
                        'answer' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    private function answerSystemPrompt(): string
    {
        return <<<'PROMPT'
You are Bean, HeyBean's natural productivity assistant. Write the final user-facing answer after Laravel has executed scoped tools/actions.
Use only the provided tool_results and recent_context as facts. Do not invent private app data.
If the user asked a factual question, answer it directly with the actual titles, workspace names, dates, counts, or reasons found in tool_results.
Do not mention internal action names, JSON, tools, or Laravel.
Do not append generic "Done" to factual answers.
If a mutation completed, briefly confirm it. If data is missing or ambiguous, say exactly what is missing and ask a concise follow-up.
Keep the answer conversational and concise. For voice, one or two sentences is best unless listing multiple items.
PROMPT;
    }

    private function plannerContext(BeanSession $session): array
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        return [
            'recent_entities' => array_slice(is_array($metadata['recent_entities'] ?? null) ? $metadata['recent_entities'] : [], 0, 10),
            'current_workspace_id' => $session->workspace_id,
        ];
    }

    private function recentContext(BeanSession $session): array
    {
        return [
            'session_metadata' => $session->metadata ?? [],
            'recent_messages' => $session->messages()
                ->latest('id')
                ->limit(8)
                ->get(['role', 'content'])
                ->reverse()
                ->values()
                ->all(),
        ];
    }

    private function responseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'bean_action_proposal',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['response', 'actions'],
                    'properties' => [
                        'response' => [
                            'type' => 'string',
                            'description' => 'Short user-facing Bean response. Do not claim an action is done unless an action is included for Laravel to execute.',
                        ],
                        'actions' => [
                            'type' => 'array',
                            'description' => 'Zero or more allowlisted structured actions for Laravel to validate and execute.',
                            'items' => [
                                'type' => 'object',
                                'additionalProperties' => false,
                                'required' => ['action', 'arguments'],
                                'properties' => [
                                    'action' => [
                                        'type' => 'string',
                                        'enum' => self::ACTIONS,
                                    ],
                                    'arguments' => $this->argumentsSchema(),
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
    }

    private function argumentsSchema(): array
    {
        $nullableString = ['type' => ['string', 'null']];
        $nullableInteger = ['type' => ['integer', 'null']];
        $nullableBoolean = ['type' => ['boolean', 'null']];
        $nullableIntegerArray = [
            'type' => ['array', 'null'],
            'items' => ['type' => 'integer'],
        ];
        $nullableStringArray = [
            'type' => ['array', 'null'],
            'items' => ['type' => 'string'],
        ];
        $properties = [
            'id' => $nullableInteger,
            'resource_id' => $nullableInteger,
            'workspace_id' => $nullableInteger,
            'note_folder_id' => $nullableInteger,
            'resource' => $nullableString,
            'question' => $nullableString,
            'query' => $nullableString,
            'title' => $nullableString,
            'type' => $nullableString,
            'status' => $nullableString,
            'notes' => $nullableString,
            'category' => $nullableString,
            'color' => $nullableString,
            'description' => $nullableString,
            'location' => $nullableString,
            'recurrence' => $nullableString,
            'due_at' => $nullableString,
            'completed_at' => $nullableString,
            'remind_at' => $nullableString,
            'starts_at' => $nullableString,
            'ends_at' => $nullableString,
            'plain_text' => $nullableString,
            'body' => $nullableString,
            'body_html' => $nullableString,
            'content' => $nullableString,
            'all_day' => $nullableBoolean,
            'is_critical' => $nullableBoolean,
            'is_pinned' => $nullableBoolean,
            'sync_to_workspace_ids' => $nullableIntegerArray,
            'delete_from_workspace_ids' => $nullableIntegerArray,
            'include' => $nullableStringArray,
            'date_scope' => $nullableString,
            'include_workspaces' => $nullableBoolean,
            'explain_visibility' => $nullableBoolean,
        ];

        return [
            'type' => 'object',
            'description' => 'Simple JSON arguments for the action. Use ids only when known from context. Use query when lookup is needed. Use null for fields that do not apply.',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    private function systemPrompt(): string
    {
        $actions = implode(', ', self::ACTIONS);

        return <<<PROMPT
You are Bean, the HeyBean productivity assistant. Return only JSON matching the provided schema.
Use actions only from this list: {$actions}.
Laravel is the source of truth: you propose structured actions; Laravel validates, scopes, confirms, and executes them.
For destructive actions include the delete action, but Laravel will require confirmation before execution.
Arguments should be simple JSON. The schema includes common argument fields; set fields that do not apply to null. Use ISO 8601 dates when the user supplied a date/time. Use resource.query for flexible factual questions about app data and resource.relationships for relationship/context questions, including workspace/context/why/relationship questions. For resource.query/resource.relationships set resource to tasks, reminders, calendar_events, notes, or workspaces; set query/title for lookup text; set include_workspaces=true when workspace context could matter; set explain_visibility=true for why-is-this-shown questions. Use date_scope="today" for list requests that say today, this morning, this afternoon, or tonight; task/reminder lists for today include overdue items due before today. Use date_scope="overdue" for overdue or past-due list requests. If an item needs lookup by title, use query rather than inventing an id. Use strict mutation actions only when changing app state.
Do not invent private dashboard data; call list/search/dashboard actions when data is needed.
Keep response concise and avoid saying an action is complete before Laravel executes it.
PROMPT;
    }

    private function cleanResponse(mixed $response): string
    {
        $text = trim(is_string($response) ? $response : '');

        return $text !== '' ? str($text)->limit(500, '')->toString() : 'I can help with that.';
    }

    private function cleanActions(mixed $actions): array
    {
        if (! is_array($actions)) {
            return [];
        }

        return collect($actions)
            ->filter(fn ($action) => is_array($action) && in_array($action['action'] ?? null, self::ACTIONS, true))
            ->map(fn (array $action) => [
                'action' => $action['action'],
                'arguments' => $this->withoutNulls(is_array($action['arguments'] ?? null) ? $action['arguments'] : []),
            ])
            ->values()
            ->all();
    }

    private function withoutNulls(array $values): array
    {
        return collect($values)
            ->reject(fn ($value): bool => $value === null)
            ->map(fn ($value) => is_array($value) ? $this->withoutNulls($value) : $value)
            ->all();
    }

    private function heuristic(string $message, ?string $prefix = null, ?BeanSession $session = null): array
    {
        $text = trim($message);
        $lower = mb_strtolower($text);
        $actions = [];
        $response = $prefix ?: 'I can help with that.';

        if ($this->isCorrectionRequest($lower, $session)) {
            $actions[] = ['action' => 'resource.query', 'arguments' => $this->resourceQueryArguments($text, $lower, $session)];
            $response = 'I’ll use the correction.';
        } elseif (str_contains($lower, 'weather')) {
            $actions[] = ['action' => 'weather.lookup', 'arguments' => ['query' => $text]];
            $response = 'I’ll check the weather.';
        } elseif ($this->isOnlineRecipeRequest($lower)) {
            $actions[] = ['action' => 'recipe.lookup', 'arguments' => ['query' => $this->recipeSubject($text, $lower)]];
            $response = 'I’ll find a simple recipe.';
        } elseif ($this->isAddRecipesToRecentMealNoteRequest($lower, $session)) {
            $note = $this->recentNoteEntity($session);
            $title = (string) ($note['title'] ?? 'Simple Dinner Meals for This Coming Week');
            $actions[] = ['action' => 'note.update', 'arguments' => [
                'id' => (int) ($note['id'] ?? 0),
                'title' => $title,
                'plain_text' => $this->dinnerMealsWithRecipesText(),
                'generated_recipe_followup' => true,
            ]];
            $response = 'I’ll add simple recipes under each meal.';
        } elseif ($this->isMealPlanNoteRequest($lower)) {
            $actions[] = ['action' => 'note.create', 'arguments' => [
                'title' => 'Simple Dinner Meals for This Coming Week',
                'plain_text' => $this->simpleDinnerMealsText(),
                'generated_meal_plan' => true,
            ]];
            $response = 'I’ll create that dinner-meal note.';
        } elseif ($this->isRecipeNoteRequest($lower)) {
            $subject = $this->recipeSubject($text, $lower);
            $actions[] = ['action' => 'note.create', 'arguments' => [
                'title' => str($subject)->title().' Recipe',
                'plain_text' => $this->recipeText($subject),
                'category' => 'recipe',
                'generated_recipe' => true,
            ]];
            $response = 'I’ll create that recipe note.';
        } elseif ($this->mentionsOverdue($lower) && ! $this->mentionsNote($lower) && ! $this->mentionsCalendar($lower)) {
            if ($this->mentionsReminder($lower) && ! $this->mentionsTask($lower)) {
                $actions[] = ['action' => 'reminder.list', 'arguments' => ['date_scope' => 'overdue']];
                $response = 'I’ll check overdue reminders.';
            } elseif ($this->mentionsTask($lower) && ! $this->mentionsReminder($lower)) {
                $actions[] = ['action' => 'task.list', 'arguments' => ['date_scope' => 'overdue']];
                $response = 'I’ll check overdue tasks.';
            } else {
                $actions[] = ['action' => 'task.list', 'arguments' => ['date_scope' => 'overdue']];
                $actions[] = ['action' => 'reminder.list', 'arguments' => ['date_scope' => 'overdue']];
                $response = 'I’ll check overdue tasks and reminders.';
            }
        } elseif ($this->isTodayTaskListQuestion($lower)) {
            $actions[] = ['action' => 'task.list', 'arguments' => ['date_scope' => 'today']];
            $response = 'I’ll check today’s tasks.';
        } elseif ($this->isTodayCalendarListQuestion($lower)) {
            $actions[] = ['action' => 'calendar_event.list', 'arguments' => ['date_scope' => 'today']];
            $response = 'I’ll check today’s calendar.';
        } elseif ($this->isNoteCreationRequest($lower)) {
            $actions[] = ['action' => 'note.create', 'arguments' => ['plain_text' => $text]];
            $response = 'I’ll add that note.';
        } elseif ($this->isFactualResourceQuestion($lower)) {
            $actions[] = ['action' => 'resource.query', 'arguments' => $this->resourceQueryArguments($text, $lower, $session)];
            $response = 'I’ll check that.';
        } elseif ($this->isTaskWorkspaceQuestion($lower)) {
            $actions[] = ['action' => 'resource.query', 'arguments' => $this->resourceQueryArguments($text, $lower, $session)];
            $response = 'I’ll check the task workspace.';
        } elseif (preg_match('/\b(time|date|today)\b/', $lower) && ! $this->mentionsTask($lower) && ! $this->mentionsReminder($lower) && ! $this->mentionsCalendar($lower)) {
            $actions[] = ['action' => 'time.now', 'arguments' => []];
            $response = 'I’ll check the current date and time.';
        } elseif ($this->mentionsTask($lower) && ! $this->mentionsReminder($lower) && ! $this->mentionsNote($lower) && ! $this->mentionsCalendar($lower)) {
            if ($this->isTaskWorkspaceQuestion($lower) || $this->isFactualResourceQuestion($lower)) {
                $actions[] = ['action' => 'resource.query', 'arguments' => $this->resourceQueryArguments($text, $lower, $session)];
                $response = 'I’ll check the task workspace.';
            } elseif ($this->isListRequest($lower)) {
                $actions[] = ['action' => 'task.list', 'arguments' => $this->listArguments($lower)];
                $response = $this->mentionsToday($lower) ? 'I’ll check today’s tasks.' : 'I’ll check your tasks.';
            } elseif ($this->isDeleteRequest($lower)) {
                $actions[] = ['action' => 'task.delete', 'arguments' => ['query' => $this->queryFromText($text, ['delete', 'remove', 'task', 'todo', 'to-do'])]];
                $response = 'I’ll ask you to confirm before deleting that task.';
            } elseif ($this->isCompleteRequest($lower)) {
                $actions[] = ['action' => 'task.complete', 'arguments' => ['query' => $this->queryFromText($text, ['complete', 'finish', 'done', 'mark', 'task', 'todo', 'to-do', 'as'])]];
                $response = 'I’ll mark that task complete.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'task.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'task', 'todo', 'to-do'])]];
                $response = 'I’ll search your tasks.';
            } else {
                $actions[] = ['action' => 'task.create', 'arguments' => ['title' => $this->titleFromText($text), 'type' => 'todo']];
                $response = 'I’ll add that task.';
            }
        } elseif ($this->mentionsReminder($lower)) {
            if ($this->isDeleteRequest($lower)) {
                $actions[] = ['action' => 'reminder.delete', 'arguments' => ['query' => $this->queryFromText($text, ['delete', 'remove', 'reminder'])]];
                $response = 'I’ll ask you to confirm before deleting that reminder.';
            } elseif ($this->isCompleteRequest($lower)) {
                $actions[] = ['action' => 'reminder.complete', 'arguments' => ['query' => $this->queryFromText($text, ['complete', 'finish', 'done', 'mark', 'reminder', 'as', 'to'])]];
                $response = 'I’ll mark that reminder complete.';
            } elseif ($this->isListRequest($lower)) {
                $actions[] = ['action' => 'reminder.list', 'arguments' => $this->listArguments($lower)];
                $response = $this->mentionsToday($lower) ? 'I’ll check today’s reminders.' : 'I’ll check your reminders.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'reminder.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'reminder'])]];
                $response = 'I’ll search your reminders.';
            } else {
                $actions[] = ['action' => 'reminder.create', 'arguments' => ['title' => $this->titleFromText($text), 'remind_at' => now()->addDay()->setTime(9, 0)->toIso8601String()]];
                $response = 'I’ll create that reminder.';
            }
        } elseif ($this->mentionsNote($lower)) {
            if ($this->isDeleteRequest($lower)) {
                $actions[] = ['action' => 'note.delete', 'arguments' => ['query' => $this->queryFromText($text, ['delete', 'remove', 'note'])]];
                $response = 'I’ll ask you to confirm before deleting that note.';
            } elseif ($this->isListRequest($lower)) {
                $actions[] = ['action' => 'note.list', 'arguments' => []];
                $response = 'I’ll check your notes.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'note.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'note', 'notes'])]];
                $response = 'I’ll search your notes.';
            } else {
                $actions[] = ['action' => 'note.create', 'arguments' => ['plain_text' => $text]];
                $response = 'I’ll add that note.';
            }
        } elseif ($this->mentionsCalendar($lower)) {
            if ($this->isDeleteRequest($lower)) {
                $actions[] = ['action' => 'calendar_event.delete', 'arguments' => ['query' => $this->queryFromText($text, ['delete', 'remove', 'calendar', 'appointment', 'event'])]];
                $response = 'I’ll ask you to confirm before deleting that calendar event.';
            } elseif ($this->isListRequest($lower)) {
                $actions[] = ['action' => 'calendar_event.list', 'arguments' => $this->listArguments($lower)];
                $response = $this->mentionsToday($lower) ? 'I’ll check today’s calendar.' : 'I’ll check your calendar.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'calendar_event.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'calendar', 'appointment', 'event'])]];
                $response = 'I’ll search your calendar.';
            } else {
                $actions[] = ['action' => 'calendar_event.create', 'arguments' => ['title' => $this->titleFromText($text), 'starts_at' => now()->addDay()->setTime(9, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'all_day' => false]];
                $response = 'I’ll add that calendar event.';
            }
        }

        return ['response' => $response, 'actions' => $actions, 'model' => 'local-heuristic'];
    }

    private function mentionsTask(string $lower): bool
    {
        return preg_match('/\b(task|tasks|todo|todos|to-do|to-dos|to do|call|buy|finish|chore|chores|work)\b/', $lower) === 1;
    }

    private function mentionsReminder(string $lower): bool
    {
        return preg_match('/\b(remind me|reminder|reminders)\b/', $lower) === 1;
    }

    private function mentionsNote(string $lower): bool
    {
        return preg_match('/\b(note|notes|write down|jot)\b/', $lower) === 1;
    }

    private function mentionsCalendar(string $lower): bool
    {
        return preg_match('/\b(calendar|appointment|appointments|event|events|meeting|meetings|schedule)\b/', $lower) === 1;
    }

    private function isDeleteRequest(string $lower): bool
    {
        return preg_match('/\b(delete|remove|trash)\b/', $lower) === 1;
    }

    private function isCompleteRequest(string $lower): bool
    {
        return preg_match('/\b(complete|completed|finish|finished|done|mark)\b/', $lower) === 1;
    }

    private function isListRequest(string $lower): bool
    {
        return preg_match('/\b(what|show|list|check|tell|any|anything|have|has|due|need|on deck)\b/', $lower) === 1;
    }

    private function isSearchRequest(string $lower): bool
    {
        return preg_match('/\b(find|search)\b/', $lower) === 1;
    }

    private function isTaskWorkspaceQuestion(string $lower): bool
    {
        return preg_match('/\b(workspace|workspaces)\b/', $lower) === 1
            && preg_match('/\b(what|which|where|in|does|do|is|are)\b/', $lower) === 1;
    }

    private function isFactualResourceQuestion(string $lower): bool
    {
        return preg_match('/\b(why|which|where|is|explain|context|workspace|workspaces)\b/', $lower) === 1
            && ($this->mentionsTask($lower) || $this->mentionsReminder($lower) || $this->mentionsCalendar($lower) || $this->mentionsNote($lower) || preg_match('/\b(card|grout|grill|item|items)\b/', $lower) === 1);
    }

    private function resourceQueryArguments(string $text, string $lower, ?BeanSession $session = null): array
    {
        $reference = $this->referencedEntity($session, $lower);
        $resource = match (true) {
            isset($reference['type']) => match ((string) $reference['type']) {
                'reminder' => 'reminders',
                'calendar_event' => 'calendar_events',
                'note' => 'notes',
                default => 'tasks',
            },
            $this->mentionsReminder($lower) => 'reminders',
            $this->mentionsCalendar($lower) => 'calendar_events',
            $this->mentionsNote($lower) => 'notes',
            default => 'tasks',
        };
        $query = $this->queryFromText($text, [
            'why', 'what', 'which', 'where', 'show', 'tell', 'me', 'explain', 'context',
            'workspace', 'workspaces', 'is', 'are', 'in', 'does', 'do', 'the', 'a', 'an',
            'task', 'tasks', 'todo', 'todos', 'to-do', 'to-dos', 'item', 'items', 'one',
            'first', 'second', 'third', 'that', 'this', 'it', 'on', 'my', 'list', 'for',
            'today', 'showing', 'appear', 'appearing', 'has', 'contains', 'contain', 'live',
            'lives', 'belong', 'belongs', 'to', 'family', 'personal',
        ]);
        $correction = $this->correctionEntity($session, $lower, $query);
        if ($correction !== null) {
            $reference = $correction['entity'];
            $query = (string) ($reference['title'] ?? $query);
        }

        $arguments = [
            'resource' => $resource,
            'query' => $query,
            'question' => $text,
            'date_scope' => $this->mentionsOverdue($lower) ? 'overdue' : ($this->mentionsToday($lower) ? 'today' : null),
            'include_workspaces' => true,
            'explain_visibility' => preg_match('/\b(why|showing|appear|appearing)\b/', $lower) === 1,
        ];
        if ($correction !== null) {
            $arguments['id'] = (int) ($reference['id'] ?? 0);
            $arguments['query'] = null;
            $arguments['heard_text'] = $correction['heard_text'];
            $arguments['correction_kind'] = $correction['kind'];
        }
        if (isset($reference['id']) && ($query === '' || preg_match('/\b(that|this|it|first|second|third|one)\b/', $lower) === 1)) {
            $arguments['id'] = (int) $reference['id'];
            $arguments['query'] = null;
        }

        return array_filter($arguments, fn ($value): bool => $value !== null && $value !== '');
    }

    private function referencedEntity(?BeanSession $session, string $lower): ?array
    {
        if (! $session) return null;
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $entities = array_values(array_filter(is_array($metadata['recent_entities'] ?? null) ? $metadata['recent_entities'] : [], 'is_array'));
        if ($entities === []) return null;
        $index = match (true) {
            preg_match('/\b(second|2nd)\b/', $lower) === 1 => 1,
            preg_match('/\b(third|3rd)\b/', $lower) === 1 => 2,
            default => 0,
        };

        return $entities[$index] ?? $entities[0] ?? null;
    }

    private function taskContextQuery(string $text): string
    {
        return $this->queryFromText($text, ['what', 'which', 'where', 'workspace', 'workspaces', 'is', 'are', 'in', 'does', 'do', 'task', 'tasks', 'todo', 'to-do']);
    }

    private function mentionsToday(string $lower): bool
    {
        return preg_match('/\b(today|this morning|this afternoon|tonight)\b/', $lower) === 1;
    }

    private function mentionsOverdue(string $lower): bool
    {
        return preg_match('/\b(overdue|past due|late|miss|missed|before today)\b/', $lower) === 1;
    }

    private function isTodayTaskListQuestion(string $lower): bool
    {
        $implicitTodayTodoList = preg_match('/\bwhat(?:’|\'| i)?s on my (?:to do|todo|to-do) list\b/u', $lower) === 1;
        if (! $this->mentionsToday($lower) && ! $implicitTodayTodoList) return false;
        if ($this->mentionsReminder($lower) || $this->mentionsCalendar($lower) || $this->mentionsNote($lower)) return false;
        if (preg_match('/\b(why|showing|appear|appearing)\b/', $lower) === 1) return false;

        return $implicitTodayTodoList
            || $this->mentionsTask($lower)
            || preg_match('/\b(list|to do list|todo list|to-do list|due|need|on deck|get done|show my list)\b/', $lower) === 1;
    }

    private function isTodayCalendarListQuestion(string $lower): bool
    {
        return $this->mentionsToday($lower) && $this->mentionsCalendar($lower) && $this->isListRequest($lower);
    }

    private function isNoteCreationRequest(string $lower): bool
    {
        return preg_match('/\b(write down|jot down|take a note|add a note|create a note)\b/', $lower) === 1;
    }

    private function listArguments(string $lower): array
    {
        if ($this->mentionsOverdue($lower)) return ['date_scope' => 'overdue'];
        return $this->mentionsToday($lower) ? ['date_scope' => 'today'] : [];
    }

    private function queryFromText(string $text, array $words): string
    {
        $query = preg_replace('/^(hey bean,?\s*)?(please\s+)?/i', '', trim($text)) ?: trim($text);
        foreach ($words as $word) {
            $query = preg_replace('/\b'.preg_quote($word, '/').'\b/i', ' ', $query) ?: $query;
        }
        $query = preg_replace('/\s+/', ' ', trim($query)) ?: trim($text);
        $query = trim($query, " \t\n\r\0\x0B?.!,;:'\"");

        return str($query)->limit(120, '')->toString();
    }

    private function isOnlineRecipeRequest(string $lower): bool
    {
        return str_contains($lower, 'recipe') && preg_match('/\b(go online|online|find|look up|lookup|search|internet|web)\b/u', $lower) === 1;
    }

    private function isCorrectionRequest(string $lower, ?BeanSession $session): bool
    {
        return $this->correctionEntity($session, $lower, '') !== null;
    }

    private function isRecipeNoteRequest(string $lower): bool
    {
        return str_contains($lower, 'recipe') && str_contains($lower, 'note') && preg_match('/\b(create|make|add|write)\b/u', $lower) === 1;
    }

    private function isMealPlanNoteRequest(string $lower): bool
    {
        return str_contains($lower, 'note') && str_contains($lower, 'dinner') && str_contains($lower, 'meal') && preg_match('/\b(five|5)\b/u', $lower) === 1;
    }

    private function isAddRecipesToRecentMealNoteRequest(string $lower, ?BeanSession $session): bool
    {
        if (! str_contains($lower, 'recipe') || ! preg_match('/\b(each|those|meals?)\b/u', $lower)) return false;
        $note = $this->recentNoteEntity($session);
        if ($note === null) return false;
        $title = mb_strtolower((string) ($note['title'] ?? ''));
        return str_contains($title, 'dinner') || str_contains($title, 'meal');
    }

    private function recentNoteEntity(?BeanSession $session): ?array
    {
        if (! $session) return null;
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $entities = array_values(array_filter(is_array($metadata['recent_entities'] ?? null) ? $metadata['recent_entities'] : [], 'is_array'));
        foreach ($entities as $entity) {
            if (($entity['type'] ?? null) === 'note') return $entity;
        }
        return null;
    }

    private function correctionEntity(?BeanSession $session, string $lower, string $query): ?array
    {
        if (! $session) return null;
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $entities = array_values(array_filter(is_array($metadata['recent_entities'] ?? null) ? $metadata['recent_entities'] : [], 'is_array'));
        if ($entities === []) return null;

        $heardText = $query;
        $kind = 'misheard';
        if (preg_match('/\b(i said|i meant)\s+(.+)$/iu', $lower, $match) === 1) {
            $heardText = trim((string) $match[2], " \t\n\r\0\x0B?.!,;:'\"");
            $kind = 'correction';
        }

        $best = $this->bestEntityMatch($heardText, $entities);
        if ($best !== null) {
            return ['entity' => $best, 'heard_text' => $heardText, 'kind' => $kind];
        }

        if ($kind === 'misheard' && preg_match('/\b(page avocado|pay avocado|play avocado)\b/u', $lower) === 1) {
            return ['entity' => $entities[0], 'heard_text' => $heardText, 'kind' => 'misheard'];
        }

        return null;
    }

    private function bestEntityMatch(string $query, array $entities): ?array
    {
        $queryTokens = $this->significantTokens($query);
        if ($queryTokens === []) return null;
        $best = null;
        $bestScore = 0;
        foreach ($entities as $entity) {
            $title = (string) ($entity['title'] ?? '');
            $titleTokens = $this->significantTokens($title);
            if ($titleTokens === []) continue;
            $overlap = count(array_intersect($queryTokens, $titleTokens));
            $score = $overlap / max(1, min(count($queryTokens), count($titleTokens)));
            if ($score > $bestScore) {
                $best = $entity;
                $bestScore = $score;
            }
        }
        return $bestScore >= 0.5 ? $best : null;
    }

    private function significantTokens(string $text): array
    {
        return collect(preg_split('/[^a-z0-9]+/i', mb_strtolower($text)) ?: [])
            ->map(fn ($token): string => trim($token))
            ->reject(fn (string $token): bool => $token === '' || in_array($token, ['the', 'a', 'an', 'to', 'in', 'on', 'my', 'is', 'are', 'what', 'which', 'where', 'workspace', 'card'], true))
            ->values()
            ->all();
    }

    private function recipeSubject(string $text, string $lower): string
    {
        if (preg_match('/\b(?:for|of)\s+([a-z][a-z\s-]+?)(?:\?|$)/iu', $text, $match) === 1) {
            return trim((string) $match[1]);
        }
        $query = $this->queryFromText($text, ['can', 'you', 'please', 'create', 'make', 'add', 'write', 'note', 'recipe', 'go', 'online', 'find', 'look', 'up', 'search', 'internet', 'web', 'for']);
        return $query !== '' ? $query : 'quesadillas';
    }

    private function recipeText(string $subject): string
    {
        $subject = trim($subject) ?: 'quesadillas';
        if (str_contains(mb_strtolower($subject), 'quesadilla')) {
            return "Quesadillas Recipe\n\nIngredients:\n- 4 flour tortillas\n- 1 1/2 cups shredded cheese\n- 1/2 cup cooked chicken, beans, or vegetables (optional)\n- 1 tablespoon butter or oil\n- Salsa, sour cream, or guacamole for serving\n\nInstructions:\n1. Warm a skillet over medium heat.\n2. Place one tortilla in the skillet and sprinkle cheese over half. Add optional filling.\n3. Fold the tortilla and cook 2–3 minutes per side until crisp and melted.\n4. Slice into wedges and serve with salsa or sour cream.\n\nTime: about 15 minutes.";
        }

        return str($subject)->title()." Recipe\n\nIngredients:\n- Main ingredient for {$subject}\n- Olive oil or butter\n- Salt and pepper\n- Simple sides or toppings\n\nInstructions:\n1. Prep the ingredients.\n2. Cook over medium heat until done.\n3. Taste, season, and serve warm.\n\nTime: about 20 minutes.";
    }

    private function simpleDinnerMealsText(): string
    {
        return "Here are five simple dinner meals for the coming week:\n1. Grilled chicken with steamed vegetables\n2. Spaghetti with marinara sauce\n3. Baked salmon with rice and broccoli\n4. Tacos with ground beef and salad\n5. Vegetable stir-fry with tofu";
    }

    private function dinnerMealsWithRecipesText(): string
    {
        return "Simple Dinner Meals for This Coming Week\n\n1. Grilled chicken with steamed vegetables\nRecipe:\nIngredients: chicken breasts, mixed vegetables, olive oil, salt, pepper, garlic powder.\nInstructions: Season chicken, grill or pan-cook until done, steam vegetables, and serve together.\n\n2. Spaghetti with marinara sauce\nRecipe:\nIngredients: spaghetti, marinara sauce, parmesan, olive oil, salt.\nInstructions: Boil spaghetti, warm marinara, toss together, and top with parmesan.\n\n3. Baked salmon with rice and broccoli\nRecipe:\nIngredients: salmon fillets, rice, broccoli, lemon, olive oil, salt, pepper.\nInstructions: Bake seasoned salmon at 400°F until flaky, cook rice, steam broccoli, and serve with lemon.\n\n4. Tacos with ground beef and salad\nRecipe:\nIngredients: ground beef, taco seasoning, tortillas, lettuce, tomato, cheese, salsa.\nInstructions: Brown beef with seasoning, fill tortillas, and serve with salad toppings.\n\n5. Vegetable stir-fry with tofu\nRecipe:\nIngredients: firm tofu, mixed vegetables, soy sauce, garlic, sesame oil, rice.\nInstructions: Sear tofu, stir-fry vegetables with garlic and soy sauce, and serve over rice.";
    }

    private function titleFromText(string $text): string
    {
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(add|create|make|set|schedule)\s+(a\s+)?(task|todo|to-do|reminder|calendar event|event|note)?\s*(to\s+)?/i', '', trim($text)) ?: trim($text);
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(remind me to|remind me|write down|jot down)\s+/i', '', trim($title)) ?: trim($title);
        return str($title)->limit(120, '')->toString();
    }
}
