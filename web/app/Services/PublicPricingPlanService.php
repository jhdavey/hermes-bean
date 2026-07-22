<?php

namespace App\Services;

class PublicPricingPlanService
{
    private const CATALOG = [
        'base' => [
            'name' => 'Base',
            'description' => 'Best for one person coordinating work and personal life.',
            'monthly_price' => '4.99',
            'yearly_price' => '49.99',
            'popular' => false,
            'extra_features' => [],
        ],
        'premium' => [
            'name' => 'Premium',
            'description' => 'Best for busy households coordinating more people and responsibilities.',
            'monthly_price' => '19.99',
            'yearly_price' => '199.99',
            'popular' => true,
            'extra_features' => ['The best fit for most busy households'],
        ],
        'pro' => [
            'name' => 'Pro',
            'description' => 'Best for complex schedules with more calendars, workspaces, and history.',
            'monthly_price' => '49.99',
            'yearly_price' => '499.99',
            'popular' => false,
            'extra_features' => ['Priority support'],
        ],
    ];

    public function __construct(private readonly PlanLimitService $limits) {}

    /**
     * @return array<string, array<string, mixed>>
     */
    public function plans(): array
    {
        return collect(self::CATALOG)
            ->mapWithKeys(function (array $catalog, string $key): array {
                $limits = $this->limits->planLimits($key);

                return [$key => [
                    'key' => $key,
                    ...$catalog,
                    'limits' => $limits,
                    'features' => [
                        $this->includedFeature('Tasks, reminders, and calendar in one daily view'),
                        $this->quantityFeature($limits['workspace_limit'] ?? null, 'workspace', 'workspaces'),
                        $this->quantityFeature($limits['calendar_connection_limit'] ?? null, 'connected calendar', 'connected calendars'),
                        $this->quantityFeature($limits['connected_account_limit'] ?? null, 'connected account', 'connected accounts'),
                        $this->historyFeature($limits['history_days'] ?? null),
                        $this->notesFeature($limits),
                        $this->toggleFeature((bool) ($limits['recurring_tasks_enabled'] ?? false), 'Recurring tasks'),
                        $this->toggleFeature((bool) ($limits['recurring_reminders_enabled'] ?? false), 'Recurring reminders'),
                        $this->toggleFeature((bool) ($limits['recurring_calendar_enabled'] ?? false), 'Recurring calendar events'),
                        $this->toggleFeature((bool) ($limits['email_reminders_enabled'] ?? false), 'Email reminders'),
                        ...collect($catalog['extra_features'])->map(fn (string $label): array => $this->includedFeature($label))->all(),
                    ],
                ]];
            })
            ->all();
    }

    public function guideFacts(): string
    {
        return collect($this->plans())
            ->map(function (array $plan): string {
                $features = collect($plan['features'])
                    ->map(fn (array $feature): string => $feature['label'])
                    ->implode('; ');

                return sprintf(
                    '- %s is $%s monthly or $%s yearly. Current plan details: %s.',
                    $plan['name'],
                    $plan['monthly_price'],
                    $plan['yearly_price'],
                    $features,
                );
            })
            ->implode("\n");
    }

    /** @return array{label: string, included: bool} */
    private function quantityFeature(mixed $value, string $singular, string $plural): array
    {
        if ($value === null) {
            return $this->includedFeature('Unlimited '.$plural);
        }

        $count = max(0, (int) $value);
        if ($count === 0) {
            return $this->excludedFeature(ucfirst($plural).' not included');
        }

        return $this->includedFeature($count.' '.($count === 1 ? $singular : $plural));
    }

    /** @return array{label: string, included: bool} */
    private function historyFeature(mixed $value): array
    {
        if ($value === null || (int) $value <= 0) {
            return $this->includedFeature('Full calendar and task history');
        }

        $days = (int) $value;

        return $this->includedFeature($days.' '.($days === 1 ? 'day' : 'days').' of searchable history');
    }

    /** @return array{label: string, included: bool} */
    private function notesFeature(array $limits): array
    {
        $value = $limits['note_limit'] ?? null;
        $enabled = (bool) ($limits['notes_enabled'] ?? false)
            || $value === null
            || (is_numeric($value) && (int) $value > 0);

        if (! $enabled || (is_numeric($value) && (int) $value <= 0)) {
            return $this->excludedFeature('Notes not included');
        }

        if ($value === null) {
            return $this->includedFeature('Unlimited notes with folders');
        }

        $count = (int) $value;

        return $this->includedFeature('Up to '.$count.' '.($count === 1 ? 'note' : 'notes'));
    }

    /** @return array{label: string, included: bool} */
    private function toggleFeature(bool $enabled, string $label): array
    {
        return $enabled
            ? $this->includedFeature($label)
            : $this->excludedFeature($label.' not included');
    }

    /** @return array{label: string, included: true} */
    private function includedFeature(string $label): array
    {
        return ['label' => $label, 'included' => true];
    }

    /** @return array{label: string, included: false} */
    private function excludedFeature(string $label): array
    {
        return ['label' => $label, 'included' => false];
    }
}
