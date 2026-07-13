<?php

namespace App\Services;

use App\Models\ConversationSession;
use App\Models\Task;
use App\Models\User;
use App\Models\VoiceTurn;

final class BrowserVoiceContextReferenceResolver
{
    /**
     * @param  array<string, mixed>  $input
     */
    public function priorTurn(User $user, ConversationSession $session, array $input): ?VoiceTurn
    {
        if (data_get($input, 'conversation_context.mode') !== 'contextual_follow_up') {
            return null;
        }

        $epoch = (int) data_get($input, 'conversation_context.epoch', -1);
        $generation = (int) ($input['controller_generation'] ?? -1);
        if ($epoch < 1 || $generation < 0) {
            return null;
        }

        $turnId = trim((string) ($input['turn_id'] ?? ''));

        return VoiceTurn::query()
            ->where('user_id', $user->id)
            ->where('conversation_session_id', $session->id)
            ->when($turnId !== '', fn ($query) => $query->where('turn_id', '!=', $turnId))
            ->latest('id')
            ->limit(50)
            ->get()
            ->first(fn (VoiceTurn $candidate): bool => (int) data_get($candidate->metadata, 'conversation_context.epoch', -2) === $epoch
                && (int) data_get($candidate->metadata, 'controller_generation', -2) === $generation
            );
    }

    /**
     * Resolve a spoken entity reference only from the authoritative result of
     * the immediately authorized conversation turn. Ambiguous lists fail
     * closed and are clarified before admission.
     *
     * @return array{domain: string, resource_id: int, title: string, prior_turn_id: string}|null
     */
    public function resolve(User $user, ConversationSession $session, VoiceTurn $priorTurn, string $transcript): ?array
    {
        if (! $this->referencesTask($transcript)
            || $priorTurn->handler !== 'app.task.read'
            || $priorTurn->user_id !== $user->id
            || $priorTurn->conversation_session_id !== $session->id
            || $priorTurn->final_assistant_message_id === null) {
            return null;
        }

        $answer = (string) $priorTurn->finalAssistantMessage()->value('content');
        preg_match_all('/[“"]([^”"]+)[”"]/u', $answer, $matches);
        $mentionedTitles = collect($matches[1] ?? [])
            ->map(fn (string $title): string => $this->normalize($title))
            ->filter()
            ->unique()
            ->values();
        if ($mentionedTitles->isEmpty()) {
            return null;
        }

        $tasks = Task::query()
            ->where('user_id', $user->id)
            ->where('workspace_id', $session->workspace_id)
            ->whereNotIn('status', ['completed', 'complete', 'done', 'cancelled', 'canceled'])
            ->latest('id')
            ->limit(50)
            ->get()
            ->filter(fn (Task $task): bool => $mentionedTitles->contains($this->normalize((string) $task->title)))
            ->values();
        if ($tasks->count() !== 1) {
            return null;
        }

        /** @var Task $task */
        $task = $tasks->first();

        return [
            'domain' => 'task',
            'resource_id' => (int) $task->id,
            'title' => trim((string) $task->title),
            'prior_turn_id' => $priorTurn->turn_id,
        ];
    }

    private function referencesTask(string $transcript): bool
    {
        return preg_match('/\b(?:for|about|to)\s+(?:that|this|the)\s+task\b/iu', $transcript) === 1;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[\p{Z}\s]+/u', ' ', $value) ?? $value;

        return trim($value, " \t\n\r\0\x0B\"'“”‘’.,!?;");
    }
}
