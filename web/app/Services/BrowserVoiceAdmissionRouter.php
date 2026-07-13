<?php

namespace App\Services;

use App\Data\VoiceTurnRoute;
use App\Enums\VoiceTurnLane;
use App\Exceptions\VoiceTurnConflictException;

class BrowserVoiceAdmissionRouter
{
    public function __construct(
        private readonly BrowserVoiceSubtaskSplitter $subtasks,
        private readonly BrowserVoiceIntentText $intentText,
        private readonly BrowserVoiceTypedWriteParser $typedWrites,
    ) {}

    /**
     * @param  array<string, mixed>  $context
     */
    public function route(string $transcript, array $context = [], ?string $declaredLocalHandler = null): VoiceTurnRoute
    {
        $text = $this->normalize($transcript);
        $instantHandler = $this->instantHandler($text);

        if ($declaredLocalHandler !== null && $declaredLocalHandler !== '') {
            if ($instantHandler !== $declaredLocalHandler) {
                throw new VoiceTurnConflictException('The declared local handler does not match the admitted transcript.');
            }

            return $this->instantRoute($instantHandler);
        }

        if ($instantHandler !== null) {
            return $this->instantRoute($instantHandler);
        }

        if ($this->isCapabilityQuestion($text)) {
            return $this->instantRoute('instant.capability');
        }

        if (preg_match('/^should\s+you\b/u', $text) === 1) {
            return $this->instantRoute('instant.confirmation_required');
        }

        if ($this->isWorkStatusQuestion($text)) {
            return new VoiceTurnRoute(
                VoiceTurnLane::AppRead,
                'app.voice_work.status',
                false,
                null,
                3,
            );
        }

        if ($this->isExplicitCancellation($text)) {
            return new VoiceTurnRoute(
                VoiceTurnLane::AppWrite,
                'app.voice_work.cancel',
                false,
                null,
                5,
                2,
            );
        }

        $contextualRescheduleDomain = $this->contextualRescheduleDomain($text, $context);
        if ($contextualRescheduleDomain !== null) {
            return new VoiceTurnRoute(
                VoiceTurnLane::AppWrite,
                "app.{$contextualRescheduleDomain}.reschedule",
                false,
                null,
                5,
                2,
            );
        }

        $intentText = $this->intentText->stripEntityPayloads($text);
        $applicationDomains = $this->applicationDomains($intentText);
        $externalRequest = $this->containsAny($intentText, ['weather', 'forecast', 'temperature', 'rain', 'storm']);
        $contextualWeather = data_get($context, 'prior_handler') === 'external.weather'
            && $this->isContextualFollowUp($text);
        if ($this->isGeneratedNoteRequest($text)) {
            return new VoiceTurnRoute(
                VoiceTurnLane::ComplexAgent,
                'agent.generate_note',
                true,
                'I’ll put that together.',
                120,
                10,
            );
        }
        if ($this->containsMultipleRoutableOperations($text)
            || (($externalRequest || $contextualWeather) && $applicationDomains !== [])) {
            return new VoiceTurnRoute(
                VoiceTurnLane::ComplexAgent,
                'agent.complex',
                true,
                'I’ll take care of those together.',
                120,
                10,
            );
        }

        // A fully parsed typed create owns the nouns inside its payload. For
        // example, "a reminder to do the salt" is one reminder write; "to do"
        // is content, not a second task domain. Multi-operation transcripts
        // were already rejected above by the subtask splitter.
        $writeOperation = $this->writeOperation($intentText);
        $typedCreate = $writeOperation === 'create'
            ? $this->typedWrites->parseCreate(
                $transcript,
                timezone: data_get($context, 'timezone'),
                contextualTitle: data_get($context, 'contextual_reference.title'),
            )
            : null;
        if ($typedCreate !== null && $typedCreate->clarificationQuestion() === null) {
            $queuedFollowUp = (int) data_get($context, 'active_background_job_count', 0) > 0;

            return new VoiceTurnRoute(
                VoiceTurnLane::AppWrite,
                "app.{$typedCreate->resource}.create",
                $queuedFollowUp,
                $queuedFollowUp ? 'Got it—I added that.' : null,
                5,
                2,
            );
        }

        if (count($applicationDomains) > 1) {
            return new VoiceTurnRoute(
                VoiceTurnLane::ComplexAgent,
                'agent.complex',
                true,
                'I’ll take care of those together.',
                120,
                10,
            );
        }

        if ($contextualWeather || $externalRequest) {
            $weatherScopeText = $contextualWeather
                ? trim((string) data_get($context, 'prior_transcript', '')).' '.$text
                : $text;
            $local = ! $this->containsExplicitLocation($weatherScopeText)
                && (bool) data_get($context, 'location_context.is_local', true);

            return new VoiceTurnRoute(
                VoiceTurnLane::External,
                'external.weather',
                ! $local,
                $local ? null : 'Let me check that forecast.',
                8,
            );
        }

        $domain = $applicationDomains[0] ?? null;
        if ($domain === null && $this->isContextualFollowUp($text)) {
            $priorHandler = (string) data_get($context, 'prior_handler', '');
            if (preg_match('/^app\.(calendar|reminder|task|note)\.read$/', $priorHandler, $match) === 1) {
                $domain = $match[1];
            }
        }
        if ($domain !== null && $writeOperation !== null) {
            $queuedFollowUp = (int) data_get($context, 'active_background_job_count', 0) > 0;

            return new VoiceTurnRoute(
                VoiceTurnLane::AppWrite,
                "app.{$domain}.{$writeOperation}",
                $queuedFollowUp,
                $queuedFollowUp ? 'Got it—I added that.' : null,
                5,
                2,
            );
        }

        if ($domain !== null) {
            return new VoiceTurnRoute(
                VoiceTurnLane::AppRead,
                "app.{$domain}.read",
                false,
                null,
                3,
            );
        }

        return new VoiceTurnRoute(
            VoiceTurnLane::ComplexAgent,
            'agent.complex',
            true,
            'I’ll take care of that.',
            120,
            10,
        );
    }

    private function instantRoute(string $handler): VoiceTurnRoute
    {
        return new VoiceTurnRoute(VoiceTurnLane::Instant, $handler, false, null, 2);
    }

    private function instantHandler(string $text): ?string
    {
        if (preg_match('/^(?:what(?:\'s| is) (?:the )?time(?: right now)?|what time is it(?: right now)?|tell me (?:the )?time|current time)[?.!]*$/u', $text) === 1) {
            return 'instant.current_time';
        }

        if (preg_match('/^(?:what(?:\'s| is) (?:today\'s|the) date|what(?:\'s| is) (?:today\'s|the) day|what day is it|tell me (?:today\'s|the) date|current date)[?.!]*$/u', $text) === 1) {
            return 'instant.current_date';
        }

        if (preg_match('/^(?:can you hear me|are you listening|are you there)[?.!]*$/u', $text) === 1) {
            return 'instant.voice_state';
        }

        if (preg_match('/^(?:thanks|thank you|that(?:\'s| is) all|goodbye|bye|take care|no thanks)[?.!]*$/u', $text) === 1) {
            return 'instant.conversation_close';
        }

        return null;
    }

    private function normalize(string $transcript): string
    {
        $text = str_replace('’', "'", mb_strtolower(trim($transcript)));
        $text = preg_replace('/^\s*(?:hey[\s,.-]+)?bean\b[\s,.:;!?-]*/u', '', $text) ?? $text;
        // Speech transcription commonly emits all three spellings. Canonicalize
        // them before domain routing so an authoritative task read can never
        // fall through to the general agent merely because it used "to-do".
        $text = preg_replace('/\bto[\x{2010}-\x{2015}-]do\b/u', 'todo', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    private function containsMultipleRoutableOperations(string $text): bool
    {
        $segments = $this->subtasks->split($text);

        $routable = array_filter($segments, function (string $segment): bool {
            $intent = $this->intentText->stripEntityPayloads(trim($segment));

            return $this->applicationDomains($intent) !== []
                || $this->containsAny($intent, ['weather', 'forecast', 'temperature', 'rain', 'storm']);
        });

        return count($routable) > 1;
    }

    /** @return array<int, string> */
    private function applicationDomains(string $text): array
    {
        $domains = [
            'calendar' => ['calendar', 'schedule', 'agenda', 'event', 'meeting', 'appointment'],
            'reminder' => ['reminder', 'reminders', 'remind'],
            'task' => ['task', 'tasks', 'todo', 'to do'],
            'note' => ['note', 'notes'],
        ];

        $matched = [];
        foreach ($domains as $domain => $terms) {
            if ($this->containsAny($text, $terms)) {
                $matched[] = $domain;
            }
        }

        return $matched;
    }

    private function writeOperation(string $text): ?string
    {
        return $this->intentText->writeOperation($text);
    }

    private function isExplicitCancellation(string $text): bool
    {
        $normalized = preg_replace(
            '/^(?:(?:please|no|well|actually|wait|uh|um)[\s,.-]+)*/u',
            '',
            trim($text),
        ) ?? trim($text);
        if (preg_match('/^cancel\b/u', $normalized) !== 1
            && preg_match('/^(?:don\'t|do not|never)\s+(?:create|make|add|set|schedule|save|book|delete|remove|update|change|move|reschedule|complete|mark)\b/u', $normalized) !== 1) {
            return false;
        }

        return preg_match('/\b(?:that|it|request|work|working|job|everything|all|reminder|task|todo|note|calendar|event|meeting|appointment|create|creating)\b/u', $normalized) === 1;
    }

    private function isWorkStatusQuestion(string $text): bool
    {
        return preg_match('/^(?:did|have) you (?:finish|finished|complete|completed)\b/u', $text) === 1
            || preg_match('/^(?:are you (?:done|finished)|is (?:that|it|the .+?) (?:done|finished|complete|completed)|are you still working)\b/u', $text) === 1;
    }

    private function isCapabilityQuestion(string $text): bool
    {
        return preg_match('/^(?:can|could|would|should)\s+i\b/u', $text) === 1
            || preg_match('/^(?:is it possible(?: for you)? to|are you able to)\b/u', $text) === 1;
    }

    private function isContextualFollowUp(string $text): bool
    {
        return preg_match('/^(?:and\s+)?(?:what|how|when|where|which|who|will|is|are|did|does|do|can|could|would|about|what about)\b|\b(?:it|that|there|later|tomorrow|tonight|the first one|the next one)\b/u', $text) === 1;
    }

    private function isGeneratedNoteRequest(string $text): bool
    {
        if (! $this->containsAny($text, ['note', 'notes'])
            || $this->writeOperation($text) === null) {
            return false;
        }

        $intentText = $this->intentText->stripEntityPayloads($text);

        return $this->containsAny($intentText, [
            'plan', 'draft', 'brainstorm', 'generate', 'write me', 'pick', 'random',
            'recipe', 'recipes', 'ideas', 'outline', 'summary', 'summarize',
        ]) || preg_match(
            '/\b(?:put|include|add|write|fill)\b.+\b(?:in|into|to)\s+(?:that|this|the)\s+note\b/u',
            $text,
        ) === 1;
    }

    /** @param array<string, mixed> $context */
    private function contextualRescheduleDomain(string $text, array $context): ?string
    {
        if (data_get($context, 'prior_context_authorized') !== true
            || ! $this->typedWrites->hasClockTime($text)
            || preg_match('/^(?:please\s+)?(?:set|move|change|reschedule)\s+(?:it|that|this|the\s+one)\s+(?:for|to|at)\b/u', $text) !== 1
            || preg_match('/^app\.(reminder|task|calendar)\.(?:create|read|reschedule)$/', (string) data_get($context, 'prior_handler'), $match) !== 1) {
            return null;
        }

        return $match[1];
    }

    /** @param array<int, string> $terms */
    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function containsExplicitLocation(string $text): bool
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
}
