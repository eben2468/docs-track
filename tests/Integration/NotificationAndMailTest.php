<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\Notification;
use App\Models\User;
use App\Services\DocumentService;
use App\Services\Mail\MailTransport;
use App\Services\MailService;
use App\Services\MovementService;
use App\Services\SettingService;
use Core\Database;
use RuntimeException;
use Tests\DatabaseTestCase;

/**
 * FR-9: who gets notified, what the emails say, and how the queue is delivered.
 */
final class NotificationAndMailTest extends DatabaseTestCase
{
    private int $reg;
    private int $bur;
    private int $typeId;

    /** @var array<string, array<string, mixed>> */
    private array $u = [];

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::flushCache();
        $this->reg = $this->createDepartment('REG', 'Registry');
        $this->bur = $this->createDepartment('BUR', 'Bursary');
        $this->db->execute("INSERT INTO document_types (name, sla_days) VALUES ('Memo', 3)");
        $this->typeId = $this->db->lastInsertId();
        $this->u['reg'] = $this->user('reg', $this->reg);
        $this->u['bur1'] = $this->user('bur1', $this->bur);
        $this->u['bur2'] = $this->user('bur2', $this->bur);
        $this->u['burOff'] = $this->user('buroff', $this->bur, false);
    }

    // ---------------------------------------------------------------- targeting and content

    public function testSendNotifiesAndEmailsEveryActiveUserOfTheDepartment(): void
    {
        $doc = $this->doc('Budget estimates', 'urgent');
        (new MovementService())->send($doc, $this->to($this->bur), $this->u['reg']);

        $emails = $this->emails();
        $this->assertSame(['bur1@n.local', 'bur2@n.local'], array_column($emails, 'to_email'), 'Active BUR users only; not the sender or inactive users');
        $this->assertSame(2, $this->notificationCount('incoming'));

        $tracking = $this->trackingNo($doc);
        $this->assertSame("[URGENT] $tracking sent to Bursary", $emails[0]['subject']);
        $this->assertStringContainsString('View document', $emails[0]['body_html']);
        $this->assertStringContainsString('/documents/' . $doc, $emails[0]['body_html']);
        $this->assertStringContainsString('Budget estimates', $emails[0]['body_html']);
        $this->assertSame('pending', $emails[0]['status']);
    }

    public function testSpecificRecipientIsTheOnlyOneNotified(): void
    {
        $doc = $this->doc('Leave request');
        (new MovementService())->send($doc, $this->to($this->bur, (int) $this->u['bur2']['id']), $this->u['reg']);

        $this->assertSame(['bur2@n.local'], array_column($this->emails(), 'to_email'));
        $this->assertSame($this->trackingNo($doc) . ' sent to Bursary', $this->emails()[0]['subject'], 'No [URGENT] prefix for normal priority');
    }

    public function testConfidentialTitleNeverAppearsInEmailsOrNotifications(): void
    {
        $doc = $this->doc('Disciplinary hearing file', 'normal', 'confidential');
        (new MovementService())->send($doc, $this->to($this->bur), $this->u['reg']);

        foreach ($this->emails() as $email) {
            $this->assertStringNotContainsString('Disciplinary', $email['subject'] . $email['body_html']);
            $this->assertStringContainsString('Confidential', $email['body_html']);
        }
        $this->assertSame(0, (int) $this->db->fetchValue("SELECT COUNT(*) FROM notifications WHERE message LIKE '%Disciplinary%'"));
    }

    public function testEachLifecycleEventNotifiesTheRightPerson(): void
    {
        $movements = new MovementService();
        $doc = $this->doc('Contract renewal');
        $movements->send($doc, $this->to($this->bur), $this->u['reg']);
        $this->clearOutbox();

        $movements->receive($doc, $this->u['bur1']);
        $this->assertSame([['reg@n.local', $this->trackingNo($doc) . ' received by Bursary']], $this->outbox(), 'Received → sender');

        $movements->returnToSender($doc, 'Wrong form', $this->u['bur1']);
        $this->assertSame([['reg@n.local', $this->trackingNo($doc) . ' returned to Registry']], $this->outbox(), 'Returned → original sender');

        $movements->recall($doc, 'Fixing it myself', $this->u['bur1']);
        $this->assertSame([['reg@n.local', $this->trackingNo($doc) . ' recalled by Bursary']], $this->outbox(), 'Recalled → destination dept users');

        $movements->complete($doc, $this->u['bur2']);
        $this->assertSame([['reg@n.local', $this->trackingNo($doc) . ' completed at Bursary']], $this->outbox(), 'Completed → creator');
    }

    // ---------------------------------------------------------------- in-app notifications

    public function testUsersCanOnlyReadTheirOwnNotifications(): void
    {
        $doc = $this->doc('Memo');
        (new MovementService())->send($doc, $this->to($this->bur), $this->u['reg']);
        $model = new Notification();
        $bur1Id = (int) $this->u['bur1']['id'];
        $bur2Id = (int) $this->u['bur2']['id'];
        $theirs = (int) $this->db->fetchValue('SELECT id FROM notifications WHERE user_id = ?', [$bur2Id]);

        $this->assertSame(1, $model->unreadCount($bur1Id));
        $this->assertNull($model->findForUser($theirs, $bur1Id));
        $this->assertSame(0, $model->markRead($theirs, $bur1Id), "Cannot mark someone else's notification");
        $this->assertSame(1, $model->unreadCount($bur2Id));

        $this->assertSame(1, $model->markAllRead($bur1Id));
        $this->assertSame(0, $model->unreadCount($bur1Id));
        $this->assertSame(1, $model->unreadCount($bur2Id));
        $this->assertSame($this->trackingNo($doc), $model->latestFor($bur2Id)[0]['tracking_no']);
    }

    // ---------------------------------------------------------------- queue delivery

    public function testQueueSendsPendingEmailsAndMarksThemSent(): void
    {
        $transport = new FakeTransport();
        $mail = new MailService(transport: $transport);
        $mail->queue('a@n.local', 'One', '<p>1</p>');
        $mail->queue('b@n.local', 'Two', '<p>2</p>');

        $result = $mail->processQueue();

        $this->assertSame(['locked' => false, 'sent' => 2, 'failed' => 0], $result);
        $this->assertSame([['a@n.local', 'One'], ['b@n.local', 'Two']], $transport->sent);
        $this->assertSame(2, (int) $this->db->fetchValue("SELECT COUNT(*) FROM email_queue WHERE status = 'sent' AND sent_at IS NOT NULL AND attempts = 1"));
        $this->assertSame(['locked' => false, 'sent' => 0, 'failed' => 0], $mail->processQueue(), 'Sent emails are not resent');
    }

    public function testFailuresAreRetriedUpToThreeTimesWithTheLastError(): void
    {
        $transport = new FakeTransport(failFor: ['bad@n.local']);
        $mail = new MailService(transport: $transport);
        $id = $mail->queue('bad@n.local', 'Bounce', '<p>x</p>');

        for ($run = 1; $run <= 4; $run++) {
            $mail->processQueue();
        }

        $row = $this->db->fetch('SELECT status, attempts, last_error FROM email_queue WHERE id = ?', [$id]);
        $this->assertSame('failed', $row['status']);
        $this->assertSame(3, (int) $row['attempts'], 'Stops after 3 attempts');
        $this->assertSame('Mailbox unavailable for bad@n.local', $row['last_error']);
        $this->assertCount(3, $transport->attempted);
    }

    public function testBatchSizeIsRespected(): void
    {
        $mail = new MailService(transport: new FakeTransport());
        for ($i = 0; $i < 5; $i++) {
            $mail->queue("u$i@n.local", "S$i", '<p></p>');
        }

        $this->assertSame(3, $mail->processQueue(3)['sent']);
        $this->assertSame(2, $mail->processQueue(3)['sent']);
    }

    public function testConcurrentRunExitsWhileAnotherHoldsTheLock(): void
    {
        $mail = new MailService(transport: new FakeTransport());
        $mail->queue('a@n.local', 'Locked', '<p></p>');

        $other = Database::fromConfig($this->dbConfig());
        $this->assertSame(1, (int) $other->fetchValue("SELECT GET_LOCK('vvu_dts_send_emails', 0)"));
        try {
            $this->assertSame(['locked' => true, 'sent' => 0, 'failed' => 0], $mail->processQueue());
        } finally {
            $other->fetchValue("SELECT RELEASE_LOCK('vvu_dts_send_emails')");
        }

        $this->assertSame(1, $mail->processQueue()['sent'], 'Runs normally once the lock is free');
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @return array<string, mixed>
     */
    private function dbConfig(): array
    {
        $prop = new \ReflectionProperty(Database::class, 'config');

        return $prop->getValue($this->db);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function emails(): array
    {
        return $this->db->fetchAll('SELECT * FROM email_queue ORDER BY to_email');
    }

    /**
     * Emails queued since the last clearOutbox(), as [to, subject], then clears them.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function outbox(): array
    {
        $rows = array_map(static fn (array $r): array => [$r['to_email'], $r['subject']], $this->emails());
        $this->clearOutbox();

        return $rows;
    }

    private function clearOutbox(): void
    {
        $this->db->execute('DELETE FROM email_queue');
    }

    private function notificationCount(string $type): int
    {
        return (int) $this->db->fetchValue('SELECT COUNT(*) FROM notifications WHERE type = ?', [$type]);
    }

    private function trackingNo(int $doc): string
    {
        return (string) $this->db->fetchValue('SELECT tracking_no FROM documents WHERE id = ?', [$doc]);
    }

    private function doc(string $title, string $priority = 'normal', string $confidentiality = 'normal'): int
    {
        return (new DocumentService())->register([
            'title' => $title, 'document_type_id' => $this->typeId, 'reference_no' => null, 'description' => null,
            'priority' => $priority, 'confidentiality' => $confidentiality, 'format' => 'physical', 'pages' => null,
        ], [], $this->u['reg']);
    }

    /**
     * @return array{to_department_id: int, to_user_id: ?int, note: string}
     */
    private function to(int $dept, ?int $userId = null): array
    {
        return ['to_department_id' => $dept, 'to_user_id' => $userId, 'note' => 'Please treat'];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(string $key, int $dept, bool $active = true): array
    {
        $this->db->execute(
            'INSERT INTO users (staff_id, username, full_name, email, password_hash, role, department_id, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            ["N-$key", "n-$key", ucfirst($key), "$key@n.local", 'x', 'staff', $dept, $active ? 1 : 0]
        );

        return (new User())->find($this->db->lastInsertId());
    }
}

/** Records emails instead of sending them; fails for chosen addresses. */
final class FakeTransport implements MailTransport
{
    /** @var list<array{0: string, 1: string}> */
    public array $sent = [];

    /** @var list<string> */
    public array $attempted = [];

    /**
     * @param list<string> $failFor
     */
    public function __construct(private readonly array $failFor = [])
    {
    }

    public function send(string $to, string $subject, string $html): void
    {
        $this->attempted[] = $to;
        if (in_array($to, $this->failFor, true)) {
            throw new RuntimeException("Mailbox unavailable for $to");
        }
        $this->sent[] = [$to, $subject];
    }
}
