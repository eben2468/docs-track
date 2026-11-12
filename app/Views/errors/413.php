<?php
/** Error page. Variables from App::errorResponse(): int $status, string $title, string $message, ?Throwable $exception. */
echo partial('partials/error-page', [
    'status' => $status,
    'title' => $title,
    'message' => $message ?? '',
    'exception' => $exception ?? null,
    'icon' => 'file-earmark-x',
    'defaultMessage' => 'The upload is larger than the server allows. Please choose smaller files and try again.',
]);
