<?php

declare(strict_types=1);

namespace Core;

/**
 * Read-only access to config/*.php values using dot notation ("app.debug").
 */
final class Config
{
    /** @var array<string, mixed> */
    private static array $items = [];

    /**
     * Load every PHP file in the given directory; the file name becomes the top-level key.
     */
    public static function load(string $directory): void
    {
        foreach (glob(rtrim($directory, '/\\') . '/*.php') ?: [] as $file) {
            self::$items[basename($file, '.php')] = require $file;
        }
    }

    /**
     * Replace all loaded values (used by tests).
     *
     * @param array<string, mixed> $items
     */
    public static function set(array $items): void
    {
        self::$items = $items;
    }

    /**
     * Get a value by dot-notation key, or $default when it is not defined.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::$items;

        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
