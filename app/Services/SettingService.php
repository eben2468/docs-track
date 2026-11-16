<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Setting;
use Core\Database;
use Core\Encrypter;
use Core\Logger;
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

    /**
     * Email (SMTP) settings that Admin > Settings can override. Stored as "mail_<key>";
     * a key with no stored value falls back to the MAIL_* value in .env (config/mail.php).
     * The password is stored encrypted with APP_KEY.
     */
    public const MAIL_KEYS = ['transport', 'host', 'port', 'encryption', 'username', 'password', 'from_address', 'from_name'];

    /** @var array<string, string>|null */
    private static ?array $cache = null;

    /** @var array<string, string|null>|null Stored mail_* rows */
    private static ?array $mailCache = null;

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
     * The mail settings in effect: values saved in Settings over the MAIL_* values in .env.
     * Same keys as config/mail.php, with the password decrypted ('' if it cannot be).
     *
     * @return array{transport: string, host: string, port: int, encryption: string, username: string, password: string, from_address: string, from_name: string}
     */
    public function mailConfig(): array
    {
        $env = (array) config('mail', []);
        $stored = $this->storedMail();
        $config = [];
        foreach (self::MAIL_KEYS as $key) {
            $config[$key] = (string) ($stored[$key] ?? $env[$key] ?? '');
        }
        if (isset($stored['password'])) {
            $config['password'] = $this->decryptMailPassword((string) $stored['password']) ?? '';
        }
        $config['transport'] = $config['transport'] === 'log' ? 'log' : 'smtp';
        $config['port'] = (int) $config['port'];

        return $config;
    }

    /**
     * Mail keys whose value comes from Settings rather than .env.
     *
     * @return list<string>
     */
    public function mailOverrides(): array
    {
        return array_keys($this->storedMail());
    }

    /**
     * Where the SMTP password comes from: "saved" (Settings), "unreadable" (saved, but
     * APP_KEY changed), "env" (.env) or "none".
     */
    public function mailPasswordStatus(): string
    {
        $stored = $this->storedMail();
        if (isset($stored['password'])) {
            return $this->decryptMailPassword((string) $stored['password']) === null ? 'unreadable' : 'saved';
        }

        return (string) config('mail.password', '') !== '' ? 'env' : 'none';
    }

    /**
     * Save the mail settings (all keys except the password are replaced). The password
     * changes only when a new one is given or $clearPassword is set. Audited without secrets.
     *
     * @param array{transport: string, host: string, port: string, encryption: string, username: string, from_address: string, from_name: string} $values
     * @throws ServiceException when a password is given but APP_KEY is not set
     */
    public function updateMail(array $values, ?string $newPassword, bool $clearPassword): void
    {
        $before = $this->mailConfig();
        $values = array_intersect_key($values, array_flip(self::MAIL_KEYS));
        unset($values['password']);

        $encrypted = null;
        if ($newPassword !== null) {
            if (!Encrypter::isConfigured()) {
                throw new ServiceException('Set APP_KEY in the server\'s .env file before saving an SMTP password (see .env.example).', 'mail_password');
            }
            $encrypted = Encrypter::fromConfig()->encrypt($newPassword);
        }

        $changes = AuditService::diff($before, $values);
        if ($newPassword !== null) {
            $changes['password'] = ['(hidden)', '(changed)'];
        } elseif ($clearPassword && isset($this->storedMail()['password'])) {
            $changes['password'] = ['(hidden)', '(removed)'];
        }
        if ($changes === []) {
            return;
        }

        Database::instance()->transaction(function () use ($values, $encrypted, $clearPassword, $changes): void {
            foreach ($values as $key => $value) {
                $this->settings->put('mail_' . $key, (string) $value);
            }
            if ($encrypted !== null) {
                $this->settings->put('mail_password', $encrypted);
            } elseif ($clearPassword) {
                $this->settings->forget('mail_password');
            }
            $this->audit->log('settings.mail_updated', ['changes' => $changes]);
        });
        self::$mailCache = null;
    }

    /**
     * Remove every saved mail setting so the MAIL_* values in .env apply again.
     */
    public function resetMail(): void
    {
        if ($this->storedMail() === []) {
            return;
        }
        Database::instance()->transaction(function (): void {
            foreach (self::MAIL_KEYS as $key) {
                $this->settings->forget('mail_' . $key);
            }
            $this->audit->log('settings.mail_reset', ['keys' => $this->mailOverrides()]);
        });
        self::$mailCache = null;
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
        self::$mailCache = null;
    }

    /**
     * Stored mail_* settings keyed without the prefix (only keys that have a value row).
     *
     * @return array<string, string|null>
     */
    private function storedMail(): array
    {
        if (self::$mailCache === null) {
            self::$mailCache = [];
            foreach ($this->settings->pairs() as $key => $value) {
                if (str_starts_with((string) $key, 'mail_') && in_array(substr((string) $key, 5), self::MAIL_KEYS, true)) {
                    self::$mailCache[substr((string) $key, 5)] = $value;
                }
            }
        }

        return self::$mailCache;
    }

    private function decryptMailPassword(string $payload): ?string
    {
        try {
            return Encrypter::fromConfig()->decrypt($payload);
        } catch (\RuntimeException $e) {
            Logger::error('Saved SMTP password could not be decrypted.', ['error' => $e->getMessage()]);

            return null;
        }
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
