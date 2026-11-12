<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Auth;
use Core\Middleware;
use Core\Request;
use Core\Response;

/**
 * Pages for guests only (login, password reset): logged-in users go to the dashboard.
 */
final class GuestMiddleware implements Middleware
{
    /**
     * @param callable(Request): Response $next
     * @param list<string> $args
     */
    public function handle(Request $request, callable $next, array $args = []): Response
    {
        return Auth::check() ? Response::redirect('/') : $next($request);
    }
}
