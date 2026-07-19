<?php

namespace App\Services\Bean\External;

use Throwable;

class ExternalLookupService
{
    public function __construct(
        private readonly DuckDuckGoInstantAnswerProvider $instantAnswers,
        private readonly DuckDuckGoLiteSearchProvider $liteSearch,
        private readonly BingSearchProvider $bingSearch,
        private readonly PageFetcher $fetcher,
        private readonly ContentExtractor $extractor,
        private readonly GroundedAnswerBuilder $builder,
    ) {}

    public function lookup(array $arguments): array
    {
        $query = trim((string) ($arguments['query'] ?? $arguments['question'] ?? $arguments['title'] ?? ''));
        if ($query === '') {
            return ['ok' => false, 'error' => 'Please tell me what to look up.'];
        }

        $limit = max(1, min(8, (int) ($arguments['limit'] ?? 5)));
        $options = [
            'limit' => $limit,
            'timeout' => 3,
            'freshness' => trim((string) ($arguments['freshness'] ?? 'any')) ?: 'any',
            'objective' => trim((string) ($arguments['objective'] ?? 'answer source-backed public lookup')),
            'include_sources' => (bool) ($arguments['include_sources'] ?? true),
        ];

        $providerNames = [];
        $errors = [];
        $sources = [];
        foreach ([$this->instantAnswers, $this->liteSearch, $this->bingSearch] as $provider) {
            try {
                $providerNames[] = $provider->name();
                $sources = $provider->search($query, $options);
                if ($sources !== []) break;
            } catch (Throwable $exception) {
                $errors[] = $provider->name().': '.$exception->getMessage();
            }
        }

        if ($sources === []) {
            $sources = [$this->fallbackSearchSource($query)];
        }

        $sources = collect($sources)
            ->filter(fn ($source): bool => is_array($source))
            ->map(function (array $source): array {
                $source['retrieved_at'] = $source['retrieved_at'] ?? now()->toIso8601String();
                return $source;
            })
            ->take($limit)
            ->values()
            ->all();

        $documents = [];
        foreach (array_slice($sources, 0, 2) as $source) {
            if (($source['skip_fetch'] ?? false) === true) continue;
            $url = trim((string) ($source['url'] ?? ''));
            if ($url === '') continue;
            try {
                $page = $this->fetcher->fetch($url);
                if ($page !== null) $documents[] = $this->extractor->extract($page);
            } catch (Throwable $exception) {
                $errors[] = 'fetch: '.$exception->getMessage();
            }
        }

        $built = $this->builder->build($query, $sources, $documents);
        $ok = trim((string) ($built['summary'] ?? '')) !== '' || $sources !== [];

        return [
            'ok' => $ok,
            'provider' => implode(',', array_unique($providerNames)),
            'query' => $query,
            'objective' => $options['objective'],
            'freshness' => $options['freshness'],
            'retrieved_at' => now()->toIso8601String(),
            'title' => trim((string) ($sources[0]['title'] ?? '')) ?: $query,
            'summary' => (string) ($built['summary'] ?? ''),
            'source_url' => trim((string) ($sources[0]['url'] ?? '')) ?: null,
            'sources' => $sources,
            'claims' => $built['claims'] ?? [],
            'documents' => collect($documents)->map(fn (array $document): array => [
                'title' => $document['title'] ?? '',
                'url' => $document['url'] ?? '',
                'structured' => $document['structured'] ?? [],
                'retrieved_at' => $document['retrieved_at'] ?? null,
            ])->values()->all(),
            'confidence' => $built['confidence'] ?? 'none',
            'evidence' => [
                'query' => $query,
                'source_count' => count($sources),
                'sources_used' => collect($sources)->pluck('url')->filter()->values()->all(),
                'retrieved_at' => now()->toIso8601String(),
                'confidence' => $built['confidence'] ?? 'none',
                'errors' => $errors,
            ],
            'error' => $ok ? null : 'I could not retrieve reliable external sources for that.',
        ];
    }

    private function fallbackSearchSource(string $query): array
    {
        return [
            'title' => 'Search results for '.$query,
            'url' => 'https://duckduckgo.com/?q='.rawurlencode($query),
            'snippet' => 'I could not extract a direct source snippet, but I found a public search-results page for this query. Treat this as low-confidence evidence and avoid making specific factual claims beyond the source list.',
            'published_at' => null,
            'retrieved_at' => now()->toIso8601String(),
            'skip_fetch' => true,
        ];
    }
}
