<?php

declare(strict_types=1);

namespace Core;

/**
 * Read access to the currently authenticated user.
 *
 * The session stores only the user's ID; the user row is loaded once per request.
 * Logging in and out (with throttling and lockout) is handled by the auth service.
 */
final class Auth
{
    public const SESSION_KEY = 'auth_user_id';

    /** @var array<string, mixed>|null */
    private static ?array $user = null;

    private static bool $loaded = false;

    /**
     * ID of the logged-in user, or null for guests.
     */
    public static function id(): ?int
    {
        $id = Session::get(self::SESSION_KEY);

        return is_int($id) ? $id : null;
    }

    public static function check(): bool
    {
        return self::user() !== null;
    }

    public static function guest(): bool
    {
        return !self::check();
    }

    /**
     * The logged-in, active user's row (without the password hash), or null.
     *
     * @return array<string, mixed>|null
     */
    public static function user(): ?array
    {
        if (self::$loaded) {
            return self::$user;
        }

        self::$loaded = true;
        $id = self::id();
        if ($id === null) {
            return null;
        }

        $user = Database::instance()->fetch(
            'SELECT id, staff_id, full_name, email, phone, role, department_id, is_active
             FROM users WHERE id = ? AND is_active = 1',
            [$id]
        );

        return self::$user = $user;
    }

    /**
     * Mark a user as logged in: new session ID and CSRF token (prevents fixation).
     * Credential checks, throttling and auditing happen in AuthService.
     */
    public static function login(int $userId): void
    {
        Session::regenerate();
        Csrf::regenerate();
        Session::put(self::SESSION_KEY, $userId);
        self::forgetCachedUser();
    }

    /**
     * Log out: clear all session data and issue a new session ID. The session stays
     * active so a flash message can still be shown on the next page.
     */
    public static function logout(): void
    {
        Session::invalidate();
        self::forgetCachedUser();
    }

    /**
     * Reload the user row on next access (after profile or role changes).
     */
    public static function forgetCachedUser(): void
    {
        self::$user = null;
        self::$loaded = false;
    }

    /**
     * Whether the logged-in user has one of the given roles.
     */
    public static function hasRole(string ...$roles): bool
    {
        $user = self::user();

        return $user !== null && in_array($user['role'], $roles, true);
    }
}
