<?php

declare(strict_types=1);

/*
 * Front controller: every web request enters here.
 * Only this directory is web-accessible.
 */

$basePath = dirname(__DIR__);

if (!is_file($basePath . '/vendor/autoload.php')) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=UTF-8');
    echo "Dependencies are not installed. Run `composer install` in the project root.\n";
    exit;
}

require $basePath . '/vendor/autoload.php';

(new Core\App($basePath))->bootstrap()->run();
