<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Auth;
use Core\HttpException;
use Core\Middleware;
use Core\Request;
use Core\Response;

/**
 * Restricts a route to the roles given as arguments, e.g. "role:admin" or "role:admin,hod".
 * Must run after AuthMiddleware.
 */
final class RoleMiddleware implements Middleware
{
    /**
     * @param callable(Request): Response $next
     * @param list<string> $args Allowed roles
     */
    public function handle(Request $request, callable $next, array $args = []): Response
    {
        if ($args === [] || !Auth::hasRole(...$args)) {
            throw new HttpException(403);
        }

        return $next($request);
    }
}
