<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Thrown to abort a request with an HTTP error status (403, 404, 419, ...).
 * The front controller renders the matching page in app/Views/errors/.
 */
final class HttpException extends RuntimeException
{
    public function __construct(private readonly int $status, string $message = '')
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }
}
