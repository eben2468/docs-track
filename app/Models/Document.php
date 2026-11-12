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
        'current_department_id', 'current_holder_id', 'current_movement_id', 'status', 'completed_at', 'completed_by',
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

    /**
     * Documents joined with type, departments, holder, the open movement (om, with its
     * departments td/fd and sender fu) and the last received movement (lr).
     * DocumentScope conditions are written against these aliases.
     */
    public const BASE_FROM = "FROM documents d
            JOIN document_types t ON t.id = d.document_type_id
            JOIN departments od ON od.id = d.origin_department_id
            JOIN departments cd ON cd.id = d.current_department_id
            LEFT JOIN users hu ON hu.id = d.current_holder_id
            LEFT JOIN document_movements om ON om.document_id = d.id AND om.status = 'in_transit'
            LEFT JOIN departments td ON td.id = om.to_department_id
            LEFT JOIN departments fd ON fd.id = om.from_department_id
            LEFT JOIN users fu ON fu.id = om.from_user_id
            LEFT JOIN document_movements lr ON lr.id = d.current_movement_id";

    /** Extra list filters ("flag") matching the dashboard cards. */
    public const FLAGS = ['overdue', 'stuck', 'completed-month'];

    /**
     * Count documents matching all the given DocumentScope conditions.
     *
     * @param list<array{0: string, 1: list<mixed>}> $scopes
     */
    public function countScoped(array $scopes): int
    {
        $where = [];
        $bindings = [];
        foreach ($scopes as [$sql, $params]) {
            $where[] = $sql;
            array_push($bindings, ...$params);
        }
        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);

        return (int) $this->db->fetchValue('SELECT COUNT(*) ' . self::countFrom($whereSql) . $whereSql, $bindings);
    }

    /**
     * Rows (with the listing columns) matching the given scopes, for dashboard tables.
     *
     * @param list<array{0: string, 1: list<mixed>}> $scopes
     * @return list<array<string, mixed>>
     */
    public function findScoped(array $scopes, string $orderBy, int $limit): array
    {
        $where = [];
        $bindings = [];
        foreach ($scopes as [$sql, $params]) {
            $where[] = $sql;
            array_push($bindings, ...$params);
        }

        return $this->db->fetchAll(
            'SELECT d.id, d.tracking_no, d.title, d.confidentiality, d.priority, d.status,
                    od.code AS origin_code, cd.code AS current_code, cd.name AS current_name, hu.full_name AS holder_name,
                    om.sent_at AS om_sent_at, om.due_date AS om_due_date, td.code AS om_to_code, td.name AS om_to_name,
                    fd.code AS om_from_code, fu.full_name AS om_from_user,
                    lr.received_at AS lr_received_at, lr.due_date AS lr_due_date '
            . self::BASE_FROM . ($where === [] ? '' : ' WHERE ' . implode(' AND ', $where))
            . " ORDER BY $orderBy LIMIT " . max(1, min(200, $limit)),
            $bindings
        );
    }

    /**
     * University-wide number of documents per status (all statuses present, zero-filled).
     *
     * @return array<string, int>
     */
    public function countByStatus(): array
    {
        $counts = array_fill_keys(self::STATUSES, 0);
        foreach ($this->db->fetchAll('SELECT status, COUNT(*) AS n FROM documents GROUP BY status') as $row) {
            $counts[$row['status']] = (int) $row['n'];
        }

        return $counts;
    }

    /**
     * Departments holding the most overdue documents.
     *
     * @return list<array{id: int, code: string, name: string, overdue: int}>
     */
    public function topOverdueDepartments(int $limit = 5): array
    {
        [$sql, $bindings] = DocumentScope::overdue();
        $whereSql = " WHERE $sql";
        $rows = $this->db->fetchAll(
            'SELECT cd.id, cd.code, cd.name, x.overdue
             FROM (SELECT d.current_department_id, COUNT(*) AS overdue ' . self::countFrom($whereSql) . $whereSql . '
                   GROUP BY d.current_department_id) x
             JOIN departments cd ON cd.id = x.current_department_id
             ORDER BY x.overdue DESC, cd.name ASC LIMIT ' . max(1, min(50, $limit)),
            $bindings
        );

        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name'], 'overdue' => (int) $r['overdue'],
        ], $rows);
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

        $perPage = max(1, min(200, $perPage));
        $whereSql = substr($fromWhere, strlen(self::BASE_FROM));
        $total = (int) $this->db->fetchValue('SELECT COUNT(*) ' . self::countFrom($whereSql) . $whereSql, $bindings);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $offset = ($page - 1) * $perPage;

        return [
            'data' => $this->pageRows($select, $whereSql, $bindings, $orderBy, $perPage, $offset),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    /**
     * Deferred join: pick the page's document IDs with the leanest query that can filter and
     * sort, then load the full rows (names, movements, flags) for just those IDs.
     *
     * @param list<mixed> $bindings
     * @return list<array<string, mixed>>
     */
    private function pageRows(string $select, string $whereSql, array $bindings, string $orderBy, int $limit, int $offset): array
    {
        $ids = array_map('intval', array_column($this->db->fetchAll(
            'SELECT d.id ' . self::countFrom($whereSql . ' ' . $orderBy) . $whereSql . " ORDER BY $orderBy LIMIT $limit OFFSET $offset",
            $bindings
        ), 'id'));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        return $this->db->fetchAll("SELECT $select " . self::BASE_FROM . " WHERE d.id IN ($placeholders) ORDER BY $orderBy", $ids);
    }

    /**
     * The smallest FROM clause able to evaluate a WHERE clause written for BASE_FROM:
     * counting needs no names, so only the joins the conditions refer to are kept.
     * (Inner joins to type/departments never change the count: their keys are NOT NULL FKs.)
     */
    private static function countFrom(string $whereSql): string
    {
        $from = 'FROM documents d';
        if (preg_match('/\b(om|td|fd|fu)\./', $whereSql) === 1) {
            $from .= " LEFT JOIN document_movements om ON om.document_id = d.id AND om.status = 'in_transit'";
        }
        if (preg_match('/\blr\./', $whereSql) === 1) {
            $from .= ' LEFT JOIN document_movements lr ON lr.id = d.current_movement_id';
        }
        if (preg_match('/\bt\./', $whereSql) === 1) {
            $from .= ' JOIN document_types t ON t.id = d.document_type_id';
        }
        if (preg_match('/\b(od|cd|hu)\./', $whereSql) === 1) {
            return self::BASE_FROM; // rare: fall back to the full join
        }

        return $from . ' ';
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

        return $this->pageRows($select, substr($fromWhere, strlen(self::BASE_FROM)), $bindings, $orderBy, max(1, $limit), 0);
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

        return [
            'incoming' => $this->countScoped([DocumentScope::incoming($dept)]),
            'with_us' => $this->countScoped([DocumentScope::withUs($dept)]),
            'outgoing' => $this->countScoped([DocumentScope::outgoing($dept)]),
        ];
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

        $where = [];
        $bindings = [];
        $scopes = match ($tab) {
            'incoming' => [DocumentScope::incoming($dept)],
            'with-us' => [DocumentScope::withUs($dept)],
            'outgoing' => [DocumentScope::outgoing($dept)],
            'registered' => [DocumentScope::registered($dept)],
            'completed' => [["d.status = 'completed'", []]],
            default => [], // all, all-accessible
        };
        if (in_array($tab, ['completed', 'all', 'all-accessible'], true) && !$isAdmin) {
            $scopes[] = DocumentScope::accessibleTo($dept);
        }
        $scopes[] = match ($filters['flag'] ?? '') {
            'overdue' => DocumentScope::overdue(),
            'stuck' => DocumentScope::stuck($hours),
            'completed-month' => DocumentScope::completedThisMonth(),
            default => null,
        };
        foreach (array_filter($scopes) as [$sql, $params]) {
            $where[] = $sql;
            array_push($bindings, ...$params);
        }

        $this->applyFilters($filters, $where, $bindings);
        $from = self::BASE_FROM;

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
