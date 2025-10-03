<?php

return [
    'paths' => ['api/*', 'login', 'register', 'agent-profile', 'me', 'internal/*', 'sanctum/csrf-cookie'],
    'allowed_methods' => ['*'],
    'allowed_origins' => [
        'https://askaria.fyi',
        'https://www.askaria.fyi',
        'http://localhost:5175',
        'http://127.0.0.1:5175',
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => [
        'Accept',
        'Authorization',
        'Content-Type',
        'Origin',
        'X-CSRF-TOKEN',
        'X-Requested-With',
        'X-XSRF-TOKEN',
    ],
    'exposed_headers' => ['Authorization'],
    'max_age' => 0,
    'supports_credentials' => true,
];
