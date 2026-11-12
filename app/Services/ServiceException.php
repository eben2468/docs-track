<?php

declare(strict_types=1);

namespace App\Services;

use DomainException;

/**
 * A business-rule (domain) violation whose message is safe to show to the user
 * (e.g. "You cannot deactivate your own account."). Controllers catch it and
 * redirect back with the message as a flash.
 */
final class ServiceException extends DomainException
{
    /**
     * @param string $field Optional form field the problem belongs to
     */
    public function __construct(string $message, private readonly string $field = '')
    {
        parent::__construct($message);
    }

    public function field(): string
    {
        return $this->field;
    }
}
