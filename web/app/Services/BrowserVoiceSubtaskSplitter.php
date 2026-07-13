<?php

namespace App\Services;

final class BrowserVoiceSubtaskSplitter
{
    /** @return array<int, string> */
    public function split(string $transcript): array
    {
        $quoteSpace = "\u{E000}";
        $protected = preg_replace_callback(
            '/(?:“[^”]*”|"[^"]*")/u',
            static fn (array $match): string => str_replace(' ', $quoteSpace, $match[0]),
            trim($transcript),
        ) ?? trim($transcript);
        $parts = preg_split(
            '/\s+(?:and\s+then|and\s+also|then|also|and)\s+/iu',
            $protected,
            -1,
            PREG_SPLIT_NO_EMPTY,
        ) ?: [];
        $parts = array_map(
            static fn (string $part): string => str_replace($quoteSpace, ' ', $part),
            $parts,
        );
        if (count($parts) < 2) {
            return $parts;
        }

        $segments = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($segments === []) {
                $segments[] = $part;

                continue;
            }

            $previousIndex = array_key_last($segments);
            $previous = $segments[$previousIndex];
            $hasTitle = preg_match('/\b(?:titled|called|named|labeled|labelled)\b/iu', $previous) === 1;
            $titleHasTemporalBoundary = preg_match(
                '/\b(?:titled|called|named|labeled|labelled)\b.+\s+(?:(?:for|on|at|by)\s+)?(?:today|tomorrow|tonight|noon|midnight|(?:next\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|\d{1,2}(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?))\b/iu',
                $previous,
            ) === 1;
            $insideUnboundedTitle = $hasTitle && ! $titleHasTemporalBoundary;
            if ($insideUnboundedTitle && ! $this->startsExplicitOperation($part)) {
                $segments[$previousIndex] .= ' and '.$part;

                continue;
            }

            $segments[] = $part;
        }

        return $segments;
    }

    private function startsExplicitOperation(string $text): bool
    {
        return preg_match(
            '/^(?:(?:and|also|then|please)\s+)*(?:(?:can|could|would|will)\s+you\s+)?(?:check|show|tell|find|read|look\s+up|create|add|make|set|schedule|save|book|remind|delete|remove|change|move|reschedule|cancel|complete|mark|plan|draft|write|what|when|where|which|who|why|how)\b/iu',
            trim($text),
        ) === 1;
    }
}
