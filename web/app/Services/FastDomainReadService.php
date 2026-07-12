<?php

namespace App\Services;

use App\Exceptions\BrowserVoiceHandlerException;
use App\Models\ConversationSession;
use App\Models\Note;
use App\Models\Reminder;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class FastDomainReadService
{
    public function __construct(private readonly PlanLimitService $planLimits) {}

    public function resolve(ConversationSession $session, string $handler, string $content, array $metadata = []): ?string
    {
        if ($handler === 'app.note.read' && ! $this->planLimits->canUseNotes(User::findOrFail($session->user_id))) {
            throw new BrowserVoiceHandlerException(
                'subscription_limit_reached',
                'Notes are available on this plan after upgrading.',
                'Notes are available on this plan after upgrading.',
            );
        }
        $timezone = $this->timezone($metadata);

        return match ($handler) {
            'app.reminder.read' => $this->reminders($session, $content, $timezone),
            'app.task.read' => $this->tasks($session, $content, $timezone),
            'app.note.read' => $this->notes($session, $content),
            default => null,
        };
    }

    private function reminders(ConversationSession $session, string $content, string $timezone): string
    {
        $query = Reminder::query()
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->whereNotIn('status', ['completed', 'cancelled', 'canceled', 'dismissed']);
        [$start, $end, $label] = $this->dateWindow($content, $timezone);
        if ($start !== null) {
            $query->whereBetween('remind_at', [$start, $end]);
        }
        $items = $query->orderBy('remind_at')->limit(10)->get();
        if ($items->isEmpty()) {
            return $label ? "You don’t have any reminders {$label}." : 'You don’t have any upcoming reminders.';
        }

        return $this->listAnswer(
            $label ? "Your reminders {$label} are" : 'Your next reminders are',
            $items->map(fn (Reminder $reminder): string => '“'.$reminder->title.'” '.$this->when($reminder->remind_at, $timezone)),
        );
    }

    private function tasks(ConversationSession $session, string $content, string $timezone): string
    {
        $query = Task::query()
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->whereNotIn('status', ['completed', 'complete', 'done', 'cancelled', 'canceled']);
        [$start, $end, $label] = $this->dateWindow($content, $timezone);
        if ($start !== null) {
            $query->whereBetween('due_at', [$start, $end]);
        }
        $items = $query->orderByRaw('due_at is null')->orderBy('due_at')->orderBy('id')->limit(10)->get();
        if ($items->isEmpty()) {
            return $label ? "You don’t have any tasks due {$label}." : 'You don’t have any open tasks.';
        }

        return $this->listAnswer(
            $label ? "Your tasks {$label} are" : 'Your next tasks are',
            $items->map(fn (Task $task): string => '“'.$task->title.'”'.($task->due_at ? ' '.$this->when($task->due_at, $timezone) : '')),
        );
    }

    private function notes(ConversationSession $session, string $content): string
    {
        $query = Note::query()
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id);
        if (preg_match('/\b(?:called|named|titled|about)\s+[“"]?([^”"?.]{2,80})/iu', $content, $match) === 1) {
            $term = trim((string) $match[1]);
            $query->where(function ($query) use ($term): void {
                $query->where('title', 'like', "%{$term}%")
                    ->orWhere('plain_text', 'like', "%{$term}%");
            });
        }
        $items = $query->latest('updated_at')->limit(10)->get();
        if ($items->isEmpty()) {
            return 'I couldn’t find a matching note.';
        }
        if ($items->count() === 1) {
            $note = $items->first();
            $preview = trim((string) $note->plain_text);

            return 'Your note “'.$note->title.'”'.($preview !== '' ? ' says: '.mb_substr($preview, 0, 240) : ' is empty').'.';
        }

        return $this->listAnswer('Your most recent notes are', $items->map(fn (Note $note): string => '“'.$note->title.'”'));
    }

    /** @return array{0: ?Carbon, 1: ?Carbon, 2: ?string} */
    private function dateWindow(string $content, string $timezone): array
    {
        $text = mb_strtolower($content);
        $day = null;
        $label = null;
        if (str_contains($text, 'tomorrow')) {
            $day = now($timezone)->addDay();
            $label = 'tomorrow';
        } elseif (str_contains($text, 'today')) {
            $day = now($timezone);
            $label = 'today';
        }
        if ($day === null) {
            return [null, null, null];
        }

        return [$day->copy()->startOfDay()->utc(), $day->copy()->endOfDay()->utc(), $label];
    }

    private function when(?Carbon $value, string $timezone): string
    {
        if ($value === null) {
            return '';
        }
        $local = $value->copy()->timezone($timezone);
        $date = $local->isToday() ? 'today' : ($local->isTomorrow() ? 'tomorrow' : 'on '.$local->format('F jS'));
        $time = strtolower($local->format('g:i a'));
        $time = str_replace(':00 ', ' ', $time);
        $time = str_replace([' am', ' pm'], [' a.m.', ' p.m.'], $time);

        return "{$date} at {$time}";
    }

    /** @param Collection<int, string> $items */
    private function listAnswer(string $prefix, Collection $items): string
    {
        $values = $items->values()->all();
        $last = array_pop($values);
        $list = $values === [] ? $last : implode(', ', $values).', and '.$last;

        return rtrim($prefix.': '.$list, '.').'.';
    }

    private function timezone(array $metadata): string
    {
        $timezone = trim((string) ($metadata['client_timezone'] ?? 'UTC')) ?: 'UTC';
        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }
}
