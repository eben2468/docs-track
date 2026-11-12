<?php

declare(strict_types=1);

namespace Core;

use Throwable;

/**
 * Minimal file logger writing to storage/logs/app.log.
 */
final class Logger
{
    private static ?string $path = null;

    /**
     * Set the absolute path of the log file.
     */
    public static function setPath(string $path): void
    {
        self::$path = $path;
    }

    /**
     * Write an error-level entry.
     *
     * @param array<string, mixed> $context
     */
    public static function error(string $message, array $context = []): void
    {
        self::write('ERROR', $message, $context);
    }

    /**
     * Write an info-level entry.
     *
     * @param array<string, mixed> $context
     */
    public static function info(string $message, array $context = []): void
    {
        self::write('INFO', $message, $context);
    }

    /**
     * Log an exception with its location and stack trace.
     */
    public static function exception(Throwable $e): void
    {
        self::error(
            sprintf('%s: %s in %s:%d', $e::class, $e->getMessage(), $e->getFile(), $e->getLine()),
            ['trace' => $e->getTraceAsString()]
        );
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function write(string $level, string $message, array $context): void
    {
        if (self::$path === null) {
            error_log("[$level] $message");
            return;
        }

        $line = sprintf('[%s] %s: %s', date('Y-m-d H:i:s'), $level, $message);
        if ($context !== []) {
            $line .= ' ' . json_encode($context, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }

        @file_put_contents(self::$path, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
}
