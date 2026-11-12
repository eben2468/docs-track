<?php

declare(strict_types=1);

/*
 * Overdue and stuck-document alerts (FR-10). Run every 30 minutes:
 *
 *   * /30 * * * *  php /path/to/app/cron/check_alerts.php >> /path/to/app/storage/logs/cron.log 2>&1
 *
 * Command line only. A second run started while one is in progress exits immediately.
 * Alert emails are queued; cron/send_emails.php delivers them.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

use App\Models\LoginOtp;
use App\Models\LoginThrottle;
use App\Services\AlertService;
use Core\App;
use Core\Logger;

try {
    (new App($basePath))->bootstrap();
    $result = (new AlertService())->run();

    // Housekeeping: rate-limit hits older than a day and sign-in codes.
    $purged = (new LoginThrottle())->purgeOlderThan(24) + (new LoginOtp())->purgeOlderThan(24);

    echo '[' . date('Y-m-d H:i:s') . '] ' . ($result['locked']
        ? 'Another check_alerts run is in progress; skipped.'
        : sprintf(
            'Unreceived: %d (escalated: %d), overdue: %d, notifications: %d.',
            $result['unreceived'],
            $result['escalated'],
            $result['overdue'],
            $result['notified']
        )) . ($purged > 0 ? " Purged $purged old throttle/sign-in code row(s)." : '') . PHP_EOL;
} catch (Throwable $e) {
    Logger::exception($e);
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ERROR: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
