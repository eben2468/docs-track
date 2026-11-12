<?php

declare(strict_types=1);

namespace App\Middleware;

use Core\Auth;
use Core\Middleware;
use Core\Request;
use Core\Response;
use Core\Session;

/**
 * Only logged-in, active users may continue. Guests are sent to /login and returned
 * to the page they wanted afterwards; AJAX requests get a 401 JSON response.
 */
final class AuthMiddleware implements Middleware
{
    public const INTENDED_KEY = 'url.intended';

    /**
     * @param callable(Request): Response $next
     * @param list<string> $args
     */
    public function handle(Request $request, callable $next, array $args = []): Response
    {
        if (Auth::check()) {
            return $next($request);
        }

        // A session that points at a deactivated user is ended.
        if (Auth::id() !== null) {
            Auth::logout();
            Session::addMessage('warning', 'Your account is no longer active. Please contact the system administrator.');
        }

        if ($request->isAjax()) {
            return Response::json(['error' => 'Your session has ended. Please sign in again.'], 401);
        }

        if ($request->method() === 'GET') {
            Session::put(self::INTENDED_KEY, $request->path());
        }

        return Response::redirect('/login');
    }
}
