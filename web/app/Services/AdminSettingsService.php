<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Support\Collection;

class AdminSettingsService
{
    public const MAIN_MODEL = 'models.main';

    public const EXTERNAL_LOOKUP_MODEL = 'models.external_lookup';

    public const BEAN_CHAT_ENABLED = 'kill_switches.bean_chat_enabled';

    public const BASE_COST_LIMIT = 'usage.base_cost_limit';

    public const BASE_EXTERNAL_COST_LIMIT = 'usage.base_external_cost_limit';

    public const PREMIUM_COST_LIMIT = 'usage.premium_cost_limit';

    public const PREMIUM_EXTERNAL_COST_LIMIT = 'usage.premium_external_cost_limit';

    public const PRO_COST_LIMIT = 'usage.pro_cost_limit';

    public const PRO_EXTERNAL_COST_LIMIT = 'usage.pro_external_cost_limit';

    private ?Collection $settings = null;

    public function payload(): array
    {
        return [
            'models' => [
                'main_model' => $this->settingPayload(self::MAIN_MODEL, $this->defaultMainModel()),
                'external_lookup_model' => $this->settingPayload(self::EXTERNAL_LOOKUP_MODEL, $this->defaultExternalLookupModel()),
            ],
            'kill_switches' => [
                'bean_chat_enabled' => $this->settingPayload(self::BEAN_CHAT_ENABLED, true),
            ],
            'usage_limits' => collect($this->usageLimitDefaults())
                ->mapWithKeys(fn (mixed $default, string $key): array => [$key => $this->settingPayload('usage.'.$key, $default)])
                ->all(),
        ];
    }

    public function update(array $modelSettings, array $usageLimits, ?User $actor = null, bool $applyMainModelToProfiles = false, array $killSwitches = []): array
    {
        foreach ([
            self::MAIN_MODEL => $modelSettings['main_model'] ?? null,
            self::EXTERNAL_LOOKUP_MODEL => $modelSettings['external_lookup_model'] ?? null,
        ] as $key => $value) {
            $this->set($key, trim((string) $value), 'string', $actor);
        }

        foreach ($this->usageLimitDefaults() as $name => $default) {
            if (array_key_exists($name, $usageLimits)) {
                $this->set('usage.'.$name, (float) $usageLimits[$name], 'float', $actor);
            }
        }

        foreach ([
            self::BEAN_CHAT_ENABLED => $killSwitches['bean_chat_enabled'] ?? null,
        ] as $key => $value) {
            if ($value !== null) {
                $this->set($key, (bool) $value, 'boolean', $actor);
            }
        }

        $this->settings = null;

        if ($applyMainModelToProfiles) {
            AgentProfile::query()->update(['model' => $this->mainModel()]);
            $this->settings = null;
        }

        return $this->payload();
    }

    public function mainModel(): string
    {
        return $this->stringValue(self::MAIN_MODEL, $this->defaultMainModel());
    }

    public function mainModelOverride(): ?string
    {
        return $this->storedStringValue(self::MAIN_MODEL);
    }

    public function externalLookupModel(): string
    {
        return $this->stringValue(self::EXTERNAL_LOOKUP_MODEL, $this->defaultExternalLookupModel());
    }

    public function beanChatEnabled(): bool
    {
        return $this->boolValue(self::BEAN_CHAT_ENABLED, true);
    }

    public function usageLimits(): array
    {
        return collect($this->usageLimitDefaults())
            ->mapWithKeys(fn (mixed $default, string $key): array => [
                $key => $this->floatValue('usage.'.$key, (float) $default),
            ])
            ->all();
    }

    private function settingPayload(string $key, mixed $default): array
    {
        $stored = $this->settings()->get($key);
        $storedValue = $stored instanceof AdminSetting ? data_get($stored->value, 'value') : null;

        return [
            'key' => $key,
            'value' => $storedValue ?? $default,
            'default' => $default,
            'is_overridden' => $stored instanceof AdminSetting,
            'updated_at' => $stored?->updated_at?->toIso8601String(),
        ];
    }

    private function stringValue(string $key, string $default): string
    {
        return $this->storedStringValue($key) ?? $default;
    }

    private function storedStringValue(string $key): ?string
    {
        $value = $this->storedValue($key);
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }

    private function floatValue(string $key, float $default): float
    {
        $value = $this->storedValue($key);

        return is_numeric($value) ? (float) $value : $default;
    }

    private function boolValue(string $key, bool $default): bool
    {
        $value = $this->storedValue($key);

        return is_bool($value) ? $value : $default;
    }

    private function storedValue(string $key): mixed
    {
        $setting = $this->settings()->get($key);

        return $setting instanceof AdminSetting ? data_get($setting->value, 'value') : null;
    }

    private function set(string $key, mixed $value, string $type, ?User $actor = null): AdminSetting
    {
        return AdminSetting::updateOrCreate(
            ['key' => $key],
            [
                'value' => ['value' => $value],
                'type' => $type,
                'updated_by_user_id' => $actor?->id,
            ],
        );
    }

    /**
     * @return Collection<string, AdminSetting>
     */
    private function settings(): Collection
    {
        $this->settings ??= AdminSetting::query()->get()->keyBy('key');

        return $this->settings;
    }

    private function defaultMainModel(): string
    {
        return (string) config('services.hermes_runtime.default_model', 'gpt-5.5');
    }

    private function defaultExternalLookupModel(): string
    {
        return (string) config('services.hermes_runtime.external_lookup_model', 'gpt-5-mini');
    }

    private function usageLimitDefaults(): array
    {
        $limits = (array) config('services.ai_usage.limits', []);

        return [
            'base_cost_limit' => (float) ($limits['base_cost_limit'] ?? 1.00),
            'base_external_cost_limit' => (float) ($limits['base_external_cost_limit'] ?? 0.25),
            'premium_cost_limit' => (float) ($limits['premium_cost_limit'] ?? 5.00),
            'premium_external_cost_limit' => (float) ($limits['premium_external_cost_limit'] ?? 1.00),
            'pro_cost_limit' => (float) ($limits['pro_cost_limit'] ?? 20.00),
            'pro_external_cost_limit' => (float) ($limits['pro_external_cost_limit'] ?? 5.00),
        ];
    }
}
