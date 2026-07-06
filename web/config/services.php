<?php

$openAiPublicKey = (string) env('OPENAI_PUBLIC_KEY', '');
$hermesApiBase = env('HERMES_API_BASE') ?: 'https://api.openai.com/v1';

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
        'default_model' => env('HERMES_DEFAULT_MODEL', 'gpt-5-mini'),
        'crud_planner_enabled' => env('HERMES_CRUD_PLANNER_ENABLED', true),
        'crud_planner_model' => env('HERMES_CRUD_PLANNER_MODEL', 'gpt-5-nano'),
        'crud_planner_timeout' => (float) env('HERMES_CRUD_PLANNER_TIMEOUT', 20),
        'api_key' => $openAiPublicKey,
        'api_key_source' => $openAiPublicKey !== '' ? 'OPENAI_PUBLIC_KEY' : null,
        'api_base' => $hermesApiBase,
        'cli_path' => env('HERMES_CLI_PATH', 'hermes'),
        'cli_workdir' => env('HERMES_CLI_WORKDIR', base_path()),
        'cli_timeout' => (float) env('HERMES_CLI_TIMEOUT', 120),
        'update_timeout' => (float) env('HERMES_UPDATE_TIMEOUT', 600),
        'users_home' => env('HERMES_USERS_HOME', storage_path('app/hermes-users')),
        'base_home' => env('HERMES_BASE_HOME'),
        'timeout' => (float) env('HERMES_AGENT_TIMEOUT', 120),
        'external_lookup_model' => env('HERMES_EXTERNAL_LOOKUP_MODEL', 'gpt-5-mini'),
        'external_lookup_timeout' => (float) env('HERMES_EXTERNAL_LOOKUP_TIMEOUT', 8),
        'external_lookup_connect_timeout' => (float) env('HERMES_EXTERNAL_LOOKUP_CONNECT_TIMEOUT', 3),
        'external_lookup_attempts' => max(1, (int) env('HERMES_EXTERNAL_LOOKUP_ATTEMPTS', 1)),
        'external_lookup_tool' => env('HERMES_EXTERNAL_LOOKUP_TOOL', 'web_search'),
        'live_lookup_cache_seconds' => (int) env('HERMES_LIVE_LOOKUP_CACHE_SECONDS', 300),
        'tavily_search_enabled' => (bool) env('TAVILY_SEARCH_ENABLED', true),
        'tavily_api_key' => env('TAVILY_API_KEY', ''),
        'tavily_search_depth' => env('TAVILY_SEARCH_DEPTH', 'ultra-fast'),
        'tavily_search_timeout' => (float) env('TAVILY_SEARCH_TIMEOUT', 6),
        'tavily_search_connect_timeout' => (float) env('TAVILY_SEARCH_CONNECT_TIMEOUT', 2),
        'google_places_enabled' => (bool) env('GOOGLE_PLACES_ENABLED', true),
        'google_maps_api_key' => env('GOOGLE_MAPS_API_KEY', ''),
        'google_places_radius_meters' => (int) env('GOOGLE_PLACES_RADIUS_METERS', 50000),
        'google_places_timeout' => (float) env('GOOGLE_PLACES_TIMEOUT', 6),
        'google_places_connect_timeout' => (float) env('GOOGLE_PLACES_CONNECT_TIMEOUT', 2),
        'weather_lookup_enabled' => (bool) env('HERMES_WEATHER_LOOKUP_ENABLED', true),
        'weather_lookup_timeout' => (float) env('HERMES_WEATHER_LOOKUP_TIMEOUT', 6),
        'weather_lookup_connect_timeout' => (float) env('HERMES_WEATHER_LOOKUP_CONNECT_TIMEOUT', 3),
        'weather_warm_cache_seconds' => (int) env('HERMES_WEATHER_WARM_CACHE_SECONDS', 300),
        'assistant_run_stale_seconds' => (int) env('HERMES_ASSISTANT_RUN_STALE_SECONDS', 210),
        'assistant_run_recovery_window_seconds' => (int) env('HERMES_ASSISTANT_RUN_RECOVERY_WINDOW_SECONDS', 900),
    ],

    'hermes_realtime' => [
        'api_key' => $openAiPublicKey,
        'model' => env('HERMES_REALTIME_MODEL', 'gpt-realtime-mini'),
        'voice' => env('HERMES_REALTIME_VOICE', 'marin'),
    ],

    'openai' => [
        'server_api_key' => $openAiPublicKey,
        'public_key' => $openAiPublicKey,
    ],

    'firebase' => [
        'project_id' => env('FIREBASE_PROJECT_ID'),
        'credentials_path' => env('FIREBASE_CREDENTIALS_PATH'),
        'credentials_json' => env('FIREBASE_CREDENTIALS_JSON'),
    ],

    'stripe' => [
        'secret' => env('STRIPE_SECRET'),
        'publishable_key' => env('STRIPE_PUBLISHABLE_KEY'),
        'api_version' => env('STRIPE_API_VERSION', '2026-05-27.dahlia'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),
        'trial_days' => (int) env('STRIPE_TRIAL_DAYS', 14),
        'prices' => [
            'base' => [
                'monthly' => env('STRIPE_PRICE_BASE_MONTHLY', env('STRIPE_PRICE_BASE')),
                'yearly' => env('STRIPE_PRICE_BASE_YEARLY'),
            ],
            'premium' => [
                'monthly' => env('STRIPE_PRICE_PREMIUM_MONTHLY', env('STRIPE_PRICE_PREMIUM')),
                'yearly' => env('STRIPE_PRICE_PREMIUM_YEARLY'),
            ],
            'pro' => [
                'monthly' => env('STRIPE_PRICE_PRO_MONTHLY', env('STRIPE_PRICE_PRO')),
                'yearly' => env('STRIPE_PRICE_PRO_YEARLY'),
            ],
        ],
    ],

    'beta' => [
        'enabled' => (bool) env('BETA_SIGNUPS_ENABLED', true),
    ],

    'ai_usage' => [
        'reserve_output_tokens' => (int) env('AI_USAGE_RESERVE_OUTPUT_TOKENS', 1200),
        'spike_multiplier' => (float) env('AI_USAGE_SPIKE_MULTIPLIER', 3),
        'spike_min_daily_cost_usd' => (float) env('AI_USAGE_SPIKE_MIN_DAILY_COST_USD', 1.00),
        'pricing_per_million' => [
            'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
            'gpt-realtime-mini' => ['input' => 0.60, 'output' => 2.40, 'audio_input' => 10.00, 'audio_output' => 20.00],
            'gpt-realtime' => ['input' => 4.00, 'output' => 16.00, 'audio_input' => 32.00, 'audio_output' => 64.00],
            'gpt-4o-mini-tts' => ['input' => 0.60, 'output' => 12.00],
            'gpt-5.5' => ['input' => 5.00, 'output' => 30.00],
            'gpt-5.4' => ['input' => 2.50, 'output' => 15.00],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
        ],
        'limits' => [
            'base_cost_limit' => (float) env('AI_BASE_COST_LIMIT', 1.00),
            'base_external_cost_limit' => (float) env('AI_BASE_EXTERNAL_COST_LIMIT', 0.25),
            'premium_cost_limit' => (float) env('AI_PREMIUM_COST_LIMIT', 5.00),
            'premium_external_cost_limit' => (float) env('AI_PREMIUM_EXTERNAL_COST_LIMIT', 1.00),
            'pro_cost_limit' => (float) env('AI_PRO_COST_LIMIT', 20.00),
            'pro_external_cost_limit' => (float) env('AI_PRO_EXTERNAL_COST_LIMIT', 5.00),
        ],
    ],

    'google_calendar' => [
        'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
        'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI'),
    ],

    'microsoft_outlook' => [
        'client_id' => env('MICROSOFT_OUTLOOK_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_OUTLOOK_CLIENT_SECRET'),
        'redirect_uri' => env('MICROSOFT_OUTLOOK_REDIRECT_URI'),
    ],

];
