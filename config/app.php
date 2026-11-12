<?php

declare(strict_types=1);

return [
    'name' => env('APP_NAME', 'VVU Document Tracking System'),
    'env' => env('APP_ENV', 'production'),
    'debug' => (bool) env('APP_DEBUG', false),
    'url' => rtrim((string) env('APP_URL', 'http://localhost:8000'), '/'),
    'timezone' => env('APP_TIMEZONE', 'Africa/Accra'),

    'session' => [
        'name' => 'vvu_dts_session',
        // Idle timeout in minutes (FR-1).
        'lifetime_minutes' => (int) env('SESSION_LIFETIME_MINUTES', 30),
        // Secure cookies are forced in production; also enabled whenever APP_URL is https.
        'secure' => env('APP_ENV', 'production') === 'production'
            || str_starts_with((string) env('APP_URL', ''), 'https://'),
        'same_site' => 'Lax',
    ],

    'log_path' => 'storage/logs/app.log',
];
