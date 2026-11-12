<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Services\SettingService;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Public branding assets driven by settings: theme colours and the uploaded logo.
 * The logo lives in storage/ (outside the web root), so it is streamed from here.
 */
final class BrandingController extends Controller
{
    public function theme(Request $request): Response
    {
        return (new Response(SettingService::themeCss(setting('primary_color'))))
            ->header('Content-Type', 'text/css; charset=UTF-8')
            ->header('Cache-Control', 'public, max-age=86400');
    }

    public function logo(Request $request): Response
    {
        return $this->image((new SettingService())->logoFile() ?? throw new HttpException(404));
    }

    /**
     * GET /branding/favicon: the logo resized to a small square icon (browser tab, home screen).
     */
    public function favicon(Request $request): Response
    {
        return $this->image((new SettingService())->faviconFile() ?? throw new HttpException(404));
    }

    private function image(string $file): Response
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file);

        return (new Response((string) file_get_contents($file)))
            ->header('Content-Type', in_array($mime, ['image/png', 'image/jpeg', 'image/webp'], true) ? $mime : 'application/octet-stream')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
