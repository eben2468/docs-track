<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Document;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\MovementService;
use App\Services\SettingService;
use Tests\DatabaseTestCase;

/**
 * Document lists (FR-12), search scope and the public tracking summary (FR-14).
 */
final class DocumentListingTest extends DatabaseTestCase
{
    private int $reg;
    private int $bur;
    private int $ict;
    private int $typeId;

    /** @var array<string, array<string, mixed>> */
    private array $u = [];

    private Document $documents;
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
        foreach (['reg' => $this->reg, 'bur' => $this->bur, 'ict' => $this->ict] as $k => $dept) {
            $this->u[$k] = $this->user($k, $dept, 'staff');
        }
        $this->u['admin'] = $this->user('admin', $this->ict, 'admin');
        $this->documents = new Document();
        $this->movements = new MovementService();
    }

    public function testTabsAreScopedToTheUsersDepartment(): void
    {
        $inTransit = $this->doc('reg', 'Budget memo for approval');
        $this->movements->send($inTransit, $this->to($this->bur), $this->u['reg']);
        $received = $this->doc('reg', 'Transcript request batch');
        $this->movements->send($received, $this->to($this->bur), $this->u['reg']);
        $this->movements->receive($received, $this->u['bur']);
        $draft = $this->doc('reg', 'Unsent draft letter');

        $this->assertSame([$inTransit], $this->ids('bur', 'incoming'));
        $this->assertSame([$received], $this->ids('bur', 'with-us'));
        $this->assertSame([$inTransit], $this->ids('reg', 'outgoing'));
        $this->assertEqualsCanonicalizing([$inTransit, $received, $draft], $this->ids('reg', 'registered'));
        $this->assertSame([], $this->ids('ict', 'incoming'));
        $this->assertSame([], $this->ids('ict', 'all-accessible'), 'ICT was never on the route');
        $this->assertEqualsCanonicalizing([$inTransit, $received], $this->ids('bur', 'all-accessible'), 'BUR sees what was sent to it, not REG drafts');
        $this->assertEqualsCanonicalizing([$inTransit, $received, $draft], $this->ids('admin', 'all'));
    }

    public function testCompletedTabShowsCompletedDocumentsOnTheRoute(): void
    {
        $doc = $this->doc('reg', 'Completed contract');
        $this->movements->send($doc, $this->to($this->bur), $this->u['reg']);
        $this->movements->receive($doc, $this->u['bur']);
        $this->movements->complete($doc, $this->u['bur']);

        $this->assertSame([$doc], $this->ids('reg', 'completed'));
        $this->assertSame([$doc], $this->ids('bur', 'completed'));
        $this->assertSame([], $this->ids('ict', 'completed'));
        $this->assertSame([$doc], $this->ids('admin', 'completed'));
    }

    public function testFiltersAndSearch(): void
    {
        $a = $this->doc('reg', 'Annual budget estimates', 'urgent');
        $b = $this->doc('reg', 'Staff leave roster');
        $trackingB = (string) $this->db->fetchValue('SELECT tracking_no FROM documents WHERE id = ?', [$b]);

        $this->assertSame([$b], $this->ids('reg', 'registered', ['q' => $trackingB]), 'tracking number');
        $this->assertSame([$b], $this->ids('reg', 'registered', ['q' => substr($trackingB, -5)]), 'partial tracking number');
        $this->assertSame([$a], $this->ids('reg', 'registered', ['priority' => 'urgent']));
        $this->assertSame([], $this->ids('reg', 'registered', ['status' => 'completed']));
        $this->assertEqualsCanonicalizing([$a, $b], $this->ids('reg', 'registered', ['from' => now()->format('Y-m-d'), 'to' => now()->format('Y-m-d')]));
        $this->assertSame([], $this->ids('reg', 'registered', ['to' => '2000-01-01']));
        $this->assertSame([], $this->ids('reg', 'registered', ['q' => "x'; DROP TABLE documents; --"]), 'Hostile input is just text');
        $this->assertSame(2, (int) $this->db->fetchValue('SELECT COUNT(*) FROM documents'));
    }

    /**
     * InnoDB FULLTEXT indexes only see committed rows, so this test commits its data
     * and removes it afterwards instead of relying on the rollback.
     */
    public function testKeywordSearchUsesFulltextIndex(): void
    {
        $a = $this->doc('reg', 'Annual budget estimates');
        $b = $this->doc('reg', 'Staff leave roster');
        $this->db->pdo()->commit();

        try {
            $this->assertSame([$a], $this->ids('reg', 'registered', ['q' => 'budget']), 'keyword');
            $this->assertSame([$a], $this->ids('reg', 'registered', ['q' => 'annual estim']), 'all words, prefix match');
            $this->assertSame([$b], $this->ids('reg', 'registered', ['q' => 'Roster']), 'case-insensitive');
            $this->assertSame([], $this->ids('reg', 'registered', ['q' => 'budget roster']), 'every word must match');
            $this->assertSame([$b], $this->ids('reg', 'registered', ['q' => 'ro']), 'words under 3 letters fall back to LIKE');
            $this->assertSame([], $this->ids('ict', 'all-accessible', ['q' => 'budget']), 'search respects permissions');
        } finally {
            foreach (['audit_logs', 'notifications', 'attachments', 'document_movements', 'documents', 'tracking_sequences', 'users', 'document_types', 'departments'] as $table) {
                $this->db->execute("DELETE FROM $table");
            }
        }
    }

    public function testOverdueAndStuckFlags(): void
    {
        $overdue = $this->doc('reg', 'Overdue voucher');
        $this->movements->send($overdue, $this->to($this->bur), $this->u['reg']);
        $this->movements->receive($overdue, $this->u['bur']);
        $this->db->execute("UPDATE document_movements SET due_date = DATE_SUB(CURDATE(), INTERVAL 2 DAY) WHERE document_id = ?", [$overdue]);

        $stuck = $this->doc('reg', 'Stuck memo');
        $this->movements->send($stuck, $this->to($this->bur), $this->u['reg']);
        $this->db->execute("UPDATE document_movements SET sent_at = DATE_SUB(NOW(), INTERVAL 30 HOUR) WHERE document_id = ?", [$stuck]);

        $rows = array_column($this->documents->listing($this->u['admin'], 'all', [], 'created', 'desc', 1, 24)['data'], null, 'id');

        $this->assertSame(1, (int) $rows[$overdue]['is_overdue']);
        $this->assertSame(0, (int) $rows[$overdue]['is_stuck']);
        $this->assertSame(1, (int) $rows[$stuck]['is_stuck']);
        $this->assertSame(0, (int) $rows[$stuck]['is_overdue']);
    }

    public function testSortingAndPagination(): void
    {
        foreach (['Charlie', 'Alpha', 'Bravo'] as $title) {
            $this->doc('reg', $title . ' document');
        }

        $asc = $this->documents->listing($this->u['reg'], 'registered', [], 'title', 'asc', 1, 24, 2);
        $this->assertSame(3, $asc['total']);
        $this->assertSame(2, $asc['last_page']);
        $this->assertSame(['Alpha document', 'Bravo document'], array_column($asc['data'], 'title'));

        $desc = $this->documents->listing($this->u['reg'], 'registered', [], 'title', 'desc', 1, 24);
        $this->assertSame('Charlie document', $desc['data'][0]['title']);
    }

    public function testPublicSummaryHidesConfidentialTitle(): void
    {
        $normal = $this->doc('reg', 'Public notice');
        $secret = $this->doc('reg', 'Disciplinary case file', 'normal', 'confidential');
        $this->movements->send($secret, $this->to($this->bur), $this->u['reg']);
        $no = fn (int $id): string => (string) $this->db->fetchValue('SELECT tracking_no FROM documents WHERE id = ?', [$id]);

        $public = $this->documents->publicSummary($no($normal));
        $hidden = $this->documents->publicSummary(strtolower($no($secret)));

        $this->assertSame('Public notice', $public['title']);
        $this->assertSame('Registry', $public['current_department']);
        $this->assertNull($hidden['title']);
        $this->assertSame('in_transit', $hidden['status']);
        $this->assertSame('Bursary', $hidden['in_transit_to']);
        $this->assertSame(['tracking_no', 'title', 'type_name', 'status', 'current_department', 'in_transit_to', 'last_updated'], array_keys($hidden));
        $this->assertNull($this->documents->publicSummary('VVU-XXX-2026-00001'));
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param array<string, string> $filters
     * @return list<int>
     */
    private function ids(string $userKey, string $tab, array $filters = []): array
    {
        $result = $this->documents->listing($this->u[$userKey], $tab, $filters, Document::defaultSort($tab), 'asc', 1, 24, 100);

        return array_map('intval', array_column($result['data'], 'id'));
    }

    private function doc(string $userKey, string $title, string $priority = 'normal', string $confidentiality = 'normal'): int
    {
        return (new DocumentService())->register([
            'title' => $title, 'document_type_id' => $this->typeId, 'reference_no' => null, 'description' => null,
            'priority' => $priority, 'confidentiality' => $confidentiality, 'format' => 'physical', 'pages' => null,
        ], [], $this->u[$userKey]);
    }

    /**
     * @return array{to_department_id: int, to_user_id: null, note: string}
     */
    private function to(int $dept): array
    {
        return ['to_department_id' => $dept, 'to_user_id' => null, 'note' => 'For action'];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $key, int $dept, string $role): array
    {
        $this->db->execute(
            'INSERT INTO users (staff_id, full_name, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, ?, ?)',
            ["L-$key", ucfirst($key), "$key@list.local", 'x', $role, $dept]
        );

        return (new User())->find($this->db->lastInsertId());
    }
}
