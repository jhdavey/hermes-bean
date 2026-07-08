<?php

namespace App\Services;

use App\Models\ConversationMessage;

class BeanIntentRouter
{
    public const SIMPLE_CONVERSATION = 'simple_conversation';
    public const SIMPLE_QUESTION = 'simple_question';
    public const NEEDS_APP_READ = 'needs_app_read';
    public const NEEDS_APP_WRITE = 'needs_app_write';
    public const NEEDS_EXTERNAL_LOOKUP = 'needs_external_lookup';
    public const NEEDS_COMPLEX_REASONING = 'needs_complex_reasoning';

    /**
     * @return array{lane:string,runtime:string,queue:bool,tool_mode:string,reason:string,confidence:float,work_plan:array<int, array{id:string,label:string,status:string}>}
     */
    public function route(ConversationMessage|string $message): array
    {
        $content = $message instanceof ConversationMessage ? (string) $message->content : $message;
        $text = $this->normalize($content);
        $wordCount = $text === '' ? 0 : str_word_count($text);

        if ($text === '') {
            return $this->decision(self::SIMPLE_CONVERSATION, 'fast_no_tools', false, 'none', 'Empty message fallback.', 0.6, $text);
        }

        $appWrite = $this->containsAny($text, $this->appWriteTerms());
        $appDomain = $this->containsAny($text, $this->appDomainTerms());
        $external = $this->containsAny($text, $this->externalTerms());
        $profileSetup = preg_match('/\b(?:i am|i\'m|my name is|call me|personality|priorities|what matters|home city|bean settings|bean preferences|skip|prefer not)\b/u', $text) === 1;
        $requestHistory = preg_match('/\b(?:what did i ask|what did i say|what was my last|previous request|recent request|earlier)\b/u', $text) === 1;
        $placeLike = preg_match('/\b\d{5}(?:-\d{4})?\b/u', $text) === 1
            || preg_match('/\b(?:nearby|nearest|near me|address|location|where is|where\'s|directions|open|opens|close|closes|closing|hours)\b/u', $text) === 1;
        $complex = $wordCount >= 45
            || preg_match('/\b(?:think through|deep dive|compare|analyze|strategy|plan out|multi step|step by step|pros and cons|tradeoffs?|research|essay|draft|write)\b/u', $text) === 1
            || preg_match('/\b(?:and then|then also|also|as well)\b/u', $text) === 1 && ($appWrite || $appDomain || $external);
        $question = str_contains($text, '?')
            || preg_match('/^(?:what|why|how|when|where|who|can|could|would|should|is|are|do|does|did)\b/u', $text) === 1;

        if ($profileSetup || $requestHistory) {
            return $this->decision(self::NEEDS_COMPLEX_REASONING, 'agent_tools', true, 'full', $profileSetup ? 'Profile or onboarding request.' : 'Request-history recall.', 0.84, $text);
        }

        if ($external && ($appWrite || $appDomain)) {
            return $this->decision(self::NEEDS_COMPLEX_REASONING, 'agent_tools', true, 'full', 'Mixed external lookup and app work request.', 0.86, $text);
        }

        if ($external) {
            return $this->decision(self::NEEDS_EXTERNAL_LOOKUP, 'agent_tools', true, 'read_lookup', 'External or current-information request.', 0.86, $text);
        }

        if ($placeLike && ! $appWrite) {
            return $this->decision(self::NEEDS_EXTERNAL_LOOKUP, 'agent_tools', true, 'read_lookup', 'Place, hours, or location lookup.', 0.82, $text);
        }

        if ($this->isCapabilityQuestion($text)) {
            return $this->decision(self::SIMPLE_QUESTION, 'fast_no_tools', false, 'none', 'Capability question.', 0.88, $text);
        }

        if ($question
            && ! $appWrite
            && ! $external
            && (
                preg_match('/\bdifference between\b/u', $text) === 1
                || preg_match('/\b(?:what does|how does|explain)\b/u', $text) === 1
                || (preg_match('/\bwhat is\b/u', $text) === 1 && preg_match('/\b(?:my|today|tomorrow|next|upcoming|due|scheduled)\b/u', $text) !== 1)
            )) {
            return $this->decision(self::SIMPLE_QUESTION, 'fast_no_tools', false, 'none', 'Simple explanatory question.', 0.78, $text);
        }

        if ($appWrite || ($appDomain && preg_match('/\b(?:add|create|make|set|schedule|book|save|remember|delete|remove|update|change|move|reschedule|complete|mark|write|plan|organize|prioritize)\b/u', $text) === 1)) {
            return $this->decision(self::NEEDS_APP_WRITE, 'agent_tools', true, 'app_crud', 'App write request.', 0.9, $text);
        }

        if ($complex) {
            return $this->decision(self::NEEDS_COMPLEX_REASONING, 'agent_tools', true, 'full', 'Complex reasoning or drafting request.', 0.78, $text);
        }

        if ($appDomain && ($question || preg_match('/\b(?:show|list|tell me|what is|what are|when is|when are|do i have|find|search|open|read|it should be|that should be|not a|instead|actually)\b/u', $text) === 1)) {
            return $this->decision(self::NEEDS_APP_READ, 'agent_tools', true, 'app_crud', 'App read request.', 0.82, $text);
        }

        if ($question) {
            return $this->decision(self::SIMPLE_QUESTION, 'fast_no_tools', false, 'none', 'Simple conversational question.', 0.72, $text);
        }

        return $this->decision(self::SIMPLE_CONVERSATION, 'fast_no_tools', false, 'none', 'Simple conversational turn.', 0.74, $text);
    }

    public function shouldQueue(array $route): bool
    {
        return (bool) ($route['queue'] ?? true);
    }

    private function normalize(string $content): string
    {
        $normalized = str_replace('’', "'", mb_strtolower($content));
        $normalized = preg_replace('/^\s*(?:kpi|req)-\d{3}:\s*/u', '', $normalized) ?: $normalized;
        $normalized = preg_replace('/[^\pL\pN\s\'?.-]+/u', ' ', $normalized) ?: $normalized;

        return trim(preg_replace('/\s+/u', ' ', $normalized) ?: $normalized);
    }

    /**
     * @return array{lane:string,runtime:string,queue:bool,tool_mode:string,reason:string,confidence:float,work_plan:array<int, array{id:string,label:string,status:string}>}
     */
    private function decision(string $lane, string $runtime, bool $queue, string $toolMode, string $reason, float $confidence, string $text): array
    {
        return [
            'lane' => $lane,
            'runtime' => $runtime,
            'queue' => $queue,
            'tool_mode' => $toolMode,
            'reason' => $reason,
            'confidence' => $confidence,
            'work_plan' => $queue ? $this->workPlan($lane, $text) : [],
        ];
    }

    /**
     * @return array<int, array{id:string,label:string,status:string}>
     */
    private function workPlan(string $lane, string $text): array
    {
        $label = match ($lane) {
            self::NEEDS_APP_WRITE => $this->appWorkLabel($text),
            self::NEEDS_APP_READ => $this->appReadLabel($text),
            self::NEEDS_EXTERNAL_LOOKUP => $this->externalLabel($text),
            self::NEEDS_COMPLEX_REASONING => 'Think through request',
            default => 'Handle request',
        };

        return [[
            'id' => 'route-plan-0',
            'label' => $label,
            'status' => 'running',
        ]];
    }

    private function appWorkLabel(string $text): string
    {
        if ($this->containsAny($text, ['calendar', 'schedule', 'event', 'meeting', 'appointment'])) {
            return 'Update calendar';
        }
        if ($this->containsAny($text, ['reminder', 'remind'])) {
            return 'Update reminders';
        }
        if ($this->containsAny($text, ['task', 'tasks', 'todo', 'to do'])) {
            return 'Update tasks';
        }
        if ($this->containsAny($text, ['note', 'notes', 'essay', 'draft'])) {
            return 'Update notes';
        }
        if ($this->containsAny($text, ['remember', 'forget', 'memory', 'preference'])) {
            return 'Update saved knowledge';
        }

        return 'Update HeyBean';
    }

    private function appReadLabel(string $text): string
    {
        if ($this->containsAny($text, ['calendar', 'schedule', 'event', 'meeting', 'appointment', 'agenda'])) {
            return 'Check calendar';
        }
        if ($this->containsAny($text, ['reminder', 'remind'])) {
            return 'Check reminders';
        }
        if ($this->containsAny($text, ['task', 'tasks', 'todo', 'to do'])) {
            return 'Check tasks';
        }
        if ($this->containsAny($text, ['note', 'notes'])) {
            return 'Check notes';
        }

        return 'Check HeyBean';
    }

    private function externalLabel(string $text): string
    {
        if ($this->containsAny($text, ['weather', 'forecast'])) {
            return 'Check weather';
        }
        if ($this->containsAny($text, ['traffic', 'route', 'drive', 'commute'])) {
            return 'Check traffic';
        }
        if ($this->containsAny($text, ['news', 'headline', 'headlines'])) {
            return 'Check latest news';
        }
        if ($this->containsAny($text, ['stock', 'market', 'price', 'prices'])) {
            return 'Check markets';
        }
        if ($this->containsAny($text, ['sports', 'score', 'scores', 'game'])) {
            return 'Check scores';
        }

        return 'Check external information';
    }

    private function containsAny(string $text, array $terms): bool
    {
        foreach ($terms as $term) {
            if (preg_match('/\b'.preg_quote($term, '/').'\b/u', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    private function isCapabilityQuestion(string $text): bool
    {
        if (preg_match('/^(?:can|could|would|will)\s+(?:you|bean)\b/u', $text) !== 1) {
            return false;
        }

        return preg_match('/\b(?:called|named|titled|labelled|labeled|that says|saying|with title|tomorrow|today|tonight|monday|tuesday|wednesday|thursday|friday|saturday|sunday|\d{1,2}(?::\d{2})?\s*(?:am|pm))\b/u', $text) !== 1;
    }

    /**
     * @return array<int, string>
     */
    private function appDomainTerms(): array
    {
        return ['task', 'tasks', 'todo', 'to do', 'reminder', 'reminders', 'remind', 'calendar', 'schedule', 'agenda', 'event', 'events', 'meeting', 'meetings', 'appointment', 'appointments', 'note', 'notes', 'list', 'memory', 'preference', 'workspace'];
    }

    /**
     * @return array<int, string>
     */
    private function appWriteTerms(): array
    {
        return ['add', 'create', 'make', 'set', 'delete', 'remove', 'update', 'change', 'move', 'reschedule', 'complete', 'mark', 'save', 'remember', 'forget', 'schedule', 'book'];
    }

    /**
     * @return array<int, string>
     */
    private function externalTerms(): array
    {
        return ['weather', 'forecast', 'traffic', 'route', 'news', 'headline', 'headlines', 'flight', 'flights', 'hotel', 'hotels', 'price', 'prices', 'stock', 'stocks', 'market', 'markets', 'sports', 'score', 'scores', 'near me', 'store hours', 'hours', 'open', 'opens', 'close', 'closes', 'closing', 'nearby', 'nearest', 'address', 'location', 'latest', 'current', 'web', 'internet', 'look up'];
    }
}
