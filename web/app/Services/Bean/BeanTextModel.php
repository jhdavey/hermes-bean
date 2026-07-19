<?php

namespace App\Services\Bean;

use App\Models\BeanSession;
use Illuminate\Support\Facades\Http;
use Throwable;

class BeanTextModel
{
    public function __construct(private readonly BeanTimeContext $timeContext) {}

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
        'external.lookup',
        'dashboard.summary',
    ];

    public function nextStep(BeanSession $session, string $message, array $toolResults = []): array
    {
        if ($this->isVoiceHealthCheck($message)) {
            return ['final_response' => 'Yes — I can hear you.', 'action' => null, 'arguments' => [], 'model' => 'local-heuristic'];
        }

        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return $this->heuristicStep($message, $session, $toolResults);
        }

        try {
            $model = (string) config('services.openai.bean_text_model', 'gpt-4.1-mini');
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model' => $model,
                    'response_format' => $this->stepResponseFormat(),
                    'messages' => [
                        ['role' => 'system', 'content' => $this->agentSystemPrompt()],
                        ['role' => 'system', 'content' => 'Bean runtime context: '.json_encode($this->plannerContext($session), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                        ['role' => 'user', 'content' => json_encode([
                            'user_request' => $message,
                            'tool_results' => $toolResults,
                        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)],
                    ],
                ]);

            if (! $response->ok()) {
                return $this->heuristicStep($message, $session, $toolResults, 'I had trouble reaching my AI model, so I used the local tool router.');
            }

            $decoded = json_decode((string) data_get($response->json(), 'choices.0.message.content', ''), true);
            if (! is_array($decoded)) {
                return $this->heuristicStep($message, $session, $toolResults);
            }

            $step = $this->cleanStep($decoded, $model);
            $lower = mb_strtolower($message);
            if (($step['action'] ?? null) === null && $toolResults === [] && ($this->isFactualResourceQuestion($lower) || $this->isTaskWorkspaceQuestion($lower))) {
                $action = ['action' => 'resource.query', 'arguments' => [...$this->resourceQueryArguments($message, $lower, $session), 'skip_synthesis' => true]];
                return ['final_response' => '', 'action' => $action['action'], 'arguments' => $action['arguments'], 'model' => $model];
            }
            if (($step['action'] ?? null) === 'time.now' && ! $this->asksDateTime($lower) && ($this->isFactualResourceQuestion($lower) || $this->mentionsCalendar($lower) || $this->mentionsTask($lower) || $this->mentionsReminder($lower))) {
                $fallback = $this->heuristicStep($message, $session, []);
                return ['final_response' => '', 'action' => $fallback['action'], 'arguments' => $fallback['arguments'] ?? [], 'model' => $model];
            }
            if (($step['action'] ?? null) !== null
                && $this->isReadOnlyAnswerCorrection($message, $lower, $session)
                && $this->containsMutationAction([['action' => $step['action']]])) {
                $action = $this->readOnlyCorrectionAction($message, $lower, $session);
                return ['final_response' => '', 'action' => $action['action'], 'arguments' => $action['arguments'], 'model' => $model];
            }

            return $step;
        } catch (Throwable) {
            return $this->heuristicStep($message, $session, $toolResults, 'I had trouble reaching my AI model, so I used the local tool router.');
        }
    }

    private function plannerContext(BeanSession $session): array
    {
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $timeContext = $this->timeContext->forSession($session);
        return [
            'recent_entities' => array_slice(is_array($metadata['recent_entities'] ?? null) ? $metadata['recent_entities'] : [], 0, 10),
            'recent_query_context' => is_array($metadata['recent_query_context'] ?? null) ? $metadata['recent_query_context'] : null,
            'current_workspace_id' => $session->workspace_id,
            'time_context' => $timeContext,
            'current_datetime' => $timeContext['local_now'],
            'current_date' => $timeContext['local_date'],
            'timezone' => $timeContext['timezone'],
        ];
    }

    private function stepResponseFormat(): array
    {
        return [
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'bean_agent_step',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['final_response', 'action', 'arguments'],
                    'properties' => [
                        'final_response' => [
                            'type' => 'string',
                            'description' => 'Final user-facing answer when no more tool/action is needed. Empty string when calling an action.',
                        ],
                        'action' => [
                            'type' => ['string', 'null'],
                            'enum' => [...self::ACTIONS, null],
                            'description' => 'One next action to execute, or null when final_response is complete.',
                        ],
                        'arguments' => $this->argumentsSchema(),
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
        $nullableFilterArray = [
            'type' => ['array', 'null'],
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['field', 'operator', 'value'],
                'properties' => [
                    'field' => ['type' => 'string'],
                    'operator' => ['type' => 'string', 'enum' => ['=', '!=', '<', '<=', '>', '>=', 'between', 'in', 'like']],
                    'value' => ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'null'], 'items' => ['type' => ['string', 'number', 'integer', 'boolean', 'null']]],
                ],
            ],
        ];
        $nullableSortArray = [
            'type' => ['array', 'null'],
            'items' => [
                'type' => 'object',
                'additionalProperties' => false,
                'required' => ['field', 'direction'],
                'properties' => [
                    'field' => ['type' => 'string'],
                    'direction' => ['type' => 'string', 'enum' => ['asc', 'desc']],
                ],
            ],
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
            'filters' => $nullableFilterArray,
            'sort' => $nullableSortArray,
            'limit' => $nullableInteger,
            'time_label' => $nullableString,
            'workspace_scope' => $nullableString,
            'include_workspaces' => $nullableBoolean,
            'explain_visibility' => $nullableBoolean,
            'objective' => $nullableString,
            'freshness' => $nullableString,
            'include_sources' => $nullableBoolean,
            'grounded_from' => $nullableString,
            'source_action' => $nullableString,
        ];

        return [
            'type' => 'object',
            'description' => 'Simple JSON arguments for the action. Use ids only when known from context. Use query when lookup is needed. Use null for fields that do not apply.',
            'additionalProperties' => false,
            'required' => array_keys($properties),
            'properties' => $properties,
        ];
    }

    private function agentSystemPrompt(): string
    {
        $actions = implode(', ', self::ACTIONS);

        return <<<PROMPT
You are Bean, HeyBean's model-driven productivity agent. Return only JSON matching the provided schema.
You own the reasoning loop. Laravel is only the thin tool host for dashboard data: it authenticates, scopes workspaces, validates schemas, normalizes dates with TimeContext, asks confirmations, and executes CRUD.
Use exactly one next action from this list when a tool is needed: {$actions}. Set action=null and final_response to answer the user when you are done.
You will receive prior tool_results. Read them before deciding the next step. Do not pre-fill a later dashboard mutation with placeholder content before you have seen the data needed for it.
For private dashboard facts, use dashboard/resource/list/search tools; never invent private app data.
For public/current/source-backed information, use external.lookup. If a lookup result is not useful for the request, search again with a better query rather than saving/answering from irrelevant results.
For dashboard mutations, call the relevant CRUD action with complete structured fields. For notes, put the actual user-requested content in plain_text/body; do not create empty or placeholder notes.
When the user asks to find/source/save public information, first use external.lookup, then after seeing the lookup result call note.create only if you can put useful content into the note.
For normal evergreen knowledge, brainstorming, drafting, or creative answers, use action=null and answer directly unless the user asks to save/change dashboard data.
For dates like today/tomorrow/Tuesday, use the provided time_context/current_date/timezone. Express exact read constraints with filters; TimeContext and Laravel will normalize storage ranges.
Do not mention internal action names, JSON, tools, or Laravel to the user.
PROMPT;
    }

    private function cleanResponse(mixed $response): string
    {
        $text = trim(is_string($response) ? $response : '');

        return $text !== '' ? str($text)->limit(500, '')->toString() : 'I can help with that.';
    }

    private function cleanStep(array $decoded, string $model): array
    {
        $action = $decoded['action'] ?? null;
        $action = is_string($action) && in_array($action, self::ACTIONS, true) ? $action : null;
        $arguments = $this->withoutNulls(is_array($decoded['arguments'] ?? null) ? $decoded['arguments'] : []);
        $final = trim((string) ($decoded['final_response'] ?? ''));

        if ($action !== null) {
            return ['final_response' => '', 'action' => $action, 'arguments' => $arguments, 'model' => $model];
        }

        return ['final_response' => $final, 'action' => null, 'arguments' => [], 'model' => $model];
    }

    private function heuristicStep(string $message, BeanSession $session, array $toolResults = [], ?string $prefix = null): array
    {
        if ($toolResults !== []) {
            $next = $this->heuristicFollowUpStep($message, $session, $toolResults);
            if ($next !== null) return $next;

            return ['final_response' => $this->localFinalResponse($message, $toolResults, $prefix), 'action' => null, 'arguments' => [], 'model' => 'local-heuristic'];
        }

        $proposal = $this->heuristic($message, $prefix, $session);
        $actions = is_array($proposal['actions'] ?? null) ? $proposal['actions'] : [];
        $first = collect($actions)->first(fn ($action): bool => is_array($action) && in_array($action['action'] ?? null, self::ACTIONS, true));
        if (is_array($first)) {
            return [
                'final_response' => '',
                'action' => (string) $first['action'],
                'arguments' => $this->withoutNulls(is_array($first['arguments'] ?? null) ? $first['arguments'] : []),
                'model' => 'local-heuristic',
            ];
        }

        return ['final_response' => $this->cleanResponse($proposal['response'] ?? null), 'action' => null, 'arguments' => [], 'model' => 'local-heuristic'];
    }

    private function heuristicFollowUpStep(string $message, BeanSession $session, array $toolResults): ?array
    {
        $lower = mb_strtolower($message);
        $hasLookup = collect($toolResults)->contains(fn (array $result): bool => ($result['action'] ?? null) === 'external.lookup' && ($result['ok'] ?? false) === true);
        $hasNoteCreate = collect($toolResults)->contains(fn (array $result): bool => ($result['action'] ?? null) === 'note.create');
        if ($hasLookup && ! $hasNoteCreate && $this->isGroundedNoteCreationRequest($lower)) {
            $lookup = collect($toolResults)->last(fn (array $result): bool => ($result['action'] ?? null) === 'external.lookup' && ($result['ok'] ?? false) === true);
            $text = $this->genericLookupNoteText(is_array($lookup) ? $lookup : []);
            return [
                'final_response' => '',
                'action' => 'note.create',
                'arguments' => [
                    'title' => $this->genericLookupNoteTitle(is_array($lookup) ? $lookup : [], $message),
                    'plain_text' => $text,
                    'grounded_from' => 'external.lookup',
                    'source_action' => 'external.lookup',
                ],
                'model' => 'local-heuristic',
            ];
        }

        $proposal = $this->heuristic($message, null, $session);
        $planned = is_array($proposal['actions'] ?? null) ? array_values(array_filter($proposal['actions'], 'is_array')) : [];
        if (count($planned) <= 1) return null;
        foreach ($planned as $action) {
            if (! is_array($action) || ! in_array($action['action'] ?? null, self::ACTIONS, true)) continue;
            $arguments = $this->withoutNulls(is_array($action['arguments'] ?? null) ? $action['arguments'] : []);
            $alreadyRan = collect($toolResults)->contains(function (array $result) use ($action, $arguments): bool {
                return ($result['action'] ?? null) === ($action['action'] ?? null)
                    && json_encode($this->withoutNulls(is_array($result['arguments'] ?? null) ? $result['arguments'] : [])) === json_encode($arguments);
            });
            if (! $alreadyRan) {
                return ['final_response' => '', 'action' => (string) $action['action'], 'arguments' => $arguments, 'model' => 'local-heuristic'];
            }
        }

        return null;
    }

    private function localFinalResponse(string $message, array $toolResults, ?string $prefix = null): string
    {
        $last = collect($toolResults)->last();
        $lastOk = collect($toolResults)->last(fn (array $result): bool => ($result['ok'] ?? false) === true);
        if (! is_array($lastOk)) {
            if (is_array($last) && ($last['ambiguous'] ?? false) === true && is_array($last['items'] ?? null)) {
                $noun = match ((string) ($last['action'] ?? '')) {
                    'task.complete', 'task.update', 'task.delete' => 'task',
                    'reminder.complete', 'reminder.update', 'reminder.delete' => 'reminder',
                    default => 'item',
                };
                $titles = collect($last['items'])->filter(fn ($item): bool => is_array($item))->take(5)->map(fn (array $item): string => trim((string) ($item['title'] ?? 'Untitled')) ?: 'Untitled')->all();
                return 'I found multiple matching '.$noun.'s: '.$this->naturalList($titles).'. Which one should I use?';
            }
            if (is_array($last) && trim((string) ($last['error'] ?? '')) !== '') return (string) $last['error'];
            return $prefix ?: 'I could not complete that.';
        }
        $last = $lastOk;
        if (($last['action'] ?? null) === 'external.lookup') {
            $summary = trim((string) ($last['summary'] ?? ''));
            $sources = collect($last['sources'] ?? [])->filter(fn ($source): bool => is_array($source))->values();
            $source = trim((string) ($last['source_url'] ?? ($sources->first()['url'] ?? '')));
            $prefix = $sources->count() > 1 ? 'I found '.$sources->count().' sources' : 'I found this online';
            if ($summary !== '' && $source !== '') return $prefix.': '.$summary.' Source: '.$source;
            if ($summary !== '') return $prefix.': '.$summary;
            return 'I found source-backed information for that.';
        }
        if (($last['action'] ?? null) === 'note.create' && is_array($last['item'] ?? null)) {
            $title = trim((string) data_get($last, 'item.title', 'that note')) ?: 'that note';
            return (($last['grounded_from'] ?? null) === 'external.lookup') ? "I created a source-grounded note: {$title}." : "I created the note: {$title}.";
        }

        return '';
    }

    private function naturalList(array $items): string
    {
        $items = array_values(array_filter(array_map(fn ($item): string => trim((string) $item), $items), fn ($item): bool => $item !== ''));
        if (count($items) <= 1) return $items[0] ?? '';
        if (count($items) === 2) return $items[0].' and '.$items[1];
        $last = array_pop($items);
        return implode(', ', $items).', and '.$last;
    }

    private function genericLookupNoteTitle(array $lookup, string $message): string
    {
        $title = trim((string) ($lookup['title'] ?? $lookup['query'] ?? ''));
        $title = preg_replace('/^search results for\s+/i', '', $title) ?: $title;
        return $title !== '' ? str($title)->title()->limit(80, '')->toString() : str($message)->limit(80, '')->toString();
    }

    private function genericLookupNoteText(array $lookup): string
    {
        $lines = [];
        $summary = trim((string) ($lookup['summary'] ?? ''));
        if ($summary !== '') {
            $lines[] = 'Summary:';
            $lines[] = $summary;
        }
        $claims = collect($lookup['claims'] ?? [])->filter(fn ($claim): bool => is_array($claim))->values();
        if ($claims->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Key points:';
            foreach ($claims->take(8) as $claim) {
                $text = trim((string) ($claim['text'] ?? ''));
                if ($text !== '') $lines[] = '- '.$text;
            }
        }
        $sources = collect($lookup['sources'] ?? [])->filter(fn ($source): bool => is_array($source))->values();
        if ($sources->isNotEmpty()) {
            $lines[] = '';
            $lines[] = 'Sources:';
            foreach ($sources->take(5) as $source) {
                $title = trim((string) ($source['title'] ?? 'Source')) ?: 'Source';
                $url = trim((string) ($source['url'] ?? ''));
                $lines[] = '- '.$title.($url !== '' ? ': '.$url : '');
            }
        }

        return trim(implode("\n", $lines));
    }

    private function asksDateTime(string $lower): bool
    {
        return preg_match('/\b(time|current time|what time|date|today[’\']?s date|what day|time is it)\b/u', $lower) === 1;
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

        $followUp = $this->temporalFollowUpAction($text, $lower, $session);
        if ($followUp !== null) {
            $actions[] = $followUp;
            $response = 'I’ll check that for '.($followUp['arguments']['time_label'] ?? 'then').'.';
        } elseif ($this->isCorrectionRequest($lower, $session)) {
            $actions[] = ['action' => 'resource.query', 'arguments' => $this->resourceQueryArguments($text, $lower, $session)];
            $response = 'I’ll use the correction.';
        } elseif ($this->isExternalLookupRequest($lower)) {
            $query = $this->externalLookupQuery($text);
            $actions[] = ['action' => 'external.lookup', 'arguments' => [
                'query' => $query,
                'objective' => $this->externalLookupObjective($text),
                'freshness' => $this->externalLookupFreshness($lower),
                'include_sources' => true,
            ]];
            $response = $this->isGroundedNoteCreationRequest($lower) ? 'I’ll look that up first.' : 'I’ll look that up.';
        } elseif ($this->isSubstantivePublicNoteCreationRequest($lower) && ! $this->explicitlyRequestsModelKnowledgeOnly($lower)) {
            $actions[] = ['action' => 'external.lookup', 'arguments' => [
                'query' => $this->externalLookupQuery($text),
                'objective' => $this->externalLookupObjective($text),
                'freshness' => $this->externalLookupFreshness($lower),
                'include_sources' => true,
            ]];
            $response = 'I’ll look that up first.';
        } elseif ($this->mentionsOverdue($lower) && ! $this->mentionsNote($lower) && ! $this->mentionsCalendar($lower)) {
            if ($this->mentionsReminder($lower) && ! $this->mentionsTask($lower)) {
                $actions[] = ['action' => 'reminder.list', 'arguments' => $this->listArguments($lower, 'reminders', null, $session)];
                $response = 'I’ll check overdue reminders.';
            } elseif ($this->mentionsTask($lower) && ! $this->mentionsReminder($lower)) {
                $actions[] = ['action' => 'task.list', 'arguments' => $this->listArguments($lower, 'tasks', null, $session)];
                $response = 'I’ll check overdue tasks.';
            } else {
                $actions[] = ['action' => 'task.list', 'arguments' => $this->listArguments($lower, 'tasks', null, $session)];
                $actions[] = ['action' => 'reminder.list', 'arguments' => $this->listArguments($lower, 'reminders', null, $session)];
                $response = 'I’ll check overdue tasks and reminders.';
            }
        } elseif ($this->isTodayTaskListQuestion($lower)) {
            $actions[] = ['action' => 'task.list', 'arguments' => $this->listArguments($lower, 'tasks', 'today', $session)];
            $response = 'I’ll check today’s tasks.';
        } elseif ($this->isTodayCalendarListQuestion($lower)) {
            $actions[] = ['action' => 'calendar_event.list', 'arguments' => $this->listArguments($lower, 'calendar_events', null, $session)];
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
                $actions[] = ['action' => 'task.list', 'arguments' => $this->listArguments($lower, 'tasks', null, $session)];
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
                $actions[] = ['action' => 'reminder.list', 'arguments' => $this->listArguments($lower, 'reminders', null, $session)];
                $response = $this->mentionsToday($lower) ? 'I’ll check today’s reminders.' : 'I’ll check your reminders.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'reminder.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'reminder'])]];
                $response = 'I’ll search your reminders.';
            } else {
                $actions[] = ['action' => 'reminder.create', 'arguments' => ['title' => $this->titleFromText($text), 'remind_at' => $this->defaultLocalFutureIso($session, 1, 9, 0)]];
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
                $actions[] = ['action' => 'calendar_event.list', 'arguments' => $this->listArguments($lower, 'calendar_events', null, $session)];
                $response = $this->mentionsToday($lower) ? 'I’ll check today’s calendar.' : 'I’ll check your calendar.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'calendar_event.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'calendar', 'appointment', 'event'])]];
                $response = 'I’ll search your calendar.';
            } else {
                $actions[] = ['action' => 'calendar_event.create', 'arguments' => ['title' => $this->titleFromText($text), 'starts_at' => $this->defaultLocalFutureIso($session, 1, 9, 0), 'ends_at' => $this->defaultLocalFutureIso($session, 1, 10, 0), 'all_day' => false]];
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

    private function temporalFollowUpAction(string $text, string $lower, ?BeanSession $session): ?array
    {
        if (! $this->mentionsToday($lower) && ! $this->mentionsTomorrow($lower) && ! $this->mentionsOverdue($lower)) {
            return null;
        }
        if (! preg_match('/\b(what about|how about|for|today|tomorrow|overdue|past due|late)\b/u', $lower)) {
            return null;
        }

        $resource = $this->recentQueryResource($session);
        if (! in_array($resource, ['tasks', 'reminders', 'calendar_events'], true)) return null;

        $action = match ($resource) {
            'tasks' => 'task.list',
            'reminders' => 'reminder.list',
            'calendar_events' => 'calendar_event.list',
        };

        return ['action' => $action, 'arguments' => $this->listArguments($lower, $resource, null, $session)];
    }

    private function recentQueryResource(?BeanSession $session): ?string
    {
        if (! $session) return null;
        $metadata = is_array($session->metadata) ? $session->metadata : [];
        $context = is_array($metadata['recent_query_context'] ?? null) ? $metadata['recent_query_context'] : null;
        if (isset($context['resource'])) {
            $resource = $this->normalizeResourceName((string) $context['resource']);
            if ($resource !== null) return $resource;
        }

        $messages = $session->messages()->where('role', 'assistant')->latest('id')->limit(6)->get();
        foreach ($messages as $message) {
            $messageMetadata = is_array($message->metadata) ? $message->metadata : [];
            foreach (is_array($messageMetadata['results'] ?? null) ? $messageMetadata['results'] : [] as $result) {
                if (! is_array($result)) continue;
                $resource = $this->resourceFromAction((string) ($result['action'] ?? ''))
                    ?: $this->normalizeResourceName((string) ($result['resource'] ?? data_get($result, 'arguments.resource') ?? ''));
                if ($resource !== null) return $resource;
            }
        }

        foreach (array_values(array_filter(is_array($metadata['recent_entities'] ?? null) ? $metadata['recent_entities'] : [], 'is_array')) as $entity) {
            $resource = $this->normalizeResourceName((string) ($entity['type'] ?? ''));
            if ($resource !== null) return $resource;
        }

        return null;
    }

    private function resourceFromAction(string $action): ?string
    {
        return match ($action) {
            'task.list', 'task.search', 'task.context' => 'tasks',
            'reminder.list', 'reminder.search' => 'reminders',
            'calendar_event.list', 'calendar_event.search' => 'calendar_events',
            'note.list', 'note.search' => 'notes',
            default => null,
        };
    }

    private function normalizeResourceName(string $resource): ?string
    {
        return match ($resource) {
            'task', 'tasks' => 'tasks',
            'reminder', 'reminders' => 'reminders',
            'calendar', 'event', 'events', 'calendar_event', 'calendar_events' => 'calendar_events',
            'note', 'notes' => 'notes',
            default => null,
        };
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
            'today', 'tomorrow', 'showing', 'appear', 'appearing', 'has', 'have', 'contains', 'contain', 'live',
            'lives', 'belong', 'belongs', 'to', 'family', 'personal', 'yes', 'no', 'actually', 'i', 'do',
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
            'include_workspaces' => true,
            'explain_visibility' => preg_match('/\b(why|showing|appear|appearing)\b/', $lower) === 1,
        ];
        $listArguments = $this->listArguments($lower, $resource, null, $session);
        if ($listArguments !== []) {
            $arguments = [...$arguments, ...$listArguments];
        }
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

    private function mentionsTomorrow(string $lower): bool
    {
        return preg_match('/\b(tomorrow|tmrw|next day)\b/', $lower) === 1;
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

    private function listArguments(string $lower, string $resource, ?string $defaultTimeLabel = null, ?BeanSession $session = null): array
    {
        $scope = match (true) {
            $this->mentionsOverdue($lower) => 'overdue',
            $this->mentionsTomorrow($lower) => 'tomorrow',
            $this->mentionsToday($lower) => 'today',
            default => $defaultTimeLabel,
        };
        if ($scope === null) return [];
        return [
            'filters' => $this->temporalFilters($resource, $scope, $session),
            'time_label' => $scope,
            'workspace_scope' => 'accessible',
        ];
    }

    private function temporalFilters(string $resource, string $scope, ?BeanSession $session = null): array
    {
        $field = match ($resource) {
            'tasks' => 'due_at',
            'reminders' => 'remind_at',
            'calendar_events' => 'starts_at',
            default => 'updated_at',
        };
        $timeContext = $session ? $this->timeContext->forSession($session) : $this->timeContext->forClientTimezone(null, 'app_default');
        [$todayStart, $todayEnd] = $this->timeContext->todayUtcRange($timeContext);
        [$tomorrowStart, $tomorrowEnd] = $this->timeContext->tomorrowUtcRange($timeContext);

        return match ($scope) {
            'overdue' => [['field' => $field, 'operator' => '<', 'value' => $todayStart]],
            'today' => $resource === 'calendar_events'
                ? [['field' => $field, 'operator' => 'between', 'value' => [$todayStart, $todayEnd]]]
                : [['field' => $field, 'operator' => '<=', 'value' => $todayEnd]],
            'tomorrow' => [['field' => $field, 'operator' => 'between', 'value' => [$tomorrowStart, $tomorrowEnd]]],
            default => [],
        };
    }

    private function defaultLocalFutureIso(?BeanSession $session, int $days, int $hour, int $minute): string
    {
        $timeContext = $session ? $this->timeContext->forSession($session) : $this->timeContext->forClientTimezone(null, 'app_default');

        return $this->timeContext->localNow($timeContext)
            ->addDays($days)
            ->setTime($hour, $minute)
            ->utc()
            ->toIso8601String();
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

    private function externalLookupQuery(string $text): string
    {
        $query = $this->queryFromText($text, ['can', 'you', 'please', 'go', 'online', 'look', 'lookup', 'search', 'the', 'web', 'internet', 'find', 'save', 'create', 'make', 'add', 'note', 'with', 'source', 'sources', 'for', 'and', 'a', 'an']);
        return $query !== '' ? $query : trim($text);
    }

    private function externalLookupObjective(string $text): string
    {
        $lower = mb_strtolower($text);
        if (preg_match('/\b(compare|comparison|options|best|top|versus|vs)\b/u', $lower) === 1) return 'compare public options using source-backed evidence';
        if (preg_match('/\b(source|sources|cite|citation|verify|confirm)\b/u', $lower) === 1) return 'verify public facts with sources';
        if (preg_match('/\b(weather|forecast|temperature|latest|current|recent|today|right now)\b/u', $lower) === 1) return 'answer current public factual question';
        return 'answer source-backed public lookup';
    }

    private function externalLookupFreshness(string $lower): string
    {
        if (preg_match('/\b(latest|current|recent|today|right now|weather|forecast|price|prices|open now)\b/u', $lower) === 1) return 'latest';
        return 'any';
    }

    private function isGroundedNoteCreationRequest(string $lower): bool
    {
        return $this->mentionsNote($lower)
            && preg_match('/\b(save|create|make|add|write)\b/u', $lower) === 1;
    }

    private function isSubstantivePublicNoteCreationRequest(string $lower): bool
    {
        if (! $this->isGroundedNoteCreationRequest($lower)) return false;
        if (preg_match('/\b(note that|write down that|jot down that|remember that|called|titled)\b/u', $lower) === 1) return false;

        return preg_match('/\b(guide|instructions|steps|how to|how-to|recipe|research|compare|comparison|best|sources?)\b/u', $lower) === 1;
    }

    private function explicitlyRequestsModelKnowledgeOnly(string $lower): bool
    {
        return preg_match('/\b(no lookup|don[’\']?t look up|without looking|from your own knowledge|off the top of your head|just make one up)\b/u', $lower) === 1;
    }

    private function isExternalLookupRequest(string $lower): bool
    {
        if (preg_match('/\b(time|date|today[’\']?s date|what day|current time|time is it)\b/u', $lower) === 1) return false;
        return preg_match('/\b(go online|online|look up|lookup|search the web|search online|internet|web|source|sources|cite|citation|verify|latest|current public|recent reviews|weather|forecast|temperature)\b/u', $lower) === 1;
    }

    private function isVoiceHealthCheck(string $text): bool
    {
        $lower = mb_strtolower(trim(preg_replace('/[^\pL\pN\s]/u', ' ', $text) ?: $text));
        $lower = trim(preg_replace('/\s+/', ' ', $lower) ?: $lower);

        return preg_match('/\b(can you hear me|do you hear me|are you there|you there|can you hear|testing testing|mic check)\b/u', $lower) === 1;
    }

    private function isReadOnlyAnswerCorrection(string $text, string $lower, ?BeanSession $session): bool
    {
        if (! $session) return false;
        if (preg_match('/\b(create|add|remind me|set a reminder|schedule|make a reminder)\b/u', $lower) === 1) return false;
        if (preg_match('/\b(yes|no|actually|wait|i do|i have|there is|there are|you missed|wrong|not true)\b/u', $lower) !== 1) return false;

        $resource = $this->recentQueryResource($session);
        if (! in_array($resource, ['tasks', 'reminders', 'calendar_events'], true)) return false;
        if ($this->isCorrectionRequest($lower, $session)) return true;

        $assistantMessages = $session->messages()->where('role', 'assistant')->latest('id')->limit(3)->pluck('content');
        foreach ($assistantMessages as $message) {
            $assistant = mb_strtolower((string) $message);
            if (preg_match('/\b(don[’\']?t have any|couldn[’\']?t find|no\s+(open|scheduled|upcoming)?\s*(tasks?|reminders?|calendar events?|items?)|nothing)\b/u', $assistant) === 1) {
                return true;
            }
        }

        return preg_match('/\b(i do|i have|there is|there are|you missed)\b/u', $lower) === 1;
    }

    private function containsMutationAction(array $actions): bool
    {
        return collect($actions)->contains(function (array $action): bool {
            $name = (string) ($action['action'] ?? '');
            return preg_match('/\.(create|update|delete|complete)$/', $name) === 1;
        });
    }

    private function readOnlyCorrectionAction(string $text, string $lower, ?BeanSession $session): array
    {
        $resource = $this->recentQueryResource($session) ?? 'tasks';
        $arguments = $this->resourceQueryArguments($text, $lower, $session);
        $arguments['resource'] = $resource;
        $arguments['include_workspaces'] = true;
        $arguments['question'] = $text;

        return ['action' => 'resource.query', 'arguments' => $arguments];
    }

    private function isCorrectionRequest(string $lower, ?BeanSession $session): bool
    {
        return $this->correctionEntity($session, $lower, '') !== null;
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


    private function titleFromText(string $text): string
    {
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(add|create|make|set|schedule)\s+(a\s+)?(task|todo|to-do|reminder|calendar event|event|note)?\s*(to\s+)?/i', '', trim($text)) ?: trim($text);
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(remind me to|remind me|write down|jot down)\s+/i', '', trim($title)) ?: trim($title);
        return str($title)->limit(120, '')->toString();
    }
}
