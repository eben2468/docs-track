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
        ]);
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
     * the SMTP settings in .env.
     */
    public function testEmail(Request $request): Response
    {
        $data = $this->validate($request, ['test_email' => 'required|email|max:150'], ['test_email' => 'Test email address']);
        $to = (string) $data['test_email'];
        $mailConfig = (array) config('mail');

        try {
            $subject = setting('app_name') . ': test email';
            (new MailService())->sendNow($to, $subject, MailService::render('emails/test', $subject, [
                'transport' => $mailConfig['transport'] ?? 'smtp',
                'host' => $mailConfig['host'] ?? '',
                'port' => $mailConfig['port'] ?? '',
            ]));
        } catch (Throwable $e) {
            (new AuditService())->log('settings.test_email', ['to' => $to, 'ok' => false, 'error' => mb_substr($e->getMessage(), 0, 300)]);

            return Response::redirect('/admin/settings')
                ->withInput(['test_email' => $to])
                ->with('danger', 'The test email could not be sent: ' . mb_substr($e->getMessage(), 0, 300));
        }

        (new AuditService())->log('settings.test_email', ['to' => $to, 'ok' => true]);
        $where = ($mailConfig['transport'] ?? 'smtp') === 'log'
            ? ' MAIL_TRANSPORT is "log", so it was written to storage/logs/mail.log instead of being sent.'
            : " Check the inbox of $to.";

        return Response::redirect('/admin/settings')->with('success', 'Test email sent.' . $where);
    }
}
