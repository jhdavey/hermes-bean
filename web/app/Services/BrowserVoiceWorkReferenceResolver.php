<?php

namespace App\Services;

use App\Enums\VoiceTurnState;
use App\Models\VoiceTurn;
use Illuminate\Support\Collection;

class BrowserVoiceWorkReferenceResolver
{
    /**
     * @param  array<int, VoiceTurnState|string>|null  $states
     */
    public function resolve(VoiceTurn $requestTurn, ?array $states = null): ?VoiceTurn
    {
        $query = VoiceTurn::query()
            ->where('user_id', $requestTurn->user_id)
            ->where('conversation_session_id', $requestTurn->conversation_session_id)
            ->where('id', '!=', $requestTurn->id)
            ->whereNotIn('handler', ['app.voice_work.cancel', 'app.voice_work.status'])
            ->latest('id')
            ->limit(50);

        if ($states !== null) {
            $query->whereIn('state', collect($states)->map(
                fn (VoiceTurnState|string $state): string => $state instanceof VoiceTurnState ? $state->value : $state,
            )->all());
        }

        /** @var Collection<int, VoiceTurn> $candidates */
        $candidates = $query->get()
            ->reject(fn (VoiceTurn $candidate): bool => $candidate->lane->value === 'instant')
            ->values();
        if ($candidates->isEmpty()) {
            return null;
        }

        $requestText = $this->normalize($requestTurn->transcript);
        $domain = $this->domain($requestText);
        if ($domain !== null) {
            $candidates = $candidates->filter(
                fn (VoiceTurn $candidate): bool => $this->matchesDomain($candidate, $domain),
            )->values();
        }
        if ($candidates->isEmpty()) {
            return null;
        }

        $referenceTokens = $this->referenceTokens($requestText);
        if ($referenceTokens === []) {
            return $this->activeFirst($candidates);
        }

        $ranked = $candidates->map(function (VoiceTurn $candidate) use ($referenceTokens): array {
            $candidateTokens = $this->referenceTokens($this->normalize($candidate->transcript));

            return [
                'turn' => $candidate,
                'score' => count(array_intersect($referenceTokens, $candidateTokens)),
            ];
        })->sortByDesc('score')->values();

        /** @var array{turn: VoiceTurn, score: int}|null $best */
        $best = $ranked->first();

        return $best !== null && $best['score'] > 0
            ? $best['turn']
            : $this->activeFirst($candidates);
    }

    public function requestsAll(string $transcript): bool
    {
        return preg_match('/\b(?:everything|all(?:\s+(?:the\s+)?(?:work|requests?|jobs?))?)\b/iu', $transcript) === 1;
    }

    private function matchesDomain(VoiceTurn $turn, string $domain): bool
    {
        if (str_contains($turn->handler, ".{$domain}.")) {
            return true;
        }

        return $this->domain($this->normalize($turn->transcript)) === $domain;
    }

    private function domain(string $text): ?string
    {
        $domains = [
            'calendar' => ['calendar', 'schedule', 'agenda', 'event', 'meeting', 'appointment'],
            'reminder' => ['reminder', 'reminders', 'remind'],
            'task' => ['task', 'tasks', 'todo', 'to do'],
            'note' => ['note', 'notes'],
        ];

        foreach ($domains as $domain => $terms) {
            foreach ($terms as $term) {
                if (preg_match('/\b'.preg_quote($term, '/').'\b/iu', $text) === 1) {
                    return $domain;
                }
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function referenceTokens(string $text): array
    {
        $stopWords = [
            'a', 'all', 'an', 'are', 'cancel', 'canceled', 'cancelled', 'complete', 'completed',
            'create', 'creating', 'did', 'do', 'does', 'done', 'everything', 'finish', 'finished',
            'for', 'have', 'i', 'is', 'it', 'job', 'jobs', 'make', 'my', 'of', 'on', 'please',
            'request', 'requests', 'restart', 'still', 'that', 'the', 'this', 'was', 'were', 'with',
            'work', 'working', 'you', 'your', 'reminder', 'reminders', 'task', 'tasks', 'todo',
            'note', 'notes', 'calendar', 'schedule', 'agenda', 'event', 'events', 'meeting', 'meetings',
        ];
        $tokens = preg_split('/[^\pL\pN]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter(
            $tokens,
            fn (string $token): bool => mb_strlen($token) > 1 && ! in_array($token, $stopWords, true),
        )));
    }

    private function normalize(string $text): string
    {
        $text = str_replace('’', "'", mb_strtolower(trim($text)));
        $text = preg_replace('/^\s*hey[\s,.-]+bean[\s,.:;-]*/u', '', $text) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }

    /** @param Collection<int, VoiceTurn> $candidates */
    private function activeFirst(Collection $candidates): ?VoiceTurn
    {
        $active = $candidates->first(fn (VoiceTurn $candidate): bool => in_array(
            $candidate->state,
            [VoiceTurnState::Accepted, VoiceTurnState::Running],
            true,
        ));

        return $active instanceof VoiceTurn ? $active : $candidates->first();
    }
}
