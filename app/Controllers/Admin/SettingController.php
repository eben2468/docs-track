<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Services\AuditService;
use App\Services\MailService;
use App\Services\ServiceException;
use App\Services\SettingService;
use Core\Request;
use Core\Response;
use Throwable;

/**
 * Admin: application settings (FR-15).
 */
final class SettingController extends Controller
{
    public function edit(Request $request): Response
    {
        $service = new SettingService();

        return $this->view('admin/settings/index', [
            'title' => 'Settings',
            'settings' => $service->all(),
            'hasLogo' => $service->logoFile() !== null,
            'mail' => $service->mailConfig(),
            'mailOverrides' => $service->mailOverrides(),
            'mailPasswordStatus' => $service->mailPasswordStatus(),
        ]);
    }

    /**
     * POST /admin/settings/mail: save the SMTP settings (they override .env).
     */
    public function updateMail(Request $request): Response
    {
        $data = $this->validate($request, [
            'mail_transport' => 'required|in:smtp,log',
            'mail_host' => ['required', 'max:255', 'regex:/^[A-Za-z0-9.-]+$/'],
            'mail_port' => 'required|integer|min:1|max:65535',
            'mail_encryption' => 'required|in:tls,ssl,none',
            'mail_username' => 'max:255',
            'mail_password' => 'max:255',
            'mail_from_address' => 'required|email|max:150',
            'mail_from_name' => 'required|max:100',
        ], [
            'mail_transport' => 'Delivery',
            'mail_host' => 'SMTP host',
            'mail_port' => 'Port',
            'mail_encryption' => 'Encryption',
            'mail_username' => 'Username',
            'mail_password' => 'Password',
            'mail_from_address' => 'From address',
            'mail_from_name' => 'From name',
        ]);

        $values = [];
        foreach (['transport', 'host', 'port', 'encryption', 'username', 'from_address', 'from_name'] as $key) {
            $values[$key] = trim((string) ($data['mail_' . $key] ?? ''));
        }
        // App passwords are often shown in groups ("abcd efgh ..."); spaces are never part of them.
        $password = preg_replace('/\s+/', '', (string) $request->input('mail_password', '')) ?? '';

        try {
            (new SettingService())->updateMail($values, $password === '' ? null : $password, $request->input('mail_clear_password') === '1');
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/settings');
        }

        return Response::redirect('/admin/settings#email')->with('success', 'Email settings saved. Send a test email to check them.');
    }

    /**
     * POST /admin/settings/mail/reset: forget the saved SMTP settings and use .env again.
     */
    public function resetMail(Request $request): Response
    {
        (new SettingService())->resetMail();

        return Response::redirect('/admin/settings#email')->with('success', 'Email settings reset to the values in the server\'s .env file.');
    }

    public function update(Request $request): Response
    {
        $data = $this->validate($request, [
            'app_name' => 'required|max:100',
            'unreceived_alert_hours' => 'required|integer|min:1|max:720',
            'alert_repeat_hours' => 'required|integer|min:1|max:720',
            'default_sla_days' => 'required|integer|min:1|max:60',
            'max_upload_mb' => 'required|integer|min:1|max:50',
            'primary_color' => ['required', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'logo' => 'file|mimes:png,jpg,jpeg,webp|max:1024',
        ], [
            'app_name' => 'Application name',
            'unreceived_alert_hours' => 'Unreceived alert (hours)',
            'alert_repeat_hours' => 'Alert repeat (hours)',
            'default_sla_days' => 'Default SLA (working days)',
            'max_upload_mb' => 'Maximum upload size (MB)',
            'primary_color' => 'Primary colour',
        ]);

        $logo = $request->file('logo');
        $hasLogo = $logo !== null && ($logo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK;

        $values = [];
        foreach (['app_name', 'unreceived_alert_hours', 'alert_repeat_hours', 'default_sla_days', 'max_upload_mb'] as $key) {
            $values[$key] = (string) $data[$key];
        }
        $values['primary_color'] = strtolower((string) $data['primary_color']);

        try {
            (new SettingService())->update($values, $hasLogo ? $logo : null, $request->input('remove_logo') === '1');
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/admin/settings');
        }

        return Response::redirect('/admin/settings')->with('success', 'Settings saved.');
    }

    /**
     * POST /admin/settings/test-email: send an email right away (not queued) to check
     * the email settings in effect.
     */
    public function testEmail(Request $request): Response
    {
        $data = $this->validate($request, ['test_email' => 'required|email|max:150'], ['test_email' => 'Test email address']);
        $to = (string) $data['test_email'];
        $mailConfig = (new SettingService())->mailConfig();

        try {
            $subject = setting('app_name') . ': test email';
            (new MailService())->sendNow($to, $subject, MailService::render('emails/test', $subject, [
                'transport' => $mailConfig['transport'] ?? 'smtp',
                'host' => $mailConfig['host'] ?? '',
                'port' => $mailConfig['port'] ?? '',
            ]));
        } catch (Throwable $e) {
            (new AuditService())->log('settings.test_email', ['to' => $to, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return Response::redirect('/admin/settings#email')
                ->withInput(['test_email' => $to])
                ->with('danger', 'The test email could not be sent: ' . mb_substr($e->getMessage(), 0, 300));
        }

        (new AuditService())->log('settings.test_email', ['to' => $to, 'ok' => true]);
        $where = ($mailConfig['transport'] ?? 'smtp') === 'log'
            ? ' Delivery is set to "log file", so it was written to storage/logs/mail.log instead of being sent.'
            : " Check the inbox of $to.";

        return Response::redirect('/admin/settings#email')->with('success', 'Test email sent.' . $where);
    }
}
