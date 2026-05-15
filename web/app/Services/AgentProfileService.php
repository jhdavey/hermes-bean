<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class AgentProfileService
{
    private const PERSONALITIES = [
        'balanced' => [
            'label' => 'Balanced helper',
            'prompt' => 'Be calm, practical, and concise. Balance helpful suggestions with user control.',
        ],
        'coach' => [
            'label' => 'Motivating coach',
            'prompt' => 'Be encouraging, energetic, and accountability-oriented. Help the user make progress without guilt.',
        ],
        'organizer' => [
            'label' => 'Detail organizer',
            'prompt' => 'Be structured, precise, and schedule-aware. Break requests into clear next steps and keep the day tidy.',
        ],
        'creative' => [
            'label' => 'Creative partner',
            'prompt' => 'Be imaginative, warm, and idea-forward while still converting ideas into practical plans.',
        ],
    ];

    public function ensureForUser(User $user): AgentProfile
    {
        $profile = AgentProfile::firstOrCreate(
            ['user_id' => $user->id],
            $this->defaultsFor($user)
        );

        $this->bootstrapRuntimeHome($profile);

        return $profile;
    }

    public function defaultsFor(User $user): array
    {
        $slug = 'user-'.$user->id.'-'.Str::lower(Str::random(8));

        return [
            'slug' => $slug,
            'display_name' => $user->name."'s Hermes",
            'status' => 'active',
            'provider' => (string) config('services.hermes_runtime.default_provider', 'openrouter'),
            'model' => (string) config('services.hermes_runtime.default_model', 'gpt-5.5'),
            'router_mode' => (string) config('services.hermes_runtime.router_mode', 'fixed'),
            'runtime_home' => rtrim((string) config('services.hermes_runtime.users_home', ''), '/').'/'.$slug,
            'settings' => [
                'memory_enabled' => true,
                'skills_enabled' => true,
                'timezone' => 'UTC',
                'personality_type' => 'balanced',
                'personality_label' => self::PERSONALITIES['balanced']['label'],
                'personality_prompt' => self::PERSONALITIES['balanced']['prompt'],
                'onboarding' => [
                    'completed' => false,
                    'priorities' => [],
                    'context' => null,
                    'completed_at' => null,
                ],
            ],
            'tool_policy' => [
                'allow_internal_calendar' => true,
                'allow_tasks' => true,
                'allow_reminders' => true,
                'allow_activity_records' => true,
                'external_calendar_sync' => false,
            ],
            'approval_policy' => [
                'auto_approve_low_risk' => true,
                'require_approval_for' => [
                    'destructive_actions',
                    'outgoing_mail',
                    'outgoing_messages',
                    'payments',
                    'purchases',
                    'deployments',
                    'external_api_side_effects',
                ],
                'approval_surface' => 'app_home_top_banner',
            ],
            'metadata' => [
                'seeded_from' => 'hermes_bean_default_v1',
                'runtime_strategy' => 'server_hosted_unique_agent',
            ],
        ];
    }

    public function applyOnboarding(AgentProfile $profile, array $data, string $source = 'settings'): AgentProfile
    {
        $personalityKey = (string) ($data['agent_personality'] ?? data_get($profile->settings, 'personality_type', 'balanced'));
        $personality = self::PERSONALITIES[$personalityKey] ?? self::PERSONALITIES['balanced'];
        $priorities = array_values(array_filter(
            array_map(
                fn ($priority) => trim((string) $priority),
                (array) ($data['onboarding_priorities'] ?? data_get($profile->settings, 'onboarding.priorities', []))
            ),
            fn (string $priority) => $priority !== ''
        ));
        $context = trim((string) ($data['onboarding_context'] ?? data_get($profile->settings, 'onboarding.context', '')));
        $settings = $profile->settings ?? [];

        $settings['personality_type'] = $personalityKey;
        $settings['personality_label'] = $personality['label'];
        $settings['personality_prompt'] = $personality['prompt'];
        $settings['onboarding'] = [
            ...((isset($settings['onboarding']) && is_array($settings['onboarding'])) ? $settings['onboarding'] : []),
            'completed' => true,
            'priorities' => array_slice($priorities, 0, 5),
            'context' => $context === '' ? null : $context,
            'completed_at' => data_get($settings, 'onboarding.completed_at') ?: now()->toISOString(),
            'updated_at' => now()->toISOString(),
            'source' => $source,
        ];
        $settings['memory'] = $this->memorySettingsFor($settings, $source);

        $profile->forceFill(['settings' => $settings])->save();
        $this->writeRuntimeMemory($profile, $settings['memory']);

        return $profile->refresh();
    }

    public function mergeSettings(AgentProfile $profile, array $settings, string $source = 'agent'): AgentProfile
    {
        $merged = $this->recursiveMerge($profile->settings ?? [], $settings);

        if (array_key_exists('personality_type', $merged)) {
            $personalityKey = (string) $merged['personality_type'];
            $personality = self::PERSONALITIES[$personalityKey] ?? self::PERSONALITIES['balanced'];
            $merged['personality_type'] = array_key_exists($personalityKey, self::PERSONALITIES) ? $personalityKey : 'balanced';
            $merged['personality_label'] = $personality['label'];
            $merged['personality_prompt'] = $personality['prompt'];
        }

        if (data_get($merged, 'onboarding.completed') === true) {
            $merged['onboarding']['updated_at'] = now()->toISOString();
            $merged['onboarding']['source'] = $source;
            $merged['memory'] = $this->memorySettingsFor($merged, $source);
            $this->writeRuntimeMemory($profile, $merged['memory']);
        }

        $profile->forceFill(['settings' => $merged])->save();

        return $profile->refresh();
    }

    public function memorySettingsFor(array $settings, string $source = 'settings'): array
    {
        $priorities = array_values(array_filter((array) data_get($settings, 'onboarding.priorities', [])));
        $context = data_get($settings, 'onboarding.context');
        $personality = (string) data_get($settings, 'personality_type', 'balanced');

        return [
            'user_preferences' => [
                'personality_type' => $personality,
                'priorities' => $priorities,
                'context' => is_string($context) && trim($context) !== '' ? trim($context) : null,
                'summary' => $this->preferenceSummary($personality, $priorities, is_string($context) ? $context : null),
                'source' => $source,
                'updated_at' => now()->toISOString(),
            ],
        ];
    }

    private function preferenceSummary(string $personality, array $priorities, ?string $context): string
    {
        $parts = ['User prefers Bean personality: '.$personality.'.'];
        if ($priorities !== []) {
            $parts[] = 'User priorities: '.implode(', ', $priorities).'.';
        }
        if (is_string($context) && trim($context) !== '') {
            $parts[] = 'Additional user context: '.trim($context);
        }

        return implode(' ', $parts);
    }

    private function writeRuntimeMemory(AgentProfile $profile, array $memory): void
    {
        if (! $profile->runtime_home) {
            return;
        }

        File::ensureDirectoryExists($profile->runtime_home);
        File::put(
            rtrim($profile->runtime_home, '/').'/bean-preferences-memory.json',
            json_encode($memory, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL
        );
    }

    private function recursiveMerge(array $base, array $updates): array
    {
        foreach ($updates as $key => $value) {
            if (is_array($value) && array_is_list($value) === false && isset($base[$key]) && is_array($base[$key]) && array_is_list($base[$key]) === false) {
                $base[$key] = $this->recursiveMerge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    public static function personalityKeys(): array
    {
        return array_keys(self::PERSONALITIES);
    }

    private function bootstrapRuntimeHome(AgentProfile $profile): void
    {
        if (! $profile->runtime_home) {
            return;
        }

        File::ensureDirectoryExists($profile->runtime_home);
        File::ensureDirectoryExists($profile->runtime_home.'/sessions');
        File::ensureDirectoryExists($profile->runtime_home.'/logs');

        $baseHome = (string) config('services.hermes_runtime.base_home', '');
        if ($baseHome === '') {
            return;
        }

        foreach (['.env', 'config.yaml'] as $file) {
            $source = rtrim($baseHome, '/').'/'.$file;
            $target = rtrim($profile->runtime_home, '/').'/'.$file;

            if (! File::exists($source) || File::exists($target) || is_link($target)) {
                continue;
            }

            if (function_exists('symlink')) {
                @symlink($source, $target);
            }

            if (! File::exists($target) && ! is_link($target)) {
                File::copy($source, $target);
            }
        }
    }
}
