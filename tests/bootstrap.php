<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Minimal app context for unit tests: no .env, no database, no HTTP session.
new Core\App(dirname(__DIR__));

Core\Config::set([
    'app' => [
        'name' => 'VVU DTS (test)',
        'env' => 'testing',
        'debug' => true,
        'url' => 'http://localhost:8000',
        'timezone' => 'Africa/Accra',
    ],
]);
Core\View::setPath(dirname(__DIR__) . '/app/Views');
date_default_timezone_set('Africa/Accra');

$_SESSION = [];
