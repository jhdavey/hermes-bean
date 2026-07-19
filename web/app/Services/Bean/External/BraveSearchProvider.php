<?php

namespace App\Services\Bean\External;

use Illuminate\Support\Facades\Http;

class BraveSearchProvider implements SearchProviderInterface
{
    public function name(): string
    {
        return 'brave_html';
    }

    public function search(string $query, array $options = []): array
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; HeyBean/1.0)'])
            ->connectTimeout(2)
            ->timeout((int) ($options['timeout'] ?? 5))
            ->get('https://search.brave.com/search', ['q' => $query, 'source' => 'web']);
        if (! $response->ok()) return [];

        $html = (string) $response->body();
        preg_match_all('/<a\b[^>]+href=["\'](https?:\/\/[^"\']+)["\'][^>]*>(.*?)<\/a>/isu', $html, $matches, PREG_SET_ORDER);

        return collect($matches)
            ->map(function (array $match): ?array {
                $url = html_entity_decode((string) ($match[1] ?? ''), ENT_QUOTES | ENT_HTML5);
                if (! $this->isPublicResultUrl($url)) return null;
                $title = $this->clean((string) ($match[2] ?? ''));
                if ($title === '') $title = parse_url($url, PHP_URL_HOST) ?: 'Source';
                return [
                    'title' => $title,
                    'url' => $url,
                    'snippet' => '',
                    'published_at' => null,
                ];
            })
            ->filter()
            ->unique(fn (array $source): string => (string) ($source['url'] ?? ''))
            ->take((int) ($options['limit'] ?? 5))
            ->values()
            ->all();
    }

    private function isPublicResultUrl(string $url): bool
    {
        if (! preg_match('/^https?:\/\//i', $url)) return false;
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        if ($host === '' || str_contains($host, 'search.brave.com') || str_contains($host, 'imgs.search.brave.com')) return false;
        return true;
    }

    private function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return trim($text);
    }
}
