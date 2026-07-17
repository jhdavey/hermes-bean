<?php

namespace App\Services\Bean;

use App\Models\BeanSession;
use Illuminate\Support\Facades\Http;
use Throwable;

class BeanTextModel
{
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
                    'response_format' => ['type' => 'json_object'],
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
                'response' => (string) ($decoded['response'] ?? 'I can help with that.'),
                'actions' => is_array($decoded['actions'] ?? null) ? $decoded['actions'] : [],
                'model' => $model,
            ];
        } catch (Throwable) {
            return $this->heuristic($message, 'I had trouble reaching my AI model, so I used a basic local parser.');
        }
    }

    private function systemPrompt(): string
    {
        return <<<'PROMPT'
You are Bean, the HeyBean productivity assistant. Return only JSON with keys: response (string) and actions (array). Use actions only from this list: task.list, task.search, task.create, task.update, task.complete, task.delete, reminder.list, reminder.search, reminder.create, reminder.update, reminder.complete, reminder.delete, calendar_event.list, calendar_event.search, calendar_event.create, calendar_event.update, calendar_event.delete, note.list, note.search, note.create, note.update, note.delete, time.now, weather.lookup, dashboard.summary. For destructive actions include the delete action, but Laravel will require confirmation. Arguments should be simple JSON. Use ISO 8601 dates when the user supplied a date/time. If an item needs lookup by title, use query. Do not invent private dashboard data; call list/search actions.
PROMPT;
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
        } elseif (preg_match('/\b(remind me|reminder)\b/', $lower)) {
            $actions[] = ['action' => 'reminder.create', 'arguments' => ['title' => $this->titleFromText($text), 'remind_at' => now()->addDay()->setTime(9, 0)->toIso8601String()]];
            $response = 'I’ll create that reminder.';
        } elseif (preg_match('/\b(note|write down|jot)\b/', $lower)) {
            $actions[] = ['action' => 'note.create', 'arguments' => ['plain_text' => $text]];
            $response = 'I’ll add that note.';
        } elseif (preg_match('/\b(calendar|appointment|event|schedule)\b/', $lower)) {
            if (preg_match('/\b(what|show|list)\b/', $lower)) {
                $actions[] = ['action' => 'calendar_event.list', 'arguments' => []];
                $response = 'I’ll check your calendar.';
            } else {
                $actions[] = ['action' => 'calendar_event.create', 'arguments' => ['title' => $this->titleFromText($text), 'starts_at' => now()->addDay()->setTime(9, 0)->toIso8601String(), 'ends_at' => now()->addDay()->setTime(10, 0)->toIso8601String(), 'all_day' => false]];
                $response = 'I’ll add that calendar event.';
            }
        } elseif (preg_match('/\b(task|todo|to-do|call|buy|finish)\b/', $lower)) {
            if (preg_match('/\b(what|show|list)\b/', $lower)) {
                $actions[] = ['action' => 'task.list', 'arguments' => []];
                $response = 'I’ll check your tasks.';
            } else {
                $actions[] = ['action' => 'task.create', 'arguments' => ['title' => $this->titleFromText($text), 'type' => 'todo']];
                $response = 'I’ll add that task.';
            }
        }

        return ['response' => $response, 'actions' => $actions, 'model' => 'local-heuristic'];
    }

    private function titleFromText(string $text): string
    {
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(add|create|make|set)\s+(a\s+)?(task|todo|to-do|reminder|calendar event|event|note)\s+(to\s+)?/i', '', trim($text)) ?: trim($text);
        $title = preg_replace('/^(hey bean,?\s*)?(please\s+)?(remind me to|remind me|write down|jot down)\s+/i', '', trim($title)) ?: trim($title);
        return str($title)->limit(120, '')->toString();
    }
}
