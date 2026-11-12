<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;

/**
 * Thrown when request validation fails. The App turns it into a redirect back to
 * the form with per-field errors and the submitted input (for old()).
 */
final class ValidationException extends RuntimeException
{
    /**
     * @param array<string, list<string>> $errors
     * @param array<string, mixed> $input
     */
    public function __construct(
        private readonly array $errors,
        private readonly array $input,
        private readonly string $redirectTo,
    ) {
        parent::__construct('The given data was invalid.');
    }

    /**
     * @return array<string, list<string>>
     */
    public function errors(): array
    {
        return $this->errors;
    }

    /**
     * Redirect back with errors, old input and a summary message.
     */
    public function response(): Response
    {
        return Response::redirect($this->redirectTo)
            ->withErrors($this->errors)
            ->withInput($this->input)
            ->with('danger', 'Please correct the highlighted fields and try again.');
    }
}
