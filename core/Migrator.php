<?php

declare(strict_types=1);

namespace Core;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Runs numbered SQL migrations from database/migrations in filename order and
 * records each applied file in the `migrations` table.
 *
 * MySQL commits DDL implicitly, so a failed file is not rolled back: the runner
 * stops at the first error and reports the file so it can be fixed and re-run.
 */
final class Migrator
{
    private const TABLE = 'migrations';

    /** @var callable(string): void */
    private $output;

    /**
     * @param callable(string): void $output Receives one line of console output at a time
     */
    public function __construct(
        private readonly Database $db,
        private readonly string $migrationsPath,
        callable $output,
    ) {
        $this->output = $output;
    }

    /**
     * Create the configured database (utf8mb4) if it does not exist yet.
     *
     * @param array<string, mixed> $config Keys from config/database.php
     */
    public static function ensureDatabaseExists(array $config): bool
    {
        $name = (string) ($config['database'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $name) !== 1) {
            throw new RuntimeException("Invalid database name [$name]. Use letters, digits and underscores only.");
        }

        $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $config['host'] ?? '127.0.0.1', (int) ($config['port'] ?? 3306));
        $pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $exists = $pdo->prepare('SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = ?');
        $exists->execute([$name]);
        if ((int) $exists->fetchColumn() > 0) {
            return false;
        }

        $pdo->exec("CREATE DATABASE `$name` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

        return true;
    }

    /**
     * Drop every table in the current database (foreign key checks disabled meanwhile).
     *
     * @return list<string> Names of the dropped tables
     */
    public function dropAllTables(): array
    {
        $names = array_map(
            static fn (array $row): string => (string) array_values($row)[0],
            $this->db->fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'")
        );

        $this->db->execute('SET FOREIGN_KEY_CHECKS = 0');
        try {
            foreach ($names as $table) {
                $this->db->execute('DROP TABLE `' . str_replace('`', '``', $table) . '`');
            }
        } finally {
            $this->db->execute('SET FOREIGN_KEY_CHECKS = 1');
        }

        return $names;
    }

    /**
     * Apply every migration file that has not been applied yet.
     *
     * @return int Number of migrations applied
     * @throws RuntimeException naming the failing file
     */
    public function migrate(): int
    {
        $this->ensureMigrationsTable();
        $applied = array_flip($this->appliedMigrations());
        $pending = array_values(array_filter($this->migrationFiles(), static fn (string $f): bool => !isset($applied[basename($f)])));

        if ($pending === []) {
            ($this->output)('Nothing to migrate.');
            return 0;
        }

        $batch = (int) $this->db->fetchValue('SELECT COALESCE(MAX(batch), 0) + 1 FROM ' . self::TABLE);

        foreach ($pending as $file) {
            $name = basename($file);
            $started = microtime(true);
            try {
                foreach (self::splitStatements((string) file_get_contents($file)) as $statement) {
                    $this->db->execute($statement);
                }
            } catch (Throwable $e) {
                throw new RuntimeException("Migration failed in $name: " . $e->getMessage(), 0, $e);
            }

            $this->db->execute('INSERT INTO ' . self::TABLE . ' (migration, batch) VALUES (?, ?)', [$name, $batch]);
            ($this->output)(sprintf('  Migrated  %s (%d ms)', $name, (int) round((microtime(true) - $started) * 1000)));
        }

        return count($pending);
    }

    /**
     * Split a SQL file into statements: drops "-- " comment lines and splits on
     * semicolons at the end of a line. Migration files must not put ";\n" inside strings.
     *
     * @return list<string>
     */
    public static function splitStatements(string $sql): array
    {
        $lines = array_filter(
            preg_split('/\R/', $sql) ?: [],
            static fn (string $line): bool => !str_starts_with(ltrim($line), '--')
        );
        $parts = preg_split('/;\s*(?:\R|$)/', implode("\n", $lines)) ?: [];

        return array_values(array_filter(array_map('trim', $parts), static fn (string $s): bool => $s !== ''));
    }

    /**
     * Migration files sorted by name.
     *
     * @return list<string>
     */
    private function migrationFiles(): array
    {
        $files = glob(rtrim($this->migrationsPath, '/\\') . '/*.sql') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * @return list<string>
     */
    private function appliedMigrations(): array
    {
        return array_column($this->db->fetchAll('SELECT migration FROM ' . self::TABLE . ' ORDER BY id'), 'migration');
    }

    private function ensureMigrationsTable(): void
    {
        $this->db->execute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (
                id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                migration VARCHAR(255) NOT NULL UNIQUE,
                batch INT UNSIGNED NOT NULL,
                applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }
}
