<?php

declare(strict_types=1);

/*
 * Create (or promote) an administrator account on a fresh server.
 *
 *   php database/create_admin.php --username=jane.doe --email=it@vvu.edu.gh --name="Jane Doe" \
 *       --staff-id=VVU-0001 --department=ICT [--department-name="ICT Directorate"]
 *
 * There are no passwords: the admin signs in with the username and a one-time code emailed
 * to the address given, so make sure email (MAIL_* in .env) works. If the department code
 * does not exist it is created (use --department-name to name it). Running it again for an
 * existing email or staff ID updates that user to an active admin with the given username.
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

$opts = getopt('', ['username:', 'email:', 'name:', 'staff-id:', 'department:', 'department-name::', 'help']);
$fail = static function (string $message): never {
    fwrite(STDERR, "ERROR: $message" . PHP_EOL);
    exit(1);
};

if (isset($opts['help']) || !isset($opts['username'], $opts['email'], $opts['name'], $opts['staff-id'], $opts['department'])) {
    echo "Usage: php database/create_admin.php --username=USERNAME --email=EMAIL --name=\"Full Name\" --staff-id=ID\n"
        . "       --department=CODE [--department-name=\"Department name\"]\n";
    exit(isset($opts['help']) ? 0 : 1);
}

(new App($basePath))->bootstrap();
$db = Database::instance();

$data = [
    'username' => strtolower(trim((string) $opts['username'])),
    'email' => strtolower(trim((string) $opts['email'])),
    'full_name' => trim((string) $opts['name']),
    'staff_id' => trim((string) $opts['staff-id']),
    'department' => strtoupper(trim((string) $opts['department'])),
];
$v = Validator::make($data, [
    'username' => ['required', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/'],
    'email' => 'required|email|max:150',
    'full_name' => 'required|max:150',
    'staff_id' => 'required|max:30',
    'department' => ['required', 'regex:/^[A-Z]{2,6}$/'],
], ['department' => 'Department code (2-6 letters)']);
if ($v->fails()) {
    $fail(implode(' ', array_merge([], ...array_values($v->errors()))));
}

try {
    $result = $db->transaction(static function (Database $db) use ($data, $opts, $fail): array {
        $dept = $db->fetch('SELECT id, is_active FROM departments WHERE code = ?', [$data['department']]);
        if ($dept === null) {
            $name = trim((string) ($opts['department-name'] ?? '')) ?: $data['department'];
            $db->execute('INSERT INTO departments (code, name) VALUES (?, ?)', [$data['department'], $name]);
            $deptId = $db->lastInsertId();
        } else {
            $deptId = (int) $dept['id'];
            $db->execute('UPDATE departments SET is_active = 1 WHERE id = ?', [$deptId]);
        }

        $existing = $db->fetch('SELECT id FROM users WHERE email = ? OR staff_id = ?', [$data['email'], $data['staff_id']]);
        $taken = $db->fetch('SELECT id FROM users WHERE username = ?', [$data['username']]);
        if ($taken !== null && (int) $taken['id'] !== (int) ($existing['id'] ?? 0)) {
            $fail("The username \"{$data['username']}\" is already used by another account.");
        }

        if ($existing !== null) {
            $db->execute(
                "UPDATE users SET username = ?, full_name = ?, role = 'admin', is_active = 1, failed_attempts = 0, locked_until = NULL WHERE id = ?",
                [$data['username'], $data['full_name'], $existing['id']]
            );
            $userId = (int) $existing['id'];
            $action = 'updated';
        } else {
            // Random secret, used only to fingerprint sessions (there are no passwords).
            $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
            $db->execute(
                "INSERT INTO users (staff_id, username, full_name, email, password_hash, role, department_id) VALUES (?, ?, ?, ?, ?, 'admin', ?)",
                [$data['staff_id'], $data['username'], $data['full_name'], $data['email'], $hash, $deptId]
            );
            $userId = $db->lastInsertId();
            $action = 'created';
        }

        (new AuditService())->log('user.admin_cli', ['user_id' => $userId, 'username' => $data['username'], 'result' => $action], null, $userId);

        return [$userId, $action];
    });
} catch (Throwable $e) {
    $fail($e->getMessage());
}

echo "Administrator {$result[1]}: {$data['full_name']} <{$data['email']}> (user #{$result[0]}, department {$data['department']}).\n";
echo "Sign in at " . rtrim((string) config('app.url'), '/') . "/login with username \"{$data['username']}\";\n"
    . "a one-time code will be emailed to {$data['email']}.\n";
