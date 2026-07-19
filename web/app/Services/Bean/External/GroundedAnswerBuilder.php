<?php

namespace App\Services\Bean\External;

class GroundedAnswerBuilder
{
    public function build(string $query, array $sources, array $documents = []): array
    {
        $sources = collect($sources)
            ->filter(fn ($source): bool => is_array($source))
            ->map(function (array $source): array {
                return [
                    'title' => trim((string) ($source['title'] ?? '')),
                    'url' => trim((string) ($source['url'] ?? '')),
                    'snippet' => trim((string) ($source['snippet'] ?? '')),
                    'published_at' => $source['published_at'] ?? null,
                    'retrieved_at' => $source['retrieved_at'] ?? now()->toIso8601String(),
                ];
            })
            ->filter(fn (array $source): bool => $source['url'] !== '' || $source['snippet'] !== '' || $source['title'] !== '')
            ->unique(fn (array $source): string => $source['url'] !== '' ? $source['url'] : $source['title'].'|'.$source['snippet'])
            ->values();

        $documentsByUrl = collect($documents)
            ->filter(fn ($document): bool => is_array($document) && trim((string) ($document['url'] ?? '')) !== '')
            ->keyBy(fn (array $document): string => (string) $document['url']);

        $claims = $sources->take(5)->map(function (array $source) use ($documentsByUrl): array {
            $document = $documentsByUrl->get($source['url'], []);
            $text = trim((string) ($source['snippet'] ?: $document['text'] ?? ''));
            $claim = $this->firstSentence($text) ?: ($source['title'] !== '' ? $source['title'] : 'Relevant source found.');
            return [
                'text' => $claim,
                'source_url' => $source['url'] ?: null,
            ];
        })->values()->all();

        $summary = collect($claims)
            ->pluck('text')
            ->filter()
            ->take(3)
            ->implode(' ');

        return [
            'summary' => trim($summary),
            'claims' => $claims,
            'confidence' => $sources->count() >= 2 ? 'medium' : ($sources->count() === 1 ? 'low' : 'none'),
        ];
    }

    private function firstSentence(string $text): string
    {
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?: $text);
        if ($text === '') return '';
        if (preg_match('/^(.{40,260}?[.!?])\s/u', $text, $match) === 1) {
            return trim((string) $match[1]);
        }
        return str($text)->limit(240, '')->toString();
    }
}
