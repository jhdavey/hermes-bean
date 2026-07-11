<?php

namespace App\Services;

use App\Models\CalendarEvent;
use App\Models\ConversationSession;
use Illuminate\Support\Carbon;

class FastCalendarReadService
{
    public function resolve(ConversationSession $session, string $content, array $metadata = []): ?string
    {
        $text = $this->normalize($content);
        if (! preg_match('/\b(?:calendar|agenda|schedule)\b/u', $text)
            || preg_match('/\b(?:add|create|make|set|schedule an?|book|delete|remove|update|change|move|reschedule)\b/u', $text)) {
            return null;
        }

        $timezone = $this->timezone($session, $metadata);
        $now = now($timezone);
        $label = null;
        if (preg_match('/\btomorrow\b/u', $text)) {
            $day = $now->copy()->addDay();
            $label = 'tomorrow';
        } elseif (preg_match('/\btoday\b/u', $text)) {
            $day = $now->copy();
            $label = 'today';
        } elseif (preg_match('/\b(?:what(?:\'s| is) next|next on|next event|next appointment)\b/u', $text)) {
            return $this->nextEventAnswer($session, $now, $timezone);
        } else {
            return null;
        }

        $start = $day->copy()->startOfDay()->utc();
        $end = $day->copy()->endOfDay()->utc();
        $events = CalendarEvent::query()
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where('starts_at', '<=', $end)
            ->where(function ($query) use ($start): void {
                $query->whereNull('ends_at')->where('starts_at', '>=', $start)
                    ->orWhere('ends_at', '>=', $start);
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->limit(20)
            ->get()
            ->reject(fn (CalendarEvent $event): bool => (bool) data_get($event->metadata, 'recurrence_source_hidden', false))
            ->values();

        if ($events->isEmpty()) {
            return "You don’t have anything on your calendar {$label}.";
        }

        $items = $events->map(fn (CalendarEvent $event): string => $this->eventPhrase($event, $timezone))->all();
        if (count($items) === 1) {
            return rtrim(ucfirst($label).', you have '.$items[0], '.').'.';
        }

        return rtrim(ucfirst($label).', you have '.count($items).' events: '.$this->naturalList($items), '.').'.';
    }

    private function nextEventAnswer(ConversationSession $session, Carbon $now, string $timezone): string
    {
        $event = CalendarEvent::query()
            ->where('user_id', $session->user_id)
            ->where('workspace_id', $session->workspace_id)
            ->where(function ($query) use ($now): void {
                $query->where('starts_at', '>=', $now->copy()->utc())
                    ->orWhere('ends_at', '>=', $now->copy()->utc());
            })
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get()
            ->first(fn (CalendarEvent $event): bool => ! (bool) data_get($event->metadata, 'recurrence_source_hidden', false));

        if (! $event instanceof CalendarEvent) {
            return 'You don’t have another event coming up on your calendar.';
        }

        $start = $event->starts_at?->copy()->timezone($timezone);
        $date = $start?->isToday()
            ? 'today'
            : ($start?->isTomorrow() ? 'tomorrow' : $start?->format('l, F jS'));
        $when = $this->isAllDay($event)
            ? $date
            : $date.' at '.$this->time($event->starts_at, $timezone);

        return rtrim('Next is “'.$event->title.'” '.$when, '.').'.';
    }

    private function eventPhrase(CalendarEvent $event, string $timezone): string
    {
        if ($this->isAllDay($event)) {
            return '“'.$event->title.'” all day';
        }

        return '“'.$event->title.'” at '.$this->time($event->starts_at, $timezone);
    }

    private function isAllDay(CalendarEvent $event): bool
    {
        return (bool) data_get($event->metadata, 'all_day', false);
    }

    private function time(?Carbon $value, string $timezone): string
    {
        if ($value === null) {
            return 'an unspecified time';
        }

        $time = strtolower($value->copy()->timezone($timezone)->format('g:i a'));
        $time = str_replace(':00 ', ' ', $time);

        return str_replace([' am', ' pm'], [' a.m.', ' p.m.'], $time);
    }

    private function naturalList(array $items): string
    {
        if (count($items) === 2) {
            return $items[0].', and '.$items[1];
        }

        $last = array_pop($items);

        return implode(', ', $items).', and '.$last;
    }

    private function timezone(ConversationSession $session, array $metadata): string
    {
        $timezone = trim((string) ($metadata['client_timezone'] ?? data_get($session->metadata, 'client_timezone', 'UTC')));
        try {
            new \DateTimeZone($timezone);

            return $timezone;
        } catch (\Throwable) {
            return 'UTC';
        }
    }

    private function normalize(string $content): string
    {
        $text = str_replace('’', "'", mb_strtolower($content));

        return trim(preg_replace('/\s+/', ' ', $text) ?: $text);
    }
}
