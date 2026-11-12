<?php

declare(strict_types=1);

namespace App\Services;

use Core\Database;
use LogicException;

/**
 * Generates tracking numbers VVU-{DEPT_CODE}-{YYYY}-{SEQ} (spec §5.1).
 *
 * SEQ is a 5-digit counter per origin department per year, kept in tracking_sequences.
 * The counter row is created or incremented with a single atomic upsert, which takes an
 * exclusive row lock held until the surrounding transaction ends, then read back with
 * SELECT ... FOR UPDATE. Concurrent registrations for the same department therefore
 * queue on that row and can never receive the same number. Never MAX(id)+1.
 */
final class TrackingNumberService
{
    public function __construct(private readonly Database $db)
    {
    }

    /**
     * Reserve the next tracking number for a department. Must run inside the transaction
     * that inserts the document, so a rollback also releases the number.
     *
     * @param int|null $year Defaults to the current year (Africa/Accra)
     * @throws LogicException when called outside a transaction or for an unknown department
     */
    public function next(int $departmentId, ?int $year = null): string
    {
        if (!$this->db->pdo()->inTransaction()) {
            throw new LogicException('TrackingNumberService::next() must be called inside a transaction.');
        }

        $year ??= (int) now()->format('Y');
        $code = $this->db->fetchValue('SELECT code FROM departments WHERE id = ?', [$departmentId]);
        if ($code === null) {
            throw new LogicException("Department $departmentId does not exist.");
        }

        $this->db->execute(
            'INSERT INTO tracking_sequences (department_id, year, last_number) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE last_number = last_number + 1',
            [$departmentId, $year]
        );
        $sequence = (int) $this->db->fetchValue(
            'SELECT last_number FROM tracking_sequences WHERE department_id = ? AND year = ? FOR UPDATE',
            [$departmentId, $year]
        );

        return self::format((string) $code, $year, $sequence);
    }

    /**
     * Build a tracking number from its parts, e.g. ("REG", 2026, 42) → "VVU-REG-2026-00042".
     */
    public static function format(string $departmentCode, int $year, int $sequence): string
    {
        return sprintf('VVU-%s-%04d-%05d', strtoupper($departmentCode), $year, $sequence);
    }

    /**
     * Whether a string has the tracking number shape (used by search and public tracking).
     */
    public static function isValid(string $trackingNo): bool
    {
        return preg_match('/^VVU-[A-Z]{2,6}-\d{4}-\d{5,}$/', strtoupper(trim($trackingNo))) === 1;
    }
}
