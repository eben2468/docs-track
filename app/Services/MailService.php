<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\EmailQueue;
use Core\View;

/**
 * Email: messages are queued in email_queue so user actions stay fast; the
 * cron/send_emails.php job delivers them via SMTP.
 */
final class MailService
{
    public function __construct(private readonly EmailQueue $queue = new EmailQueue())
    {
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
        $html = View::render($view, $data + ['subject' => $subject], 'layouts/email');

        return $this->queue($to, $subject, $html);
    }
}
