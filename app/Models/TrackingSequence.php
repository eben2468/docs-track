<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Per-department, per-year tracking number counters (composite key: department_id + year).
 */
final class TrackingSequence extends Model
{
    protected string $table = 'tracking_sequences';

    protected ?string $primaryKey = null;

    /** @var list<string> */
    protected array $fillable = ['department_id', 'year', 'last_number'];
}
