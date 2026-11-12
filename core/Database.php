<?php

declare(strict_types=1);

namespace Core;

use PDO;
use PDOStatement;
use Throwable;

/**
 * Lazily-connected PDO singleton with small query helpers.
 *
 * Emulated prepares are disabled so parameters are always sent separately from SQL.
 */
final class Database
{
    private static ?self $instance = null;

    private ?PDO $pdo = null;

    /**
     * @param array<string, mixed> $config Keys: host, port, database, username, password, charset, collation
     */
    private function __construct(private readonly array $config)
    {
    }

    /**
     * Get the shared instance (configured from config/database.php).
     */
    public static function instance(): self
    {
        return self::$instance ??= new self((array) Config::get('database', []));
    }

    /**
     * Get the underlying PDO connection, connecting on first use.
     */
    public function pdo(): PDO
    {
        if ($this->pdo === null) {
            $c = $this->config;
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $c['host'] ?? '127.0.0.1',
                (int) ($c['port'] ?? 3306),
                $c['database'] ?? '',
                $c['charset'] ?? 'utf8mb4'
            );

            $this->pdo = new PDO($dsn, (string) ($c['username'] ?? ''), (string) ($c['password'] ?? ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::ATTR_STRINGIFY_FETCHES => false,
            ]);

            $collation = (string) ($c['collation'] ?? 'utf8mb4_unicode_ci');
            if (preg_match('/^[a-z0-9_]+$/', $collation) === 1) {
                $this->pdo->exec("SET NAMES utf8mb4 COLLATE $collation");
            }
            // Keep MySQL NOW()/CURRENT_TIMESTAMP aligned with PHP's Africa/Accra (UTC+0, no DST).
            $this->pdo->exec("SET time_zone = '+00:00'");
        }

        return $this->pdo;
    }

    /**
     * Prepare and execute a statement with bound parameters.
     *
     * @param array<int|string, mixed> $params
     */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt;
    }

    /**
     * Fetch the first row, or null when there is none.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, mixed>|null
     */
    public function fetch(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();

        return $row === false ? null : $row;
    }

    /**
     * Fetch all rows.
     *
     * @param array<int|string, mixed> $params
     * @return list<array<string, mixed>>
     */
    public function fetchAll(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /**
     * Fetch the first column of the first row, or null.
     *
     * @param array<int|string, mixed> $params
     */
    public function fetchValue(string $sql, array $params = []): mixed
    {
        $value = $this->query($sql, $params)->fetchColumn();

        return $value === false ? null : $value;
    }

    /**
     * Execute a write statement and return the number of affected rows.
     *
     * @param array<int|string, mixed> $params
     */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    public function lastInsertId(): int
    {
        return (int) $this->pdo()->lastInsertId();
    }

    /**
     * Run $callback inside a transaction. Commits on success, rolls back and
     * rethrows on any exception. Returns the callback's result.
     *
     * Nested calls join the outer transaction rather than opening a new one.
     *
     * @template T
     * @param callable(self): T $callback
     * @return T
     */
    public function transaction(callable $callback): mixed
    {
        $pdo = $this->pdo();

        if ($pdo->inTransaction()) {
            return $callback($this);
        }

        $pdo->beginTransaction();
        try {
            $result = $callback($this);
            $pdo->commit();

            return $result;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Check that the database is reachable.
     */
    public function ping(): bool
    {
        return (int) $this->fetchValue('SELECT 1') === 1;
    }
}
