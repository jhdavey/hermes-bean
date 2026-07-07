<?php

namespace App\Services;

use App\Models\AdminSetting;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AdminModelRegistryService
{
    private const GROUPS = [
        'main_model' => [
            'label' => 'Main Bean reasoning/chat',
            'description' => 'Used for normal Bean responses and agent reasoning.',
            'curated' => [
                'gpt-5-mini',
                'gpt-5-nano',
                'gpt-5.5',
                'gpt-5.4',
                'gpt-5.2',
                'gpt-5.1',
                'gpt-5',
                'gpt-4.1',
                'gpt-4o',
            ],
        ],
        'external_lookup_model' => [
            'label' => 'External lookup',
            'description' => 'Used when Bean needs live/external information.',
            'curated' => [
                'gpt-5-mini',
                'gpt-5-nano',
                'gpt-4.1-mini',
                'gpt-4o-search-preview',
                'gpt-4o-mini-search-preview',
                'gpt-4o-mini',
            ],
        ],
    ];

    public function payload(): array
    {
        $openAi = $this->openAiModels();

        return [
            'source' => $openAi['available'] ? 'openai_and_curated' : 'curated',
            'api_base' => rtrim((string) config('services.hermes_runtime.api_base'), '/'),
            'key_source' => config('services.hermes_runtime.api_key_source'),
            'openai_available' => $openAi['available'],
            'error' => $openAi['error'],
            'groups' => collect(self::GROUPS)
                ->mapWithKeys(fn (array $group, string $key): array => [$key => $this->groupPayload($key, $group, $openAi['ids'])])
                ->all(),
        ];
    }

    /**
     * @return array<string, list<string>>
     */
    public function allowedModelIds(): array
    {
        $groups = $this->payload()['groups'] ?? [];

        return collect($groups)
            ->mapWithKeys(fn (array $group, string $key): array => [
                $key => collect($group['models'] ?? [])
                    ->pluck('id')
                    ->map(fn (mixed $id): string => (string) $id)
                    ->filter()
                    ->values()
                    ->all(),
            ])
            ->all();
    }

    public function validationErrors(array $modelSettings): array
    {
        $allowed = $this->allowedModelIds();
        $errors = [];

        foreach (array_keys(self::GROUPS) as $key) {
            $value = trim((string) ($modelSettings[$key] ?? ''));
            if ($value === '') {
                continue;
            }
            if (! in_array($value, $allowed[$key] ?? [], true)) {
                $label = self::GROUPS[$key]['label'];
                $errors["model_settings.{$key}"] = ["{$value} is not available for {$label}."];
            }
        }

        return $errors;
    }

    private function groupPayload(string $key, array $group, array $openAiIds): array
    {
        $curated = $group['curated'];
        $current = $this->currentModelIdsFor($key);
        $dynamic = collect($openAiIds)
            ->filter(fn (string $id): bool => $this->belongsToGroup($key, $id))
            ->values()
            ->all();

        $ids = collect([...$current, ...$curated, ...$dynamic])
            ->map(fn (mixed $id): string => trim((string) $id))
            ->filter()
            ->unique()
            ->values();

        return [
            'label' => $group['label'],
            'description' => $group['description'],
            'models' => $ids->map(fn (string $id): array => [
                'id' => $id,
                'label' => $this->modelLabel($id),
                'source' => in_array($id, $openAiIds, true) ? 'openai' : 'curated',
                'available' => empty($openAiIds) || in_array($id, $openAiIds, true),
                'recommended' => in_array($id, $curated, true),
            ])->all(),
        ];
    }

    /**
     * @return list<string>
     */
    private function currentModelIdsFor(string $key): array
    {
        $configValue = match ($key) {
            'main_model' => config('services.hermes_runtime.default_model'),
            'external_lookup_model' => config('services.hermes_runtime.external_lookup_model'),
            default => null,
        };
        $settingKey = match ($key) {
            'main_model' => AdminSettingsService::MAIN_MODEL,
            'external_lookup_model' => AdminSettingsService::EXTERNAL_LOOKUP_MODEL,
            default => null,
        };
        $stored = $settingKey
            ? data_get(AdminSetting::query()->where('key', $settingKey)->value('value'), 'value')
            : null;

        return collect([$stored, $configValue])
            ->map(fn (mixed $value): string => trim((string) $value))
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array{available: bool, ids: list<string>, error: ?string}
     */
    private function openAiModels(): array
    {
        $apiKey = trim((string) config('services.hermes_runtime.api_key', ''));
        if ($apiKey === '') {
            return ['available' => false, 'ids' => [], 'error' => 'OpenAI API key is not configured.'];
        }

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->connectTimeout(4)
                ->timeout(8)
                ->get(rtrim((string) config('services.hermes_runtime.api_base'), '/').'/models');

            if (! $response->successful()) {
                return [
                    'available' => false,
                    'ids' => [],
                    'error' => 'OpenAI models endpoint returned HTTP '.$response->status().'.',
                ];
            }

            return [
                'available' => true,
                'ids' => collect($response->json('data', []))
                    ->pluck('id')
                    ->map(fn (mixed $id): string => trim((string) $id))
                    ->filter()
                    ->sort()
                    ->values()
                    ->all(),
                'error' => null,
            ];
        } catch (\Throwable $exception) {
            Log::warning('OpenAI model registry lookup failed.', [
                'message' => $exception->getMessage(),
                'api_base' => config('services.hermes_runtime.api_base'),
                'key_source' => config('services.hermes_runtime.api_key_source'),
            ]);

            return ['available' => false, 'ids' => [], 'error' => 'OpenAI models endpoint could not be reached.'];
        }
    }

    private function belongsToGroup(string $key, string $id): bool
    {
        $model = strtolower($id);

        if (str_contains($model, 'embedding') || str_contains($model, 'image') || str_contains($model, 'moderation')) {
            return false;
        }

        return match ($key) {
            'external_lookup_model' => ! str_contains($model, 'realtime') && (str_contains($model, 'search') || preg_match('/^gpt-(5|4\.1|4o)(-|$)/', $model) === 1),
            'main_model' => ! str_contains($model, 'realtime') && ! str_contains($model, 'search') && ! str_contains($model, 'mini') && ! str_contains($model, 'nano') && preg_match('/^gpt-(5|4\.1|4o)(-|$)/', $model) === 1,
            default => false,
        };
    }

    private function modelLabel(string $id): string
    {
        return str($id)
            ->replace('-', ' ')
            ->replace('.', '.')
            ->title()
            ->replace('Gpt', 'GPT')
            ->toString();
    }
}
