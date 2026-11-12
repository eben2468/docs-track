<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailQueue;
use App\Services\Mail\LogTransport;
use App\Services\Mail\MailTransport;
use App\Services\Mail\SmtpTransport;
use Core\Config;
use Core\Database;
use Core\View;
use Throwable;

/**
 * Email (FR-9). Messages are queued in email_queue so user actions stay fast; the
 * cron/send_emails.php job delivers them with processQueue().
 */
final class MailService
{
    public const BATCH_SIZE = 50;

    /** Delivery attempts per email before it is left as failed. */
    public const MAX_ATTEMPTS = 3;

    /** MySQL/MariaDB named lock that stops two queue runs overlapping. */
    private const LOCK_NAME = 'vvu_dts_send_emails';

    private readonly Database $db;

    public function __construct(
        private readonly EmailQueue $queue = new EmailQueue(),
        private ?MailTransport $transport = null,
        ?Database $db = null,
    ) {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Queue an HTML email. Returns the queue row ID.
     */
    public function queue(string $to, string $subject, string $bodyHtml): int
    {
        return $this->queue->insert([
            'to_email' => $to,
            'subject' => mb_substr($subject, 0, 255),
            'body_html' => $bodyHtml,
        ]);
    }

    /**
     * Render an email view (app/Views/emails/*) inside the email layout and queue it.
     *
     * @param array<string, mixed> $data
     */
    public function queueView(string $to, string $subject, string $view, array $data = []): int
    {
        return $this->queue($to, $subject, self::render($view, $subject, $data));
    }

    /**
     * Render an email view inside the email layout.
     *
     * @param array<string, mixed> $data
     */
    public static function render(string $view, string $subject, array $data = []): string
    {
        return View::render($view, $data + ['subject' => $subject], 'layouts/email');
    }

    /**
     * Send immediately, bypassing the queue (used by the "Send test email" button).
     *
     * @throws \RuntimeException with the transport's error message
     */
    public function sendNow(string $to, string $subject, string $html): void
    {
        $this->transport()->send($to, $subject, $html);
    }

    /**
     * Deliver up to $batch queued emails: pending ones, plus failed ones with fewer than
     * MAX_ATTEMPTS attempts. A named database lock makes concurrent runs exit at once.
     *
     * @return array{locked: bool, sent: int, failed: int}
     */
    public function processQueue(int $batch = self::BATCH_SIZE): array
    {
        if ((int) $this->db->fetchValue('SELECT GET_LOCK(?, 0)', [self::LOCK_NAME]) !== 1) {
            return ['locked' => true, 'sent' => 0, 'failed' => 0];
        }

        $sent = 0;
        $failed = 0;
        try {
            $rows = $this->db->fetchAll(
                "SELECT id, to_email, subject, body_html FROM email_queue
                 WHERE status = 'pending' OR (status = 'failed' AND attempts < ?)
                 ORDER BY id LIMIT " . max(1, min(500, $batch)),
                [self::MAX_ATTEMPTS]
            );

            foreach ($rows as $row) {
                try {
                    $this->transport()->send((string) $row['to_email'], (string) $row['subject'], (string) $row['body_html']);
                    $this->db->execute(
                        "UPDATE email_queue SET status = 'sent', sent_at = NOW(), attempts = attempts + 1, last_error = NULL WHERE id = ?",
                        [$row['id']]
                    );
                    $sent++;
                } catch (Throwable $e) {
                    $this->db->execute(
                        "UPDATE email_queue SET status = 'failed', attempts = attempts + 1, last_error = ? WHERE id = ?",
                        [mb_substr($e->getMessage(), 0, 500), $row['id']]
                    );
                    $failed++;
                }
            }
        } finally {
            $this->db->fetchValue('SELECT RELEASE_LOCK(?)', [self::LOCK_NAME]);
        }

        return ['locked' => false, 'sent' => $sent, 'failed' => $failed];
    }

    /**
     * The configured transport (SMTP, or the log file when MAIL_TRANSPORT=log).
     */
    private function transport(): MailTransport
    {
        if ($this->transport === null) {
            $config = (array) Config::get('mail', []);
            $this->transport = ($config['transport'] ?? 'smtp') === 'log'
                ? new LogTransport(storage_path('logs/mail.log'))
                : new SmtpTransport($config);
        }

        return $this->transport;
    }
}
