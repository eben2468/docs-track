<?php

declare(strict_types=1);

/*
 * Create (or promote) an administrator account on a fresh server.
 *
 *   php database/create_admin.php --email=it@vvu.edu.gh --name="Jane Doe" --staff-id=VVU-0001 \
 *       --department=ICT [--department-name="ICT Directorate"]
 *
 * The password is asked for interactively (hidden on Linux/macOS). For scripts, pass
 * --password-stdin and pipe it in. If the department code does not exist it is created
 * (use --department-name to name it). Running it again for an existing email updates
 * that user to an active admin and sets the new password.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$basePath = dirname(__DIR__);
require $basePath . '/vendor/autoload.php';

use App\Services\AuditService;
use Core\App;
use Core\Database;
use Core\Validator;

$opts = getopt('', ['email:', 'name:', 'staff-id:', 'department:', 'department-name::', 'password-stdin', 'help']);
$fail = static function (string $message): never {
    fwrite(STDERR, "ERROR: $message" . PHP_EOL);
    exit(1);
};

if (isset($opts['help']) || !isset($opts['email'], $opts['name'], $opts['staff-id'], $opts['department'])) {
    echo "Usage: php database/create_admin.php --email=EMAIL --name=\"Full Name\" --staff-id=ID --department=CODE\n"
        . "       [--department-name=\"Department name\"] [--password-stdin]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

(new App($basePath))->bootstrap();
$db = Database::instance();

$data = [
    'email' => strtolower(trim((string) $opts['email'])),
    'full_name' => trim((string) $opts['name']),
    'staff_id' => trim((string) $opts['staff-id']),
    'department' => strtoupper(trim((string) $opts['department'])),
];
$v = Validator::make($data, [
    'email' => 'required|email|max:150',
    'full_name' => 'required|max:150',
    'staff_id' => 'required|max:30',
    'department' => ['required', 'regex:/^[A-Z]{2,6}$/'],
], ['department' => 'Department code (2-6 letters)']);
if ($v->fails()) {
    $fail(implode(' ', array_merge([], ...array_values($v->errors()))));
}

$password = readPassword(isset($opts['password-stdin']));
$pv = Validator::make(['password' => $password], ['password' => 'required|strong_password|max:255']);
if ($pv->fails()) {
    $fail((string) $pv->first('password'));
}

try {
    $result = $db->transaction(static function (Database $db) use ($data, $password, $opts): array {
        $dept = $db->fetch('SELECT id, is_active FROM departments WHERE code = ?', [$data['department']]);
        if ($dept === null) {
            $name = trim((string) ($opts['department-name'] ?? '')) ?: $data['department'];
            $db->execute('INSERT INTO departments (code, name) VALUES (?, ?)', [$data['department'], $name]);
            $deptId = $db->lastInsertId();
        } else {
            $deptId = (int) $dept['id'];
            $db->execute('UPDATE departments SET is_active = 1 WHERE id = ?', [$deptId]);
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $existing = $db->fetch('SELECT id FROM users WHERE email = ? OR staff_id = ?', [$data['email'], $data['staff_id']]);
        if ($existing !== null) {
            $db->execute(
                "UPDATE users SET full_name = ?, password_hash = ?, role = 'admin', is_active = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?",
                [$data['full_name'], $hash, $existing['id']]
            );
            $userId = (int) $existing['id'];
            $action = 'updated';
        } else {
            $db->execute(
                "INSERT INTO users (staff_id, full_name, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, 'admin', ?)",
                [$data['staff_id'], $data['full_name'], $data['email'], $hash, $deptId]
            );
            $userId = $db->lastInsertId();
            $action = 'created';
        }

        (new AuditService())->log('user.admin_cli', ['user_id' => $userId, 'email' => $data['email'], 'result' => $action], null, $userId);

        return [$userId, $action];
    });
} catch (Throwable $e) {
    $fail($e->getMessage());
}

echo "Administrator {$result[1]}: {$data['full_name']} <{$data['email']}> (user #{$result[0]}, department {$data['department']}).\n";
echo "Sign in at " . rtrim((string) config('app.url'), '/') . "/login\n";

/**
 * Read the password from STDIN (piped) or interactively, hiding input where possible.
 */
function readPassword(bool $fromStdin): string
{
    if ($fromStdin) {
        return rtrim((string) fgets(STDIN), "\r\n");
    }

    $hide = DIRECTORY_SEPARATOR === '/' && stream_isatty(STDIN);
    $ask = static function (string $prompt) use ($hide): string {
        fwrite(STDOUT, $prompt);
        if ($hide) {
            shell_exec('stty -echo');
        }
        $value = rtrim((string) fgets(STDIN), "\r\n");
        if ($hide) {
            shell_exec('stty echo');
            fwrite(STDOUT, PHP_EOL);
        }

        return $value;
    };

    $first = $ask('Password (min 8 chars, a letter and a number): ');
    if ($ask('Confirm password: ') !== $first) {
        fwrite(STDERR, "ERROR: Passwords do not match." . PHP_EOL);
        exit(1);
    }

    return $first;
}
