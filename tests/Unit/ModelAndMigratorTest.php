<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\AuditLog;
use App\Models\Model;
use App\Models\TrackingSequence;
use Core\Migrator;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

/**
 * Guards that must hold without a database (no query is ever sent in these tests).
 */
final class ModelAndMigratorTest extends TestCase
{
    public function testBuildWhereHandlesEqualsNullAndIn(): void
    {
        [$sql, $bindings] = (new ProbeModel())->probeWhere(['role' => ['hod', 'staff'], 'locked_until' => null, 'is_active' => true]);

        $this->assertSame(' WHERE `role` IN (?, ?) AND `locked_until` IS NULL AND `is_active` = ?', $sql);
        $this->assertSame(['hod', 'staff', 1], $bindings);
    }

    public function testEmptyInListMatchesNothing(): void
    {
        [$sql, $bindings] = (new ProbeModel())->probeWhere(['id' => []]);

        $this->assertSame(' WHERE 1 = 0', $sql);
        $this->assertSame([], $bindings);
    }

    public function testOrderByIsValidatedAndQuoted(): void
    {
        $this->assertSame(' ORDER BY `name` ASC, `created_at` DESC', (new ProbeModel())->probeOrderBy('name, created_at desc'));

        $this->expectException(InvalidArgumentException::class);
        (new ProbeModel())->probeOrderBy('name; DROP TABLE users');
    }

    public function testConditionColumnNamesAreValidated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ProbeModel())->probeWhere(['id = 1 OR 1' => 1]);
    }

    public function testOnlyFillableColumnsAreKept(): void
    {
        $this->assertSame(['name' => 'x'], (new ProbeModel())->probeFillable(['name' => 'x', 'id' => 5, 'is_admin' => 1]));
    }

    public function testAuditLogsAreImmutable(): void
    {
        $this->expectException(LogicException::class);
        (new AuditLog())->update(1, ['action' => 'tampered']);
    }

    public function testCompositeKeyModelHasNoFind(): void
    {
        $this->expectException(LogicException::class);
        (new TrackingSequence())->find(1);
    }

    public function testSplitStatementsIgnoresCommentsAndSplitsOnLineEndSemicolons(): void
    {
        $sql = "-- header comment\nCREATE TABLE a (\n  id INT -- inline stays\n);\n\n-- next\nALTER TABLE a ADD b INT;\n";

        $this->assertSame(
            ["CREATE TABLE a (\n  id INT -- inline stays\n)", 'ALTER TABLE a ADD b INT'],
            Migrator::splitStatements($sql)
        );
    }

    public function testEveryMigrationFileParsesIntoStatements(): void
    {
        $files = glob(dirname(__DIR__, 2) . '/database/migrations/*.sql') ?: [];

        $this->assertCount(17, $files);
        foreach ($files as $file) {
            $this->assertNotEmpty(Migrator::splitStatements((string) file_get_contents($file)), basename($file));
        }
    }
}

/** Exposes the protected query builders of the base Model for testing. */
final class ProbeModel extends Model
{
    protected string $table = 'probe';

    /** @var list<string> */
    protected array $fillable = ['name'];

    /** @param array<string, mixed> $c @return array{0: string, 1: list<mixed>} */
    public function probeWhere(array $c): array
    {
        return $this->buildWhere($c);
    }

    public function probeOrderBy(string $o): string
    {
        return $this->buildOrderBy($o);
    }

    /** @param array<string, mixed> $d @return array<string, mixed> */
    public function probeFillable(array $d): array
    {
        return $this->onlyFillable($d);
    }
}
