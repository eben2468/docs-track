<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\AuditService;
use App\Services\ServiceException;
use App\Services\SettingService;
use App\Services\UserImportService;
use Core\Validator;
use PHPUnit\Framework\TestCase;

/**
 * Database-free logic added in Phase 2: CSV import parsing/checks, theme colours,
 * audit diffs and the new validation rules.
 */
final class Phase2LogicTest extends TestCase
{
    public function testCsvParseNormalisesValuesAndIgnoresColumnOrderBomAndBlankLines(): void
    {
        $csv = "\xEF\xBB\xBFRole,Email,staff_id,Username,full_name,phone,department_code\r\n"
            . " , A@x.test , S1 , Ama.M , Ama , , reg \r\n"
            . "\r\n"
            . "HOD,b@x.test,S2,kofi,Kofi,024,BUR\r\n";

        $rows = UserImportService::parse($csv);

        $this->assertCount(2, $rows);
        $this->assertSame(2, $rows[0]['line']);
        $this->assertSame(4, $rows[1]['line']);
        $this->assertSame(
            ['staff_id' => 'S1', 'username' => 'ama.m', 'full_name' => 'Ama', 'email' => 'A@x.test', 'phone' => '', 'department_code' => 'REG', 'role' => 'staff'],
            $rows[0]['data']
        );
        $this->assertSame('hod', $rows[1]['data']['role']);
    }

    public function testCsvParseRejectsMissingColumns(): void
    {
        $this->expectException(ServiceException::class);
        $this->expectExceptionMessage('Missing column(s): username, phone, department_code, role');
        UserImportService::parse("staff_id,full_name,email\nS1,Ama,a@x.test\n");
    }

    public function testCsvParseRejectsHeaderOnlyFile(): void
    {
        $this->expectException(ServiceException::class);
        UserImportService::parse(implode(',', UserImportService::HEADERS) . "\n\n");
    }

    public function testCsvRowChecksReportEveryProblem(): void
    {
        $rows = UserImportService::parse(
            "staff_id,username,full_name,email,phone,department_code,role\n"
            . "S1,ama,Ama,ama@x.test,,REG,staff\n"           // ok
            . "OLD1,kofi,Kofi,taken@x.test,abc,XYZ,boss\n"   // taken username + staff ID + email, bad phone, unknown dept, bad role
            . "S1,AMA,Yaw,ama@x.test,,REG,staff\n"           // repeats line 2
            . ",,,,,,\n"                                     // blank: skipped
            . ",e s,Esi,not-an-email,,REG,\n"                // missing staff ID, bad username, bad email
        );

        $existing = ['username' => ['kofi' => true], 'email' => ['taken@x.test' => true], 'staff_id' => ['old1' => true]];
        $checked = UserImportService::checkRows($rows, $existing, ['REG' => 1]);

        $this->assertSame([], $checked[0]['errors']);
        $this->assertSame([
            'Phone format is invalid.',
            'Role is not a valid choice.',
            'Username is already used by an existing user.',
            'Email is already used by an existing user.',
            'Staff ID is already used by an existing user.',
            'Department code "XYZ" does not match an active department.',
        ], $checked[1]['errors']);
        $this->assertSame([
            'Username is repeated (also on line 2).',
            'Email is repeated (also on line 2).',
            'Staff ID is repeated (also on line 2).',
        ], $checked[2]['errors']);
        $this->assertContains('Username format is invalid.', $checked[3]['errors']);
        $this->assertSame(6, $checked[3]['line']);
        $this->assertContains('Staff id is required.', $checked[3]['errors']);
        $this->assertContains('Email must be a valid email address.', $checked[3]['errors']);
    }

    public function testTemplateHasHeaderAndExampleRow(): void
    {
        $rows = UserImportService::parse(UserImportService::template());

        $this->assertCount(1, $rows);
        $this->assertSame('REG', $rows[0]['data']['department_code']);
    }

    public function testThemeCssDerivesDarkShadeAndRgb(): void
    {
        $css = SettingService::themeCss('#0B3D75');

        $this->assertStringContainsString('--dts-primary: #0b3d75;', $css);
        $this->assertStringContainsString('--dts-primary-dark: #09305b;', $css);
        $this->assertStringContainsString('--bs-primary-rgb: 11, 61, 117;', $css);
    }

    public function testThemeCssFallsBackOnInvalidColour(): void
    {
        $css = SettingService::themeCss('red; } body { display:none');

        $this->assertStringContainsString('--dts-primary: #0b3d75;', $css);
        $this->assertStringNotContainsString('display', $css);
    }

    public function testAuditDiffListsOnlyChangedFieldsAndHidesSecrets(): void
    {
        $before = ['name' => 'Registry', 'code' => 'REG', 'parent_id' => null, 'is_active' => 1, 'password_hash' => 'x'];
        $after = ['name' => 'Registry', 'code' => 'REGI', 'parent_id' => 3, 'is_active' => '1', 'password_hash' => 'y', 'unknown' => 1];

        $this->assertSame(['code' => ['REG', 'REGI'], 'parent_id' => [null, 3]], AuditService::diff($before, $after));
    }

    public function testCsvExportNeutralisesFormulas(): void
    {
        $csv = \App\Services\CsvExport::build(['A', 'B'], [['=HYPERLINK("http://evil")', '+1'], ['-2', 'Plain "quoted", text'], ['@SUM(A1)', null]]);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString("\"'=HYPERLINK(\"\"http://evil\"\")\",'+1\r\n", $csv);
        $this->assertStringContainsString("'-2,\"Plain \"\"quoted\"\", text\"\r\n", $csv);
        $this->assertStringContainsString("'@SUM(A1),\r\n", $csv);
    }

    public function testStrongPasswordRule(): void
    {
        $rules = ['password' => 'strong_password'];

        $this->assertTrue(Validator::make(['password' => 'ChangeMe@123'], $rules)->passes());
        $this->assertTrue(Validator::make(['password' => 'ábcdefg1'], $rules)->passes());
        $this->assertTrue(Validator::make(['password' => 'short1'], $rules)->fails());
        $this->assertTrue(Validator::make(['password' => 'onlyletters'], $rules)->fails());
        $this->assertTrue(Validator::make(['password' => '12345678'], $rules)->fails());
    }

    public function testRegexRule(): void
    {
        $rules = ['code' => ['required', 'regex:/^[A-Za-z]{2,6}$/']];

        $this->assertTrue(Validator::make(['code' => 'REG'], $rules)->passes());
        $this->assertSame('Code format is invalid.', Validator::make(['code' => 'R3G'], $rules)->first('code'));
        $this->assertTrue(Validator::make(['code' => 'TOOLONGX'], $rules)->fails());
    }
}
