<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\UploadService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class UploadServiceTest extends TestCase
{
    /** @var list<string> */
    private array $tmpFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->tmpFiles as $f) {
            @unlink($f);
        }
    }

    public function testNormaliseTransposesMultipleUploadAndSkipsEmptySlots(): void
    {
        $entry = [
            'name' => ['a.pdf', '', 'b.png'],
            'type' => ['application/pdf', '', 'image/png'],
            'tmp_name' => ['/tmp/a', '', '/tmp/b'],
            'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_NO_FILE, UPLOAD_ERR_OK],
            'size' => [10, 0, 20],
        ];

        $files = UploadService::normalise($entry);

        $this->assertCount(2, $files);
        $this->assertSame('b.png', $files[1]['name']);
        $this->assertSame(20, $files[1]['size']);
        $this->assertSame([], UploadService::normalise(null));
    }

    public function testAcceptsRealPdfAndPng(): void
    {
        $pdf = $this->file('memo.pdf', "%PDF-1.4\n1 0 obj<<>>endobj\n%%EOF");
        $png = $this->file('scan.PNG', $this->pngBytes());

        $this->assertSame([], $this->service()->validate([$pdf, $png], 10));
    }

    public function testRejectsPhpRenamedAsPdf(): void
    {
        $fake = $this->file('invoice.pdf', "<?php system(\$_GET['c']);");

        $errors = $this->service()->validate([$fake], 10);

        $this->assertCount(1, $errors);
        $this->assertStringContainsString('"invoice.pdf": does not match its file type', $errors[0]);
    }

    public function testRejectsDisallowedExtension(): void
    {
        $errors = $this->service()->validate([$this->file('shell.php', 'x')], 10);

        $this->assertStringContainsString('is not an allowed type', $errors[0]);
    }

    public function testRejectsOversizedAndEmptyFiles(): void
    {
        $big = $this->file('big.pdf', '%PDF-1.4' . str_repeat('x', 1024 * 1024 + 10));
        $empty = $this->file('empty.pdf', '');

        $errors = $this->service()->validate([$big, $empty], 1);

        $this->assertStringContainsString('is larger than 1 MB', $errors[0]);
        $this->assertStringContainsString('is empty', $errors[1]);
    }

    public function testRejectsTooManyFiles(): void
    {
        $files = array_fill(0, UploadService::MAX_FILES + 1, $this->file('a.pdf', '%PDF-1.4'));

        $this->assertSame(['You can upload at most 5 files at a time.'], $this->service()->validate($files, 10));
    }

    public function testDocxMustContainWordDocument(): void
    {
        if (!class_exists(ZipArchive::class)) {
            $this->markTestSkipped('ext-zip not installed.');
        }

        $real = $this->zipFile('letter.docx', ['[Content_Types].xml' => '<x/>', 'word/document.xml' => '<w/>']);
        $fake = $this->zipFile('fake.docx', ['readme.txt' => 'not word']);

        $this->assertSame([], $this->service()->validate([$real], 10));
        $this->assertStringContainsString('is not a valid DOCX document', $this->service()->validate([$fake], 10)[0]);
    }

    public function testRejectsFilesThatWereNotUploaded(): void
    {
        $file = $this->file('memo.pdf', '%PDF-1.4');

        $errors = (new UploadService())->validate([$file], 10);

        $this->assertStringContainsString('did not upload completely', $errors[0]);
    }

    public function testSafeNameStripsPathsAndControlCharacters(): void
    {
        $this->assertSame('passwd', UploadService::safeName('../../etc/passwd'));
        $this->assertSame('report.pdf', UploadService::safeName("C:\\Users\\me\\report\x00.pdf"));
        $this->assertSame('file', UploadService::safeName('   '));
    }

    public function testAbsolutePathOnlyAcceptsGeneratedNames(): void
    {
        $this->assertNotNull(UploadService::absolutePath('2026/03/' . str_repeat('a', 32) . '.pdf'));
        $this->assertNull(UploadService::absolutePath('../../.env'));
        $this->assertNull(UploadService::absolutePath('2026/03/' . str_repeat('a', 32) . '.php'));
    }

    private function service(): UploadService
    {
        return new class extends UploadService {
            protected function isUploadedFile(string $path): bool
            {
                return is_file($path);
            }
        };
    }

    /**
     * @return array{name: string, tmp_name: string, error: int, size: int}
     */
    private function file(string $name, string $contents): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dts');
        file_put_contents($tmp, $contents);
        $this->tmpFiles[] = $tmp;

        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => strlen($contents)];
    }

    /**
     * @param array<string, string> $entries
     * @return array{name: string, tmp_name: string, error: int, size: int}
     */
    private function zipFile(string $name, array $entries): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'dts');
        $this->tmpFiles[] = $tmp;
        $zip = new ZipArchive();
        $zip->open($tmp, ZipArchive::OVERWRITE);
        foreach ($entries as $path => $content) {
            $zip->addFromString($path, $content);
        }
        $zip->close();

        return ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => (int) filesize($tmp)];
    }

    private function pngBytes(): string
    {
        return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==', true);
    }
}
