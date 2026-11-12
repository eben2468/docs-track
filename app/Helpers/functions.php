<?php

declare(strict_types=1);

/*
 * Global helpers available in controllers, services and views.
 */

use Core\App;
use Core\Auth;
use Core\Config;
use Core\Csrf;
use Core\HttpException;
use Core\Response;
use Core\Session;
use Core\View;

if (!function_exists('env')) {
    /**
     * Read an environment variable, converting "true"/"false"/"null"/"empty" strings.
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

if (!function_exists('e')) {
    /**
     * Escape a value for safe HTML output.
     */
    function e(mixed $value): string
    {
        if ($value === null || is_bool($value)) {
            return '';
        }

        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('config')) {
    /**
     * Get a configuration value using dot notation, e.g. config('app.name').
     */
    function config(string $key, mixed $default = null): mixed
    {
        return Config::get($key, $default);
    }
}

if (!function_exists('url')) {
    /**
     * Absolute URL for an app path, with an optional query string.
     *
     * @param array<string, scalar|null> $query
     */
    function url(string $path = '/', array $query = []): string
    {
        $url = rtrim((string) Config::get('app.url', ''), '/') . '/' . ltrim($path, '/');
        $url = $path === '/' || $path === '' ? rtrim($url, '/') . '/' : $url;

        $query = array_filter($query, static fn ($v): bool => $v !== null && $v !== '');
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        return $url;
    }
}

if (!function_exists('asset')) {
    /**
     * URL of a file in public/assets, with a cache-busting version from its modification time.
     */
    function asset(string $path): string
    {
        $path = ltrim($path, '/');
        $file = base_path('public/assets/' . $path);
        $version = is_file($file) ? '?v=' . filemtime($file) : '';

        return url('assets/' . $path) . $version;
    }
}

if (!function_exists('base_path')) {
    /**
     * Absolute path inside the project root.
     */
    function base_path(string $path = ''): string
    {
        return App::instance()->basePath($path);
    }
}

if (!function_exists('storage_path')) {
    /**
     * Absolute path inside storage/.
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path === '' ? '' : '/' . ltrim($path, '/')));
    }
}

if (!function_exists('redirect')) {
    /**
     * Redirect response to an app path or absolute URL.
     */
    function redirect(string $to): Response
    {
        return Response::redirect($to);
    }
}

if (!function_exists('view')) {
    /**
     * Render a view inside a layout and return the HTML.
     *
     * @param array<string, mixed> $data
     */
    function view(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        return View::render($view, $data, $layout);
    }
}

if (!function_exists('partial')) {
    /**
     * Render a partial template, e.g. partial('partials/pagination', [...]).
     *
     * @param array<string, mixed> $data
     */
    function partial(string $view, array $data = []): string
    {
        return View::partial($view, $data);
    }
}

if (!function_exists('abort')) {
    /**
     * Stop the request with an HTTP error page.
     */
    function abort(int $status, string $message = ''): never
    {
        throw new HttpException($status, $message);
    }
}

if (!function_exists('old')) {
    /**
     * Previously submitted input (after a failed validation redirect).
     */
    function old(string $key, mixed $default = ''): mixed
    {
        $old = Session::getFlash('_old_input', []);

        return is_array($old) && array_key_exists($key, $old) ? $old[$key] : $default;
    }
}

if (!function_exists('errors')) {
    /**
     * All validation errors from the previous request (field => messages).
     *
     * @return array<string, list<string>>
     */
    function errors(): array
    {
        $errors = Session::getFlash('_errors', []);

        return is_array($errors) ? $errors : [];
    }
}

if (!function_exists('error')) {
    /**
     * The first validation error for a field, or null.
     */
    function error(string $field): ?string
    {
        return errors()[$field][0] ?? null;
    }
}

if (!function_exists('csrf_token')) {
    function csrf_token(): string
    {
        return Csrf::token();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Hidden input carrying the CSRF token; include in every POST form.
     */
    function csrf_field(): string
    {
        return '<input type="hidden" name="' . Csrf::FIELD . '" value="' . e(Csrf::token()) . '">';
    }
}

if (!function_exists('flash')) {
    /**
     * Queue a message (success, danger, warning, info) to show on the next page.
     */
    function flash(string $type, string $message): void
    {
        Session::addMessage($type, $message);
    }
}

if (!function_exists('auth')) {
    /**
     * The logged-in user's row, or null for guests.
     *
     * @return array<string, mixed>|null
     */
    function auth(): ?array
    {
        return Auth::user();
    }
}

if (!function_exists('now')) {
    /**
     * Current time in the application timezone (Africa/Accra).
     */
    function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now', new DateTimeZone((string) Config::get('app.timezone', 'Africa/Accra')));
    }
}
