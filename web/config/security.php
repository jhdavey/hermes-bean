<?php

return [
    'cors' => [
        'allowed_origins' => array_values(array_filter(array_map(
            'trim',
            explode(',', env('APP_ALLOWED_ORIGINS', env('APP_URL', '')))
        ))),
    ],

    'rate_limits' => [
        'api_per_minute' => env('API_RATE_LIMIT_PER_MINUTE', 60),
        'decay_seconds' => env('API_RATE_LIMIT_DECAY_SECONDS', 60),
    ],

    'api_token_ttl_days' => (int) env('API_TOKEN_TTL_DAYS', 90),
];
