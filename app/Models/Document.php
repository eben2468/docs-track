<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Registered documents. Status changes go through DocumentService/MovementService only.
 */
final class Document extends Model
{
    protected string $table = 'documents';

    /** @var list<string> */
    protected array $fillable = [
        'tracking_no', 'title', 'document_type_id', 'reference_no', 'description', 'priority',
        'confidentiality', 'format', 'pages', 'origin_department_id', 'created_by',
        'current_department_id', 'current_holder_id', 'status', 'completed_at', 'completed_by',
    ];

    /**
     * Find a document by tracking number (e.g. "VVU-REG-2026-00042").
     *
     * @return array<string, mixed>|null
     */
    public function findByTrackingNo(string $trackingNo): ?array
    {
        return $this->first(['tracking_no' => strtoupper(trim($trackingNo))]);
    }

    /**
     * A document with the names needed for display (type, departments, people).
     *
     * @return array<string, mixed>|null
     */
    public function findDetailed(int $id): ?array
    {
        return $this->db->fetch(
            'SELECT d.*,
                    t.name AS type_name, t.sla_days AS type_sla_days,
                    od.name AS origin_department_name, od.code AS origin_department_code,
                    cd.name AS current_department_name, cd.code AS current_department_code,
                    cu.full_name AS created_by_name,
                    hu.full_name AS current_holder_name,
                    pu.full_name AS completed_by_name
             FROM documents d
             JOIN document_types t ON t.id = d.document_type_id
             JOIN departments od ON od.id = d.origin_department_id
             JOIN departments cd ON cd.id = d.current_department_id
             JOIN users cu ON cu.id = d.created_by
             LEFT JOIN users hu ON hu.id = d.current_holder_id
             LEFT JOIN users pu ON pu.id = d.completed_by
             WHERE d.id = ?',
            [$id]
        );
    }

    /** List tabs (FR-12). "all" is for administrators; "all-accessible" is what a user may view. */
    public const TABS = ['incoming', 'with-us', 'outgoing', 'registered', 'completed', 'all', 'all-accessible'];

    public const STATUSES = ['draft', 'in_transit', 'received', 'completed', 'cancelled'];

    /** Sortable columns: request key => SQL expression (never user input). */
    private const SORTS = [
        'tracking' => 'd.tracking_no',
        'title' => 'd.title',
        'type' => 't.name',
        'status' => 'd.status',
        'priority' => 'd.priority',
        'origin' => 'od.code',
        'current' => 'cd.code',
        'created' => 'd.created_at',
        'sent' => 'om.sent_at',
        'received' => 'lr.received_at',
        'due' => 'COALESCE(om.due_date, lr.due_date)',
    ];

    /**
     * One page of a document list tab for a user, with filters and sorting.
     *
     * Each row carries the open movement (om_*), the last received movement (lr_*) and
     * flags: is_overdue (held past its due date) and is_stuck (unreceived past the alert threshold).
     *
     * @param array<string, mixed> $user
     * @param array<string, string> $filters q, type, status, priority, origin, current, from, to
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function listing(array $user, string $tab, array $filters, string $sort, string $dir, int $page, int $alertHours, int $perPage = 25): array
    {
        [$select, $fromWhere, $bindings, $orderBy] = $this->listingQuery($user, $tab, $filters, $sort, $dir, $alertHours);

        return $this->paginateSql($select, $fromWhere, $bindings, $orderBy, $page, $perPage);
    }

    /**
     * The same rows as listing() without pagination (for CSV export), capped at $limit.
     *
     * @param array<string, mixed> $user
     * @param array<string, string> $filters
     * @return list<array<string, mixed>>
     */
    public function listingAll(array $user, string $tab, array $filters, string $sort, string $dir, int $alertHours, int $limit = 10000): array
    {
        [$select, $fromWhere, $bindings, $orderBy] = $this->listingQuery($user, $tab, $filters, $sort, $dir, $alertHours);

        return $this->db->fetchAll("SELECT $select $fromWhere ORDER BY $orderBy LIMIT " . max(1, $limit), $bindings);
    }

    /**
     * Counts per tab for the tab badges.
     *
     * @param array<string, mixed> $user
     * @return array<string, int>
     */
    public function tabCounts(array $user): array
    {
        $dept = (int) $user['department_id'];

        return array_map('intval', $this->db->fetch(
            "SELECT
                (SELECT COUNT(*) FROM document_movements WHERE to_department_id = ? AND status = 'in_transit') AS incoming,
                (SELECT COUNT(*) FROM documents WHERE current_department_id = ? AND status = 'received') AS with_us,
                (SELECT COUNT(*) FROM document_movements WHERE from_department_id = ? AND status = 'in_transit') AS outgoing",
            [$dept, $dept, $dept]
        ) ?? []);
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, string> $filters
     * @return array{0: string, 1: string, 2: list<mixed>, 3: string}
     */
    private function listingQuery(array $user, string $tab, array $filters, string $sort, string $dir, int $alertHours): array
    {
        $dept = (int) $user['department_id'];
        $isAdmin = $user['role'] === 'admin';
        $hours = max(1, $alertHours);

        $select = "d.id, d.tracking_no, d.title, d.confidentiality, d.priority, d.status, d.created_at, d.updated_at,
            d.reference_no, d.completed_at,
            t.name AS type_name,
            od.code AS origin_code, od.name AS origin_name,
            cd.code AS current_code, cd.name AS current_name,
            hu.full_name AS holder_name,
            om.id AS om_id, om.sent_at AS om_sent_at, om.due_date AS om_due_date, om.note AS om_note, om.to_user_id AS om_to_user_id,
            om.action AS om_action, td.code AS om_to_code, td.name AS om_to_name, fd.code AS om_from_code, fd.name AS om_from_name,
            fu.full_name AS om_from_user,
            lr.received_at AS lr_received_at, lr.due_date AS lr_due_date,
            (d.status = 'received' AND lr.due_date IS NOT NULL AND lr.due_date < CURDATE()) AS is_overdue,
            (d.status = 'in_transit' AND om.sent_at < (NOW() - INTERVAL $hours HOUR)) AS is_stuck";

        $from = "FROM documents d
            JOIN document_types t ON t.id = d.document_type_id
            JOIN departments od ON od.id = d.origin_department_id
            JOIN departments cd ON cd.id = d.current_department_id
            LEFT JOIN users hu ON hu.id = d.current_holder_id
            LEFT JOIN document_movements om ON om.document_id = d.id AND om.status = 'in_transit'
            LEFT JOIN departments td ON td.id = om.to_department_id
            LEFT JOIN departments fd ON fd.id = om.from_department_id
            LEFT JOIN users fu ON fu.id = om.from_user_id
            LEFT JOIN document_movements lr ON lr.id = (
                SELECT x.id FROM document_movements x
                WHERE x.document_id = d.id AND x.status = 'received'
                ORDER BY x.received_at DESC, x.id DESC LIMIT 1
            )";

        $where = [];
        $bindings = [];
        $accessible = '(d.origin_department_id = ? OR d.current_department_id = ? OR EXISTS (
            SELECT 1 FROM document_movements r WHERE r.document_id = d.id AND (r.from_department_id = ? OR r.to_department_id = ?)))';

        switch ($tab) {
            case 'incoming':
                $where[] = 'om.to_department_id = ?';
                $bindings[] = $dept;
                break;
            case 'with-us':
                $where[] = "d.status = 'received' AND d.current_department_id = ?";
                $bindings[] = $dept;
                break;
            case 'outgoing':
                $where[] = 'om.from_department_id = ?';
                $bindings[] = $dept;
                break;
            case 'registered':
                $where[] = 'd.origin_department_id = ?';
                $bindings[] = $dept;
                break;
            case 'completed':
                $where[] = "d.status = 'completed'";
                if (!$isAdmin) {
                    $where[] = $accessible;
                    array_push($bindings, $dept, $dept, $dept, $dept);
                }
                break;
            default: // all, all-accessible
                if (!$isAdmin) {
                    $where[] = $accessible;
                    array_push($bindings, $dept, $dept, $dept, $dept);
                }
        }

        $this->applyFilters($filters, $where, $bindings);

        $sortSql = self::SORTS[$sort] ?? self::SORTS[self::defaultSort($tab)];
        $direction = strtolower($dir) === 'asc' ? 'ASC' : 'DESC';
        $orderBy = "$sortSql $direction, d.id DESC";

        return [$select, $from . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where)), $bindings, $orderBy];
    }

    /**
     * Default sort column per tab.
     */
    public static function defaultSort(string $tab): string
    {
        return match ($tab) {
            'incoming', 'outgoing' => 'sent',
            'with-us' => 'received',
            default => 'created',
        };
    }

    /**
     * Default sort direction per tab (oldest first where waiting time matters).
     */
    public static function defaultDirection(string $tab): string
    {
        return in_array($tab, ['incoming', 'outgoing', 'with-us'], true) ? 'asc' : 'desc';
    }

    /**
     * Whether a sort key is allowed.
     */
    public static function isSortable(string $sort): bool
    {
        return isset(self::SORTS[$sort]);
    }

    /**
     * @param array<string, string> $filters
     * @param list<string> $where
     * @param list<mixed> $bindings
     */
    private function applyFilters(array $filters, array &$where, array &$bindings): void
    {
        $q = trim($filters['q'] ?? '');
        if ($q !== '') {
            if (preg_match('/\d/', $q) === 1 && !str_contains($q, ' ')) {
                // Looks like a tracking or reference number (or part of one).
                $where[] = '(d.tracking_no LIKE ? OR d.reference_no LIKE ?)';
                array_push($bindings, self::likeTerm($q), self::likeTerm($q));
            } else {
                $words = array_values(array_filter(preg_split('/\s+/', preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $q) ?? '') ?: []));
                $short = array_filter($words, static fn (string $w): bool => mb_strlen($w) < 3);
                if ($words !== [] && $short === []) {
                    $where[] = 'MATCH(d.title, d.description, d.reference_no) AGAINST (? IN BOOLEAN MODE)';
                    $bindings[] = implode(' ', array_map(static fn (string $w): string => '+' . $w . '*', $words));
                } else {
                    $where[] = '(d.title LIKE ? OR d.reference_no LIKE ?)';
                    array_push($bindings, self::likeTerm($q), self::likeTerm($q));
                }
            }
        }

        $intFilters = ['type' => 'd.document_type_id', 'origin' => 'd.origin_department_id', 'current' => 'd.current_department_id'];
        foreach ($intFilters as $key => $column) {
            if (isset($filters[$key]) && ctype_digit((string) $filters[$key])) {
                $where[] = "$column = ?";
                $bindings[] = (int) $filters[$key];
            }
        }
        if (in_array($filters['status'] ?? '', self::STATUSES, true)) {
            $where[] = 'd.status = ?';
            $bindings[] = $filters['status'];
        }
        if (in_array($filters['priority'] ?? '', ['normal', 'urgent'], true)) {
            $where[] = 'd.priority = ?';
            $bindings[] = $filters['priority'];
        }
        foreach (['from' => '>=', 'to' => '<'] as $key => $op) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) ($filters[$key] ?? ''));
            if ($date !== false && $date->format('Y-m-d') === ($filters[$key] ?? '')) {
                $where[] = "d.created_at $op ?";
                $bindings[] = ($key === 'to' ? $date->modify('+1 day') : $date)->format('Y-m-d H:i:s');
            }
        }
    }

    /**
     * The limited facts shown on the public tracking page (FR-14). The title is null for
     * confidential documents; nothing else about the document is returned.
     *
     * @return array{tracking_no: string, title: ?string, type_name: string, status: string,
     *               current_department: string, in_transit_to: ?string, last_updated: string}|null
     */
    public function publicSummary(string $trackingNo): ?array
    {
        $row = $this->db->fetch(
            "SELECT d.tracking_no, d.confidentiality, d.title, d.status, t.name AS type_name,
                    cd.name AS current_department, td.name AS in_transit_to,
                    GREATEST(
                        COALESCE(d.updated_at, d.created_at),
                        COALESCE((SELECT MAX(GREATEST(m.sent_at, COALESCE(m.received_at, m.sent_at), COALESCE(m.recalled_at, m.sent_at)))
                                  FROM document_movements m WHERE m.document_id = d.id), d.created_at)
                    ) AS last_updated
             FROM documents d
             JOIN document_types t ON t.id = d.document_type_id
             JOIN departments cd ON cd.id = d.current_department_id
             LEFT JOIN document_movements om ON om.document_id = d.id AND om.status = 'in_transit'
             LEFT JOIN departments td ON td.id = om.to_department_id
             WHERE d.tracking_no = ?",
            [strtoupper(trim($trackingNo))]
        );
        if ($row === null) {
            return null;
        }

        return [
            'tracking_no' => (string) $row['tracking_no'],
            'title' => $row['confidentiality'] === 'confidential' ? null : (string) $row['title'],
            'type_name' => (string) $row['type_name'],
            'status' => (string) $row['status'],
            'current_department' => (string) $row['current_department'],
            'in_transit_to' => $row['in_transit_to'] === null ? null : (string) $row['in_transit_to'],
            'last_updated' => (string) $row['last_updated'],
        ];
    }

    /**
     * Lock a document row until the current transaction ends (SELECT ... FOR UPDATE).
     * Every state change must call this first.
     *
     * @return array<string, mixed>|null
     */
    public function lockForUpdate(int $id): ?array
    {
        return $this->db->fetch('SELECT * FROM documents WHERE id = ? FOR UPDATE', [$id]);
    }
}
