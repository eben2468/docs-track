<?php

declare(strict_types=1);

namespace App\Services\Mail;

use PHPMailer\PHPMailer\Exception as MailerException;
use PHPMailer\PHPMailer\PHPMailer;
use RuntimeException;

/**
 * SMTP delivery through PHPMailer using the MAIL_* settings in .env.
 */
final class SmtpTransport implements MailTransport
{
    /**
     * @param array<string, mixed> $config Keys from config/mail.php
     */
    public function __construct(private readonly array $config)
    {
    }

    public function send(string $to, string $subject, string $html): void
    {
        $mail = new PHPMailer(true);

        try {
            $mail->isSMTP();
            $mail->Host = (string) $this->config['host'];
            $mail->Port = (int) $this->config['port'];
            $mail->Timeout = 20;
            $mail->CharSet = PHPMailer::CHARSET_UTF8;

            $username = (string) ($this->config['username'] ?? '');
            $mail->SMTPAuth = $username !== '';
            if ($mail->SMTPAuth) {
                $mail->Username = $username;
                $mail->Password = (string) ($this->config['password'] ?? '');
            }

            $encryption = strtolower((string) ($this->config['encryption'] ?? ''));
            if ($encryption === 'ssl' || $encryption === 'smtps') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            } elseif ($encryption === 'tls' || $encryption === 'starttls') {
                $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            } else {
                $mail->SMTPSecure = '';
                $mail->SMTPAutoTLS = false;
            }

            $mail->setFrom((string) $this->config['from_address'], (string) $this->config['from_name']);
            $mail->addAddress($to);
            $mail->Subject = $subject;
            $mail->isHTML(true);
            $mail->Body = $html;
            $mail->AltBody = self::plainText($html);

            $mail->send();
        } catch (MailerException $e) {
            throw new RuntimeException(trim($mail->ErrorInfo !== '' ? $mail->ErrorInfo : $e->getMessage()), 0, $e);
        }
    }

    /**
     * A readable plain-text version of an HTML email (links kept as "text (url)").
     */
    public static function plainText(string $html): string
    {
        $html = preg_replace('#<(script|style|head)\b[^>]*>.*?</\1>#is', '', $html) ?? $html;
        $html = preg_replace('#<a\b[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html) ?? $html;
        $html = preg_replace('#<(br|/p|/tr|/h\d|/li)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace("/[ \t]+/", ' ', $text) ?? $text;

        return trim(preg_replace("/\n\s*\n\s*\n+/", "\n\n", $text) ?? $text);
    }
}
