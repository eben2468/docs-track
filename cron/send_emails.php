<?php

declare(strict_types=1);

/*
 * Sends queued emails (FR-9). Run every 2 minutes:
 *
 *   * /2 * * * *  php /path/to/app/cron/send_emails.php >> /path/to/app/storage/logs/cron.log 2>&1
 *
 * Sends up to 50 emails per run (pending first, then failed ones with fewer than 3 attempts).
 * Safe to run concurrently: a second run exits immediately while one is in progress.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

use App\Services\MailService;
use Core\App;
use Core\Logger;

try {
    (new App($basePath))->bootstrap();
    $result = (new MailService())->processQueue(MailService::BATCH_SIZE);

    $line = $result['locked']
        ? 'Another send_emails run is in progress; skipped.'
        : sprintf('Sent %d, failed %d.', $result['sent'], $result['failed']);
    echo '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL;

    if ($result['failed'] > 0) {
        Logger::error("send_emails: {$result['failed']} email(s) failed; see email_queue.last_error.");
    }
} catch (Throwable $e) {
    Logger::exception($e);
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
