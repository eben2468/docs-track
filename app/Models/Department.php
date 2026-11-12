<?php

declare(strict_types=1);

namespace App\Models;

/**
 * University departments / offices. Deactivated (is_active = 0), never deleted.
 */
final class Department extends Model
{
    protected string $table = 'departments';

    /** @var list<string> */
    protected array $fillable = ['name', 'code', 'parent_id', 'head_user_id', 'location', 'email', 'is_active'];

    /**
     * Find a department by its short code (e.g. "REG").
     *
     * @return array<string, mixed>|null
     */
    public function findByCode(string $code): ?array
    {
        return $this->first(['code' => strtoupper($code)]);
    }

    /**
     * Active departments ordered by name.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return $this->where(['is_active' => 1], 'name ASC');
    }
}
