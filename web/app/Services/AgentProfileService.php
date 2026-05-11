<?php

namespace App\Services;

use App\Models\AgentProfile;
use App\Models\User;
use Illuminate\Support\Str;

class AgentProfileService
{
    public function ensureForUser(User $user): AgentProfile
    {
        return AgentProfile::firstOrCreate(
            ['user_id' => $user->id],
            $this->defaultsFor($user)
        );
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
}
