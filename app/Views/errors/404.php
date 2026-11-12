<?php
/** Error page. Variables from App::errorResponse(): int $status, string $title, string $message, ?Throwable $exception. */
echo partial('partials/error-page', [
    'status' => $status,
    'title' => $title,
    'message' => $message ?? '',
    'exception' => $exception ?? null,
    'icon' => 'signpost-split',
    'defaultMessage' => 'The page you are looking for does not exist or may have been moved.',
]);
