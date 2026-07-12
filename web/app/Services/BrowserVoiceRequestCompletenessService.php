<?php

namespace App\Services;

class BrowserVoiceRequestCompletenessService
{
    public function __construct(
        private readonly BrowserVoiceTypedWriteParser $typedWrites,
        private readonly BrowserVoiceSubtaskSplitter $subtasks,
        private readonly BrowserVoiceIntentText $intentText,
    ) {}

    public function clarificationQuestion(
        string $transcript,
        bool $hasDefaultLocation = false,
        ?string $timezone = null,
        bool $inspectSubtasks = true,
        bool $hasAuthorizedContextualReference = false,
    ): ?string {
        $text = str_replace('’', "'", mb_strtolower(trim($transcript)));
        $text = preg_replace('/^hey[\s,.-]+bean[\s,.:;-]*/u', '', $text) ?? $text;
        $intentText = $this->intentText->stripEntityPayloads($text);
        if ($this->isExplicitCancellation($text) || $this->isWorkStatusQuestion($text)) {
            return null;
        }
        if (preg_match('/\b(?:plan|draft|brainstorm|generate|pick|random)\b/u', $intentText) === 1) {
            return null;
        }

        if ($inspectSubtasks) {
            $segments = $this->subtasks->split($transcript);
            if (count($segments) > 1) {
                $previousOperation = null;
                $previousDomain = null;
                foreach ($segments as $segment) {
                    $segmentIntent = $this->intentText->stripEntityPayloads(mb_strtolower($segment));
                    $segmentDomain = $this->domain($segmentIntent);
                    if ($previousOperation === 'create'
                        && $previousDomain !== null
                        && $segmentDomain === $previousDomain
                        && preg_match('/^(?:another|one\s+more|a\s+second|the\s+second)\b/u', trim(mb_strtolower($segment))) === 1) {
                        $segment = 'Create '.$segment;
                        $segmentIntent = 'create '.$segmentIntent;
                    }
                    $question = $this->clarificationQuestion(
                        $segment,
                        $hasDefaultLocation,
                        $timezone,
                        false,
                        $hasAuthorizedContextualReference
                            || ($previousOperation === 'create'
                                && $previousDomain !== null
                                && $segmentDomain === $previousDomain),
                    );
                    if ($question !== null) {
                        return $question;
                    }
                    $previousOperation = $this->intentText->writeOperation($segmentIntent);
                    $previousDomain = $segmentDomain;
                }
            }
        }

        $operation = $this->intentText->writeOperation($intentText);
        $creates = $operation === 'create';
        $deletes = in_array($operation, ['delete', 'complete', 'reschedule'], true);
        $reschedules = $operation === 'reschedule';
        if ($operation === null) {
            return $this->weatherQuestion($intentText, $hasDefaultLocation);
        }

        $typedHandler = match (true) {
            preg_match('/\breminders?\b|\bremind me\b/u', $intentText) === 1 => 'app.reminder.create',
            preg_match('/\b(?:calendar|events?|meetings?|appointments?)\b/u', $intentText) === 1
                || preg_match('/^(?:(?:can|could|would|will) you\s+)?(?:please\s+)?(?:schedule|book)\b/u', $intentText) === 1 => 'app.calendar.create',
            default => null,
        };
        $typedWrite = $creates && $typedHandler !== null
            ? $this->typedWrites->parseCreate($transcript, $typedHandler, $timezone)
            : null;
        if ($typedWrite !== null && ($question = $typedWrite->clarificationQuestion()) !== null) {
            return $question;
        }

        if (preg_match('/\breminders?\b|\bremind me\b/u', $intentText) === 1) {
            if ($reschedules && ! $this->typedWrites->hasClockTime($text)) {
                return 'What time should I move the reminder to?';
            }
            if ($deletes && ! $this->hasTarget(
                $text,
                'reminder',
                $hasAuthorizedContextualReference,
                ! $reschedules,
            )) {
                return 'Which reminder should I change?';
            }
        }

        if (preg_match('/\b(?:tasks?|to do|todo)\b/u', $intentText) === 1) {
            if ($creates && ! $this->hasCreatedNounContent($text, '(?:task|to do|todo)')) {
                return 'What task should I create?';
            }
            if ($deletes && ! $this->hasTarget($text, 'task', $hasAuthorizedContextualReference)) {
                return 'Which task should I change?';
            }
        }

        if (preg_match('/\bnotes?\b/u', $intentText) === 1) {
            if ($creates && ! $this->hasCreatedNounContent($text, 'note')) {
                return 'What should the note include?';
            }
            if ($deletes && ! $this->hasTarget($text, 'note', $hasAuthorizedContextualReference)) {
                return 'Which note should I change?';
            }
        }

        if (preg_match('/\b(?:calendar|events?|meetings?|appointments?)\b/u', $intentText) === 1) {
            if ($reschedules && ! $this->typedWrites->hasClockTime($text)) {
                return 'What time should I move the calendar event to?';
            }
            if ($deletes && ! $this->hasTarget(
                $text,
                '(?:calendar )?event|meeting|appointment',
                $hasAuthorizedContextualReference,
                ! $reschedules,
            )) {
                return 'Which calendar event should I change?';
            }
        }

        return $this->weatherQuestion($intentText, $hasDefaultLocation);
    }

    private function weatherQuestion(string $text, bool $hasDefaultLocation): ?string
    {
        if ($hasDefaultLocation
            || preg_match('/\b(?:weather|forecast|temperature|rain|storm)\b/u', $text) !== 1
            || $this->hasExplicitWeatherLocation($text)) {
            return null;
        }

        return 'Which location should I check?';
    }

    private function hasExplicitWeatherLocation(string $text): bool
    {
        preg_match_all('/\b(in|at|near|around|for)\s+([a-z0-9][a-z0-9\'-]*)/u', $text, $matches, PREG_SET_ORDER);
        $temporal = [
            'today', 'tomorrow', 'tonight', 'later', 'now', 'noon', 'midnight',
            'morning', 'afternoon', 'evening', 'night', 'week', 'weekend',
        ];
        foreach ($matches as $match) {
            $preposition = $match[1];
            $token = $match[2];
            if (in_array($token, $temporal, true)
                || preg_match('/^(?:a\.?m\.?|p\.?m\.?)$/u', $token) === 1
                || preg_match('/^\d{1,2}(?:a\.?m\.?|p\.?m\.?)$/u', $token) === 1
                || in_array($token, ['me', 'here', 'home'], true)) {
                continue;
            }
            if (ctype_digit($token) && ! ($preposition === 'in' && preg_match('/^\d{5}$/', $token) === 1)) {
                continue;
            }

            return true;
        }

        return false;
    }

    private function hasCreatedNounContent(string $text, string $noun): bool
    {
        return preg_match('/\b(?:titled|called|named|that says|saying|with (?:the )?(?:text|content))\s+\S+/u', $text) === 1
            || preg_match('/\b(?:create|add|make|set|schedule|save)\s+(?:a |an |the )?(?:'.$noun.')\b\s+(?!for\b|on\b|at\b|today\b|tomorrow\b)\S+/u', $text) === 1
            || preg_match('/\S+\s+(?:as|into)\s+(?:a |an )?(?:'.$noun.')\b/u', $text) === 1;
    }

    private function hasTime(string $text): bool
    {
        return preg_match('/\b(?:today|tomorrow|tonight|noon|midnight|monday|tuesday|wednesday|thursday|friday|saturday|sunday|january|february|march|april|may|june|july|august|september|october|november|december)\b/u', $text) === 1
            || preg_match('/\b(?:[01]?\d|2[0-3])(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?)\b/u', $text) === 1;
    }

    private function hasTarget(
        string $text,
        string $noun,
        bool $hasAuthorizedContextualReference,
        bool $allowTemporalTarget = true,
    ): bool {
        return ($hasAuthorizedContextualReference
                && preg_match('/\b(?:that|it|the one|this)\b/u', $text) === 1)
            || preg_match('/\b(?:all|every)\b/u', $text) === 1
            || preg_match('/\b(?:'.$noun.')\b\s+(?:titled|called|named)\s+\S+/u', $text) === 1
            || ($allowTemporalTarget && $this->hasTime($text));
    }

    private function isExplicitCancellation(string $text): bool
    {
        $normalized = preg_replace(
            '/^(?:(?:please|no|well|actually|wait|uh|um)[\s,.-]+)*/u',
            '',
            trim($text),
        ) ?? trim($text);

        return preg_match('/^cancel\b/u', $normalized) === 1
            || preg_match('/^(?:don\'t|do not|never)\s+(?:create|make|add|set|schedule|save|book|delete|remove|update|change|move|reschedule|complete|mark)\b/u', $normalized) === 1;
    }

    private function isWorkStatusQuestion(string $text): bool
    {
        return preg_match('/^(?:did|have) you (?:finish|finished|complete|completed)\b/u', $text) === 1
            || preg_match('/^(?:are you (?:done|finished)|is (?:that|it|the .+?) (?:done|finished|complete|completed)|are you still working)\b/u', $text) === 1;
    }

    private function domain(string $text): ?string
    {
        return match (true) {
            preg_match('/\b(?:calendar|schedule|agenda|event|meeting|appointment)\b/u', $text) === 1 => 'calendar',
            preg_match('/\b(?:reminder|reminders|remind)\b/u', $text) === 1 => 'reminder',
            preg_match('/\b(?:task|tasks|todo|to do)\b/u', $text) === 1 => 'task',
            preg_match('/\b(?:note|notes)\b/u', $text) === 1 => 'note',
            default => null,
        };
    }
}
