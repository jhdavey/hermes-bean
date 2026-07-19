<?php

namespace App\Services\Bean\External;

class ContentExtractor
{
    public function extract(array $page): array
    {
        $body = (string) ($page['body'] ?? '');
        $title = '';
        if (preg_match('/<title[^>]*>(.*?)<\/title>/isu', $body, $match) === 1) {
            $title = $this->clean($match[1]);
        }

        $text = preg_replace('/<script\b[^>]*>.*?<\/script>/isu', ' ', $body) ?: $body;
        $text = preg_replace('/<style\b[^>]*>.*?<\/style>/isu', ' ', $text) ?: $text;
        $text = $this->clean(strip_tags($text));

        return [
            'title' => $title,
            'url' => (string) ($page['url'] ?? ''),
            'text' => str($text)->limit(5000, '')->toString(),
            'retrieved_at' => (string) ($page['retrieved_at'] ?? now()->toIso8601String()),
        ];
    }

    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return trim($text);
    }
}
