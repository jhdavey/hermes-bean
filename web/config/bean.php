<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bean Runtime
    |--------------------------------------------------------------------------
    |
    | Production Bean chat runs as a thin UI over an isolated Hermes agent for
    | each user. Laravel remains the scoped dashboard tool host and safety
    | boundary; it does not run a separate local deterministic assistant.
    |
    */

    'hermes' => [
        'binary' => env('BEAN_HERMES_BINARY', 'hermes'),
        'users_path' => env('BEAN_HERMES_USERS_PATH', storage_path('hermes/users')),
        'source' => env('BEAN_HERMES_SOURCE', 'bean'),
        'provider' => env('BEAN_HERMES_PROVIDER', 'custom'),
        'model' => env('BEAN_HERMES_MODEL', env('OPENAI_BEAN_TEXT_MODEL', 'gpt-4.1-mini')),
        'base_url' => env('BEAN_HERMES_BASE_URL', 'https://api.openai.com/v1'),
        'timeout_seconds' => (int) env('BEAN_HERMES_TIMEOUT_SECONDS', 120),
        'max_turns' => (int) env('BEAN_HERMES_MAX_TURNS', 24),
        'voice_max_turns' => (int) env('BEAN_HERMES_VOICE_MAX_TURNS', 10),
        'toolsets' => env('BEAN_HERMES_TOOLSETS', 'bean_dashboard,skills,memory,session_search,web'),
        'voice_toolsets' => env('BEAN_HERMES_VOICE_TOOLSETS', 'bean_dashboard,skills'),
        'skills' => env('BEAN_HERMES_SKILLS', 'bean-dashboard'),
        'php_binary' => env('BEAN_HERMES_PHP_BINARY', 'php'),
    ],
];
