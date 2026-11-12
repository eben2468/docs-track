<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Outgoing emails, queued for the send_emails cron job.
 */
final class EmailQueue extends Model
{
    protected string $table = 'email_queue';

    /** @var list<string> */
    protected array $fillable = ['to_email', 'subject', 'body_html', 'status', 'attempts', 'last_error', 'sent_at'];
}
