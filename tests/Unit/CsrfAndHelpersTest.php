<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Middleware\CsrfMiddleware;
use Core\Csrf;
use Core\HttpException;
use Core\Request;
use Core\Response;
use Core\View;
use PHPUnit\Framework\TestCase;

final class CsrfAndHelpersTest extends TestCase
{
    protected function setUp(): void
    {
        $_SESSION = [];
    }

    public function testPostWithoutTokenIsRejectedWith419(): void
    {
        Csrf::token();

        try {
            (new CsrfMiddleware())->handle(new Request('POST', '/x', [], ['title' => 'x']), $this->ok());
            $this->fail('Expected HttpException');
        } catch (HttpException $e) {
            $this->assertSame(419, $e->status());
        }
    }

    public function testPostWithWrongTokenIsRejected(): void
    {
        Csrf::token();

        $this->expectException(HttpException::class);
        (new CsrfMiddleware())->handle(new Request('POST', '/x', [], ['_token' => 'forged']), $this->ok());
    }

    public function testPostWithValidFieldOrHeaderTokenPasses(): void
    {
        $token = Csrf::token();

        $viaField = (new CsrfMiddleware())->handle(new Request('POST', '/x', [], ['_token' => $token]), $this->ok());
        $viaHeader = (new CsrfMiddleware())->handle(
            new Request('POST', '/x', [], [], [], ['HTTP_X_CSRF_TOKEN' => $token]),
            $this->ok()
        );

        $this->assertSame('ok', $viaField->body());
        $this->assertSame('ok', $viaHeader->body());
    }

    public function testGetRequestsSkipCsrf(): void
    {
        $this->assertSame('ok', (new CsrfMiddleware())->handle(new Request('GET', '/x'), $this->ok())->body());
    }

    public function testEscapeHelper(): void
    {
        $this->assertSame('&lt;script&gt;alert(&quot;x&quot;)&lt;/script&gt; &#039;', e('<script>alert("x")</script> \''));
        $this->assertSame('', e(null));
        $this->assertSame('42', e(42));
    }

    public function testUrlHelper(): void
    {
        $this->assertSame('http://localhost:8000/', url('/'));
        $this->assertSame('http://localhost:8000/documents', url('/documents'));
        $this->assertSame('http://localhost:8000/documents?tab=incoming&page=2', url('documents', ['tab' => 'incoming', 'page' => 2, 'q' => '']));
    }

    public function testCsrfFieldContainsEscapedSessionToken(): void
    {
        $this->assertSame('<input type="hidden" name="_token" value="' . Csrf::token() . '">', csrf_field());
    }

    public function testOldReturnsFlashedInputWithoutPasswords(): void
    {
        Response::redirect('/form')->withInput(['title' => 'Memo', 'password' => 'secret']);
        // Simulate the next request: new flash data becomes readable.
        $_SESSION['_flash_old'] = $_SESSION['_flash_new'];

        $this->assertSame('Memo', old('title'));
        $this->assertSame('', old('password'));
        $this->assertSame('fallback', old('missing', 'fallback'));
    }

    public function testErrorPagesRenderWithPublicLayout(): void
    {
        foreach ([403, 404, 405, 419, 500] as $status) {
            $html = View::render("errors/$status", ['status' => $status, 'title' => 'T', 'message' => '', 'exception' => null], 'layouts/public');
            $this->assertStringContainsString('<p class="error-code">' . $status . '</p>', $html);
        }
    }

    public function testSecurityHeadersAreAdded(): void
    {
        $headers = (new Response('x'))->withSecurityHeaders()->headers();

        $this->assertSame('SAMEORIGIN', $headers['X-Frame-Options']);
        $this->assertSame('nosniff', $headers['X-Content-Type-Options']);
        $this->assertSame('same-origin', $headers['Referrer-Policy']);
        $this->assertStringContainsString("default-src 'self'", $headers['Content-Security-Policy']);
    }

    /** @return callable(Request): Response */
    private function ok(): callable
    {
        return static fn (Request $r): Response => new Response('ok');
    }
}
