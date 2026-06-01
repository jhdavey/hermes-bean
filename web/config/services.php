<?php

$hermesApiKey = (string) env('HERMES_API_KEY', '');
$openAiKey = (string) env('OPENAI_API_KEY', '');
$openAiPublicKey = (string) env('OPENAI_PUBLIC_KEY', '');
$hermesApiBase = env('HERMES_API_BASE') ?: 'https://api.openai.com/v1';
$hermesResolvedApiKey = $hermesApiKey !== '' ? $hermesApiKey : ($openAiPublicKey !== '' ? $openAiPublicKey : $openAiKey);

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'hermes_runtime' => [
        'default_provider' => 'openai',
        'default_model' => env('HERMES_DEFAULT_MODEL', 'gpt-5.5'),
        'api_key' => $hermesResolvedApiKey,
        'api_base' => $hermesApiBase,
        'users_home' => env('HERMES_USERS_HOME', storage_path('app/hermes-users')),
        'base_home' => env('HERMES_BASE_HOME'),
        'timeout' => (float) env('HERMES_AGENT_TIMEOUT', 120),
    ],

    'hermes_realtime' => [
        'api_key' => env('HERMES_REALTIME_API_KEY') ?: ($hermesApiKey !== '' ? $hermesApiKey : $openAiKey),
        'model' => env('HERMES_REALTIME_MODEL', 'gpt-realtime'),
        'voice' => env('HERMES_REALTIME_VOICE', 'marin'),
    ],

    'beta' => [
        'enabled' => (bool) env('BETA_SIGNUPS_ENABLED', true),
    ],

    'ai_usage' => [
        'reserve_output_tokens' => (int) env('AI_USAGE_RESERVE_OUTPUT_TOKENS', 1200),
        'spike_multiplier' => (float) env('AI_USAGE_SPIKE_MULTIPLIER', 3),
        'spike_min_daily_cost_usd' => (float) env('AI_USAGE_SPIKE_MIN_DAILY_COST_USD', 1.00),
        'pricing_per_million' => [
            'gpt-5.5' => ['input' => 5.00, 'output' => 30.00],
            'gpt-5.4' => ['input' => 2.50, 'output' => 15.00],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
        ],
        'budgets' => [
            'free' => [
                'monthly_ai_actions' => (int) env('AI_FREE_MONTHLY_ACTIONS', 50),
                'monthly_tokens' => (int) env('AI_FREE_MONTHLY_TOKENS', 1_000_000),
                'monthly_cost_usd' => (float) env('AI_FREE_MONTHLY_COST_USD', 5.00),
                'daily_soft_tokens' => (int) env('AI_FREE_DAILY_SOFT_TOKENS', 60_000),
                'daily_hard_tokens' => (int) env('AI_FREE_DAILY_HARD_TOKENS', 180_000),
                'daily_soft_cost_usd' => (float) env('AI_FREE_DAILY_SOFT_COST_USD', 0.35),
                'daily_hard_cost_usd' => (float) env('AI_FREE_DAILY_HARD_COST_USD', 1.00),
            ],
            'mid' => [
                'monthly_ai_actions' => (int) env('AI_MID_MONTHLY_ACTIONS', 1_000),
                'monthly_tokens' => (int) env('AI_MID_MONTHLY_TOKENS', 12_000_000),
                'monthly_cost_usd' => (float) env('AI_MID_MONTHLY_COST_USD', 25.00),
                'daily_soft_tokens' => (int) env('AI_MID_DAILY_SOFT_TOKENS', 400_000),
                'daily_hard_tokens' => (int) env('AI_MID_DAILY_HARD_TOKENS', 1_000_000),
                'daily_soft_cost_usd' => (float) env('AI_MID_DAILY_SOFT_COST_USD', 2.00),
                'daily_hard_cost_usd' => (float) env('AI_MID_DAILY_HARD_COST_USD', 5.00),
            ],
            'pro' => [
                'monthly_ai_actions' => (int) env('AI_PRO_MONTHLY_ACTIONS', 5_000),
                'monthly_tokens' => (int) env('AI_PRO_MONTHLY_TOKENS', 60_000_000),
                'monthly_cost_usd' => (float) env('AI_PRO_MONTHLY_COST_USD', 100.00),
                'daily_soft_tokens' => (int) env('AI_PRO_DAILY_SOFT_TOKENS', 1_500_000),
                'daily_hard_tokens' => (int) env('AI_PRO_DAILY_HARD_TOKENS', 4_000_000),
                'daily_soft_cost_usd' => (float) env('AI_PRO_DAILY_SOFT_COST_USD', 8.00),
                'daily_hard_cost_usd' => (float) env('AI_PRO_DAILY_HARD_COST_USD', 20.00),
            ],
            'admin' => [
                'monthly_ai_actions' => (int) env('AI_ADMIN_MONTHLY_ACTIONS', 50_000),
                'monthly_tokens' => (int) env('AI_ADMIN_MONTHLY_TOKENS', 500_000_000),
                'monthly_cost_usd' => (float) env('AI_ADMIN_MONTHLY_COST_USD', 1_000.00),
                'daily_soft_tokens' => (int) env('AI_ADMIN_DAILY_SOFT_TOKENS', 10_000_000),
                'daily_hard_tokens' => (int) env('AI_ADMIN_DAILY_HARD_TOKENS', 30_000_000),
                'daily_soft_cost_usd' => (float) env('AI_ADMIN_DAILY_SOFT_COST_USD', 50.00),
                'daily_hard_cost_usd' => (float) env('AI_ADMIN_DAILY_HARD_COST_USD', 150.00),
            ],
        ],
    ],

    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    ],

];
