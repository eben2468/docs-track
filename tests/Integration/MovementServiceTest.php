<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\User;
use App\Services\DocumentService;
use App\Services\MovementService;
use App\Services\ServiceException;
use App\Services\SettingService;
use App\Services\WorkingDayService;
use Tests\DatabaseTestCase;

/**
 * The movement engine against a real database: every allowed transition in spec §4.2
 * and every forbidden case in the §14 checklist.
 */
final class MovementServiceTest extends DatabaseTestCase
{
    private int $reg;
    private int $bur;
    private int $ict;
    private int $typeId;

    /** @var array<string, array<string, mixed>> */
    private array $u = [];

    private MovementService $movements;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::flushCache();

        $this->reg = $this->createDepartment('REG', 'Registry');
        $this->bur = $this->createDepartment('BUR', 'Bursary');
        $this->ict = $this->createDepartment('ICT', 'ICT');
        $this->db->execute("INSERT INTO document_types (name, sla_days) VALUES ('Memo', 3)");
        $this->typeId = $this->db->lastInsertId();

        foreach (['reg' => $this->reg, 'bur' => $this->bur, 'ict' => $this->ict] as $code => $dept) {
            $this->u["{$code}1"] = $this->createUser("{$code}1", $dept, 'staff');
            $this->u["{$code}2"] = $this->createUser("{$code}2", $dept, 'staff');
            $this->u["{$code}Hod"] = $this->createUser("{$code}hod", $dept, 'hod');
        }
        $this->u['admin'] = $this->createUser('admin', $this->ict, 'admin');

        $this->movements = new MovementService();
    }

    // ---------------------------------------------------------------- allowed transitions

    public function testSendMovesDraftIntoTransitAndNotifiesDestination(): void
    {
        $doc = $this->draft('reg1');

        $movementId = $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->assertDocument($doc, 'in_transit', $this->reg);
        $m = $this->movement($movementId);
        $this->assertSame(['send', 'in_transit', $this->reg, $this->bur], [$m['action'], $m['status'], (int) $m['from_department_id'], (int) $m['to_department_id']]);
        $this->assertSame(WorkingDayService::add(now(), 3, [])->format('Y-m-d'), $m['due_date'], 'Default due date = type SLA in working days');
        $this->assertSame(3, $this->notificationCount('incoming', $doc), 'All active BUR users notified');
        $this->assertSame(0, $this->notificationCountFor($this->u['reg1'], $doc), 'Sender is not notified of their own action');
        $this->assertAudited('document.sent', $doc);
    }

    public function testSendToSpecificRecipientNotifiesOnlyThem(): void
    {
        $doc = $this->draft('reg1');

        $this->movements->send($doc, $this->to($this->bur, (int) $this->u['bur2']['id']), $this->u['reg1']);

        $this->assertSame(1, $this->notificationCount('incoming', $doc));
        $this->assertSame(1, $this->notificationCountFor($this->u['bur2'], $doc));
    }

    public function testReceiveMakesDestinationTheHolder(): void
    {
        $doc = $this->draft('reg1');
        $movementId = $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->movements->receive($doc, $this->u['bur2'], 'All pages present', 'qr');

        $this->assertDocument($doc, 'received', $this->bur, (int) $this->u['bur2']['id']);
        $m = $this->movement($movementId);
        $this->assertSame(['received', (int) $this->u['bur2']['id'], 'qr', 'All pages present'], [$m['status'], (int) $m['received_by'], $m['receive_method'], $m['receipt_note']]);
        $this->assertNotNull($m['received_at']);
        $this->assertSame(1, $this->notificationCountFor($this->u['reg1'], $doc, 'received'), 'Sender told it arrived');
        $this->assertAudited('document.received', $doc);
    }

    public function testForwardFromReceivedToAnotherDepartment(): void
    {
        $doc = $this->receivedAt('bur1', from: 'reg1');

        $movementId = $this->movements->forward($doc, $this->to($this->ict, null, 'Please treat'), $this->u['bur1']);

        $this->assertDocument($doc, 'in_transit', $this->bur);
        $this->assertSame('forward', $this->movement($movementId)['action']);
        $this->assertAudited('document.forwarded', $doc);
    }

    public function testReturnGoesBackToTheDepartmentAndPersonItCameFrom(): void
    {
        $doc = $this->receivedAt('bur1', from: 'reg1');

        $movementId = $this->movements->returnToSender($doc, 'Missing signature', $this->u['bur1']);

        $m = $this->movement($movementId);
        $this->assertSame(['return', $this->reg, (int) $this->u['reg1']['id'], 'Missing signature'], [$m['action'], (int) $m['to_department_id'], (int) $m['to_user_id'], $m['note']]);
        $this->assertDocument($doc, 'in_transit', $this->bur);
        $this->assertSame(1, $this->notificationCountFor($this->u['reg1'], $doc, 'returned'));
        $this->assertAudited('document.returned', $doc);

        $this->movements->receive($doc, $this->u['reg2'], null);
        $this->assertDocument($doc, 'received', $this->reg, (int) $this->u['reg2']['id']);
    }

    public function testRecallOfFirstSendRestoresDraft(): void
    {
        $doc = $this->draft('reg1');
        $movementId = $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->movements->recall($doc, 'Wrong department', $this->u['reg1']);

        $this->assertDocument($doc, 'draft', $this->reg);
        $m = $this->movement($movementId);
        $this->assertSame(['recalled', (int) $this->u['reg1']['id'], 'Wrong department'], [$m['status'], (int) $m['recalled_by'], $m['recall_reason']]);
        $this->assertSame(3, $this->notificationCount('recalled', $doc));
        $this->assertAudited('document.recalled', $doc);

        // It can be sent again afterwards.
        $this->movements->send($doc, $this->to($this->ict), $this->u['reg2']);
        $this->assertDocument($doc, 'in_transit', $this->reg);
    }

    public function testRecallOfForwardRestoresReceivedAtSender(): void
    {
        $doc = $this->receivedAt('bur1', from: 'reg1');
        $this->movements->forward($doc, $this->to($this->ict), $this->u['bur1']);

        $this->movements->recall($doc, 'Not ready yet', $this->u['burHod']);

        $this->assertDocument($doc, 'received', $this->bur, (int) $this->u['bur1']['id']);
    }

    public function testAdminCanRecallAnySend(): void
    {
        $doc = $this->draft('reg1');
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->movements->recall($doc, 'Admin correction', $this->u['admin']);

        $this->assertDocument($doc, 'draft', $this->reg);
    }

    public function testCompleteClosesTheDocumentAndNotifiesCreator(): void
    {
        $doc = $this->receivedAt('bur1', from: 'reg1');

        $this->movements->complete($doc, $this->u['bur2']);

        $row = $this->db->fetch('SELECT status, completed_by, completed_at FROM documents WHERE id = ?', [$doc]);
        $this->assertSame('completed', $row['status']);
        $this->assertSame((int) $this->u['bur2']['id'], (int) $row['completed_by']);
        $this->assertNotNull($row['completed_at']);
        $this->assertSame(1, $this->notificationCountFor($this->u['reg1'], $doc, 'completed'));
        $this->assertAudited('document.completed', $doc);
    }

    public function testBulkReceiveHandlesEachDocumentSeparately(): void
    {
        $a = $this->draft('reg1');
        $b = $this->draft('reg1');
        $c = $this->draft('reg1');
        $this->movements->send($a, $this->to($this->bur), $this->u['reg1']);
        $this->movements->send($b, $this->to($this->bur), $this->u['reg1']);
        $this->movements->send($c, $this->to($this->ict), $this->u['reg1']);

        $result = $this->movements->receiveBulk([$a, $b, $c], $this->u['bur1'], 'Batch');

        $this->assertCount(2, $result['received']);
        $this->assertCount(1, $result['failed']);
        $this->assertSame(['Document #' . $c => 'This document is not available to your department.'], $result['failed'], 'Does not reveal documents outside the route');
        $this->assertDocument($a, 'received', $this->bur);
        $this->assertDocument($c, 'in_transit', $this->reg);
        $this->assertSame('bulk', $this->db->fetchValue("SELECT receive_method FROM document_movements WHERE document_id = ? AND status = 'received'", [$a]));
    }

    public function testDocumentCanBeFollowedAcrossFourDepartments(): void
    {
        $hr = $this->createDepartment('HR', 'Human Resources');
        $hr1 = $this->createUser('hr1', $hr, 'staff');
        $doc = $this->draft('reg1');

        $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);
        $this->movements->receive($doc, $this->u['bur1']);
        $this->movements->forward($doc, $this->to($this->ict), $this->u['bur1']);
        $this->movements->receive($doc, $this->u['ict1']);
        $this->movements->forward($doc, $this->to($hr), $this->u['ict1']);
        $this->movements->receive($doc, $hr1);
        $this->movements->complete($doc, $hr1);

        $this->assertDocument($doc, 'completed', $hr, (int) $hr1['id']);
        $this->assertSame(3, (int) $this->db->fetchValue("SELECT COUNT(*) FROM document_movements WHERE document_id = ? AND status = 'received'", [$doc]));
        $actions = array_column($this->db->fetchAll('SELECT action FROM audit_logs WHERE document_id = ? ORDER BY id', [$doc]), 'action');
        $this->assertSame([
            'document.created', 'document.sent', 'document.received', 'document.forwarded', 'document.received',
            'document.forwarded', 'document.received', 'document.completed',
        ], $actions, 'Every state change writes an audit row');
    }

    public function testUserSuppliedDueDateIsKept(): void
    {
        $doc = $this->draft('reg1');
        $due = now()->modify('+10 days')->format('Y-m-d');

        $id = $this->movements->send($doc, $this->to($this->bur) + ['due_date' => $due], $this->u['reg1']);

        $this->assertSame($due, $this->movement($id)['due_date']);
    }

    public function testDefaultDueDateSkipsWeekendsAndHolidays(): void
    {
        $holiday = WorkingDayService::add(now(), 1, [])->format('Y-m-d');
        $this->db->execute("INSERT INTO holidays (holiday_date, name) VALUES (?, 'Test holiday')", [$holiday]);
        $doc = $this->draft('reg1');

        $id = $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->assertSame(WorkingDayService::add(now(), 3, [$holiday])->format('Y-m-d'), $this->movement($id)['due_date']);
    }

    // ---------------------------------------------------------------- forbidden (spec §14)

    public function testCannotReceiveADocumentSentToAnotherDepartment(): void
    {
        $doc = $this->draft('reg1');
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->assertRejected(fn () => $this->movements->receive($doc, $this->u['ict1']), 'not sent to your department');
        $this->assertRejected(fn () => $this->movements->receive($doc, $this->u['admin']), 'not sent to your department');
        $this->assertRejected(fn () => $this->movements->receive($doc, $this->u['reg1']), 'not sent to your department');
        $this->assertDocument($doc, 'in_transit', $this->reg);
    }

    public function testCannotReceiveTwice(): void
    {
        $doc = $this->draft('reg1');
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);
        $this->movements->receive($doc, $this->u['bur1']);

        $this->assertRejected(fn () => $this->movements->receive($doc, $this->u['bur2']), 'already been received');
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM document_movements WHERE document_id = ? AND status = 'received'", [$doc]));
    }

    public function testCannotForwardADocumentYourDepartmentDoesNotHold(): void
    {
        $doc = $this->receivedAt('bur1', from: 'reg1');

        $this->assertRejected(fn () => $this->movements->forward($doc, $this->to($this->ict), $this->u['ict1']), 'Only the department currently holding');
        $this->assertRejected(fn () => $this->movements->forward($doc, $this->to($this->ict), $this->u['reg1']), 'Only the department currently holding');
        $this->assertRejected(fn () => $this->movements->forward($doc, $this->to($this->reg), $this->u['admin']), 'Only the department currently holding');
    }

    public function testCannotSendADraftFromAnotherDepartment(): void
    {
        $doc = $this->draft('reg1');

        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->ict), $this->u['bur1']), 'Only the department currently holding');
    }

    public function testCannotSendToOwnDepartment(): void
    {
        $doc = $this->draft('reg1');

        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->reg), $this->u['reg1']), 'cannot be sent to the department that already holds it');

        $received = $this->receivedAt('bur1', from: 'reg1');
        $this->assertRejected(fn () => $this->movements->forward($received, $this->to($this->bur), $this->u['bur1']), 'cannot be sent to the department that already holds it');
    }

    public function testOnlyOneOpenMovementPerDocument(): void
    {
        $doc = $this->draft('reg1');
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->ict), $this->u['reg1']), 'already in transit');
        $this->assertRejected(fn () => $this->movements->forward($doc, $this->to($this->ict), $this->u['reg1']), 'already in transit');
        $this->assertSame(1, (int) $this->db->fetchValue("SELECT COUNT(*) FROM document_movements WHERE document_id = ? AND status = 'in_transit'", [$doc]));
    }

    public function testSendAndForwardRequireTheRightStatus(): void
    {
        $draft = $this->draft('reg1');
        $received = $this->receivedAt('bur1', from: 'reg1');

        $this->assertRejected(fn () => $this->movements->forward($draft, $this->to($this->bur), $this->u['reg1']), 'Only a document your department has received can be forwarded');
        $this->assertRejected(fn () => $this->movements->send($received, $this->to($this->ict), $this->u['bur1']), 'Only a draft can be sent');
    }

    public function testRecallIsBlockedAfterReceipt(): void
    {
        $doc = $this->draft('reg1');
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);
        $this->movements->receive($doc, $this->u['bur1']);

        $this->assertRejected(fn () => $this->movements->recall($doc, 'Too late', $this->u['reg1']), 'already been received');
    }

    public function testRecallOnlyBySenderSendingHodOrAdmin(): void
    {
        $doc = $this->draft('reg1');
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']);

        $this->assertRejected(fn () => $this->movements->recall($doc, 'x', $this->u['reg2']), 'Only the person who sent');
        $this->assertRejected(fn () => $this->movements->recall($doc, 'x', $this->u['burHod']), 'Only the person who sent');
        $this->assertRejected(fn () => $this->movements->recall($doc, 'x', $this->u['bur1']), 'Only the person who sent');

        $this->movements->recall($doc, 'HOD recall', $this->u['regHod']);
        $this->assertDocument($doc, 'draft', $this->reg);
    }

    public function testCompletedDocumentsAreReadOnly(): void
    {
        $doc = $this->receivedAt('bur1', from: 'reg1');
        $this->movements->complete($doc, $this->u['bur1']);
        $msg = 'can no longer be changed';

        $this->assertRejected(fn () => $this->movements->forward($doc, $this->to($this->ict), $this->u['bur1']), $msg);
        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->ict), $this->u['bur1']), $msg);
        $this->assertRejected(fn () => $this->movements->returnToSender($doc, 'x', $this->u['bur1']), $msg);
        $this->assertRejected(fn () => $this->movements->complete($doc, $this->u['bur1']), $msg);
        $this->assertRejected(fn () => $this->movements->receive($doc, $this->u['bur1']), $msg);
        $this->assertRejected(fn () => $this->movements->recall($doc, 'x', $this->u['admin']), $msg);
        $this->assertDocument($doc, 'completed', $this->bur);
    }

    public function testCancelledDocumentsAreReadOnly(): void
    {
        $doc = $this->draft('reg1');
        (new DocumentService())->cancel($doc, $this->u['reg1']);

        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->bur), $this->u['reg1']), 'can no longer be changed');
        $this->assertRejected(fn () => $this->movements->complete($doc, $this->u['reg1']), 'can no longer be changed');
    }

    public function testCompleteRequiresReceivedStatusAndHolder(): void
    {
        $draft = $this->draft('reg1');
        $this->assertRejected(fn () => $this->movements->complete($draft, $this->u['reg1']), 'Only a document that has been received');

        $this->movements->send($draft, $this->to($this->bur), $this->u['reg1']);
        $this->assertRejected(fn () => $this->movements->complete($draft, $this->u['bur1']), 'Only a document that has been received');

        $received = $this->receivedAt('bur1', from: 'reg1');
        $this->assertRejected(fn () => $this->movements->complete($received, $this->u['reg1']), 'Only the department currently holding');
    }

    public function testReturnRequiresAReceivedDocumentHeldByYou(): void
    {
        $draft = $this->draft('reg1');
        $this->assertRejected(fn () => $this->movements->returnToSender($draft, 'x', $this->u['reg1']), 'Only a document that your department has received');

        $received = $this->receivedAt('bur1', from: 'reg1');
        $this->assertRejected(fn () => $this->movements->returnToSender($received, 'x', $this->u['ict1']), 'Only the department currently holding');
        $this->assertRejected(fn () => $this->movements->returnToSender($received, '   ', $this->u['bur1']), 'reason');
    }

    public function testDestinationAndRecipientAreValidated(): void
    {
        $doc = $this->draft('reg1');
        $this->db->execute('UPDATE departments SET is_active = 0 WHERE id = ?', [$this->ict]);

        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->ict), $this->u['reg1']), 'active destination department');
        $this->assertRejected(fn () => $this->movements->send($doc, $this->to(999999), $this->u['reg1']), 'active destination department');
        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->bur, (int) $this->u['reg2']['id']), $this->u['reg1']), 'recipient must be an active member');
        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->bur, null, '  '), $this->u['reg1']), 'action note is required');
        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->bur) + ['due_date' => '2000-01-01'], $this->u['reg1']), 'cannot be in the past');
        $this->assertRejected(fn () => $this->movements->send($doc, $this->to($this->bur) + ['due_date' => '2026-02-30'], $this->u['reg1']), 'not a valid date');
        $this->assertDocument($doc, 'draft', $this->reg);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM document_movements WHERE document_id = ?', [$doc]));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function createUser(string $key, int $departmentId, string $role): array
    {
        $this->db->execute(
            'INSERT INTO users (staff_id, username, full_name, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, ?, ?, ?)',
            ["T-$key", "t-$key", ucfirst($key) . ' User', "$key@test.local", 'x', $role, $departmentId]
        );

        return (new User())->find($this->db->lastInsertId());
    }

    private function draft(string $userKey): int
    {
        return (new DocumentService())->register([
            'title' => 'Test document ' . bin2hex(random_bytes(3)),
            'document_type_id' => $this->typeId,
            'reference_no' => null,
            'description' => null,
            'priority' => 'normal',
            'confidentiality' => 'normal',
            'format' => 'physical',
            'pages' => null,
        ], [], $this->u[$userKey]);
    }

    /**
     * A document registered by $from's department, sent to and received by $receiverKey.
     */
    private function receivedAt(string $receiverKey, string $from): int
    {
        $doc = $this->draft($from);
        $this->movements->send($doc, $this->to((int) $this->u[$receiverKey]['department_id']), $this->u[$from]);
        $this->movements->receive($doc, $this->u[$receiverKey]);

        return $doc;
    }

    /**
     * @return array{to_department_id: int, to_user_id: ?int, note: string}
     */
    private function to(int $departmentId, ?int $userId = null, string $note = 'For your approval'): array
    {
        return ['to_department_id' => $departmentId, 'to_user_id' => $userId, 'note' => $note];
    }

    /**
     * @return array<string, mixed>
     */
    private function movement(int $id): array
    {
        return $this->db->fetch('SELECT * FROM document_movements WHERE id = ?', [$id]);
    }

    private function assertDocument(int $id, string $status, int $departmentId, ?int $holderId = null): void
    {
        $row = $this->db->fetch('SELECT status, current_department_id, current_holder_id FROM documents WHERE id = ?', [$id]);
        $this->assertSame($status, $row['status'], 'status');
        $this->assertSame($departmentId, (int) $row['current_department_id'], 'current department');
        if ($holderId !== null) {
            $this->assertSame($holderId, (int) $row['current_holder_id'], 'current holder');
        }
    }

    private function assertRejected(callable $action, string $messagePart): void
    {
        try {
            $action();
            $this->fail("Expected rejection containing \"$messagePart\"");
        } catch (ServiceException $e) {
            $this->assertStringContainsStringIgnoringCase($messagePart, $e->getMessage());
        }
    }

    private function assertAudited(string $action, int $documentId): void
    {
        $this->assertGreaterThan(0, (int) $this->db->fetchValue(
            'SELECT COUNT(*) FROM audit_logs WHERE action = ? AND document_id = ? AND user_id IS NOT NULL',
            [$action, $documentId]
        ), "Audit row $action");
    }

    private function notificationCount(string $type, int $documentId): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE type = ? AND document_id = ?', [$type, $documentId]);
    }

    /**
     * @param array<string, mixed> $user
     */
    private function notificationCountFor(array $user, int $documentId, ?string $type = null): int
    {
        $sql = 'SELECT COUNT(*) FROM notifications WHERE user_id = ? AND document_id = ?';
        $params = [(int) $user['id'], $documentId];
        if ($type !== null) {
            $sql .= ' AND type = ?';
            $params[] = $type;
        }

        return (int) $this->db->fetchValue($sql, $params);
    }
}
