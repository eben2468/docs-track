<?php

declare(strict_types=1);

/*
 * Database migration runner.
 *
 *   php database/migrate.php            Run pending migrations
 *   php database/migrate.php --seed     Run pending migrations, then seeds
 *   php database/migrate.php --fresh    Drop ALL tables, then run every migration
 *   php database/migrate.php --fresh --seed
 *
 * --fresh is refused when APP_ENV=production unless --force is also given.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

use Core\App;
use Core\Config;
use Core\Database;
use Core\Migrator;

$args = array_slice($argv, 1);
$known = ['--fresh', '--seed', '--force', '--help'];
$unknown = array_diff($args, $known);

if (in_array('--help', $args, true) || $unknown !== []) {
    if ($unknown !== []) {
        fwrite(STDERR, 'Unknown option(s): ' . implode(' ', $unknown) . PHP_EOL);
    }
    echo "Usage: php database/migrate.php [--fresh] [--seed] [--force]\n";
    exit($unknown === [] ? 0 : 1);
}

$fresh = in_array('--fresh', $args, true);
$seed = in_array('--seed', $args, true);
$force = in_array('--force', $args, true);

$line = static function (string $text = ''): void {
    echo $text . PHP_EOL;
};
$fail = static function (string $message): never {
    fwrite(STDERR, PHP_EOL . 'ERROR: ' . $message . PHP_EOL);
    exit(1);
};

try {
    (new App($basePath))->bootstrap();
    $dbConfig = (array) Config::get('database');
    $line(sprintf('Database: %s@%s:%d/%s', $dbConfig['username'], $dbConfig['host'], $dbConfig['port'], $dbConfig['database']));

    if ($fresh && Config::get('app.env') === 'production' && !$force) {
        $fail('--fresh would delete all data (including audit logs) in production. Add --force if you really mean it.');
    }

    if (Migrator::ensureDatabaseExists($dbConfig)) {
        $line("Created database `{$dbConfig['database']}`.");
    }

    $db = Database::instance();
    $migrator = new Migrator($db, $basePath . '/database/migrations', $line);

    if ($fresh) {
        $dropped = $migrator->dropAllTables();
        $line(sprintf('Dropped %d table(s).', count($dropped)));
    }

    $line('Running migrations...');
    $count = $migrator->migrate();
    if ($count > 0) {
        $line(sprintf('%d migration(s) applied.', $count));
    }

    if ($seed) {
        $line('Seeding...');
        $seeder = require $basePath . '/database/seeds/seed.php';
        $seeder($db, $line);
        $line('Seeding complete.');
    }

    $line('Done.');
} catch (Throwable $e) {
    $fail($e->getMessage());
}
