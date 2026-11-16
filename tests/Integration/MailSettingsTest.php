<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Services\ServiceException;
use App\Services\SettingService;
use Core\Config;
use Core\Encrypter;
use Tests\DatabaseTestCase;

/**
 * SMTP settings saved in Admin > Settings: override .env, password encrypted and write-only.
 */
final class MailSettingsTest extends DatabaseTestCase
{
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = Config::all();
        $items = $this->config;
        $items['app']['key'] = 'base64:' . base64_encode(str_repeat('k', 32));
        $items['mail'] = [
            'transport' => 'log', 'host' => 'smtp.env.test', 'port' => 25, 'encryption' => 'none',
            'username' => '', 'password' => 'env-secret', 'from_address' => 'env@x.test', 'from_name' => 'Env',
        ];
        Config::set($items);
        $this->db->execute("DELETE FROM settings WHERE `key` LIKE 'mail\\_%'");
        SettingService::flushCache();
    }

    protected function tearDown(): void
    {
        Config::set($this->config);
        SettingService::flushCache();
        parent::tearDown();
    }

    public function testEnvValuesApplyUntilSomethingIsSaved(): void
    {
        $service = new SettingService();

        $this->assertSame('smtp.env.test', $service->mailConfig()['host']);
        $this->assertSame([], $service->mailOverrides());
        $this->assertSame('env', $service->mailPasswordStatus());
    }

    public function testSavedValuesOverrideEnvAndPasswordIsEncrypted(): void
    {
        (new SettingService())->updateMail($this->gmail(), 'abcdefghijklmnop', false);
        SettingService::flushCache();
        $config = (new SettingService())->mailConfig();

        $this->assertSame('smtp', $config['transport']);
        $this->assertSame('smtp.gmail.com', $config['host']);
        $this->assertSame(587, $config['port']);
        $this->assertSame('abcdefghijklmnop', $config['password']);

        $stored = (string) $this->db->fetchValue("SELECT `value` FROM settings WHERE `key` = 'mail_password'");
        $this->assertStringNotContainsString('abcdefghijklmnop', $stored);
        $this->assertSame('abcdefghijklmnop', Encrypter::fromConfig()->decrypt($stored));

        $audit = (string) $this->db->fetchValue("SELECT details FROM audit_logs WHERE action = 'settings.mail_updated' ORDER BY id DESC LIMIT 1");
        $this->assertStringNotContainsString('abcdefghijklmnop', $audit, 'Password never reaches the audit log');
    }

    public function testBlankPasswordKeepsTheSavedOneAndClearRemovesIt(): void
    {
        $service = new SettingService();
        $service->updateMail($this->gmail(), 'first-pass', false);
        $service->updateMail(['host' => 'smtp.office365.com'] + $this->gmail(), null, false);
        SettingService::flushCache();
        $this->assertSame('first-pass', $service->mailConfig()['password']);
        $this->assertSame('saved', $service->mailPasswordStatus());

        $service->updateMail($this->gmail(), null, true);
        SettingService::flushCache();
        $this->assertSame('env-secret', $service->mailConfig()['password'], 'Falls back to .env');
    }

    public function testPasswordSavedWithAnotherKeyIsReportedUnreadable(): void
    {
        (new SettingService())->updateMail($this->gmail(), 'secret', false);
        $items = Config::all();
        $items['app']['key'] = 'base64:' . base64_encode(str_repeat('z', 32));
        Config::set($items);
        SettingService::flushCache();

        $this->assertSame('unreadable', (new SettingService())->mailPasswordStatus());
        $this->assertSame('', (new SettingService())->mailConfig()['password']);
    }

    public function testPasswordNeedsAppKey(): void
    {
        $items = Config::all();
        $items['app']['key'] = '';
        Config::set($items);

        $this->expectException(ServiceException::class);
        (new SettingService())->updateMail($this->gmail(), 'secret', false);
    }

    public function testResetGoesBackToEnv(): void
    {
        $service = new SettingService();
        $service->updateMail($this->gmail(), 'secret', false);
        $service->resetMail();
        SettingService::flushCache();

        $this->assertSame([], $service->mailOverrides());
        $this->assertSame('smtp.env.test', $service->mailConfig()['host']);
        $this->assertSame('log', $service->mailConfig()['transport']);
    }

    /**
     * @return array{transport: string, host: string, port: string, encryption: string, username: string, from_address: string, from_name: string}
     */
    private function gmail(): array
    {
        return [
            'transport' => 'smtp', 'host' => 'smtp.gmail.com', 'port' => '587', 'encryption' => 'tls',
            'username' => 'dts@gmail.test', 'from_address' => 'dts@gmail.test', 'from_name' => 'VVU DTS',
        ];
    }
}
