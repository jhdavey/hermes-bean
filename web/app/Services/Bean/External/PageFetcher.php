<?php

namespace App\Services\Bean\External;

use Illuminate\Support\Facades\Http;

class PageFetcher
{
    public function fetch(string $url): ?array
    {
        $url = trim($url);
        if ($url === '' || ! preg_match('/^https?:\/\//i', $url)) return null;

        $response = Http::withHeaders(['User-Agent' => 'HeyBean/1.0'])
            ->connectTimeout(1)
            ->timeout(3)
            ->get($url);

        if (! $response->ok()) return null;
        $contentType = strtolower((string) $response->header('content-type', ''));
        if ($contentType !== '' && ! str_contains($contentType, 'text/html') && ! str_contains($contentType, 'text/plain')) return null;

        return [
            'url' => $url,
            'content_type' => $contentType,
            'body' => (string) $response->body(),
            'retrieved_at' => now()->toIso8601String(),
        ];
    }
}
