<?php

declare(strict_types=1);

namespace Core;

use InvalidArgumentException;
use Throwable;

/**
 * Plain-PHP templates in app/Views with layouts and partials.
 *
 * A view is rendered first; its output is passed to the layout as $content.
 * Views may set $title (and any other variable) for the layout by assigning
 * to the shared $layoutData array, e.g. `$layoutData['title'] = 'Incoming';`.
 */
final class View
{
    private static string $path = '';

    /**
     * Set the absolute path of the views directory.
     */
    public static function setPath(string $path): void
    {
        self::$path = rtrim($path, '/\\');
    }

    /**
     * Render a view inside a layout (pass null for no layout).
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $view, array $data = [], ?string $layout = 'layouts/app'): string
    {
        $layoutData = $data;
        $content = self::renderFile(self::resolve($view), $data, $layoutData);

        if ($layout === null) {
            return $content;
        }

        $layoutData['content'] = $content;
        $ignored = [];

        return self::renderFile(self::resolve($layout), $layoutData, $ignored);
    }

    /**
     * Render a partial (no layout), e.g. View::partial('partials/flash').
     *
     * @param array<string, mixed> $data
     */
    public static function partial(string $view, array $data = []): string
    {
        $ignored = [];

        return self::renderFile(self::resolve($view), $data, $ignored);
    }

    /**
     * Whether a view file exists.
     */
    public static function exists(string $view): bool
    {
        try {
            return is_file(self::resolve($view));
        } catch (InvalidArgumentException) {
            return false;
        }
    }

    private static function resolve(string $view): string
    {
        if (preg_match('#^[a-z0-9_\-]+(/[a-z0-9_\-]+)*$#i', $view) !== 1) {
            throw new InvalidArgumentException("Invalid view name [$view].");
        }

        return self::$path . '/' . $view . '.php';
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $layoutData Passed by reference so views can set layout variables
     */
    private static function renderFile(string $__file, array $data, array &$layoutData): string
    {
        if (!is_file($__file)) {
            throw new InvalidArgumentException("View file not found: $__file");
        }

        extract($data, EXTR_SKIP);
        ob_start();
        try {
            require $__file;
        } catch (Throwable $e) {
            ob_end_clean();
            throw $e;
        }

        return (string) ob_get_clean();
    }
}
