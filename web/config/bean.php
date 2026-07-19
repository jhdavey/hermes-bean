<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Bean Runtime
    |--------------------------------------------------------------------------
    |
    | Production Bean chat should run as a thin UI over an isolated Hermes agent
    | for each user. The local runtime remains test-only so automated tests do
    | not require a live model process.
    |
    */

    'runtime_driver' => env('BEAN_RUNTIME_DRIVER', env('APP_ENV') === 'testing' ? 'local' : 'hermes'),

    'hermes' => [
        'binary' => env('BEAN_HERMES_BINARY', 'hermes'),
        'users_path' => env('BEAN_HERMES_USERS_PATH', storage_path('hermes/users')),
        'source' => env('BEAN_HERMES_SOURCE', 'bean'),
        'provider' => env('BEAN_HERMES_PROVIDER', 'openai'),
        'model' => env('BEAN_HERMES_MODEL', env('OPENAI_BEAN_TEXT_MODEL', 'gpt-4.1-mini')),
        'timeout_seconds' => (int) env('BEAN_HERMES_TIMEOUT_SECONDS', 120),
        'max_turns' => (int) env('BEAN_HERMES_MAX_TURNS', 24),
        'toolsets' => env('BEAN_HERMES_TOOLSETS', 'bean_dashboard,skills,memory,session_search,web'),
        'skills' => env('BEAN_HERMES_SKILLS', 'bean-dashboard'),
    ],
];
