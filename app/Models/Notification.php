<?php

declare(strict_types=1);

namespace App\Models;

/**
 * In-app notifications.
 */
final class Notification extends Model
{
    protected string $table = 'notifications';

    /** @var list<string> */
    protected array $fillable = ['user_id', 'document_id', 'type', 'message', 'is_read'];
}
