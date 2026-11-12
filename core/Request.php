<?php

declare(strict_types=1);

namespace Core;

/**
 * The current HTTP request. Input is read-only; only the matched route parameters are set later.
 */
final class Request
{
    /** @var array<string, string> Route parameters set by the router. */
    private array $routeParams = [];

    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, mixed> $server
     */
    public function __construct(
        private readonly string $method,
        private readonly string $path,
        private readonly array $query = [],
        private readonly array $post = [],
        private readonly array $files = [],
        private readonly array $server = [],
    ) {
    }

    /**
     * Build a request from PHP globals. $basePath is the path portion of APP_URL
     * (e.g. "/docs-track") and is stripped so routes are always relative to the app root.
     */
    public static function capture(string $basePath = ''): self
    {
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');

        return new self(
            strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')),
            self::normalisePath($uri, $basePath),
            $_GET,
            $_POST,
            $_FILES,
            $_SERVER,
        );
    }

    /**
     * Convert a raw URI into a clean route path: no query string, no base path,
     * leading slash, no trailing slash (except "/").
     */
    public static function normalisePath(string $uri, string $basePath = ''): string
    {
        $path = rawurldecode((string) (parse_url($uri, PHP_URL_PATH) ?? '/'));
        $basePath = rtrim($basePath, '/');

        if ($basePath !== '' && ($path === $basePath || str_starts_with($path, $basePath . '/'))) {
            $path = substr($path, strlen($basePath));
        }
        if (str_starts_with($path, '/index.php')) {
            $path = substr($path, strlen('/index.php'));
        }

        $path = '/' . trim($path, '/');

        return $path;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    public function isPost(): bool
    {
        return $this->method === 'POST';
    }

    /**
     * A value from the query string.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * A value from the POST body, falling back to the query string.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $this->query[$key] ?? $default;
    }

    /**
     * All POST input, or only the given keys.
     *
     * @param list<string>|null $keys
     * @return array<string, mixed>
     */
    public function all(?array $keys = null): array
    {
        if ($keys === null) {
            return $this->post;
        }

        return array_intersect_key($this->post, array_flip($keys));
    }

    /**
     * An uploaded file entry from $_FILES, or null.
     *
     * @return array<string, mixed>|null
     */
    public function file(string $key): ?array
    {
        $file = $this->files[$key] ?? null;

        return is_array($file) ? $file : null;
    }

    /**
     * @return array<string, mixed>
     */
    public function files(): array
    {
        return $this->files;
    }

    /**
     * A request header (case-insensitive name), or null.
     */
    public function header(string $name): ?string
    {
        $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        $value = $this->server[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * A raw $_SERVER value, or null.
     */
    public function server(string $key): ?string
    {
        $value = $this->server[$key] ?? null;

        return is_scalar($value) ? (string) $value : null;
    }

    public function ip(): string
    {
        return (string) ($this->server['REMOTE_ADDR'] ?? '0.0.0.0');
    }

    public function userAgent(): string
    {
        return mb_substr((string) ($this->server['HTTP_USER_AGENT'] ?? ''), 0, 255);
    }

    public function isAjax(): bool
    {
        return strtolower((string) $this->header('X-Requested-With')) === 'xmlhttprequest'
            || str_contains((string) $this->header('Accept'), 'application/json');
    }

    public function isSecure(): bool
    {
        $https = $this->server['HTTPS'] ?? '';

        return ($https !== '' && $https !== 'off')
            || ($this->server['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    }

    /**
     * Set the parameters captured from the matched route.
     *
     * @param array<string, string> $params
     */
    public function setRouteParams(array $params): void
    {
        $this->routeParams = $params;
    }

    /**
     * A captured route parameter such as {id}.
     */
    public function param(string $key, ?string $default = null): ?string
    {
        return $this->routeParams[$key] ?? $default;
    }

    /**
     * The URL of the previous page, if it belongs to this app.
     */
    public function referer(): ?string
    {
        $referer = $this->header('Referer');
        $appUrl = (string) Config::get('app.url', '');

        return ($referer !== null && $appUrl !== '' && str_starts_with($referer, $appUrl)) ? $referer : null;
    }
}
