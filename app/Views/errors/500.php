<?php
/** Error page. Variables from App::errorResponse(): int $status, string $title, string $message, ?Throwable $exception. */
echo partial('partials/error-page', [
    'status' => $status,
    'title' => $title,
    'message' => $message ?? '',
    'exception' => $exception ?? null,
    'icon' => 'exclamation-triangle',
    'defaultMessage' => 'Something went wrong on our side. The problem has been logged; please try again shortly.',
]);
