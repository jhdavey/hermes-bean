<?php

namespace App\Services\Bean\External;

use Illuminate\Support\Facades\Http;

class DuckDuckGoLiteSearchProvider implements SearchProviderInterface
{
    public function name(): string
    {
        return 'duckduckgo_lite';
    }

    public function search(string $query, array $options = []): array
    {
        $response = Http::withHeaders(['User-Agent' => 'HeyBean/1.0'])
            ->connectTimeout(1)
            ->timeout((int) ($options['timeout'] ?? 3))
            ->get('https://lite.duckduckgo.com/lite/', ['q' => $query]);
        if (! $response->ok()) return [];

        $html = (string) $response->body();
        preg_match_all("/<a[^>]+href=['\"]([^'\"]+)['\"][^>]+class=['\"]result-link['\"][^>]*>(.*?)<\/a>/isu", $html, $links, PREG_SET_ORDER);
        if ($links === []) {
            preg_match_all("/<a[^>]+class=['\"]result-link['\"][^>]+href=['\"]([^'\"]+)['\"][^>]*>(.*?)<\/a>/isu", $html, $links, PREG_SET_ORDER);
        }
        preg_match_all("/<td[^>]+class=['\"]result-snippet['\"][^>]*>(.*?)<\/td>/isu", $html, $snippets);
        $snippetValues = collect($snippets[1] ?? [])
            ->map(fn (string $snippet): string => trim(html_entity_decode(strip_tags($snippet), ENT_QUOTES | ENT_HTML5)))
            ->values();

        return collect($links)
            ->take((int) ($options['limit'] ?? 5))
            ->values()
            ->map(function (array $match, int $index) use ($snippetValues): array {
                $url = $this->decodeDuckDuckGoUrl(html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5));
                $title = trim(html_entity_decode(strip_tags($match[2]), ENT_QUOTES | ENT_HTML5));
                return [
                    'title' => $title,
                    'url' => $url,
                    'snippet' => (string) ($snippetValues[$index] ?? ''),
                    'published_at' => null,
                ];
            })
            ->filter(fn (array $source): bool => trim((string) ($source['url'] ?? '')) !== '')
            ->values()
            ->all();
    }

    private function decodeDuckDuckGoUrl(string $url): string
    {
        if (str_starts_with($url, '//')) $url = 'https:'.$url;
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        return isset($query['uddg']) ? (string) $query['uddg'] : $url;
    }
}
