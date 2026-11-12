<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Models\EmailQueue;
use App\Services\AuthService;
use App\Services\Mail\MailTransport;
use App\Services\MailService;
use App\Services\ServiceException;
use App\Services\SettingService;
use Core\Auth;
use Tests\DatabaseTestCase;

/**
 * Passwordless sign-in: username -> emailed one-time code -> session. Also covers
 * lockout, enumeration resistance and session security.
 */
final class AuthSessionTest extends DatabaseTestCase
{
    private int $userId;

    private CapturingTransport $transport;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::flushCache();
        $_SESSION = [];
        $dept = $this->createDepartment('REG');
        $this->db->execute(
            "INSERT INTO users (staff_id, username, full_name, email, password_hash, role, department_id)
             VALUES ('S-1', 'ama.test', 'Ama Test', 'ama@s.local', ?, 'staff', ?)",
            [password_hash('random-secret', PASSWORD_DEFAULT), $dept]
        );
        $this->userId = $this->db->lastInsertId();
        $this->transport = new CapturingTransport();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Auth::forgetCachedUser();
        parent::tearDown();
    }

    public function testCodeIsEmailedAndSignsTheUserIn(): void
    {
        $auth = $this->auth();
        $auth->requestCode('Ama.Test', '10.0.0.1'); // usernames are case-insensitive

        $this->assertCount(1, $this->transport->sent);
        $this->assertSame('ama@s.local', $this->transport->sent[0]['to']);
        $code = $this->transport->lastCode();
        $this->assertStringNotContainsString($code, $this->transport->sent[0]['subject'], 'Code is not in the subject line');

        $user = $auth->verifyCode('ama.test', $code, '10.0.0.1');

        $this->assertSame($this->userId, (int) $user['id']);
        $this->assertSame($this->userId, Auth::id());
        $this->assertSame('ama.test', Auth::user()['username']);
        $this->assertArrayNotHasKey('password_hash', Auth::user(), 'Hash never leaves Auth');
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_otps WHERE code_hash = ?', [$code]), 'Only the hash is stored');
    }

    public function testCodeWorksOnlyOnce(): void
    {
        $auth = $this->auth();
        $auth->requestCode('ama.test', '10.0.0.1');
        $code = $this->transport->lastCode();
        $auth->verifyCode('ama.test', $code, '10.0.0.1');

        $this->expectException(ServiceException::class);
        $auth->verifyCode('ama.test', $code, '10.0.0.1');
    }

    public function testOnlyTheNewestCodeWorks(): void
    {
        $auth = $this->auth();
        $auth->requestCode('ama.test', '10.0.0.1');
        $first = $this->transport->lastCode();
        $auth->requestCode('ama.test', '10.0.0.1');
        $second = $this->transport->lastCode();

        try {
            $auth->verifyCode('ama.test', $first, '10.0.0.1');
            $this->fail('A replaced code must not work');
        } catch (ServiceException) {
        }
        $this->assertSame($this->userId, (int) $auth->verifyCode('ama.test', $second, '10.0.0.1')['id']);
    }

    public function testExpiredCodeIsRejected(): void
    {
        $auth = $this->auth();
        $auth->requestCode('ama.test', '10.0.0.1');
        $this->db->execute('UPDATE login_otps SET expires_at = NOW() - INTERVAL 1 MINUTE WHERE user_id = ?', [$this->userId]);

        $this->expectException(ServiceException::class);
        $auth->verifyCode('ama.test', $this->transport->lastCode(), '10.0.0.1');
    }

    public function testCodeIsCaseSensitiveButIgnoresPastedWhitespace(): void
    {
        $auth = $this->auth();
        $auth->requestCode('ama.test', '10.0.0.1');
        $code = $this->transport->lastCode();

        if (strtolower($code) !== $code) {
            try {
                $auth->verifyCode('ama.test', strtolower($code), '10.0.0.1');
                $this->fail('Codes are case-sensitive');
            } catch (ServiceException) {
            }
        }
        $this->assertSame($this->userId, (int) $auth->verifyCode('ama.test', "  $code\n", '10.0.0.1')['id']);
    }

    public function testNoEmailForUnknownOrDeactivatedAccounts(): void
    {
        $auth = $this->auth();
        $auth->requestCode('nobody', '10.0.0.1'); // no exception: the response must not reveal anything
        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$this->userId]);
        $auth->requestCode('ama.test', '10.0.0.1');

        $this->assertSame([], $this->transport->sent);
        $this->assertSame(0, (int) $this->db->fetchValue('SELECT COUNT(*) FROM login_otps'));
    }

    public function testCodeRequestsPerAccountAreCapped(): void
    {
        $auth = $this->auth();
        for ($i = 0; $i < AuthService::CODES_PER_WINDOW + 2; $i++) {
            $auth->requestCode('ama.test', '10.0.0.1');
        }

        $this->assertCount(AuthService::CODES_PER_WINDOW, $this->transport->sent);
    }

    public function testFailedSendIsQueuedForRetry(): void
    {
        $this->transport->fail = true;
        $this->auth()->requestCode('ama.test', '10.0.0.1');

        $this->assertSame(1, (new EmailQueue())->count(['to_email' => 'ama@s.local']));
    }

    public function testFiveWrongCodesLockTheAccount(): void
    {
        $auth = $this->auth();
        $auth->requestCode('ama.test', '10.0.0.2');
        $code = $this->transport->lastCode();
        for ($i = 1; $i <= AuthService::MAX_ATTEMPTS; $i++) {
            try {
                $auth->verifyCode('ama.test', "wrong$i", '10.0.0.2');
            } catch (ServiceException) {
            }
        }

        try {
            $auth->verifyCode('ama.test', $code, '10.0.0.3');
            $this->fail('Locked account must not sign in, even with the right code');
        } catch (ServiceException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }
        $this->assertNull(Auth::id());

        $auth->requestCode('ama.test', '10.0.0.3');
        $this->assertCount(1, $this->transport->sent, 'No new codes while locked');
    }

    public function testUnknownAccountsAndWrongCodesGetTheSameMessage(): void
    {
        $auth = $this->auth();
        $auth->requestCode('ama.test', '10.0.0.4');
        $messages = [];
        foreach ([['nobody', 'ABcd2345'], ['ama.test', 'ABcd2345']] as [$username, $code]) {
            try {
                $auth->verifyCode($username, $code, '10.0.0.4');
            } catch (ServiceException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(2, $messages);
        $this->assertSame($messages[0], $messages[1]);
    }

    public function testGeneratedCodesMixCaseDigitsAndSometimesSymbols(): void
    {
        $withSymbol = 0;
        $seen = [];
        for ($i = 0; $i < 300; $i++) {
            $code = AuthService::generateCode();
            $this->assertSame(6, strlen($code));
            $this->assertMatchesRegularExpression('/^[A-HJ-NP-Za-km-z2-9@#$%&*?!]+$/', $code, 'No look-alike characters');
            $this->assertMatchesRegularExpression('/[A-Z]/', $code, 'Has an uppercase letter');
            $this->assertMatchesRegularExpression('/[a-z]/', $code, 'Has a lowercase letter');
            $this->assertMatchesRegularExpression('/\d/', $code, 'Has a digit');
            $withSymbol += preg_match('/[@#$%&*?!]/', $code);
            $seen[$code] = true;
        }

        $this->assertGreaterThan(50, $withSymbol, 'Symbols appear in a good share of codes');
        $this->assertLessThan(300, $withSymbol, 'but not in every code');
        $this->assertCount(300, $seen, 'Codes are random');
    }

    public function testDeactivatedUserIsSignedOut(): void
    {
        $this->signIn();
        $this->db->execute('UPDATE users SET is_active = 0 WHERE id = ?', [$this->userId]);
        Auth::forgetCachedUser();

        $this->assertNull(Auth::user());
    }

    public function testSessionWithoutFingerprintIsRejected(): void
    {
        $_SESSION[Auth::SESSION_KEY] = $this->userId; // e.g. a forged or pre-upgrade session
        Auth::forgetCachedUser();

        $this->assertNull(Auth::user());
    }

    private function auth(): AuthService
    {
        return new AuthService(mail: new MailService(transport: $this->transport));
    }

    private function signIn(): void
    {
        $auth = $this->auth();
        $auth->requestCode('ama.test', '10.0.0.1');
        $auth->verifyCode('ama.test', $this->transport->lastCode(), '10.0.0.1');
    }
}

/**
 * Records emails instead of sending them, and pulls the code out of the sign-in email.
 */
final class CapturingTransport implements MailTransport
{
    /** @var list<array{to: string, subject: string, html: string}> */
    public array $sent = [];

    public bool $fail = false;

    public function send(string $to, string $subject, string $html): void
    {
        if ($this->fail) {
            throw new \RuntimeException('SMTP down');
        }
        $this->sent[] = ['to' => $to, 'subject' => $subject, 'html' => $html];
    }

    public function lastCode(): string
    {
        $html = end($this->sent)['html'] ?? '';
        preg_match('/user-select:all;">([^<]+)</', $html, $m);

        return html_entity_decode($m[1] ?? '', ENT_QUOTES | ENT_HTML5);
    }
}
