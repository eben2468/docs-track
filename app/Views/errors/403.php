<?php
/** Error page. Variables from App::errorResponse(): int $status, string $title, string $message, ?Throwable $exception. */
echo partial('partials/error-page', [
    'status' => $status,
    'title' => $title,
    'message' => $message ?? '',
    'exception' => $exception ?? null,
    'icon' => 'shield-lock',
    'defaultMessage' => 'You do not have permission to view this page or perform this action.',
]);
