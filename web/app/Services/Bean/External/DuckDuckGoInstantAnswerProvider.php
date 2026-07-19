<?php

namespace App\Services\Bean\External;

use Illuminate\Support\Facades\Http;

class DuckDuckGoInstantAnswerProvider implements SearchProviderInterface
{
    public function name(): string
    {
        return 'duckduckgo_instant_answer';
    }

    public function search(string $query, array $options = []): array
    {
        $response = Http::acceptJson()
            ->connectTimeout(1)
            ->timeout((int) ($options['timeout'] ?? 3))
            ->get('https://api.duckduckgo.com/', [
                'q' => $query,
                'format' => 'json',
                'no_html' => 1,
                'skip_disambig' => 1,
            ]);

        if (! $response->ok()) return [];

        $data = $response->json() ?: [];
        $sources = [];
        $abstractUrl = trim((string) ($data['AbstractURL'] ?? ''));
        $abstract = trim((string) ($data['AbstractText'] ?? $data['Answer'] ?? ''));
        if ($abstractUrl !== '' || $abstract !== '') {
            $sources[] = [
                'title' => trim((string) ($data['Heading'] ?? '')) ?: ($abstractUrl !== '' ? (string) parse_url($abstractUrl, PHP_URL_HOST) : $query),
                'url' => $abstractUrl,
                'snippet' => $abstract,
                'published_at' => null,
            ];
        }

        $related = is_array($data['RelatedTopics'] ?? null) ? $data['RelatedTopics'] : [];
        foreach ($related as $topic) {
            if (is_array($topic['Topics'] ?? null)) {
                foreach ($topic['Topics'] as $nested) {
                    $this->appendSource($sources, $nested);
                }
            } else {
                $this->appendSource($sources, $topic);
            }
            if (count($sources) >= (int) ($options['limit'] ?? 5)) break;
        }

        return collect($sources)
            ->filter(fn (array $source): bool => trim((string) ($source['url'] ?? '')) !== '' || trim((string) ($source['snippet'] ?? '')) !== '')
            ->unique(fn (array $source): string => (string) ($source['url'] ?? $source['snippet'] ?? ''))
            ->take((int) ($options['limit'] ?? 5))
            ->values()
            ->all();
    }

    private function appendSource(array &$sources, mixed $topic): void
    {
        if (! is_array($topic)) return;
        $text = trim((string) ($topic['Text'] ?? ''));
        $url = trim((string) ($topic['FirstURL'] ?? ''));
        if ($text === '' && $url === '') return;
        $sources[] = [
            'title' => $text !== '' ? str($text)->before(' - ')->limit(100, '')->toString() : (string) parse_url($url, PHP_URL_HOST),
            'url' => $url,
            'snippet' => $text,
            'published_at' => null,
        ];
    }
}
