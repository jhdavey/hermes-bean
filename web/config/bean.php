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
        'toolsets' => env('BEAN_HERMES_TOOLSETS', 'bean_dashboard,skills,memory,session_search,web'),
        'skills' => env('BEAN_HERMES_SKILLS', 'bean-dashboard'),
        'php_binary' => env('BEAN_HERMES_PHP_BINARY', 'php'),
    ],

    'landing' => [
        'visitors_path' => env('BEAN_LANDING_HERMES_VISITORS_PATH', storage_path('hermes/landing-visitors')),
        'source' => env('BEAN_LANDING_HERMES_SOURCE', 'bean-landing'),
        'provider' => env('BEAN_LANDING_HERMES_PROVIDER', env('BEAN_HERMES_PROVIDER', 'custom')),
        'model' => env('BEAN_LANDING_HERMES_MODEL', 'gpt-4.1-nano'),
        'base_url' => env('BEAN_LANDING_HERMES_BASE_URL', env('BEAN_HERMES_BASE_URL', 'https://api.openai.com/v1')),
        'timeout_seconds' => (int) env('BEAN_LANDING_HERMES_TIMEOUT_SECONDS', 25),
        'retention_hours' => (int) env('BEAN_LANDING_HERMES_RETENTION_HOURS', 24),
        'max_visitor_turns' => (int) env('BEAN_LANDING_MAX_VISITOR_TURNS', 20),
        'sessions_per_hour' => (int) env('BEAN_LANDING_SESSIONS_PER_HOUR', 3),
        'sessions_per_day' => (int) env('BEAN_LANDING_SESSIONS_PER_DAY', 6),
        'ip_sessions_per_hour' => (int) env('BEAN_LANDING_IP_SESSIONS_PER_HOUR', 12),
        'ip_sessions_per_day' => (int) env('BEAN_LANDING_IP_SESSIONS_PER_DAY', 30),
        'global_sessions_per_minute' => (int) env('BEAN_LANDING_GLOBAL_SESSIONS_PER_MINUTE', 12),
        'global_sessions_per_day' => (int) env('BEAN_LANDING_GLOBAL_SESSIONS_PER_DAY', 150),
        'messages_per_hour' => (int) env('BEAN_LANDING_MESSAGES_PER_HOUR', 80),
        'messages_per_day' => (int) env('BEAN_LANDING_MESSAGES_PER_DAY', 160),
    ],

    'usage' => [
        'elevenlabs_max_duration_seconds' => (int) env('ELEVENLABS_MAX_DURATION_SECONDS', 60),
        'elevenlabs_silence_timeout_seconds' => (int) env('ELEVENLABS_SILENCE_TIMEOUT_SECONDS', 5),
        'elevenlabs_agent_cost_per_minute_usd' => (float) env('ELEVENLABS_AGENT_COST_PER_MINUTE_USD', 0.08),
        'elevenlabs_agent_credits_per_minute' => (float) env('ELEVENLABS_AGENT_CREDITS_PER_MINUTE', 10000 / 15),
        'openai_model_prices' => [
            'gpt-4.1-mini' => [
                'input_per_1m' => (float) env('OPENAI_GPT_4_1_MINI_INPUT_PER_1M_USD', 0.40),
                'output_per_1m' => (float) env('OPENAI_GPT_4_1_MINI_OUTPUT_PER_1M_USD', 1.60),
            ],
            'gpt-4.1-nano' => [
                'input_per_1m' => (float) env('OPENAI_GPT_4_1_NANO_INPUT_PER_1M_USD', 0.10),
                'output_per_1m' => (float) env('OPENAI_GPT_4_1_NANO_OUTPUT_PER_1M_USD', 0.40),
            ],
            'gpt-4.1' => [
                'input_per_1m' => (float) env('OPENAI_GPT_4_1_INPUT_PER_1M_USD', 2.00),
                'output_per_1m' => (float) env('OPENAI_GPT_4_1_OUTPUT_PER_1M_USD', 8.00),
            ],
        ],
    ],
];
