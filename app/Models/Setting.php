<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Key/value application settings (FR-15).
 */
final class Setting extends Model
{
    protected string $table = 'settings';

    protected ?string $primaryKey = 'key';

    /** @var list<string> */
    protected array $fillable = ['key', 'value'];

    /**
     * A setting's value, or $default when it is not set.
     */
    public function value(string $key, ?string $default = null): ?string
    {
        $row = $this->find($key);

        return $row === null ? $default : $row['value'];
    }

    /**
     * Create or replace a setting.
     */
    public function put(string $key, ?string $value): void
    {
        $this->db->execute(
            'INSERT INTO settings (`key`, `value`) VALUES (?, ?) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)',
            [$key, $value]
        );
    }

    /**
     * All settings as key => value.
     *
     * @return array<string, string|null>
     */
    public function pairs(): array
    {
        return array_column($this->db->fetchAll('SELECT `key`, `value` FROM settings'), 'value', 'key');
    }
}
