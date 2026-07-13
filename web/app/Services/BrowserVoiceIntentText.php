<?php

namespace App\Services;

final class BrowserVoiceIntentText
{
    public function writeOperation(string $intentText): ?string
    {
        $normalized = trim(str_replace('’', "'", mb_strtolower($intentText)));
        if (preg_match('/^(?:did|do|does|have|has|are|is|was|were)\b/u', $normalized) === 1
            || preg_match('/^(?:can|could|would|will|should)\s+(?:i|we|my|our)\b/u', $normalized) === 1
            || preg_match('/^should\s+you\b/u', $normalized) === 1) {
            return null;
        }
        preg_match(
            '/\b(what|when|where|which|who|why|how|show|check|tell|find|read|look\s+up|create|add|make|set|schedule|save|book|remind\s+me|delete|remove|forget|complete|finish|mark|update|change|move|reschedule)\b/iu',
            $normalized,
            $match,
        );

        return match (mb_strtolower((string) ($match[1] ?? ''))) {
            'delete', 'remove', 'forget' => 'delete',
            'complete', 'finish', 'mark' => 'complete',
            'update', 'change', 'move', 'reschedule' => 'reschedule',
            'create', 'add', 'make', 'set', 'schedule', 'save', 'book', 'remind me' => 'create',
            default => null,
        };
    }

    public function stripEntityPayloads(string $transcript): string
    {
        $text = trim($transcript);
        $operationBoundary = '(?:and\s+then|and\s+also|then|also|and)\s+(?:(?:can|could|would|will)\s+you\s+)?(?:check|show|tell|find|read|look\s+up|create|add|make|set|schedule|save|book|remind|delete|remove|change|move|reschedule|cancel|complete|mark|plan|draft|write)\b';
        $temporalBoundary = '(?:(?:for|on|at|by)\s+)?(?:today|tomorrow|tonight|noon|midnight|(?:next\s+)?(?:monday|tuesday|wednesday|thursday|friday|saturday|sunday)|\d{1,2}(?::[0-5]\d)?\s*(?:a\.?m\.?|p\.?m\.?))\b';
        $payload = '(?:“[^”]*”|"[^"]*"|.+?)';

        $text = preg_replace(
            '/\b(?:titled|called|named|labeled|labelled)\s+'.$payload.'(?=\s+(?:'.$temporalBoundary.'|'.$operationBoundary.')|[.!?]*$)/iu',
            ' ',
            $text,
        ) ?? $text;
        $text = preg_replace(
            '/\b(?:that\s+says|saying|with(?:\s+the)?\s+(?:text|content))\s+'.$payload.'(?=\s+'.$operationBoundary.'|[.!?]*$)/iu',
            ' ',
            $text,
        ) ?? $text;

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
