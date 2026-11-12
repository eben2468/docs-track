<?php

declare(strict_types=1);

namespace Core;

/**
 * Contract for route middleware.
 *
 * A middleware either returns a Response (short-circuit), throws an HttpException,
 * or calls $next($request) to continue the chain. Extra arguments from the route
 * definition (e.g. "role:admin,hod") are passed in $args.
 */
interface Middleware
{
    /**
     * @param callable(Request): Response $next
     * @param list<string> $args
     */
    public function handle(Request $request, callable $next, array $args = []): Response;
}
