<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Csrf;
use Core\HttpException;
use Core\Middleware;
use Core\Request;
use Core\Response;

/**
 * Rejects state-changing requests that lack a valid CSRF token (HTTP 419).
 *
 * The token is read from the "_token" form field or the "X-CSRF-Token" header (AJAX).
 */
final class CsrfMiddleware implements Middleware
{
    /**
     * @param callable(Request): Response $next
     * @param list<string> $args
     */
    public function handle(Request $request, callable $next, array $args = []): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $token = $request->input(Csrf::FIELD) ?? $request->header(Csrf::HEADER);

        if (!Csrf::verify(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Your session has expired or the form is no longer valid. Please go back, refresh the page and try again.');
        }

        return $next($request);
    }
}
