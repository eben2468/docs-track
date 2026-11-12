<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * Local-testing transport (MAIL_TRANSPORT=log): appends each email, as plain text, to
 * storage/logs/mail.log instead of sending it.
 */
final class LogTransport implements MailTransport
{
    public function __construct(private readonly string $path)
    {
    }

    public function send(string $to, string $subject, string $html): void
    {
        $entry = sprintf(
            "==== %s ====\nTo: %s\nSubject: %s\n\n%s\n\n",
            date('Y-m-d H:i:s'),
            $to,
            $subject,
            SmtpTransport::plainText($html)
        );

        if (@file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException("Cannot write to {$this->path}");
        }
    }
}
