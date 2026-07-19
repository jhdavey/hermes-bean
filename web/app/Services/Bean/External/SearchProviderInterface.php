<?php

namespace App\Services\Bean\External;

interface SearchProviderInterface
{
    public function name(): string;

    /**
     * @return array<int, array{title?: string, url?: string, snippet?: string, published_at?: string|null}>
     */
    public function search(string $query, array $options = []): array;
}
