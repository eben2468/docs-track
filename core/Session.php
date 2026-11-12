<?php

declare(strict_types=1);

namespace Core;

/**
 * Session wrapper: secure cookie settings, idle timeout, and one-request flash data.
 *
 * Flash data written during request N is readable during request N+1, then removed.
 */
final class Session
{
    private const FLASH_NEW = '_flash_new';
    private const FLASH_OLD = '_flash_old';
    private const LAST_ACTIVITY = '_last_activity';

    /**
     * Start the session with hardened cookie parameters and enforce the idle timeout.
     *
     * @param array<string, mixed> $config Keys from config('app.session')
     */
    public static function start(array $config): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');

        session_name((string) ($config['name'] ?? 'app_session'));
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => (bool) ($config['secure'] ?? false),
            'httponly' => true,
            'samesite' => (string) ($config['same_site'] ?? 'Lax'),
        ]);
        session_start();

        self::enforceIdleTimeout((int) ($config['lifetime_minutes'] ?? 30));
        self::ageFlashData();
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function has(string $key): bool
    {
        return isset($_SESSION[$key]);
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    /**
     * Issue a new session ID (call on login and privilege changes).
     */
    public static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    /**
     * Clear all data and issue a new session ID, keeping the session usable (for flash messages).
     */
    public static function invalidate(): void
    {
        $_SESSION = [self::FLASH_NEW => [], self::FLASH_OLD => []];
        self::regenerate();
    }

    /**
     * Clear all data and destroy the session and its cookie.
     */
    public static function destroy(): void
    {
        $_SESSION = [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }

        $params = session_get_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $params['path'],
            'secure' => $params['secure'],
            'httponly' => $params['httponly'],
            'samesite' => $params['samesite'],
        ]);
        session_destroy();
    }

    /**
     * Store a value for the next request only.
     */
    public static function flash(string $key, mixed $value): void
    {
        $_SESSION[self::FLASH_NEW][$key] = $value;
    }

    /**
     * Read a flash value set during the previous request.
     */
    public static function getFlash(string $key, mixed $default = null): mixed
    {
        return $_SESSION[self::FLASH_OLD][$key] ?? $default;
    }

    /**
     * Append a user-facing message (success, danger, warning, info) for the next request.
     */
    public static function addMessage(string $type, string $message): void
    {
        $_SESSION[self::FLASH_NEW]['_messages'][] = ['type' => $type, 'message' => $message];
    }

    /**
     * Messages to show on this request: those flashed by the previous request, plus any
     * added during this request (so a page rendered without a redirect still shows them).
     *
     * @return list<array{type: string, message: string}>
     */
    public static function messages(): array
    {
        $messages = array_merge(
            $_SESSION[self::FLASH_OLD]['_messages'] ?? [],
            $_SESSION[self::FLASH_NEW]['_messages'] ?? []
        );
        unset($_SESSION[self::FLASH_OLD]['_messages'], $_SESSION[self::FLASH_NEW]['_messages']);

        return $messages;
    }

    private static function enforceIdleTimeout(int $minutes): void
    {
        $now = time();
        $last = $_SESSION[self::LAST_ACTIVITY] ?? null;

        if (is_int($last) && $minutes > 0 && ($now - $last) > $minutes * 60) {
            $wasLoggedIn = isset($_SESSION[Auth::SESSION_KEY]);
            $_SESSION = [];
            session_regenerate_id(true);

            if ($wasLoggedIn) {
                $_SESSION[self::FLASH_NEW]['_messages'][] = [
                    'type' => 'warning',
                    'message' => 'Your session expired after a period of inactivity. Please sign in again.',
                ];
            }
        }

        $_SESSION[self::LAST_ACTIVITY] = $now;
    }

    private static function ageFlashData(): void
    {
        $_SESSION[self::FLASH_OLD] = $_SESSION[self::FLASH_NEW] ?? [];
        $_SESSION[self::FLASH_NEW] = [];
    }
}
