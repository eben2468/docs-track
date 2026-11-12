<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\AuthService;
use App\Services\ProfileService;
use App\Services\ServiceException;
use App\Services\SettingService;
use Core\Auth;
use Tests\DatabaseTestCase;

/**
 * Session security: a password change or reset ends every other session of the account,
 * deactivation ends sessions, and lockout/throttling hold.
 */
final class AuthSessionTest extends DatabaseTestCase
{
    private int $userId;

    protected function setUp(): void
    {
        parent::setUp();
        SettingService::flushCache();
        $_SESSION = [];
        $dept = $this->createDepartment('REG');
        $this->db->execute(
            "INSERT INTO users (staff_id, full_name, email, password_hash, role, department_id) VALUES ('S-1', 'Ama Test', 'ama@s.local', ?, 'staff', ?)",
            [password_hash('Original123', PASSWORD_DEFAULT), $dept]
        );
        $this->userId = $this->db->lastInsertId();
    }

    protected function tearDown(): void
    {
        $_SESSION = [];
        Auth::forgetCachedUser();
        parent::tearDown();
    }

    public function testLoginStoresUserAndFingerprint(): void
    {
        (new AuthService())->attempt('S-1', 'Original123', '10.0.0.1');

        $this->assertSame($this->userId, Auth::id());
        $this->assertSame('Ama Test', Auth::user()['full_name']);
        $this->assertArrayNotHasKey('password_hash', Auth::user(), 'Hash never leaves Auth');
    }

    public function testPasswordChangedElsewhereEndsThisSession(): void
    {
        (new AuthService())->attempt('ama@s.local', 'Original123', '10.0.0.1');
        $otherBrowser = $_SESSION;

        // The password is reset from another browser / by an administrator.
        $this->db->execute('UPDATE users SET password_hash = ? WHERE id = ?', [password_hash('Changed456', PASSWORD_DEFAULT), $this->userId]);

        $_SESSION = $otherBrowser;
        Auth::forgetCachedUser();
        $this->assertNull(Auth::user(), 'Old session no longer authenticates');
    }

    public function testChangingYourOwnPasswordKeepsThisSessionButEndsOthers(): void
    {
        (new AuthService())->attempt('ama@s.local', 'Original123', '10.0.0.1');
        $otherBrowser = $_SESSION;

        (new ProfileService())->changePassword($this->userId, 'Original123', 'Changed456');
        $this->assertNotNull(Auth::user(), 'Current session stays signed in');

        $_SESSION = $otherBrowser;
        Auth::forgetCachedUser();
        $this->assertNull(Auth::user(), 'The other session is signed out');
    }

    public function testDeactivatedUserIsSignedOut(): void
    {
        (new AuthService())->attempt('ama@s.local', 'Original123', '10.0.0.1');
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

    public function testFiveFailuresLockTheAccount(): void
    {
        $auth = new AuthService();
        for ($i = 1; $i <= AuthService::MAX_ATTEMPTS; $i++) {
            try {
                $auth->attempt('S-1', "wrong$i", '10.0.0.2');
            } catch (ServiceException) {
            }
        }

        try {
            $auth->attempt('S-1', 'Original123', '10.0.0.3');
            $this->fail('Locked account must not sign in, even with the right password');
        } catch (ServiceException $e) {
            $this->assertStringContainsString('locked', $e->getMessage());
        }
        $this->assertNull(Auth::id());
    }

    public function testUnknownAccountsAndWrongPasswordsGetTheSameMessage(): void
    {
        $messages = [];
        foreach ([['nobody@s.local', 'x'], ['S-1', 'wrong']] as [$login, $password]) {
            try {
                (new AuthService())->attempt($login, $password, '10.0.0.4');
            } catch (ServiceException $e) {
                $messages[] = $e->getMessage();
            }
        }

        $this->assertCount(2, $messages);
        $this->assertSame($messages[0], $messages[1]);
    }
}
