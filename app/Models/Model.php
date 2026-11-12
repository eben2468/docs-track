<?php

declare(strict_types=1);

namespace App\Models;

use Core\Database;
use InvalidArgumentException;
use LogicException;

/**
 * Thin table gateway. Subclasses set $table, $fillable and (optionally) $primaryKey.
 *
 * Every query uses bound parameters for values. Column names come only from code and
 * are checked against a strict identifier pattern; insert/update accept only $fillable
 * columns, so request arrays can never introduce arbitrary columns.
 *
 * There is deliberately no generic delete(): history is never deleted (users and
 * departments are deactivated). Models whose rows may be removed add their own method.
 *
 * Conditions are column => value pairs combined with AND; a null value means IS NULL
 * and an array value means IN (...).
 */
abstract class Model
{
    protected string $table = '';

    /** Primary key column, or null for tables with a composite/natural key. */
    protected ?string $primaryKey = 'id';

    /** @var list<string> Columns that insert()/update() may write. */
    protected array $fillable = [];

    protected Database $db;

    public function __construct(?Database $db = null)
    {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Find one row by primary key.
     *
     * @return array<string, mixed>|null
     */
    public function find(int|string $id): ?array
    {
        return $this->db->fetch(
            sprintf('SELECT * FROM `%s` WHERE `%s` = ? LIMIT 1', $this->table, $this->pk()),
            [$id]
        );
    }

    /**
     * All rows, optionally ordered (e.g. "name ASC").
     *
     * @return list<array<string, mixed>>
     */
    public function all(?string $orderBy = null): array
    {
        return $this->where([], $orderBy);
    }

    /**
     * Rows matching all conditions.
     *
     * @param array<string, mixed> $conditions
     * @return list<array<string, mixed>>
     */
    public function where(array $conditions, ?string $orderBy = null, ?int $limit = null, int $offset = 0): array
    {
        [$whereSql, $bindings] = $this->buildWhere($conditions);
        $sql = sprintf('SELECT * FROM `%s`%s%s', $this->table, $whereSql, $this->buildOrderBy($orderBy));

        if ($limit !== null) {
            $sql .= sprintf(' LIMIT %d OFFSET %d', max(0, $limit), max(0, $offset));
        }

        return $this->db->fetchAll($sql, $bindings);
    }

    /**
     * The first row matching all conditions, or null.
     *
     * @param array<string, mixed> $conditions
     * @return array<string, mixed>|null
     */
    public function first(array $conditions, ?string $orderBy = null): ?array
    {
        return $this->where($conditions, $orderBy, 1)[0] ?? null;
    }

    /**
     * Number of rows matching all conditions.
     *
     * @param array<string, mixed> $conditions
     */
    public function count(array $conditions = []): int
    {
        [$whereSql, $bindings] = $this->buildWhere($conditions);

        return (int) $this->db->fetchValue(sprintf('SELECT COUNT(*) FROM `%s`%s', $this->table, $whereSql), $bindings);
    }

    /**
     * Insert a row (only $fillable columns) and return the new auto-increment ID (0 if none).
     *
     * @param array<string, mixed> $data
     */
    public function insert(array $data): int
    {
        $data = $this->onlyFillable($data);
        if ($data === []) {
            throw new InvalidArgumentException(static::class . '::insert() received no fillable columns.');
        }

        $columns = array_keys($data);
        $this->db->execute(
            sprintf(
                'INSERT INTO `%s` (%s) VALUES (%s)',
                $this->table,
                implode(', ', array_map(static fn (string $c): string => "`$c`", $columns)),
                implode(', ', array_fill(0, count($columns), '?'))
            ),
            array_values($data)
        );

        return $this->db->lastInsertId();
    }

    /**
     * Update a row by primary key (only $fillable columns). Returns affected rows.
     *
     * @param array<string, mixed> $data
     */
    public function update(int|string $id, array $data): int
    {
        $data = $this->onlyFillable($data);
        if ($data === []) {
            return 0;
        }

        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = ?", array_keys($data)));

        return $this->db->execute(
            sprintf('UPDATE `%s` SET %s WHERE `%s` = ?', $this->table, $set, $this->pk()),
            [...array_values($data), $id]
        );
    }

    /**
     * One page of rows plus paging metadata.
     *
     * @param array<string, mixed> $conditions
     * @return array{data: list<array<string, mixed>>, total: int, page: int, per_page: int, last_page: int}
     */
    public function paginate(int $page = 1, int $perPage = 25, array $conditions = [], ?string $orderBy = null): array
    {
        $perPage = max(1, min(200, $perPage));
        $total = $this->count($conditions);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);

        return [
            'data' => $this->where($conditions, $orderBy, $perPage, ($page - 1) * $perPage),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
        ];
    }

    public function table(): string
    {
        return $this->table;
    }

    /**
     * @param array<string, mixed> $conditions
     * @return array{0: string, 1: list<mixed>}
     */
    protected function buildWhere(array $conditions): array
    {
        if ($conditions === []) {
            return ['', []];
        }

        $clauses = [];
        $bindings = [];
        foreach ($conditions as $column => $value) {
            $column = self::identifier((string) $column);
            if ($value === null) {
                $clauses[] = "`$column` IS NULL";
            } elseif (is_array($value)) {
                if ($value === []) {
                    $clauses[] = '1 = 0';
                    continue;
                }
                $clauses[] = "`$column` IN (" . implode(', ', array_fill(0, count($value), '?')) . ')';
                array_push($bindings, ...array_values($value));
            } else {
                $clauses[] = "`$column` = ?";
                $bindings[] = is_bool($value) ? (int) $value : $value;
            }
        }

        return [' WHERE ' . implode(' AND ', $clauses), $bindings];
    }

    /**
     * Validate "col", "col DESC" or "col1 ASC, col2 DESC" and quote the columns.
     */
    protected function buildOrderBy(?string $orderBy): string
    {
        if ($orderBy === null || trim($orderBy) === '') {
            return '';
        }

        $parts = [];
        foreach (explode(',', $orderBy) as $part) {
            if (preg_match('/^\s*([A-Za-z_][A-Za-z0-9_]*)(?:\s+(ASC|DESC))?\s*$/i', $part, $m) !== 1) {
                throw new InvalidArgumentException("Invalid ORDER BY clause [$orderBy].");
            }
            $parts[] = '`' . $m[1] . '` ' . strtoupper($m[2] ?? 'ASC');
        }

        return ' ORDER BY ' . implode(', ', $parts);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function onlyFillable(array $data): array
    {
        return array_intersect_key($data, array_flip($this->fillable));
    }

    /**
     * Ensure a column name is a plain SQL identifier.
     */
    protected static function identifier(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid column name [$name].");
        }

        return $name;
    }

    private function pk(): string
    {
        return $this->primaryKey ?? throw new LogicException(static::class . ' has no single primary key.');
    }
}
