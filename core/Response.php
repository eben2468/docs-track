<?php

declare(strict_types=1);

namespace Core;

/**
 * HTTP response: status, headers and body, plus redirect helpers that carry
 * validation errors, old input and flash messages to the next request.
 */
final class Response
{
    /** @var array<string, string> */
    private array $headers = [];

    /** File streamed as the body instead of $body (see file()). */
    private ?string $filePath = null;

    public function __construct(private string $body = '', private int $status = 200)
    {
    }

    /**
     * An HTML response.
     */
    public static function html(string $body, int $status = 200): self
    {
        return (new self($body, $status))->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /**
     * A JSON response.
     */
    public static function json(mixed $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return (new self($body, $status))->header('Content-Type', 'application/json; charset=UTF-8');
    }

    /**
     * A redirect to an absolute URL or an app path ("/documents").
     */
    public static function redirect(string $to, int $status = 302): self
    {
        $location = preg_match('#^https?://#i', $to) === 1 ? $to : url($to);

        return (new self('', $status))->header('Location', $location);
    }

    /**
     * Stream a file from disk as a download (or inline), with a safe, UTF-8 aware filename.
     */
    public static function file(string $path, string $downloadName, string $mime, bool $inline = false): self
    {
        $ascii = preg_replace('/[^A-Za-z0-9._ -]/', '_', $downloadName) ?: 'download';
        $disposition = sprintf(
            '%s; filename="%s"; filename*=UTF-8\'\'%s',
            $inline ? 'inline' : 'attachment',
            $ascii,
            rawurlencode($downloadName)
        );

        $response = (new self('', 200))
            ->header('Content-Type', $mime)
            ->header('Content-Disposition', $disposition)
            ->header('Content-Length', (string) filesize($path))
            ->header('Cache-Control', 'private, no-store');
        $response->filePath = $path;

        return $response;
    }

    /**
     * Set a response header (replaces any existing value).
     */
    public function header(string $name, string $value): self
    {
        $this->headers[$name] = $value;

        return $this;
    }

    public function hasHeader(string $name): bool
    {
        return isset($this->headers[$name]);
    }

    /**
     * Flash validation errors (field => list of messages) for the next request.
     *
     * @param array<string, list<string>> $errors
     */
    public function withErrors(array $errors): self
    {
        Session::flash('_errors', $errors);

        return $this;
    }

    /**
     * Flash submitted input for old() on the next request. Password fields are never kept.
     *
     * @param array<string, mixed> $input
     */
    public function withInput(array $input): self
    {
        $input = array_filter(
            $input,
            static fn ($key): bool => !in_array($key, [Csrf::FIELD, 'password', 'password_confirmation', 'current_password'], true),
            ARRAY_FILTER_USE_KEY
        );
        Session::flash('_old_input', $input);

        return $this;
    }

    /**
     * Flash a user-facing message (success, danger, warning, info).
     */
    public function with(string $type, string $message): self
    {
        Session::addMessage($type, $message);

        return $this;
    }

    public function status(): int
    {
        return $this->status;
    }

    public function body(): string
    {
        return $this->body;
    }

    /**
     * @return array<string, string>
     */
    public function headers(): array
    {
        return $this->headers;
    }

    /**
     * Add the baseline security headers (spec §10.1) unless already set.
     */
    public function withSecurityHeaders(bool $https = false): self
    {
        $defaults = [
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Content-Security-Policy' => implode('; ', [
                "default-src 'self'",
                "img-src 'self' data: blob:",
                "style-src 'self'",
                "script-src 'self'",
                "font-src 'self'",
                "connect-src 'self'",
                "media-src 'self' blob:",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self'",
                "frame-ancestors 'self'",
            ]),
            'Permissions-Policy' => 'camera=(self), microphone=(), geolocation=()',
        ];
        if ($https) {
            $defaults['Strict-Transport-Security'] = 'max-age=31536000';
        }

        foreach ($defaults as $name => $value) {
            $this->headers[$name] ??= $value;
        }

        return $this;
    }

    /**
     * Emit the status line, headers and body.
     */
    public function send(): void
    {
        if (!headers_sent()) {
            http_response_code($this->status);
            header_remove('X-Powered-By');
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . $value);
            }
        }

        if ($this->filePath !== null) {
            readfile($this->filePath);
            return;
        }

        echo $this->body;
    }

    /**
     * Path of the file this response streams, if any.
     */
    public function filePath(): ?string
    {
        return $this->filePath;
    }
}
