<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\File;
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

        $this->bootstrapRuntimeHome($profile);

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

        $this->bootstrapRuntimeHome($profile);

        return $profile;
    }

    public function defaultsForWorkspace(Workspace $workspace, User $actor): array
    {
        $defaults = $this->defaultsFor($actor);
        $slug = 'workspace-'.$workspace->id.'-'.Str::lower(Str::random(8));
        $defaults['user_id'] = $actor->id;
        $defaults['slug'] = $slug;
        $defaults['display_name'] = $workspace->name.' Hermes';
        $defaults['runtime_home'] = rtrim((string) config('services.hermes_runtime.users_home', ''), '/').'/workspaces/'.$workspace->id.'/'.$slug;
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
            'provider' => (string) config('services.hermes_runtime.default_provider', 'openrouter'),
            'model' => (string) config('services.hermes_runtime.default_model', 'gpt-5.5'),
            'router_mode' => (string) config('services.hermes_runtime.router_mode', 'heuristic'),
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

    public function onboardingComplete(?AgentProfile $profile): bool
    {
        return data_get($profile?->settings ?? [], 'onboarding.completed') === true;
    }

    public function syncUserOnboardingFlag(User $user, ?AgentProfile $profile = null): User
    {
        if ($user->onboard_complete || ! $this->onboardingComplete($profile)) {
            return $user;
        }

        $user->forceFill(['onboard_complete' => true])->save();

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

    public function appendWorkspaceMemoryNote(Workspace $workspace, User $actor, string $note): AgentProfile
    {
        $note = trim($note);
        if ($note === '') {
            throw new \InvalidArgumentException('Workspace memory note cannot be empty.');
        }

        $profile = $this->ensureForWorkspace($workspace, $actor);
        $path = rtrim($profile->runtime_home, '/').'/MEMORY.md';
        File::ensureDirectoryExists($profile->runtime_home);
        if (! File::exists($path)) {
            $this->writeRuntimeMarkdownMemory($profile->refresh());
        }

        $existing = File::exists($path) ? File::get($path) : '';
        $line = '- '.$note;
        if (! str_contains($existing, $line)) {
            File::append($path, PHP_EOL.$line.PHP_EOL);
        }

        return $profile->refresh();
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
        $jsonPath = rtrim($profile->runtime_home, '/').'/bean-preferences-memory.json';
        if (File::exists($jsonPath)) {
            File::delete($jsonPath);
        }

        $this->writeRuntimeMarkdownMemory($profile->refresh(), $memory);
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

    private function writeRuntimeMarkdownMemory(AgentProfile $profile, ?array $memory = null): void
    {
        if (! $profile->runtime_home) {
            return;
        }

        File::ensureDirectoryExists($profile->runtime_home);

        $workspace = $profile->workspace ?: ($profile->workspace_id ? Workspace::find($profile->workspace_id) : null);
        $user = $profile->user ?: ($profile->user_id ? User::find($profile->user_id) : null);
        $settings = $profile->settings ?? [];
        $memory ??= (array) data_get($settings, 'memory', []);
        $workspaceName = $workspace?->name ?? $profile->display_name ?? 'Personal';
        $workspaceType = $workspace?->type ?? 'personal';
        $workspaceId = $workspace?->id ?? $profile->workspace_id;
        $priorities = array_values(array_filter((array) data_get($settings, 'onboarding.priorities', [])));
        $context = data_get($settings, 'onboarding.context');
        $summary = data_get($memory, 'user_preferences.summary') ?: data_get($settings, 'memory.user_preferences.summary');
        $personality = (string) data_get($settings, 'personality_type', 'balanced');
        $updatedAt = now()->toISOString();

        $this->putManagedMarkdown(
            rtrim($profile->runtime_home, '/').'/USER.md',
            '# User Memory'.PHP_EOL.PHP_EOL,
            implode(PHP_EOL, array_filter([
                '## Managed identity context',
                '- signed_in_user_id: '.($user?->id ?? 'unknown'),
                '- signed_in_user_name: '.($user?->name ?: 'unknown'),
                '- signed_in_user_email: '.($user?->email ?: 'unknown'),
                '- workspace_id: '.($workspaceId ?? 'unknown'),
                '- workspace_name: '.$workspaceName,
                '- workspace_type: '.$workspaceType,
                '- agent_profile_id: '.$profile->id,
                '- agent_slug: '.$profile->slug,
                '- updated_at: '.$updatedAt,
            ])).PHP_EOL
        );

        $this->putManagedMarkdown(
            rtrim($profile->runtime_home, '/').'/MEMORY.md',
            '# Workspace Memory'.PHP_EOL.PHP_EOL,
            implode(PHP_EOL, array_filter([
                '## Managed workspace memory context',
                '- workspace_id: '.($workspaceId ?? 'unknown'),
                '- workspace_name: '.$workspaceName,
                '- workspace_type: '.$workspaceType,
                '- isolation: This memory belongs only to this '.$workspaceType.' workspace. Do not copy facts into Personal or another household unless a user explicitly asks to sync/share them.',
                $summary ? '- preference_summary: '.$summary : null,
                is_string($context) && trim($context) !== '' ? '- context: '.trim($context) : null,
                '- updated_at: '.$updatedAt,
            ])).PHP_EOL.PHP_EOL."## Agent-learned durable facts\nAdd concise durable facts for this workspace below this heading. Keep Personal-only facts out of household files and household facts out of Personal files.\n"
        );

        $householdLines = [
            '## Managed workspace profile',
            '- name: '.$workspaceName,
            '- type: '.$workspaceType,
            '- workspace_id: '.($workspaceId ?? 'unknown'),
            '- owner_or_actor_user_id: '.($user?->id ?? 'unknown'),
            '- owner_or_actor_name: '.($user?->name ?: 'unknown'),
            '- updated_at: '.$updatedAt,
        ];
        if ($workspaceType === 'household') {
            $householdLines[] = '- guidance: Store shared household routines, member preferences relevant to this household, and family scheduling context here.';
        } else {
            $householdLines[] = '- guidance: This is the Personal workspace. Store only the signed-in user\'s personal preferences and routines here.';
        }
        $this->putManagedMarkdown(
            rtrim($profile->runtime_home, '/').'/HOUSEHOLD.md',
            '# Household / Workspace Context'.PHP_EOL.PHP_EOL,
            implode(PHP_EOL, $householdLines).PHP_EOL.PHP_EOL."## Agent-learned workspace context\nAdd household/workspace-specific context below this heading.\n"
        );

        $preferenceLines = [
            '## Managed preferences',
            '- personality_type: '.$personality,
            '- personality_label: '.((string) data_get($settings, 'personality_label', 'Balanced helper')),
        ];
        foreach ($priorities as $priority) {
            $preferenceLines[] = '- priority: '.$priority;
        }
        if (is_string($context) && trim($context) !== '') {
            $preferenceLines[] = '- context: '.trim($context);
        }
        if ($summary) {
            $preferenceLines[] = '- summary: '.$summary;
        }
        $preferenceLines[] = '- source: '.((string) data_get($memory, 'user_preferences.source', data_get($settings, 'memory.user_preferences.source', 'settings')));
        $preferenceLines[] = '- updated_at: '.$updatedAt;

        $this->putManagedMarkdown(
            rtrim($profile->runtime_home, '/').'/PREFERENCES.md',
            '# Preferences'.PHP_EOL.PHP_EOL,
            implode(PHP_EOL, $preferenceLines).PHP_EOL.PHP_EOL."## Agent-learned preference notes\nAdd stable preference notes for this workspace below this heading.\n"
        );
    }

    private function putManagedMarkdown(string $path, string $defaultHeader, string $managedContent): void
    {
        $begin = '<!-- BEGIN HERMES-BEAN MANAGED -->';
        $end = '<!-- END HERMES-BEAN MANAGED -->';
        $section = $begin.PHP_EOL.rtrim($managedContent).PHP_EOL.$end;

        if (! File::exists($path)) {
            File::put($path, rtrim($defaultHeader).PHP_EOL.PHP_EOL.$section.PHP_EOL);

            return;
        }

        $existing = File::get($path);
        $pattern = '/'.preg_quote($begin, '/').'.*?'.preg_quote($end, '/').'/s';
        if (preg_match($pattern, $existing)) {
            File::put($path, preg_replace($pattern, $section, $existing));

            return;
        }

        File::put($path, rtrim($existing).PHP_EOL.PHP_EOL.$section.PHP_EOL);
    }

    private function bootstrapRuntimeHome(AgentProfile $profile): void
    {
        if (! $profile->runtime_home) {
            return;
        }

        File::ensureDirectoryExists($profile->runtime_home);
        File::ensureDirectoryExists($profile->runtime_home.'/sessions');
        File::ensureDirectoryExists($profile->runtime_home.'/logs');
        $this->writeRuntimeMarkdownMemory($profile->refresh());

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
