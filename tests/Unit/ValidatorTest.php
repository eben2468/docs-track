<?php

declare(strict_types=1);

namespace Tests\Unit;

use Core\Validator;
use PHPUnit\Framework\TestCase;

final class ValidatorTest extends TestCase
{
    public function testRequiredRejectsMissingAndBlank(): void
    {
        $v = Validator::make(['title' => '   '], ['title' => 'required', 'note' => 'required']);

        $this->assertTrue($v->fails());
        $this->assertSame('Title is required.', $v->first('title'));
        $this->assertSame('Note is required.', $v->first('note'));
    }

    public function testOptionalEmptyFieldsSkipOtherRules(): void
    {
        $v = Validator::make(['email' => ''], ['email' => 'email|max:5']);

        $this->assertTrue($v->passes());
        $this->assertNull($v->validated()['email']);
    }

    public function testEmailMaxMinAndIn(): void
    {
        $v = Validator::make(
            ['email' => 'not-an-email', 'code' => 'TOOLONGCODE', 'name' => 'A', 'role' => 'superuser'],
            ['email' => 'required|email', 'code' => 'required|max:6', 'name' => 'min:2', 'role' => 'in:admin,hod,staff']
        );

        $this->assertSame('Email must be a valid email address.', $v->first('email'));
        $this->assertSame('Code must not be greater than 6 characters.', $v->first('code'));
        $this->assertSame('Name must be at least 2 characters.', $v->first('name'));
        $this->assertSame('Role is not a valid choice.', $v->first('role'));
    }

    public function testNumericMinMaxCompareValues(): void
    {
        $v = Validator::make(['pages' => '500', 'sla' => '0'], ['pages' => 'integer|max:300', 'sla' => 'integer|min:1']);

        $this->assertSame('Pages must not be greater than 300.', $v->first('pages'));
        $this->assertSame('Sla must be at least 1.', $v->first('sla'));
    }

    public function testDateAndConfirmed(): void
    {
        $v = Validator::make(
            ['due' => '2026-02-30', 'password' => 'secret123', 'password_confirmation' => 'other'],
            ['due' => 'date', 'password' => 'required|confirmed']
        );

        $this->assertNotNull($v->first('due'));
        $this->assertSame('Password confirmation does not match.', $v->first('password'));
    }

    public function testCustomLabelsAndValidatedTrims(): void
    {
        $v = Validator::make(['ref_no' => '  REF-1  '], ['ref_no' => 'required|max:100'], ['ref_no' => 'Reference number']);

        $this->assertTrue($v->passes());
        $this->assertSame(['ref_no' => 'REF-1'], $v->validated());

        $v2 = Validator::make([], ['ref_no' => 'required'], ['ref_no' => 'Reference number']);
        $this->assertSame('Reference number is required.', $v2->first('ref_no'));
    }

    public function testMimesUsesFileContentNotExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dts');
        file_put_contents($tmp, "<?php echo 'pwned';");

        $file = ['name' => 'invoice.pdf', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 20, 'type' => 'application/pdf'];
        $v = TrustingValidator::make(['doc' => $file], ['doc' => 'required|file|mimes:pdf,png|max:10240']);

        $this->assertSame('Doc must be a file of type: pdf, png.', $v->first('doc'));
        unlink($tmp);
    }

    public function testMimesAcceptsRealPdfAndChecksSizeInKb(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dts');
        file_put_contents($tmp, "%PDF-1.4\n" . str_repeat('x', 3000) . "\n%%EOF");

        $file = ['name' => 'memo.PDF', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => 3020, 'type' => ''];
        $ok = TrustingValidator::make(['doc' => $file], ['doc' => 'file|mimes:pdf|max:10']);
        $tooBig = TrustingValidator::make(['doc' => $file], ['doc' => 'file|mimes:pdf|max:2']);

        $this->assertTrue($ok->passes(), implode(' ', $ok->errors()['doc'] ?? []));
        $this->assertSame('Doc must not be greater than 2 kilobytes.', $tooBig->first('doc'));
        unlink($tmp);
    }

    public function testFileRuleRejectsNonUploadedFile(): void
    {
        $file = ['name' => 'x.pdf', 'tmp_name' => __FILE__, 'error' => UPLOAD_ERR_OK, 'size' => 1];
        $v = Validator::make(['doc' => $file], ['doc' => 'required|file']);

        $this->assertSame('Doc must be a successfully uploaded file.', $v->first('doc'));
    }

    public function testMissingOptionalFileIsSkipped(): void
    {
        $file = ['name' => '', 'tmp_name' => '', 'error' => UPLOAD_ERR_NO_FILE, 'size' => 0];

        $this->assertTrue(Validator::make(['doc' => $file], ['doc' => 'file|mimes:pdf'])->passes());
    }

    public function testUnsafeIdentifierInUniqueRuleIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Validator::make(['email' => 'a@b.com'], ['email' => 'unique:users;DROP TABLE users,email']);
    }
}

/** Treats any existing file as an HTTP upload so file rules can be unit-tested. */
final class TrustingValidator extends Validator
{
    protected function isUploadedFile(string $tmpPath): bool
    {
        return is_file($tmpPath);
    }
}
