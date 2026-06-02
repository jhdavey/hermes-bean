<?php

namespace App\Services;

use App\Models\AdminSetting;
use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Support\Collection;

class AdminSettingsService
{
    public const MAIN_MODEL = 'models.main';

    public const QUICK_VOICE_MODEL = 'models.quick_voice';

    public const REALTIME_MODEL = 'models.realtime';

    public const EXTERNAL_LOOKUP_MODEL = 'models.external_lookup';

    public const BETA_API_PER_MINUTE = 'beta.api_per_minute';

    public const BETA_MONTHLY_AI_ACTIONS = 'beta.monthly_ai_actions';

    public const BETA_MONTHLY_TOKENS = 'beta.monthly_tokens';

    public const BETA_MONTHLY_COST_USD = 'beta.monthly_cost_usd';

    public const BETA_DAILY_SOFT_TOKENS = 'beta.daily_soft_tokens';

    public const BETA_DAILY_HARD_TOKENS = 'beta.daily_hard_tokens';

    public const BETA_DAILY_SOFT_COST_USD = 'beta.daily_soft_cost_usd';

    public const BETA_DAILY_HARD_COST_USD = 'beta.daily_hard_cost_usd';

    private ?Collection $settings = null;

    public function payload(): array
    {
        return [
            'models' => [
                'main_model' => $this->settingPayload(self::MAIN_MODEL, $this->defaultMainModel()),
                'quick_voice_model' => $this->settingPayload(self::QUICK_VOICE_MODEL, $this->defaultQuickVoiceModel()),
                'realtime_model' => $this->settingPayload(self::REALTIME_MODEL, $this->defaultRealtimeModel()),
                'external_lookup_model' => $this->settingPayload(self::EXTERNAL_LOOKUP_MODEL, $this->defaultExternalLookupModel()),
            ],
            'beta_limits' => collect($this->betaLimitDefaults())
                ->mapWithKeys(fn (mixed $default, string $key): array => [$key => $this->settingPayload('beta.'.$key, $default)])
                ->all(),
        ];
    }

    public function update(array $modelSettings, array $betaLimits, ?User $actor = null, bool $applyMainModelToProfiles = false): array
    {
        foreach ([
            self::MAIN_MODEL => $modelSettings['main_model'] ?? null,
            self::QUICK_VOICE_MODEL => $modelSettings['quick_voice_model'] ?? null,
            self::REALTIME_MODEL => $modelSettings['realtime_model'] ?? null,
            self::EXTERNAL_LOOKUP_MODEL => $modelSettings['external_lookup_model'] ?? null,
        ] as $key => $value) {
            $this->set($key, trim((string) $value), 'string', $actor);
        }

        foreach ($this->betaLimitDefaults() as $name => $default) {
            if (array_key_exists($name, $betaLimits)) {
                $this->set('beta.'.$name, is_float($default) ? (float) $betaLimits[$name] : (int) $betaLimits[$name], is_float($default) ? 'float' : 'integer', $actor);
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

    public function quickVoiceModel(): string
    {
        return $this->stringValue(self::QUICK_VOICE_MODEL, $this->defaultQuickVoiceModel());
    }

    public function realtimeModel(): string
    {
        return $this->stringValue(self::REALTIME_MODEL, $this->defaultRealtimeModel());
    }

    public function externalLookupModel(): string
    {
        return $this->stringValue(self::EXTERNAL_LOOKUP_MODEL, $this->defaultExternalLookupModel());
    }

    public function betaBudget(): array
    {
        return collect($this->betaLimitDefaults())
            ->mapWithKeys(fn (mixed $default, string $key): array => [
                $key => is_float($default)
                    ? $this->floatValue('beta.'.$key, $default)
                    : $this->intValue('beta.'.$key, (int) $default),
            ])
            ->all();
    }

    public function betaApiPerMinute(): int
    {
        return $this->intValue(self::BETA_API_PER_MINUTE, (int) config('security.rate_limits.api_per_minute', 60));
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

    private function intValue(string $key, int $default): int
    {
        $value = $this->storedValue($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    private function floatValue(string $key, float $default): float
    {
        $value = $this->storedValue($key);

        return is_numeric($value) ? (float) $value : $default;
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

    private function defaultQuickVoiceModel(): string
    {
        return (string) config('services.hermes_runtime.quick_reply_model', 'gpt-5.4-mini');
    }

    private function defaultRealtimeModel(): string
    {
        return (string) config('services.hermes_realtime.model', 'gpt-realtime');
    }

    private function defaultExternalLookupModel(): string
    {
        return (string) config('services.hermes_runtime.external_lookup_model', 'gpt-5-mini');
    }

    private function betaLimitDefaults(): array
    {
        $free = (array) config('services.ai_usage.budgets.free', []);

        return [
            'api_per_minute' => (int) config('security.rate_limits.api_per_minute', 60),
            'monthly_ai_actions' => (int) ($free['monthly_ai_actions'] ?? 50),
            'monthly_tokens' => (int) ($free['monthly_tokens'] ?? 1_000_000),
            'monthly_cost_usd' => (float) ($free['monthly_cost_usd'] ?? 5.00),
            'daily_soft_tokens' => (int) ($free['daily_soft_tokens'] ?? 60_000),
            'daily_hard_tokens' => (int) ($free['daily_hard_tokens'] ?? 180_000),
            'daily_soft_cost_usd' => (float) ($free['daily_soft_cost_usd'] ?? 0.35),
            'daily_hard_cost_usd' => (float) ($free['daily_hard_cost_usd'] ?? 1.00),
        ];
    }
}
