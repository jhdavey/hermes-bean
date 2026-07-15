<?php

use App\Services\OpenAiVoiceService;

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
        'semantic_interpretation_model' => env('HERMES_SEMANTIC_INTERPRETATION_MODEL', 'gpt-5.6-luna'),
        'semantic_reasoning_effort' => env('HERMES_SEMANTIC_REASONING_EFFORT', 'none'),
        'semantic_interpretation_timeout' => max(0.1, (float) env('HERMES_SEMANTIC_INTERPRETATION_TIMEOUT', 0.9)),
        'semantic_interpretation_connect_timeout' => max(0.1, (float) env('HERMES_SEMANTIC_INTERPRETATION_CONNECT_TIMEOUT', 0.3)),
        'semantic_composition_timeout' => max(0.1, (float) env('HERMES_SEMANTIC_COMPOSITION_TIMEOUT', 2)),
        'semantic_composition_connect_timeout' => max(0.1, (float) env('HERMES_SEMANTIC_COMPOSITION_CONNECT_TIMEOUT', 0.5)),
        'semantic_interpretation_reserved_output_tokens' => max(1, (int) env('HERMES_SEMANTIC_INTERPRETATION_RESERVED_OUTPUT_TOKENS', 800)),
        'semantic_composition_reserved_output_tokens' => max(1, (int) env('HERMES_SEMANTIC_COMPOSITION_RESERVED_OUTPUT_TOKENS', 300)),
        'api_key' => $openAiPublicKey,
        'api_key_source' => $openAiPublicKey !== '' ? 'OPENAI_PUBLIC_KEY' : null,
        'api_base' => $hermesApiBase,
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
        'osm_places_enabled' => (bool) env('OSM_PLACES_ENABLED', true),
        'osm_photon_base' => env('OSM_PHOTON_BASE', 'https://photon.komoot.io'),
        'osm_places_timeout' => (float) env('OSM_PLACES_TIMEOUT', 5),
        'osm_places_connect_timeout' => (float) env('OSM_PLACES_CONNECT_TIMEOUT', 2),
        'zippopotam_base' => env('ZIPPOPOTAM_BASE', 'https://api.zippopotam.us'),
        'weather_lookup_enabled' => (bool) env('HERMES_WEATHER_LOOKUP_ENABLED', true),
        'weather_lookup_timeout' => (float) env('HERMES_WEATHER_LOOKUP_TIMEOUT', 3),
        'weather_lookup_connect_timeout' => (float) env('HERMES_WEATHER_LOOKUP_CONNECT_TIMEOUT', 1.5),
        'weather_warm_cache_seconds' => (int) env('HERMES_WEATHER_WARM_CACHE_SECONDS', 300),
        'assistant_run_stale_seconds' => (int) env('HERMES_ASSISTANT_RUN_STALE_SECONDS', 210),
        'assistant_run_stale_recovery_attempts' => (int) env('HERMES_ASSISTANT_RUN_STALE_RECOVERY_ATTEMPTS', 1),
        'assistant_run_recovery_window_seconds' => (int) env('HERMES_ASSISTANT_RUN_RECOVERY_WINDOW_SECONDS', 900),
    ],

    'openai' => [
        'server_api_key' => $openAiPublicKey,
        'public_key' => $openAiPublicKey,
        'realtime_model' => env('OPENAI_REALTIME_MODEL', OpenAiVoiceService::DEFAULT_REALTIME_MODEL),
        'realtime_sideband_url' => env('OPENAI_REALTIME_SIDEBAND_URL', 'wss://api.openai.com/v1/realtime'),
        'realtime_reasoning_effort' => env('OPENAI_REALTIME_REASONING_EFFORT', 'low'),
        'realtime_session_timeout' => (float) env('OPENAI_REALTIME_SESSION_TIMEOUT', 10),
        'realtime_vad_threshold' => (float) env('OPENAI_REALTIME_VAD_THRESHOLD', 0.5),
        'realtime_vad_prefix_padding_ms' => (int) env('OPENAI_REALTIME_VAD_PREFIX_PADDING_MS', 300),
        'realtime_vad_silence_duration_ms' => (int) env('OPENAI_REALTIME_VAD_SILENCE_DURATION_MS', 2000),
    ],

    'voice_realtime' => [
        'operation_queue' => env('VOICE_REALTIME_OPERATION_QUEUE', 'voice-high'),
        'lease_seconds' => max(2, (int) env('VOICE_REALTIME_LEASE_SECONDS', 15)),
        'heartbeat_seconds' => max(1, (int) env('VOICE_REALTIME_HEARTBEAT_SECONDS', 5)),
        'connect_timeout_seconds' => max(1, (float) env('VOICE_REALTIME_CONNECT_TIMEOUT_SECONDS', 10)),
        'scan_interval_ms' => max(20, (int) env('VOICE_REALTIME_SCAN_INTERVAL_MS', 100)),
        'command_interval_ms' => max(10, (int) env('VOICE_REALTIME_COMMAND_INTERVAL_MS', 25)),
        'admission_ready_timeout_ms' => max(0, (int) env('VOICE_REALTIME_ADMISSION_READY_TIMEOUT_MS', 1200)),
        'playback_authorization_grace_ms' => max(250, (int) env('VOICE_REALTIME_PLAYBACK_AUTHORIZATION_GRACE_MS', 350)),
        'max_reconnect_attempts' => max(0, (int) env('VOICE_REALTIME_MAX_RECONNECT_ATTEMPTS', 3)),
        'reconnect_delay_ms' => max(0, (int) env('VOICE_REALTIME_RECONNECT_DELAY_MS', 250)),
        'session_batch' => max(1, (int) env('VOICE_REALTIME_SESSION_BATCH', 25)),
        'command_batch' => max(1, (int) env('VOICE_REALTIME_COMMAND_BATCH', 20)),
        'event_batch' => max(1, (int) env('VOICE_REALTIME_EVENT_BATCH', 20)),
        'event_max_attempts' => max(1, (int) env('VOICE_REALTIME_EVENT_MAX_ATTEMPTS', 3)),
        'event_retry_delay_ms' => max(0, (int) env('VOICE_REALTIME_EVENT_RETRY_DELAY_MS', 100)),
        'once_grace_ms' => max(50, (int) env('VOICE_REALTIME_ONCE_GRACE_MS', 500)),
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
        'trial_days' => (int) env('STRIPE_TRIAL_DAYS', 7),
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
        'plan_amounts' => [
            'base' => [
                'monthly' => (float) env('STRIPE_AMOUNT_BASE_MONTHLY', 4.99),
                'yearly' => (float) env('STRIPE_AMOUNT_BASE_YEARLY', 49.99),
            ],
            'premium' => [
                'monthly' => (float) env('STRIPE_AMOUNT_PREMIUM_MONTHLY', 19.99),
                'yearly' => (float) env('STRIPE_AMOUNT_PREMIUM_YEARLY', 199.99),
            ],
            'pro' => [
                'monthly' => (float) env('STRIPE_AMOUNT_PRO_MONTHLY', 49.99),
                'yearly' => (float) env('STRIPE_AMOUNT_PRO_YEARLY', 499.99),
            ],
        ],
    ],

    'beta' => [
        'enabled' => (bool) env('BETA_SIGNUPS_ENABLED', true),
    ],

    'ai_usage' => [
        'realtime_session_minimum_cost_usd' => (float) env('AI_USAGE_REALTIME_SESSION_MINIMUM_COST_USD', 0.001),
        'pricing_per_million' => [
            'gpt-5.6-luna' => ['input' => 1.00, 'output' => 6.00],
            'gpt-5-nano' => ['input' => 0.05, 'output' => 0.40],
            'gpt-5-mini' => ['input' => 0.25, 'output' => 2.00],
            'gpt-5.5' => ['input' => 5.00, 'output' => 30.00],
            'gpt-5.4' => ['input' => 2.50, 'output' => 15.00],
            'gpt-5.4-mini' => ['input' => 0.75, 'output' => 4.50],
        ],
        'realtime_pricing_per_million' => [
            'gpt-realtime-2.1-mini' => [
                'text_input' => 0.60,
                'cached_text_input' => 0.06,
                'audio_input' => 10.00,
                'cached_audio_input' => 0.30,
                'text_output' => 2.40,
                'audio_output' => 20.00,
            ],
            'gpt-realtime-2.1' => [
                'text_input' => 4.00,
                'cached_text_input' => 0.40,
                'audio_input' => 32.00,
                'cached_audio_input' => 0.40,
                'text_output' => 24.00,
                'audio_output' => 64.00,
            ],
            'gpt-realtime' => [
                'text_input' => 4.00,
                'cached_text_input' => 0.40,
                'audio_input' => 32.00,
                'cached_audio_input' => 0.40,
                'text_output' => 16.00,
                'audio_output' => 64.00,
            ],
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
