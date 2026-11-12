<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Request log used to rate-limit login attempts and public tracking per IP.
 */
final class LoginThrottle extends Model
{
    protected string $table = 'login_throttle';

    /** @var list<string> */
    protected array $fillable = ['ip_address', 'scope'];
}
