<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\User;
use App\Services\AlertService;
use App\Services\DashboardService;
use App\Services\DocumentService;
use App\Services\MovementService;
use App\Services\SettingService;
use App\Services\WorkingDayService;
use Core\Database;
use Core\Logger;
use DateTimeImmutable;
use Tests\DatabaseTestCase;

/**
 * FR-10 alerts (thresholds, escalation, repeat window, overdue) and FR-11 dashboard
 * figures, which must agree with the document lists.
 */
final class AlertsAndDashboardTest extends DatabaseTestCase
{
    private int $reg;
    private int $bur;
    private int $ict;
    private int $typeId;
    private string $logFile;

    /** @var array<string, array<string, mixed>> */
    private array $u = [];

    private MovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::flushCache();
        $this->logFile = (string) tempnam(sys_get_temp_dir(), 'dtslog');
        Logger::setPath($this->logFile);

        $this->reg = $this->createDepartment('REG', 'Registry');
        $this->bur = $this->createDepartment('BUR', 'Bursary');
        $this->ict = $this->createDepartment('ICT', 'ICT');
        $this->db->execute("INSERT INTO document_types (name, sla_days) VALUES ('Memo', 3)");
        $this->typeId = $this->db->lastInsertId();
        $this->u['reg'] = $this->user('reg', $this->reg, 'staff');
        $this->u['bur1'] = $this->user('bur1', $this->bur, 'staff');
        $this->u['bur2'] = $this->user('bur2', $this->bur, 'staff');
        $this->u['burHod'] = $this->user('burhod', $this->bur, 'hod');
        $this->u['ict'] = $this->user('ict', $this->ict, 'staff');
        $this->movements = new MovementService();
    }

    protected function tearDown(): void
    {
        @unlink($this->logFile);
        parent::tearDown();
    }

    // ---------------------------------------------------------------- unreceived alerts

    public function testNoAlertBeforeTheThreshold(): void
    {
        $doc = $this->sentToBur(hoursAgo: 10);

        $this->assertSame(0, (new AlertService())->run()['unreceived']);
        $this->assertSame(0, $this->alertCount('unreceived_alert', $doc));
    }

    public function testUnreceivedAlertGoesToDestinationStaffAndSender(): void
    {
        $doc = $this->sentToBur(hoursAgo: 25);

        $summary = (new AlertService())->run();

        $this->assertSame(['locked' => false, 'unreceived' => 1, 'escalated' => 0, 'overdue' => 0, 'notified' => 3], $summary);
        $this->assertEqualsCanonicalizing(['bur1', 'bur2', 'reg'], $this->alertedUsers('unreceived_alert', $doc), 'Staff + sender, not the HOD yet');
        $this->assertNotNull($this->db->fetchValue("SELECT last_alert_at FROM document_movements WHERE document_id = ? AND status = 'in_transit'", [$doc]));
        $this->assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM email_queue WHERE subject LIKE '%not yet received by Bursary'"));
        $this->assertStringContainsString('check_alerts: 1 unreceived (0 escalated to HOD), 0 overdue, 3 notification(s).', (string) file_get_contents($this->logFile));
    }

    public function testEscalatesToHodAfterTwiceTheThreshold(): void
    {
        $doc = $this->sentToBur(hoursAgo: 49);

        $summary = (new AlertService())->run();

        $this->assertSame(1, $summary['escalated']);
        $this->assertEqualsCanonicalizing(['bur1', 'bur2', 'burhod', 'reg'], $this->alertedUsers('unreceived_alert', $doc));
        $this->assertStringContainsString('escalated to the head of department', (string) $this->db->fetchValue(
            'SELECT message FROM notifications WHERE user_id = ? AND type = ?',
            [(int) $this->u['burHod']['id'], 'unreceived_alert']
        ));
    }

    public function testAlertsAreNotRepeatedWithinTheRepeatWindow(): void
    {
        $doc = $this->sentToBur(hoursAgo: 25);
        $alerts = new AlertService();

        $alerts->run();
        $this->assertSame(0, $alerts->run()['unreceived'], 'Second run in the same window sends nothing');
        $this->assertSame(3, $this->alertCount('unreceived_alert', $doc));

        $this->db->execute("UPDATE document_movements SET last_alert_at = NOW() - INTERVAL 25 HOUR WHERE document_id = ?", [$doc]);
        $this->assertSame(1, $alerts->run()['unreceived'], 'Alerts again once the window has passed');
        $this->assertSame(6, $this->alertCount('unreceived_alert', $doc));
    }

    public function testReceivedOrRecalledDocumentsAreNotUnreceived(): void
    {
        $received = $this->sentToBur(hoursAgo: 30);
        $this->movements->receive($received, $this->u['bur1']);
        $recalled = $this->sentToBur(hoursAgo: 30);
        $this->movements->recall($recalled, 'Changed my mind', $this->u['reg']);

        $this->assertSame(0, (new AlertService())->run()['unreceived']);
    }

    // ---------------------------------------------------------------- overdue alerts

    public function testOverdueAlertGoesToTheWholeHolderDepartment(): void
    {
        $doc = $this->receivedAtBur(dueDaysAgo: 2);

        $summary = (new AlertService())->run();

        $this->assertSame(1, $summary['overdue']);
        $this->assertEqualsCanonicalizing(['bur1', 'bur2', 'burhod'], $this->alertedUsers('overdue_alert', $doc), 'Holder dept users + HOD');
        $this->assertStringContainsString('(2 days ago)', (string) $this->db->fetchValue("SELECT message FROM notifications WHERE type = 'overdue_alert' LIMIT 1"));
        $this->assertSame(0, (new AlertService())->run()['overdue'], 'Not repeated within the window');
    }

    public function testNotOverdueOnTheDueDateOrOnceCompleted(): void
    {
        $this->receivedAtBur(dueDaysAgo: 0);
        $completed = $this->receivedAtBur(dueDaysAgo: 5);
        $this->movements->complete($completed, $this->u['bur1']);

        $this->assertSame(0, (new AlertService())->run()['overdue']);
    }

    public function testConcurrentRunIsSkipped(): void
    {
        $other = Database::fromConfig((new \ReflectionProperty(Database::class, 'config'))->getValue($this->db));
        $other->fetchValue("SELECT GET_LOCK('vvu_dts_check_alerts', 0)");
        try {
            $this->assertTrue((new AlertService())->run()['locked']);
        } finally {
            $other->fetchValue("SELECT RELEASE_LOCK('vvu_dts_check_alerts')");
        }
    }

    // ---------------------------------------------------------------- overdue / stuck queries and dashboards

    public function testDashboardCardsMatchTheListsTheyLinkTo(): void
    {
        $this->sentToBur(hoursAgo: 1);
        $this->sentToBur(hoursAgo: 30);
        $this->receivedAtBur(dueDaysAgo: 3);
        $this->receivedAtBur(dueDaysAgo: -2);
        $done = $this->receivedAtBur(dueDaysAgo: 0);
        $this->movements->complete($done, $this->u['bur2']);

        $cards = (new DashboardService())->department($this->u['bur1'], 24)['cards'];
        $docs = new Document();
        $total = fn (string $tab, array $filters = []): int => $docs->listing($this->u['bur1'], $tab, $filters, 'created', 'desc', 1, 24)['total'];

        $this->assertSame(['incoming' => 2, 'with_us' => 2, 'outgoing' => 0, 'overdue' => 1, 'completed_month' => 1], $cards);
        $this->assertSame($cards['incoming'], $total('incoming'));
        $this->assertSame($cards['with_us'], $total('with-us'));
        $this->assertSame($cards['overdue'], $total('with-us', ['flag' => 'overdue']));
        $this->assertSame($cards['completed_month'], $total('completed', ['flag' => 'completed-month', 'current' => (string) $this->bur]));
        $this->assertSame(1, $total('incoming', ['flag' => 'stuck']), 'Stuck = in transit > 24 h');

        $regCards = (new DashboardService())->department($this->u['reg'], 24)['cards'];
        $this->assertSame(2, $regCards['outgoing']);
        $this->assertSame(0, $regCards['overdue'], 'Overdue counts only for the holder department');
    }

    public function testAdminFiguresByStatusAndTopOverdueDepartments(): void
    {
        $this->sentToBur(hoursAgo: 30);
        $this->receivedAtBur(dueDaysAgo: 1);
        $this->receivedAtBur(dueDaysAgo: 4);
        $this->doc();

        $admin = (new DashboardService())->admin(24);

        $this->assertSame(['draft' => 1, 'in_transit' => 1, 'received' => 2, 'completed' => 0, 'cancelled' => 0], $admin['byStatus']);
        $this->assertSame(2, $admin['overdueTotal']);
        $this->assertSame(1, $admin['stuckTotal']);
        $this->assertSame([['id' => $this->bur, 'code' => 'BUR', 'name' => 'Bursary', 'overdue' => 2]], $admin['topOverdue']);
        $this->assertCount(1, $admin['stuck']);
        $this->assertNotEmpty($admin['activity']);
    }

    public function testAverageProcessingTimeForTheLast30Days(): void
    {
        // Received 5 h ago, forwarded 3 h ago → 2 h.
        $a = $this->receivedAtBur(dueDaysAgo: -3);
        $this->db->execute("UPDATE document_movements SET received_at = NOW() - INTERVAL 5 HOUR WHERE document_id = ? AND status = 'received'", [$a]);
        $this->movements->forward($a, $this->to($this->ict), $this->u['bur1']);
        $this->db->execute("UPDATE document_movements SET sent_at = NOW() - INTERVAL 3 HOUR WHERE document_id = ? AND status = 'in_transit'", [$a]);

        // Received 6 h ago, completed 2 h ago → 4 h.
        $b = $this->receivedAtBur(dueDaysAgo: -3);
        $this->db->execute("UPDATE document_movements SET received_at = NOW() - INTERVAL 6 HOUR WHERE document_id = ? AND status = 'received'", [$b]);
        $this->movements->complete($b, $this->u['bur1']);
        $this->db->execute('UPDATE documents SET completed_at = NOW() - INTERVAL 2 HOUR WHERE id = ?', [$b]);

        // Still being processed, and a recalled forward: neither counts.
        $c = $this->receivedAtBur(dueDaysAgo: -3);
        $this->movements->forward($c, $this->to($this->ict), $this->u['bur1']);
        $this->movements->recall($c, 'Not yet', $this->u['bur1']);

        $avg = (new DocumentMovement())->averageProcessing($this->bur, 30);

        $this->assertSame(2, $avg['count']);
        $this->assertEqualsWithDelta(3 * 3600, $avg['seconds'], 5, 'Average of 2 h and 4 h');
        $this->assertSame(['seconds' => null, 'count' => 0], (new DocumentMovement())->averageProcessing($this->ict, 30));
    }

    public function testWorkingDaysUseHolidaysFromTheDatabase(): void
    {
        $this->db->execute("INSERT INTO holidays (holiday_date, name) VALUES ('2030-03-06', 'Independence Day'), ('2030-03-07', 'Extra day')");

        // Tuesday 5 March 2030 + 2 working days, with Wed 6 and Thu 7 off → Fri 8, Mon 11.
        $due = (new WorkingDayService())->addWorkingDays(new DateTimeImmutable('2030-03-05'), 2);

        $this->assertSame('2030-03-11', $due->format('Y-m-d'));
    }

    // ---------------------------------------------------------------- helpers

    private function doc(): int
    {
        return (new DocumentService())->register([
            'title' => 'Doc ' . bin2hex(random_bytes(3)), 'document_type_id' => $this->typeId, 'reference_no' => null,
            'description' => null, 'priority' => 'normal', 'confidentiality' => 'normal', 'format' => 'physical', 'pages' => null,
        ], [], $this->u['reg']);
    }

    private function sentToBur(int $hoursAgo): int
    {
        $doc = $this->doc();
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg']);
        $this->db->execute(
            "UPDATE document_movements SET sent_at = NOW() - INTERVAL $hoursAgo HOUR WHERE document_id = ? AND status = 'in_transit'",
            [$doc]
        );
        $this->db->execute('DELETE FROM notifications');
        $this->db->execute('DELETE FROM email_queue');

        return $doc;
    }

    /**
     * Sent to and received by BUR, with the due date $dueDaysAgo days in the past (negative = future).
     */
    private function receivedAtBur(int $dueDaysAgo): int
    {
        $doc = $this->doc();
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg']);
        $this->movements->receive($doc, $this->u['bur1']);
        $this->db->execute(
            "UPDATE document_movements SET due_date = DATE_SUB(CURDATE(), INTERVAL $dueDaysAgo DAY) WHERE document_id = ?",
            [$doc]
        );
        $this->db->execute('DELETE FROM notifications');
        $this->db->execute('DELETE FROM email_queue');

        return $doc;
    }

    /**
     * @return array{to_department_id: int, to_user_id: null, note: string}
     */
    private function to(int $dept): array
    {
        return ['to_department_id' => $dept, 'to_user_id' => null, 'note' => 'Please treat'];
    }

    private function alertCount(string $type, int $doc): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE type = ? AND document_id = ?', [$type, $doc]);
    }

    /**
     * @return list<string> Staff-ID suffixes of the users who received the alert
     */
    private function alertedUsers(string $type, int $doc): array
    {
        return array_map(
            static fn (array $r): string => substr((string) $r['staff_id'], 2),
            $this->db->fetchAll(
                'SELECT u.staff_id FROM notifications n JOIN users u ON u.id = n.user_id WHERE n.type = ? AND n.document_id = ?',
                [$type, $doc]
            )
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $key, int $dept, string $role): array
    {
        $this->db->execute(
            'INSERT INTO users (staff_id, full_name, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, ?, ?)',
            ["A-$key", ucfirst($key), "$key@a.local", 'x', $role, $dept]
        );

        return (new User())->find($this->db->lastInsertId());
    }
}
