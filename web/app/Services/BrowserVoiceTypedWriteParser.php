<?php

namespace App\Services;

use App\Data\BrowserVoiceTypedWriteIntent;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

final class BrowserVoiceTypedWriteParser
{
    private const CREATE_PATTERN = '/\b(?:create|add|make|set|schedule|save|book)\b|\bremind me\b/iu';

    private const CALENDAR_NOUN_PATTERN = '(?:calendar\s+)?(?:event|meeting|appointment)';

    private const DATE_WORD_PATTERN = '(?:today|tomorrow|tonight|(?:next\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|(?:january|february|march|april|may|june|july|august|september|october|november|december)\s+\d{1,2}(?:st|nd|rd|th)?(?:,?\s+\d{4})?)';

    private const CLOCK_PATTERN = '(?:noon|midnight|(?:1[0-2]|0?[1-9])(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?)|(?:one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)(?:\s+(?:thirty|fifteen|forty[ -]?five))?\s*(?:a\.?m\.?|p\.?m\.?)|(?:[01]?\d|2[0-3]):[0-5]\d)';

    public function parseCreate(
        string $transcript,
        ?string $handler = null,
        ?string $timezone = null,
        ?CarbonInterface $referenceTime = null,
        ?string $contextualTitle = null,
    ): ?BrowserVoiceTypedWriteIntent {
        $text = $this->withoutWake($transcript);
        if (preg_match(self::CREATE_PATTERN, $text) !== 1) {
            return null;
        }

        $resource = $this->resource($text, $handler);
        if (! in_array($resource, ['reminder', 'calendar'], true)) {
            return null;
        }

        $hasClockTime = $this->hasClockTime($text);

        $title = $this->title($text, $resource);
        if ($title === null
            && $resource === 'reminder'
            && filled($contextualTitle)
            && preg_match('/\b(?:for|about|to)\s+(?:that|this|the)\s+task\b/iu', $text) === 1) {
            $title = mb_substr(trim((string) $contextualTitle), 0, 180) ?: null;
        }

        return new BrowserVoiceTypedWriteIntent(
            resource: $resource,
            title: $title,
            scheduledAt: $hasClockTime
                ? $this->parseScheduledAt($text, $timezone, $referenceTime)
                : null,
            hasClockTime: $hasClockTime,
        );
    }

    public function hasClockTime(string $transcript): bool
    {
        return preg_match('/\b'.self::CLOCK_PATTERN.'\b/iu', $this->withoutWake($transcript)) === 1;
    }

    public function parseScheduledAt(
        string $transcript,
        ?string $timezone = null,
        ?CarbonInterface $referenceTime = null,
    ): ?Carbon {
        $text = $this->withoutWake($transcript);
        if (! $this->hasClockTime($text)) {
            return null;
        }

        return $this->scheduledAt($text, $timezone, $referenceTime);
    }

    private function resource(string $text, ?string $handler): ?string
    {
        if (str_starts_with((string) $handler, 'app.reminder.')) {
            return 'reminder';
        }
        if (str_starts_with((string) $handler, 'app.calendar.')) {
            return 'calendar';
        }
        if (preg_match('/\breminders?\b|\bremind me\b/iu', $text) === 1) {
            return 'reminder';
        }
        if (preg_match('/\b(?:calendar|events?|meetings?|appointments?)\b/iu', $text) === 1
            || preg_match('/^(?:(?:can|could|would|will) you\s+)?(?:please\s+)?(?:schedule|book)\b/iu', $text) === 1) {
            return 'calendar';
        }

        return null;
    }

    private function title(string $text, string $resource): ?string
    {
        $temporalBoundary = '(?=\s+(?:(?:for|on|at|by)\s+)?(?:'.self::DATE_WORD_PATTERN.'|'.self::CLOCK_PATTERN.')\b|[.!?]*$)';
        $patterns = [
            '/\b(?:titled|called|named)\s+[\x{201C}"]?(.+?)[\x{201D}"]?'.$temporalBoundary.'/iu',
        ];

        if ($resource === 'reminder') {
            $patterns[] = '/\bremind me to\s+[\x{201C}"]?(.+?)[\x{201D}"]?'.$temporalBoundary.'/iu';
            $patterns[] = '/\b(?:create|add|make|set|save)\s+(?:a\s+|an\s+|the\s+)?reminder\b\s+(?:to\s+|about\s+)?(?!for\b|on\b|at\b|'.self::DATE_WORD_PATTERN.'\b)(.+?)'.$temporalBoundary.'/iu';
        } else {
            $patterns[] = '/\b(?:create|add|make|set|schedule|save|book)\s+(?:a\s+|an\s+|the\s+)?'.self::CALENDAR_NOUN_PATTERN.'\b\s+(?:(?:to|about|with)\s+)?(?!for\b|on\b|at\b|'.self::DATE_WORD_PATTERN.'\b)(.+?)'.$temporalBoundary.'/iu';
            $patterns[] = '/\b(?:add|put)\s+(.+?)\s+(?:to|on)\s+(?:my\s+|the\s+)?calendar\b/iu';
            $patterns[] = '/^(?:(?:can|could|would|will) you\s+)?(?:please\s+)?(?:schedule|book)\s+(?!'.self::DATE_WORD_PATTERN.'\b|'.self::CLOCK_PATTERN.'\b)(.+?)'.$temporalBoundary.'/iu';
        }

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $match) !== 1) {
                continue;
            }
            $title = $this->cleanTitle((string) $match[1], $resource);
            if ($title !== null) {
                return $title;
            }
        }

        return null;
    }

    private function cleanTitle(string $candidate, string $resource): ?string
    {
        $title = trim($candidate, " \t\n\r\0\x0B\"\xE2\x80\x9C\xE2\x80\x9D.,!?;");
        $title = preg_replace('/^(?:a|an|the)\s+/iu', '', $title) ?? $title;
        $title = trim($title);
        $generic = $resource === 'reminder'
            ? ['reminder', 'it', 'one', 'something']
            : ['calendar', 'calendar event', 'event', 'meeting', 'appointment', 'it', 'one', 'something'];
        if ($title === '' || in_array(mb_strtolower($title), $generic, true)) {
            return null;
        }

        return mb_substr($title, 0, 180);
    }

    private function scheduledAt(
        string $text,
        ?string $timezone,
        ?CarbonInterface $referenceTime,
    ): ?Carbon {
        $timezone = $this->validTimezone($timezone);
        $base = $referenceTime instanceof CarbonInterface
            ? Carbon::instance($referenceTime)->timezone($timezone)
            : now($timezone);
        $base = $base->copy()->setSecond(0);

        if (preg_match('/\btomorrow\b/iu', $text) === 1) {
            $base->addDay();
        } elseif (preg_match('/\b(?:next\s+)?(monday|tuesday|wednesday|thursday|friday|saturday|sunday)\b/iu', $text, $weekday) === 1) {
            $base = $base->next(ucfirst(mb_strtolower($weekday[1])));
        } elseif (preg_match('/\b(january|february|march|april|may|june|july|august|september|october|november|december)\s+(\d{1,2})(?:st|nd|rd|th)?(?:,?\s+(\d{4}))?\b/iu', $text, $date) === 1) {
            $base = Carbon::parse($date[1].' '.$date[2].' '.($date[3] ?? $base->year), $timezone);
        }

        if (preg_match('/\bnoon\b/iu', $text) === 1) {
            return $base->setTime(12, 0);
        }
        if (preg_match('/\bmidnight\b/iu', $text) === 1) {
            return $base->setTime(0, 0);
        }
        if (preg_match('/\b(?:at\s+)?(1[0-2]|0?[1-9])(?::([0-5]\d))?\s*(a\.?m\.?|p\.?m\.?)\b/iu', $text, $clock) === 1) {
            $hour = (int) $clock[1] % 12;
            if (str_starts_with(mb_strtolower((string) $clock[3]), 'p')) {
                $hour += 12;
            }

            return $base->setTime($hour, (int) ($clock[2] ?? 0));
        }
        if (preg_match('/\b(?:at\s+)?(one|two|three|four|five|six|seven|eight|nine|ten|eleven|twelve)(?:\s+(thirty|fifteen|forty[ -]?five))?\s*(a\.?m\.?|p\.?m\.?)\b/iu', $text, $clock) === 1) {
            $hours = array_flip(['one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve']);
            $hour = ((int) $hours[mb_strtolower($clock[1])] + 1) % 12;
            if (str_starts_with(mb_strtolower((string) $clock[3]), 'p')) {
                $hour += 12;
            }
            $minute = match (str_replace([' ', '-'], '', mb_strtolower((string) ($clock[2] ?? '')))) {
                'fifteen' => 15,
                'thirty' => 30,
                'fortyfive' => 45,
                default => 0,
            };

            return $base->setTime($hour, $minute);
        }
        if (preg_match('/\b(?:at\s+)?([01]?\d|2[0-3]):([0-5]\d)\b/u', $text, $clock) === 1) {
            return $base->setTime((int) $clock[1], (int) $clock[2]);
        }

        return null;
    }

    private function validTimezone(?string $timezone): string
    {
        $timezone = trim((string) $timezone) ?: 'UTC';
        try {
            new \DateTimeZone($timezone);
        } catch (\Throwable) {
            return 'UTC';
        }

        return $timezone;
    }

    private function withoutWake(string $transcript): string
    {
        $text = trim(str_replace('’', "'", $transcript));

        return trim(preg_replace('/^hey[\s,.-]+bean[\s,.:;-]*/iu', '', $text) ?? $text);
    }
}
