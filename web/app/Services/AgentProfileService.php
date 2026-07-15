<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Str;

class AgentProfileService
{
    private const PERSONALITIES = [
        'balanced' => [
            'label' => 'Balanced helper',
            'prompt' => 'Be a calm, practical, concise co-pilot. Answer directly, keep confirmations short, and offer at most one useful next suggestion unless the user asks for more. Balance helpful proactive ideas with user control. Use steady language such as "Got it", "Here is the clean version", and "Want me to tidy the rest up too?" Avoid unnecessary jokes, pressure, or over-explaining.',
        ],
        'coach' => [
            'label' => 'Motivating coach',
            'prompt' => 'Be warm, encouraging, energetic, and progress-oriented without guilt. Celebrate small wins, suggest the smallest next step when the user seems overloaded, and use gentle accountability language such as "Nice — that is handled", "Small win", and "Want me to protect time for it?" Help the user move forward while avoiding shame, pressure, or overly peppy cheerleading.',
        ],
        'organizer' => [
            'label' => 'Detail organizer',
            'prompt' => 'Be structured, precise, schedule-aware, and detail-oriented. Prefer tidy summaries with labels such as Added, Changed, Still needed, and Next step. Ask exact follow-up questions for missing dates, times, recurrence, category, workspace, or calendar routing. Suggest categories, reminders, conflict checks, and cleanup when helpful so the day stays organized.',
        ],
        'creative' => [
            'label' => 'Creative partner',
            'prompt' => 'Be imaginative, warm, idea-forward, and lightly playful while staying useful. Help brainstorm names, themes, checklists, plans, and options, then convert good ideas into concrete tasks, reminders, or calendar events. Use approachable language such as "Love this", "Tiny plan incoming", and "Let’s make it easy and fun" without becoming distracting.',
        ],
        'direct' => [
            'label' => 'Direct operator',
            'prompt' => 'Be crisp, decisive, and action-oriented. Keep language short, skip emotional padding, lead with the answer or completed action, and ask only the minimum follow-up needed to move work forward. Use clear phrases such as "Done", "I need one detail", and "Best next move". Avoid pep talks, jokes, or long explanations unless the user asks.',
        ],
        'gentle' => [
            'label' => 'Gentle companion',
            'prompt' => 'Be soft, patient, reassuring, and low-pressure while staying practical. Help the user feel settled, break things into manageable steps, and use calm language such as "No rush", "We can keep this simple", and "Here is the next easy step". Avoid urgency, guilt, or overly forceful recommendations.',
        ],
    ];

    public function ensureForUser(User $user): AgentProfile
    {
        $workspaceId = app(WorkspaceService::class)->ensurePersonalWorkspaceForUser($user);
        $profile = AgentProfile::firstOrCreate(
            ['workspace_id' => $workspaceId],
            ['user_id' => $user->id] + $this->defaultsFor($user)
        );

        if (! $profile->user_id) {
            $profile->forceFill(['user_id' => $user->id])->save();
        }

        return $profile;
    }

    public function ensureForWorkspace(Workspace $workspace, ?User $actor = null): AgentProfile
    {
        $owner = $actor ?: $workspace->creator ?: $workspace->personalOwner ?: $workspace->memberships()->where('role', 'owner')->with('user')->first()?->user;
        if (! $owner) {
            throw new \InvalidArgumentException('Workspace agent profile requires an owner or actor.');
        }

        $profile = AgentProfile::firstOrCreate(
            ['workspace_id' => $workspace->id],
            $this->defaultsForWorkspace($workspace, $owner)
        );

        return $profile;
    }

    public function defaultsForWorkspace(Workspace $workspace, User $actor): array
    {
        $defaults = $this->defaultsFor($actor);
        $slug = 'workspace-'.$workspace->id.'-'.Str::lower(Str::random(8));
        $defaults['user_id'] = $actor->id;
        $defaults['slug'] = $slug;
        $defaults['display_name'] = $workspace->name.' Hermes';
        $defaults['metadata'] = [...($defaults['metadata'] ?? []), 'workspace_id' => $workspace->id, 'workspace_type' => $workspace->type];

        return $defaults;
    }

    public function defaultsFor(User $user): array
    {
        $slug = 'user-'.$user->id.'-'.Str::lower(Str::random(8));

        return [
            'slug' => $slug,
            'display_name' => $user->name."'s Hermes",
            'status' => 'active',
            'settings' => [
                'memory_enabled' => true,
                'skills_enabled' => true,
                'timezone' => null,
                'personality_type' => 'balanced',
                'personality_label' => self::PERSONALITIES['balanced']['label'],
                'personality_prompt' => self::PERSONALITIES['balanced']['prompt'],
                'onboarding' => [
                    'completed' => false,
                    'priorities' => [],
                    'context' => null,
                    'completed_at' => null,
                ],
                'voice' => app(OpenAiVoiceService::class)->defaultVoiceSettings(),
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
        }

        $profile->forceFill(['settings' => $merged])->save();

        return $profile->refresh();
    }

    public function updateHomeCitySettings(AgentProfile $profile, ?string $homeCity): AgentProfile
    {
        $settings = $profile->settings ?? [];
        $city = trim((string) $homeCity);

        if ($city === '') {
            data_forget($settings, 'weather.location');
            data_forget($settings, 'weather_location');
            data_forget($settings, 'default_weather_location');
            data_forget($settings, 'home_location');
            data_forget($settings, 'memory.user_preferences.weather_location');
            data_forget($settings, 'memory.user_preferences.home_location');
        } else {
            data_set($settings, 'weather.location', $city);
            $settings['weather_location'] = $city;
            $settings['default_weather_location'] = $city;
            $settings['home_location'] = $city;
            data_set($settings, 'memory.user_preferences.weather_location', $city);
            data_set($settings, 'memory.user_preferences.home_location', $city);
        }

        $profile->forceFill(['settings' => $settings])->save();

        return $profile->refresh();
    }

    public function publicSettings(array $settings): array
    {
        $settings['voice'] = app(OpenAiVoiceService::class)->defaultVoiceSettings(
            data_get($settings, 'voice.voice')
        );

        return $settings;
    }

    public function exposePublicSettings(AgentProfile $profile): AgentProfile
    {
        $profile->setAttribute('settings', $this->publicSettings($profile->settings ?? []));

        return $profile;
    }

    public function onboardingComplete(?AgentProfile $profile): bool
    {
        return data_get($profile?->settings ?? [], 'onboarding.completed') === true;
    }

    public function preferencesReady(?AgentProfile $profile): bool
    {
        if (! $this->onboardingComplete($profile)) {
            return false;
        }

        $settings = $profile?->settings ?? [];
        $priorities = array_values(array_filter(
            array_map(fn ($priority) => trim((string) $priority), (array) data_get($settings, 'onboarding.priorities', [])),
            fn (string $priority) => $priority !== ''
        ));
        $context = trim((string) data_get($settings, 'onboarding.context', ''));

        return $priorities !== [] || $context !== '';
    }

    public function needsOnboarding(User $user, ?AgentProfile $profile = null): bool
    {
        return ! $user->onboard_complete || ! $this->preferencesReady($profile);
    }

    public function syncUserOnboardingFlag(User $user, ?AgentProfile $profile = null): User
    {
        if ($this->preferencesReady($profile)) {
            if (! $user->onboard_complete) {
                $user->forceFill(['onboard_complete' => true])->save();

                return $user->refresh();
            }

            return $user;
        }

        if (! $user->onboard_complete) {
            return $user;
        }

        $user->forceFill(['onboard_complete' => false])->save();

        return $user->refresh();
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
}
