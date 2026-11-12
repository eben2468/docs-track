<?php

declare(strict_types=1);

namespace Core;

/**
 * Per-session CSRF token (synchronizer token pattern).
 */
final class Csrf
{
    public const FIELD = '_token';
    public const HEADER = 'X-CSRF-Token';
    private const SESSION_KEY = '_csrf_token';

    /**
     * The current session's token, generated on first use.
     */
    public static function token(): string
    {
        $token = Session::get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put(self::SESSION_KEY, $token);
        }

        return $token;
    }

    /**
     * Constant-time comparison of a submitted token against the session token.
     */
    public static function verify(?string $token): bool
    {
        $expected = Session::get(self::SESSION_KEY);

        return is_string($expected) && $expected !== '' && is_string($token)
            && hash_equals($expected, $token);
    }

    /**
     * Replace the token (call after login so a pre-login token cannot be reused).
     */
    public static function regenerate(): void
    {
        Session::put(self::SESSION_KEY, bin2hex(random_bytes(32)));
    }
}
