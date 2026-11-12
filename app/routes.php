<?php

declare(strict_types=1);

use App\Controllers\HomeController;
use App\Middleware\CsrfMiddleware;
use Core\Router;

/*
 * Route table. Middleware aliases are registered here and grouped under "web",
 * which every browser route uses (CSRF is checked on all POST requests).
 */
return static function (Router $router): void {
    $router->aliasMiddleware('csrf', CsrfMiddleware::class);
    $router->middlewareGroup('web', ['csrf']);

    $router->group(['middleware' => ['web']], static function (Router $router): void {
        $router->get('/', [HomeController::class, 'index']);
    });
};
