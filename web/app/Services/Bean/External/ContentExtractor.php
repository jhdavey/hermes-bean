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
            'structured' => $this->structuredData($body),
            'retrieved_at' => (string) ($page['retrieved_at'] ?? now()->toIso8601String()),
        ];
    }

    private function structuredData(string $body): array
    {
        preg_match_all('/<script[^>]+type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/isu', $body, $matches);
        $nodes = [];
        foreach ($matches[1] ?? [] as $json) {
            $decoded = json_decode(html_entity_decode(trim($json), ENT_QUOTES | ENT_HTML5), true);
            if (is_array($decoded)) $this->collectSchemaNodes($decoded, $nodes);
        }

        $recipes = collect($nodes)
            ->filter(fn ($node): bool => is_array($node) && ($this->schemaTypeMatches($node, 'Recipe') || isset($node['recipeIngredient']) || isset($node['recipeInstructions'])))
            ->map(fn (array $node): array => $this->normalizeRecipe($node))
            ->filter(fn (array $recipe): bool => ($recipe['name'] ?? '') !== '' || $recipe['ingredients'] !== [] || $recipe['instructions'] !== [])
            ->values()
            ->all();

        return array_filter(['recipes' => $recipes], fn ($value): bool => $value !== []);
    }

    private function collectSchemaNodes(array $node, array &$nodes): void
    {
        if ($node === []) return;
        if (isset($node['@graph']) && is_array($node['@graph'])) {
            foreach ($node['@graph'] as $child) {
                if (is_array($child)) $this->collectSchemaNodes($child, $nodes);
            }
        }
        if (isset($node['@type']) || isset($node['recipeIngredient']) || isset($node['recipeInstructions'])) {
            $nodes[] = $node;
        }
        foreach ($node as $value) {
            if (is_array($value) && array_is_list($value)) {
                foreach ($value as $child) {
                    if (is_array($child)) $this->collectSchemaNodes($child, $nodes);
                }
            }
        }
    }

    private function schemaTypeMatches(array $node, string $type): bool
    {
        $schemaType = $node['@type'] ?? null;
        $types = is_array($schemaType) ? $schemaType : [$schemaType];
        return in_array($type, array_map('strval', array_filter($types)), true);
    }

    private function normalizeRecipe(array $node): array
    {
        return [
            'type' => 'Recipe',
            'name' => $this->clean((string) ($node['name'] ?? $node['headline'] ?? '')),
            'yield' => $this->normalizeStringList($node['recipeYield'] ?? $node['yield'] ?? null),
            'prep_time' => $this->clean((string) ($node['prepTime'] ?? '')),
            'cook_time' => $this->clean((string) ($node['cookTime'] ?? '')),
            'total_time' => $this->clean((string) ($node['totalTime'] ?? '')),
            'ingredients' => $this->normalizeStringList($node['recipeIngredient'] ?? []),
            'instructions' => $this->normalizeInstructions($node['recipeInstructions'] ?? []),
        ];
    }

    private function normalizeStringList(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        return collect($values)
            ->map(fn ($item): string => $this->clean((string) $item))
            ->filter()
            ->values()
            ->all();
    }

    private function normalizeInstructions(mixed $value): array
    {
        $values = is_array($value) ? $value : [$value];
        $steps = [];
        foreach ($values as $item) {
            if (is_string($item)) {
                $steps[] = $this->clean($item);
            } elseif (is_array($item) && isset($item['itemListElement']) && is_array($item['itemListElement'])) {
                $steps = [...$steps, ...$this->normalizeInstructions($item['itemListElement'])];
            } elseif (is_array($item)) {
                $steps[] = $this->clean((string) ($item['text'] ?? $item['name'] ?? ''));
            }
        }
        return collect($steps)->filter()->values()->all();
    }

    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5);
        $text = preg_replace('/\s+/u', ' ', $text) ?: $text;
        return trim($text);
    }
}
