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

        // PHP discards the whole body when it exceeds post_max_size, which would look like a CSRF failure.
        if ($request->all() === [] && $request->files() === [] && self::bodyTooLarge($request)) {
            throw new HttpException(413, 'The upload is larger than the server allows. Please choose smaller files and try again.');
        }

        $token = $request->input(Csrf::FIELD) ?? $request->header(Csrf::HEADER);

        if (!Csrf::verify(is_string($token) ? $token : null)) {
            throw new HttpException(419, 'Your session has expired or the form is no longer valid. Please go back, refresh the page and try again.');
        }

        return $next($request);
    }

    private static function bodyTooLarge(Request $request): bool
    {
        $length = (int) ($request->server('CONTENT_LENGTH') ?? 0);
        $limit = trim((string) ini_get('post_max_size'));
        $bytes = (int) $limit;
        $bytes *= match (strtolower(substr($limit, -1))) {
            'g' => 1024 ** 3,
            'm' => 1024 ** 2,
            'k' => 1024,
            default => 1,
        };

        return $bytes > 0 && $length > $bytes;
    }
}
