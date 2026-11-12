<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use Core\Database;
use Core\Validator;

/**
 * Bulk user import from CSV (FR-2).
 *
 * Columns: staff_id, username, full_name, email, phone, department_code, role.
 * All-or-nothing: if any row is invalid, nothing is imported and a row-by-row
 * report is returned so the file can be fixed and uploaded again.
 * Imported users are emailed their username and how to sign in (one-time email codes).
 */
final class UserImportService
{
    public const HEADERS = ['staff_id', 'username', 'full_name', 'email', 'phone', 'department_code', 'role'];

    public const MAX_ROWS = 1000;

    public function __construct(
        private readonly UserService $users = new UserService(),
        private readonly Department $departments = new Department(),
    ) {
    }

    /**
     * CSV template with the header row and one example row.
     */
    public static function template(): string
    {
        return implode(',', self::HEADERS) . "\r\n"
            . "VVU-1234,ama.mensah,Ama Mensah,ama.mensah@example.com,0241234567,REG,staff\r\n";
    }

    /**
     * Validate and (if every row is valid) import the file.
     *
     * @return array{imported: int, rows: list<array{line: int, data: array<string, string>, errors: list<string>}>}
     * @throws ServiceException when the file itself is unusable (bad header, empty, too many rows)
     */
    public function import(string $path): array
    {
        $rows = self::parse((string) file_get_contents($path));
        $rows = $this->validate($rows);

        foreach ($rows as $row) {
            if ($row['errors'] !== []) {
                return ['imported' => 0, 'rows' => $rows];
            }
        }

        $deptIds = $this->activeDepartmentIds();
        Database::instance()->transaction(function () use ($rows, $deptIds): void {
            foreach ($rows as $row) {
                $d = $row['data'];
                $this->users->create([
                    'staff_id' => $d['staff_id'],
                    'username' => $d['username'],
                    'full_name' => $d['full_name'],
                    'email' => $d['email'],
                    'phone' => $d['phone'] === '' ? null : $d['phone'],
                    'department_id' => $deptIds[$d['department_code']],
                    'role' => $d['role'],
                ], 'csv');
            }
        });

        return ['imported' => count($rows), 'rows' => $rows];
    }

    /**
     * Parse CSV text into rows keyed by header name. Header order and case do not matter;
     * blank lines are skipped; a UTF-8 BOM is removed. Values are trimmed; department
     * codes are upper-cased, usernames and roles lower-cased (blank role becomes "staff").
     *
     * @return list<array{line: int, data: array<string, string>, errors: list<string>}>
     * @throws ServiceException
     */
    public static function parse(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv) ?? $csv;
        $handle = fopen('php://memory', 'r+');
        fwrite($handle, $csv);
        rewind($handle);

        $header = fgetcsv($handle, 0, ',', '"', '');
        if ($header === false || $header === [null]) {
            throw new ServiceException('The file is empty. Download the template to see the expected columns.');
        }
        $header = array_map(static fn ($h): string => strtolower(trim((string) $h)), $header);

        $missing = array_diff(self::HEADERS, $header);
        if ($missing !== []) {
            throw new ServiceException('Missing column(s): ' . implode(', ', $missing) . '. Download the template to see the expected columns.');
        }

        $rows = [];
        $line = 1;
        while (($values = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            if ($values === [null] || implode('', array_map('trim', array_map('strval', $values))) === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new ServiceException('The file has more than ' . self::MAX_ROWS . ' rows. Please split it into smaller files.');
            }

            $data = [];
            foreach (self::HEADERS as $column) {
                $index = array_search($column, $header, true);
                $data[$column] = trim((string) ($values[$index] ?? ''));
            }
            $data['department_code'] = strtoupper($data['department_code']);
            $data['username'] = strtolower($data['username']);
            $data['role'] = strtolower($data['role']) === '' ? 'staff' : strtolower($data['role']);

            $rows[] = ['line' => $line, 'data' => $data, 'errors' => []];
        }
        fclose($handle);

        if ($rows === []) {
            throw new ServiceException('The file has a header row but no users.');
        }

        return $rows;
    }

    /** Columns that must be unique across users, with their labels for messages. */
    public const UNIQUE_COLUMNS = ['username' => 'Username', 'email' => 'Email', 'staff_id' => 'Staff ID'];

    /**
     * Check each row's format, plus uniqueness against existing users and within the file.
     * Pure function: all database facts are passed in.
     *
     * @param list<array{line: int, data: array<string, string>, errors: list<string>}> $rows
     * @param array<string, array<string, true>> $existing Per unique column: lower-cased values already in use
     * @param array<string, int> $deptIds Active department code => ID
     * @return list<array{line: int, data: array<string, string>, errors: list<string>}>
     */
    public static function checkRows(array $rows, array $existing, array $deptIds): array
    {
        $seen = [];

        foreach ($rows as &$row) {
            $d = $row['data'];
            $validator = Validator::make($d, [
                'staff_id' => 'required|max:30',
                'username' => ['required', 'min:3', 'max:50', 'regex:/^[a-z0-9._-]+$/'],
                'full_name' => 'required|max:150',
                'email' => 'required|email|max:150',
                'phone' => ['max:20', 'regex:/^[0-9+()\s-]*$/'],
                'department_code' => 'required',
                'role' => 'in:admin,hod,staff',
            ]);
            $errors = array_merge([], ...array_values($validator->errors()));

            foreach (self::UNIQUE_COLUMNS as $column => $label) {
                $value = strtolower($d[$column]);
                if ($value === '') {
                    continue;
                }
                if (isset($existing[$column][$value])) {
                    $errors[] = "$label is already used by an existing user.";
                } elseif (isset($seen[$column][$value])) {
                    $errors[] = "$label is repeated (also on line {$seen[$column][$value]}).";
                }
                $seen[$column][$value] ??= $row['line'];
            }
            if ($d['department_code'] !== '' && !isset($deptIds[$d['department_code']])) {
                $errors[] = "Department code \"{$d['department_code']}\" does not match an active department.";
            }

            $row['errors'] = $errors;
        }
        unset($row);

        return $rows;
    }

    /**
     * @param list<array{line: int, data: array<string, string>, errors: list<string>}> $rows
     * @return list<array{line: int, data: array<string, string>, errors: list<string>}>
     */
    private function validate(array $rows): array
    {
        $db = Database::instance();
        $data = array_column($rows, 'data');

        $existing = [];
        foreach (array_keys(self::UNIQUE_COLUMNS) as $column) {
            $existing[$column] = [];
            foreach (array_chunk(array_values(array_filter(array_column($data, $column))), 500) as $chunk) {
                $placeholders = implode(',', array_fill(0, count($chunk), '?'));
                // $column comes from the UNIQUE_COLUMNS constant, never from input.
                foreach ($db->fetchAll("SELECT $column AS v FROM users WHERE $column IN ($placeholders)", $chunk) as $r) {
                    $existing[$column][strtolower((string) $r['v'])] = true;
                }
            }
        }

        return self::checkRows($rows, $existing, $this->activeDepartmentIds());
    }

    /**
     * @return array<string, int>
     */
    private function activeDepartmentIds(): array
    {
        return array_map('intval', array_column($this->departments->active(), 'id', 'code'));
    }
}
