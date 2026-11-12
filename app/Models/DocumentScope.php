<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Shared definitions of the document sets used by lists, dashboards and alerts, so every
 * screen counts the same way. Each method returns [SQL condition, bindings] written
 * against Document::BASE_FROM aliases:
 *   d  = documents, om = the open (in_transit) movement, lr = the last received movement.
 */
final class DocumentScope
{
    /**
     * In transit to the department, awaiting its receipt.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function incoming(int $departmentId): array
    {
        return ['om.to_department_id = ?', [$departmentId]];
    }

    /**
     * Received and currently held by the department (not completed).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function withUs(int $departmentId): array
    {
        return ["d.status = 'received' AND d.current_department_id = ?", [$departmentId]];
    }

    /**
     * Sent by the department and not yet received elsewhere.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function outgoing(int $departmentId): array
    {
        return ['om.from_department_id = ?', [$departmentId]];
    }

    /**
     * Registered by the department (any status).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function registered(int $departmentId): array
    {
        return ['d.origin_department_id = ?', [$departmentId]];
    }

    /**
     * Held past the due date of the movement that brought it to its holder (FR-10).
     * With a department: only documents that department holds.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function overdue(?int $departmentId = null): array
    {
        $sql = "d.status = 'received' AND lr.due_date IS NOT NULL AND lr.due_date < CURDATE()";

        return $departmentId === null ? [$sql, []] : [$sql . ' AND d.current_department_id = ?', [$departmentId]];
    }

    /**
     * In transit and not received within $hours (FR-10 "unacknowledged").
     * With a department: only documents on their way to it.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function stuck(int $hours, ?int $departmentId = null): array
    {
        $sql = "d.status = 'in_transit' AND om.sent_at < (NOW() - INTERVAL " . max(1, $hours) . ' HOUR)';

        return $departmentId === null ? [$sql, []] : [$sql . ' AND om.to_department_id = ?', [$departmentId]];
    }

    /**
     * Completed since the first day of the current month.
     * With a department: completed while that department held it.
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function completedThisMonth(?int $departmentId = null): array
    {
        $sql = "d.status = 'completed' AND d.completed_at >= ?";
        $bindings = [now()->format('Y-m-01 00:00:00')];
        if ($departmentId !== null) {
            $sql .= ' AND d.current_department_id = ?';
            $bindings[] = $departmentId;
        }

        return [$sql, $bindings];
    }

    /**
     * Documents a non-admin user may view: their department is the origin, the holder,
     * or on any movement (mirrors DocumentPolicy::canView).
     *
     * @return array{0: string, 1: list<mixed>}
     */
    public static function accessibleTo(int $departmentId): array
    {
        // Two uncorrelated IN lists (each resolved once via the from/to department indexes)
        // instead of a per-document EXISTS probe.
        return [
            '(d.origin_department_id = ? OR d.current_department_id = ?
              OR d.id IN (SELECT r1.document_id FROM document_movements r1 WHERE r1.from_department_id = ?)
              OR d.id IN (SELECT r2.document_id FROM document_movements r2 WHERE r2.to_department_id = ?))',
            [$departmentId, $departmentId, $departmentId, $departmentId],
        ];
    }
}
