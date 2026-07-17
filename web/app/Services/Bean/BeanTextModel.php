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
        'dashboard.summary',
    ];

    public function propose(BeanSession $session, string $message): array
    {
        $apiKey = (string) config('services.openai.api_key');
        if ($apiKey === '') {
            return $this->heuristic($message);
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
                        ['role' => 'user', 'content' => $message],
                    ],
                ]);

            if (! $response->ok()) {
                return $this->heuristic($message, 'I had trouble reaching my AI model, so I used a basic local parser.');
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $decoded = json_decode($content, true);
            if (! is_array($decoded)) {
                return $this->heuristic($message);
            }

            return [
                'response' => $this->cleanResponse($decoded['response'] ?? null),
                'actions' => $this->cleanActions($decoded['actions'] ?? []),
                'model' => $model,
            ];
        } catch (Throwable) {
            return $this->heuristic($message, 'I had trouble reaching my AI model, so I used a basic local parser.');
        }
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
        $properties = [
            'id' => $nullableInteger,
            'resource_id' => $nullableInteger,
            'workspace_id' => $nullableInteger,
            'note_folder_id' => $nullableInteger,
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
Arguments should be simple JSON. The schema includes common argument fields; set fields that do not apply to null. Use ISO 8601 dates when the user supplied a date/time. If an item needs lookup by title, use query rather than inventing an id.
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

    private function heuristic(string $message, ?string $prefix = null): array
    {
        $text = trim($message);
        $lower = mb_strtolower($text);
        $actions = [];
        $response = $prefix ?: 'I can help with that.';

        if (str_contains($lower, 'weather')) {
            $actions[] = ['action' => 'weather.lookup', 'arguments' => ['query' => $text]];
            $response = 'I’ll check the weather.';
        } elseif (preg_match('/\b(time|date|today)\b/', $lower)) {
            $actions[] = ['action' => 'time.now', 'arguments' => []];
            $response = 'I’ll check the current date and time.';
        } elseif ($this->mentionsTask($lower)) {
            if ($this->isDeleteRequest($lower)) {
                $actions[] = ['action' => 'task.delete', 'arguments' => ['query' => $this->queryFromText($text, ['delete', 'remove', 'task', 'todo', 'to-do'])]];
                $response = 'I’ll ask you to confirm before deleting that task.';
            } elseif ($this->isCompleteRequest($lower)) {
                $actions[] = ['action' => 'task.complete', 'arguments' => ['query' => $this->queryFromText($text, ['complete', 'finish', 'done', 'mark', 'task', 'todo', 'to-do', 'as'])]];
                $response = 'I’ll mark that task complete.';
            } elseif ($this->isListRequest($lower)) {
                $actions[] = ['action' => 'task.list', 'arguments' => []];
                $response = 'I’ll check your tasks.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'task.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'task', 'todo', 'to-do'])]];
                $response = 'I’ll search your tasks.';
            } else {
                $actions[] = ['action' => 'task.create', 'arguments' => ['title' => $this->titleFromText($text), 'type' => 'todo']];
                $response = 'I’ll add that task.';
            }
        } elseif (preg_match('/\b(remind me|reminder)\b/', $lower)) {
            if ($this->isDeleteRequest($lower)) {
                $actions[] = ['action' => 'reminder.delete', 'arguments' => ['query' => $this->queryFromText($text, ['delete', 'remove', 'reminder'])]];
                $response = 'I’ll ask you to confirm before deleting that reminder.';
            } elseif ($this->isCompleteRequest($lower)) {
                $actions[] = ['action' => 'reminder.complete', 'arguments' => ['query' => $this->queryFromText($text, ['complete', 'finish', 'done', 'mark', 'reminder', 'as'])]];
                $response = 'I’ll mark that reminder complete.';
            } elseif ($this->isListRequest($lower)) {
                $actions[] = ['action' => 'reminder.list', 'arguments' => []];
                $response = 'I’ll check your reminders.';
            } elseif ($this->isSearchRequest($lower)) {
                $actions[] = ['action' => 'reminder.search', 'arguments' => ['query' => $this->queryFromText($text, ['find', 'search', 'for', 'reminder'])]];
                $response = 'I’ll search your reminders.';
            } else {
                $actions[] = ['action' => 'reminder.create', 'arguments' => ['title' => $this->titleFromText($text), 'remind_at' => now()->addDay()->setTime(9, 0)->toIso8601String()]];
                $response = 'I’ll create that reminder.';
            }
        } elseif (preg_match('/\b(note|notes|write down|jot)\b/', $lower)) {
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
        } elseif (preg_match('/\b(calendar|appointment|event|schedule)\b/', $lower)) {
            if ($this->isDeleteRequest($lower)) {
                $actions[] = ['action' => 'calendar_event.delete', 'arguments' => ['query' => $this->queryFromText($text, ['delete', 'remove', 'calendar', 'appointment', 'event'])]];
                $response = 'I’ll ask you to confirm before deleting that calendar event.';
            } elseif ($this->isListRequest($lower)) {
                $actions[] = ['action' => 'calendar_event.list', 'arguments' => []];
                $response = 'I’ll check your calendar.';
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
        return preg_match('/\b(task|todo|to-do|call|buy|finish)\b/', $lower) === 1;
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
        return preg_match('/\b(what|show|list|check)\b/', $lower) === 1;
    }

    private function isSearchRequest(string $lower): bool
    {
        return preg_match('/\b(find|search)\b/', $lower) === 1;
    }

    private function queryFromText(string $text, array $words): string
    {
        $query = preg_replace('/^(hey bean,?\s*)?(please\s+)?/i', '', trim($text)) ?: trim($text);
        foreach ($words as $word) {
            $query = preg_replace('/\b'.preg_quote($word, '/').'\b/i', ' ', $query) ?: $query;
        }
        $query = preg_replace('/\s+/', ' ', trim($query)) ?: trim($text);

        return str($query)->limit(120, '')->toString();
    }

    private function titleFromText(string $text): string
    {
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(add|create|make|set)\s+(a\s+)?(task|todo|to-do|reminder|calendar event|event|note)\s+(to\s+)?/i', '', trim($text)) ?: trim($text);
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(remind me to|remind me|write down|jot down)\s+/i', '', trim($title)) ?: trim($title);
        return str($title)->limit(120, '')->toString();
    }
}
