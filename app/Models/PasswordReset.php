<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Password reset tokens (stored as SHA-256 hashes, single use).
 */
final class PasswordReset extends Model
{
    protected string $table = 'password_resets';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'token_hash', 'expires_at', 'used_at'];
}
