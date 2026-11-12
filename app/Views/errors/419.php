<?php
/** Error page. Variables from App::errorResponse(): int $status, string $title, string $message, ?Throwable $exception. */
echo partial('partials/error-page', [
    'status' => $status,
    'title' => $title,
    'message' => $message ?? '',
    'exception' => $exception ?? null,
    'icon' => 'hourglass-bottom',
    'defaultMessage' => 'Your session has expired or the form is no longer valid. Please refresh the page and try again.',
]);
