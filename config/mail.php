<?php

declare(strict_types=1);

return [
    'host' => env('MAIL_HOST', 'smtp.example.com'),
    'port' => (int) env('MAIL_PORT', 587),
    'username' => (string) env('MAIL_USERNAME', ''),
    'password' => (string) env('MAIL_PASSWORD', ''),
    'encryption' => env('MAIL_ENCRYPTION', 'tls'),
    'from_address' => env('MAIL_FROM_ADDRESS', 'dts@vvu.edu.gh'),
    'from_name' => env('MAIL_FROM_NAME', 'VVU DTS'),
];
