<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Core\Database;
use InvalidArgumentException;

/**
 * Application settings (FR-15): cached per request, with defaults, logo storage and theme colours.
 */
final class SettingService
{
    /** Known settings and their defaults. */
    public const DEFAULTS = [
        'app_name' => 'VVU Document Tracking System',
        'unreceived_alert_hours' => '24',
        'alert_repeat_hours' => '24',
        'default_sla_days' => '3',
        'max_upload_mb' => '10',
        'primary_color' => '#0b3d75',
        'logo_path' => '',
    ];

    public const LOGO_DIR = 'branding';

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    public function __construct(
        private readonly Setting $settings = new Setting(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * All settings (stored values over defaults).
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        if (self::$cache === null) {
            $stored = array_map(static fn ($v): string => (string) $v, $this->settings->pairs());
            self::$cache = array_merge(self::DEFAULTS, array_intersect_key($stored, self::DEFAULTS));
        }

        return self::$cache;
    }

    /**
     * One setting's value.
     */
    public function get(string $key): string
    {
        return $this->all()[$key] ?? throw new InvalidArgumentException("Unknown setting [$key].");
    }

    /**
     * Save settings and (optionally) a new or removed logo, writing one audit row
     * with the changed values.
     *
     * @param array<string, string> $values Validated values for keys in DEFAULTS (except logo_path)
     * @param array<string, mixed>|null $logo Validated $_FILES entry, or null
     */
    public function update(array $values, ?array $logo, bool $removeLogo): void
    {
        $before = $this->all();
        $values = array_intersect_key($values, self::DEFAULTS);
        unset($values['logo_path']);

        $oldLogo = $before['logo_path'];
        if ($logo !== null) {
            $values['logo_path'] = $this->storeLogo($logo);
        } elseif ($removeLogo) {
            $values['logo_path'] = '';
        }

        $changes = AuditService::diff($before, $values);
        if ($changes === []) {
            return;
        }

        Database::instance()->transaction(function () use ($values, $changes): void {
            foreach ($values as $key => $value) {
                $this->settings->put($key, $value);
            }
            $this->audit->log('settings.updated', ['changes' => $changes]);
        });
        self::$cache = null;

        if (isset($changes['logo_path']) && $oldLogo !== '') {
            $this->deleteLogoFile($oldLogo);
        }
    }

    /**
     * Absolute path of the current logo file, or null when none is set.
     */
    public function logoFile(): ?string
    {
        $path = $this->all()['logo_path'];
        if ($path === '' || !preg_match('#^' . self::LOGO_DIR . '/[a-f0-9]{32}\.(png|jpg|webp)$#', $path)) {
            return null;
        }
        $file = storage_path('uploads/' . $path);

        return is_file($file) ? $file : null;
    }

    /**
     * A small square PNG (180×180) made from the logo, for the browser tab and home-screen
     * icon. Created on first request and kept next to the logo; null when there is no logo.
     * Falls back to the logo itself if the GD extension is not available.
     */
    public function faviconFile(): ?string
    {
        $logo = $this->logoFile();
        if ($logo === null) {
            return null;
        }

        $icon = preg_replace('/\.(png|jpg|webp)$/', '-icon.png', $logo);
        if (is_file($icon) && filemtime($icon) >= filemtime($logo)) {
            return $icon;
        }

        return self::makeSquareIcon($logo, $icon, 180) ? $icon : $logo;
    }

    /**
     * Resize an image to fit a transparent square canvas. Returns false if GD is missing
     * or the image cannot be read.
     */
    public static function makeSquareIcon(string $source, string $target, int $size): bool
    {
        if (!function_exists('imagecreatefromstring')) {
            return false;
        }
        $image = @imagecreatefromstring((string) file_get_contents($source));
        if ($image === false) {
            return false;
        }

        [$w, $h] = [imagesx($image), imagesy($image)];
        $inner = (int) round($size * 0.94);
        $scale = min($inner / $w, $inner / $h);
        [$nw, $nh] = [max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale))];

        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        imagefill($canvas, 0, 0, imagecolorallocatealpha($canvas, 0, 0, 0, 127));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $image, intdiv($size - $nw, 2), intdiv($size - $nh, 2), 0, 0, $nw, $nh, $w, $h);
        imagesavealpha($canvas, true);

        return imagepng($canvas, $target, 9);
    }

    /**
     * CSS custom properties for the configured primary colour (served as /branding/theme).
     */
    public static function themeCss(string $hex): string
    {
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $hex) !== 1) {
            $hex = self::DEFAULTS['primary_color'];
        }
        [$r, $g, $b] = array_map('hexdec', str_split(substr($hex, 1), 2));
        $dark = sprintf('#%02x%02x%02x', (int) round($r * 0.78), (int) round($g * 0.78), (int) round($b * 0.78));

        return sprintf(
            ":root {\n    --dts-primary: %s;\n    --dts-primary-dark: %s;\n    --bs-primary-rgb: %d, %d, %d;\n    --bs-link-color-rgb: %d, %d, %d;\n}\n",
            strtolower($hex),
            $dark,
            $r, $g, $b,
            $r, $g, $b
        );
    }

    /**
     * Forget cached values (tests, or after external changes).
     */
    public static function flushCache(): void
    {
        self::$cache = null;
    }

    /**
     * @param array<string, mixed> $file
     */
    private function storeLogo(array $file): string
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']);
        $ext = match ($mime) {
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            default => throw new ServiceException('The logo must be a PNG, JPG or WebP image.', 'logo'),
        };

        $dir = storage_path('uploads/' . self::LOGO_DIR);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create directory $dir");
        }

        $relative = self::LOGO_DIR . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], storage_path('uploads/' . $relative))) {
            throw new \RuntimeException('Could not save the uploaded logo.');
        }

        return $relative;
    }

    private function deleteLogoFile(string $relative): void
    {
        if (preg_match('#^' . self::LOGO_DIR . '/[a-f0-9]{32}\.(png|jpg|webp)$#', $relative) === 1) {
            $file = storage_path('uploads/' . $relative);
            @unlink($file);
            @unlink((string) preg_replace('/\.(png|jpg|webp)$/', '-icon.png', $file));
        }
    }
}
