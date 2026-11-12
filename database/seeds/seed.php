<?php

declare(strict_types=1);

/*
 * Development / initial seed data (spec §8.1).
 *
 * Idempotent: every step inserts rows that are missing and never overwrites existing
 * ones, so re-running --seed will not undo changes made through the admin pages.
 * Test accounts use the reserved ".test" domain so no real mailbox ever receives mail.
 */

use Core\Database;

/**
 * Insert a row unless a row with the same unique key exists. Returns 1 if inserted.
 *
 * @param array<string, mixed> $row Column names are fixed strings from this file, never user input.
 */
$insertIfMissing = static function (Database $db, string $table, array $row): int {
    $columns = array_keys($row);
    $sql = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE `%s` = `%s`',
        $table,
        implode(', ', array_map(static fn (string $c): string => "`$c`", $columns)),
        implode(', ', array_fill(0, count($columns), '?')),
        $columns[0],
        $columns[0]
    );

    return $db->execute($sql, array_values($row)) === 1 ? 1 : 0;
};

/**
 * Easter Sunday for a Gregorian year (anonymous Gregorian algorithm; no ext-calendar needed).
 */
$easterSunday = static function (int $year): DateTimeImmutable {
    $a = $year % 19;
    $b = intdiv($year, 100);
    $c = $year % 100;
    $d = intdiv($b, 4);
    $e = $b % 4;
    $f = intdiv($b + 8, 25);
    $g = intdiv($b - $f + 1, 3);
    $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4);
    $k = $c % 4;
    $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
    $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31);
    $day = (($h + $l - 7 * $m + 114) % 31) + 1;

    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
};

/**
 * Ghana statutory public holidays for a year. Islamic holidays depend on the moon and are
 * announced each year, so only provisional dates for known years are included; admins
 * should confirm all dates on the Holidays page against the official gazette.
 *
 * @return array<string, string> date (Y-m-d) => name
 */
$ghanaHolidays = static function (int $year) use ($easterSunday): array {
    $easter = $easterSunday($year);
    $farmersDay = new DateTimeImmutable("first friday of december $year");

    $holidays = [
        "$year-01-01" => "New Year's Day",
        "$year-01-07" => 'Constitution Day',
        "$year-03-06" => 'Independence Day',
        $easter->modify('-2 days')->format('Y-m-d') => 'Good Friday',
        $easter->modify('+1 day')->format('Y-m-d') => 'Easter Monday',
        "$year-05-01" => 'May Day',
        "$year-07-01" => 'Republic Day',
        "$year-09-21" => 'Kwame Nkrumah Memorial Day',
        $farmersDay->format('Y-m-d') => "Farmers' Day",
        "$year-12-25" => 'Christmas Day',
        "$year-12-26" => 'Boxing Day',
    ];

    $eid = [
        2026 => ['2026-03-20' => 'Eid al-Fitr (provisional)', '2026-05-27' => 'Eid al-Adha (provisional)'],
        2027 => ['2027-03-10' => 'Eid al-Fitr (provisional)', '2027-05-17' => 'Eid al-Adha (provisional)'],
    ];

    $holidays += $eid[$year] ?? [];
    ksort($holidays);

    return $holidays;
};

return static function (Database $db, callable $line) use ($insertIfMissing, $ghanaHolidays): void {
    $db->transaction(static function (Database $db) use ($line, $insertIfMissing, $ghanaHolidays): void {
        // Departments (sample: edit to match VVU's actual structure).
        $departments = [
            ['VC', 'Office of the Vice-Chancellor'],
            ['REG', 'Registry'],
            ['BUR', 'Bursary / Finance'],
            ['HR', 'Human Resources'],
            ['ADM', 'Admissions'],
            ['ACA', 'Academic Affairs'],
            ['FOS', 'Faculty of Science'],
            ['FOB', 'Faculty of Business'],
            ['ICT', 'ICT Directorate'],
            ['LIB', 'Library'],
            ['CHP', 'Chaplaincy'],
            ['SAF', "Students' Affairs"],
        ];
        $inserted = 0;
        foreach ($departments as [$code, $name]) {
            $inserted += $insertIfMissing($db, 'departments', ['code' => $code, 'name' => $name]);
        }
        $line(sprintf('  Departments     %d inserted, %d already present', $inserted, count($departments) - $inserted));

        // Document types with default SLA (working days).
        $types = [
            'Memo' => 3, 'Letter' => 3, 'Payment Voucher' => 2, 'Transcript Request' => 5,
            'Leave Request' => 2, 'Contract' => 5, 'Student Petition' => 5, 'Other' => 3,
        ];
        $inserted = 0;
        foreach ($types as $name => $sla) {
            $inserted += $insertIfMissing($db, 'document_types', ['name' => $name, 'sla_days' => $sla]);
        }
        $line(sprintf('  Document types  %d inserted, %d already present', $inserted, count($types) - $inserted));

        // Users: 1 admin + 1 HOD and 2 staff in REG, BUR and ICT, with usernames taken from
        // their email (admin, reg.hod, reg.staff1, ...). Dev-only accounts, so never created in
        // production (use database/create_admin.php there). Sign-in codes go to the mail log.
        $deptIds = array_column($db->fetchAll('SELECT id, code FROM departments'), 'id', 'code');
        $production = config('app.env') === 'production';
        if ($production) {
            $line('  Users           skipped in production (run database/create_admin.php)');
        }
        $passwordHash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

        $users = $production ? [] : [['VVU-0001', 'System Administrator', 'admin@vvu.test', 'admin', 'ICT']];
        foreach ($production ? [] : ['REG' => [1, 'Registry'], 'BUR' => [2, 'Bursary'], 'ICT' => [3, 'ICT']] as $code => [$n, $label]) {
            $prefix = strtolower($code);
            $users[] = [sprintf('VVU-%d001', $n), "$label HOD", "$prefix.hod@vvu.test", 'hod', $code];
            $users[] = [sprintf('VVU-%d002', $n), "$label Staff 1", "$prefix.staff1@vvu.test", 'staff', $code];
            $users[] = [sprintf('VVU-%d003', $n), "$label Staff 2", "$prefix.staff2@vvu.test", 'staff', $code];
        }
        $inserted = 0;
        foreach ($users as [$staffId, $name, $email, $role, $deptCode]) {
            $inserted += $insertIfMissing($db, 'users', [
                'email' => $email,
                'staff_id' => $staffId,
                'username' => strstr($email, '@', true),
                'full_name' => $name,
                'password_hash' => $passwordHash,
                'role' => $role,
                'department_id' => $deptIds[$deptCode],
            ]);
        }
        if (!$production) {
            $line(sprintf('  Users           %d inserted, %d already present', $inserted, count($users) - $inserted));

            // Set each seeded HOD as head of their department, unless a head is already set.
            $heads = $db->execute(
                "UPDATE departments d
                   JOIN users u ON u.department_id = d.id AND u.role = 'hod' AND u.email = CONCAT(LOWER(d.code), '.hod@vvu.test')
                    SET d.head_user_id = u.id
                  WHERE d.head_user_id IS NULL"
            );
            $line(sprintf('  Department HODs %d assigned', $heads));
        }

        // Settings (FR-15 defaults).
        $settings = [
            'app_name' => 'VVU Document Tracking System',
            'unreceived_alert_hours' => '24',
            'alert_repeat_hours' => '24',
            'default_sla_days' => '3',
            'max_upload_mb' => '10',
            'primary_color' => '#0b3d75',
        ];
        $inserted = 0;
        foreach ($settings as $key => $value) {
            $inserted += $insertIfMissing($db, 'settings', ['key' => $key, 'value' => $value]);
        }
        $line(sprintf('  Settings        %d inserted, %d already present', $inserted, count($settings) - $inserted));

        // Ghana public holidays for the current year.
        $year = (int) now()->format('Y');
        $holidays = $ghanaHolidays($year);
        $inserted = 0;
        foreach ($holidays as $date => $name) {
            $inserted += $insertIfMissing($db, 'holidays', ['holiday_date' => $date, 'name' => $name]);
        }
        $line(sprintf('  %-15s %d inserted, %d already present', "Holidays $year", $inserted, count($holidays) - $inserted));
    });
};
