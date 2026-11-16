<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Mail\SmtpTransport;
use PHPMailer\PHPMailer\PHPMailer;
use PHPUnit\Framework\TestCase;

/**
 * Message building (no network): the header logo is embedded as an inline image.
 */
final class SmtpTransportTest extends TestCase
{
    private const HTML = '<p><img src="cid:vvu-logo" alt="VVU"> Hello <a href="http://x.test/login">Sign in</a></p>';

    public function testLogoIsEmbeddedAsInlineImage(): void
    {
        $logo = tempnam(sys_get_temp_dir(), 'logo');
        // 1x1 transparent PNG.
        file_put_contents($logo, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAYAAjCB0C8AAAAASUVORK5CYII='));

        $mime = $this->mime(['logo_file' => $logo]);
        unlink($logo);

        $this->assertStringContainsString('Content-ID: <vvu-logo>', $mime);
        $this->assertStringContainsString('Content-Disposition: inline', $mime);
        $this->assertStringContainsString('multipart/related', $mime);
        $this->assertStringContainsString('cid:vvu-logo', $mime);
    }

    public function testImageTagIsDroppedWhenThereIsNoLogoFile(): void
    {
        $mime = $this->mime(['logo_file' => '']);

        $this->assertStringNotContainsString('cid:vvu-logo', $mime);
        $this->assertStringNotContainsString('Content-ID', $mime);
        $this->assertStringContainsString('Hello', $mime);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private function mime(array $extra): string
    {
        $transport = new SmtpTransport($extra + [
            'host' => 'smtp.test', 'port' => 587, 'encryption' => 'tls', 'username' => '', 'password' => '',
            'from_address' => 'dts@x.test', 'from_name' => 'VVU DTS',
        ]);
        $mail = new PHPMailer(true);
        $transport->build($mail, 'user@x.test', 'Subject', self::HTML);
        $mail->preSend();

        return $mail->getSentMIMEMessage();
    }
}
