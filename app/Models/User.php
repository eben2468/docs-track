<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Staff accounts. Each user belongs to exactly one department. Deactivated, never deleted.
 */
final class User extends Model
{
    protected string $table = 'users';

    /** @var list<string> */
    protected array $fillable = [
        'staff_id', 'full_name', 'email', 'phone', 'password_hash', 'role', 'department_id',
        'is_active', 'failed_attempts', 'locked_until', 'last_login_at',
    ];

    /**
     * Find a user by email address (case-insensitive under utf8mb4_unicode_ci).
     *
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->first(['email' => trim($email)]);
    }
}
