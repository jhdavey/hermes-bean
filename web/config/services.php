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

    'hermes_runtime' => [
        'mode' => env('HERMES_RUNTIME_MODE', 'stub'),
        'default_provider' => env('HERMES_DEFAULT_PROVIDER', 'openrouter'),
        'default_model' => env('HERMES_DEFAULT_MODEL', 'gpt-5.5'),
        'router_mode' => env('HERMES_ROUTER_MODE', 'fixed'),
        'users_home' => env('HERMES_USERS_HOME', storage_path('app/hermes-users')),
        'base_home' => env('HERMES_BASE_HOME'),
        'cli_path' => env('HERMES_CLI_PATH'),
        'timeout' => (float) env('HERMES_CLI_TIMEOUT', 30),
        'workdir' => env('HERMES_CLI_WORKDIR'),
        'profile' => env('HERMES_CLI_PROFILE'),
        'environment' => [],
    ],

];
