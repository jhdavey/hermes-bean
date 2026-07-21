<?php

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

    'places' => [
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

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'bean_text_model' => env('OPENAI_BEAN_TEXT_MODEL', 'gpt-4.1-mini'),
        'bean_reasoning_model' => env('OPENAI_BEAN_REASONING_MODEL', 'gpt-4.1'),
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'agent_id' => env('ELEVENLABS_AGENT_ID'),
        'agent_environment' => env('ELEVENLABS_AGENT_ENVIRONMENT'),
        'agent_branch_id' => env('ELEVENLABS_AGENT_BRANCH_ID'),
        'agent_enabled' => (bool) env('ELEVENLABS_AGENT_ENABLED', false),
        'landing_agent_id' => env('ELEVENLABS_LANDING_AGENT_ID'),
        'landing_agent_environment' => env('ELEVENLABS_LANDING_AGENT_ENVIRONMENT'),
        'landing_agent_branch_id' => env('ELEVENLABS_LANDING_AGENT_BRANCH_ID'),
    ],

    'turnstile' => [
        'site_key' => env('CLOUDFLARE_TURNSTILE_SITE_KEY'),
        'secret_key' => env('CLOUDFLARE_TURNSTILE_SECRET_KEY'),
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
