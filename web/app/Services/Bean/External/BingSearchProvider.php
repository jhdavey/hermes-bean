<?php

namespace App\Services\Bean\External;

use Illuminate\Support\Facades\Http;

class BingSearchProvider implements SearchProviderInterface
{
    public function name(): string
    {
        return 'bing_html';
    }

    public function search(string $query, array $options = []): array
    {
        $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; HeyBean/1.0)'])
            ->connectTimeout(2)
            ->timeout((int) ($options['timeout'] ?? 5))
            ->get('https://www.bing.com/search', ['q' => $query]);
        if (! $response->ok()) return [];

        $html = (string) $response->body();
        preg_match_all('/<li[^>]+class="[^"]*b_algo[^"]*"[^>]*>(.*?)<\/li>/isu', $html, $blocks);

        return collect($blocks[1] ?? [])
            ->map(function (string $block): ?array {
                if (preg_match('/<h2[^>]*>\s*<a[^>]+href="([^"]+)"[^>]*>(.*?)<\/a>\s*<\/h2>/isu', $block, $link) !== 1) return null;
                $url = $this->decodeBingUrl(html_entity_decode((string) $link[1], ENT_QUOTES | ENT_HTML5));
                $title = $this->clean((string) $link[2]);
                $snippet = '';
                if (preg_match('/<p[^>]*>(.*?)<\/p>/isu', $block, $snippetMatch) === 1) {
                    $snippet = $this->clean((string) $snippetMatch[1]);
                }
                if (! preg_match('/^https?:\/\//i', $url)) return null;
                return [
                    'title' => $title,
                    'url' => $url,
                    'snippet' => $snippet,
                    'published_at' => null,
                ];
            })
            ->filter()
            ->unique(fn (array $source): string => (string) ($source['url'] ?? ''))
            ->take((int) ($options['limit'] ?? 5))
            ->values()
            ->all();
    }

    private function clean(string $html): string
    {
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return trim($text);
    }

    private function decodeBingUrl(string $url): string
    {
        $parts = parse_url($url);
        parse_str((string) ($parts['query'] ?? ''), $query);
        $encoded = (string) ($query['u'] ?? '');
        if ($encoded !== '') {
            if (str_starts_with($encoded, 'a1')) $encoded = substr($encoded, 2);
            $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);
            if (is_string($decoded) && preg_match('/^https?:\/\//i', $decoded) === 1) return $decoded;
        }
        return $url;
    }
}
