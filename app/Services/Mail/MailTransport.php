<?php

declare(strict_types=1);

namespace App\Services\Mail;

use RuntimeException;

/**
 * Delivers one email. Implementations throw RuntimeException with a readable reason on failure.
 */
interface MailTransport
{
    /**
     * @throws RuntimeException
     */
    public function send(string $to, string $subject, string $html): void;
}
