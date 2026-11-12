<?php

declare(strict_types=1);

namespace Tests;

use Core\Database;
use Core\Migrator;
use PDOException;
use PHPUnit\Framework\TestCase;

/**
 * Base class for tests that need MySQL. Uses a separate database (DB_TEST_DATABASE,
 * default "vvu_dts_test") that is rebuilt from the migrations once per test run.
 * Each test runs inside a transaction that is rolled back afterwards.
 * Tests are skipped when the database server is not reachable.
 */
abstract class DatabaseTestCase extends TestCase
{
    private static bool $migrated = false;

    private static ?string $skipReason = null;

    protected Database $db;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$skipReason !== null) {
            $this->markTestSkipped(self::$skipReason);
        }

        $config = self::config();
        try {
            if (!self::$migrated) {
                Migrator::ensureDatabaseExists($config);
                $db = Database::fromConfig($config);
                $migrator = new Migrator($db, dirname(__DIR__) . '/database/migrations', static fn (string $l) => null);
                $migrator->dropAllTables();
                $migrator->migrate();
                self::$migrated = true;
            }
        } catch (PDOException $e) {
            self::$skipReason = 'Test database unavailable: ' . $e->getMessage();
            $this->markTestSkipped(self::$skipReason);
        }

        $this->db = Database::fromConfig($config);
        Database::setInstance($this->db);
        $this->db->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->db) && $this->db->pdo()->inTransaction()) {
            $this->db->pdo()->rollBack();
        }
        Database::setInstance(null);
        parent::tearDown();
    }

    /**
     * Insert a department and return its ID.
     */
    protected function createDepartment(string $code, string $name = ''): int
    {
        $this->db->execute('INSERT INTO departments (code, name) VALUES (?, ?)', [$code, $name !== '' ? $name : "Dept $code"]);

        return $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>
     */
    private static function config(): array
    {
        $env = static fn (string $key, string $default): string => (string) (getenv($key) !== false ? getenv($key) : $default);

        return [
            'host' => $env('DB_TEST_HOST', '127.0.0.1'),
            'port' => (int) $env('DB_TEST_PORT', '3306'),
            'database' => $env('DB_TEST_DATABASE', 'vvu_dts_test'),
            'username' => $env('DB_TEST_USERNAME', 'root'),
            'password' => $env('DB_TEST_PASSWORD', ''),
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
        ];
    }
}
