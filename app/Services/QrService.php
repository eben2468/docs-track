<?php

declare(strict_types=1);

namespace App\Services;

use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\SvgWriter;

/**
 * QR codes for document labels (spec §5.2). SVG output: sharp at any print size and
 * independent of the GD extension.
 */
final class QrService
{
    /**
     * The URL a document's QR code opens: {APP_URL}/track/{tracking_no}.
     */
    public static function trackingUrl(string $trackingNo): string
    {
        return url('/track/' . rawurlencode($trackingNo));
    }

    /**
     * A data: URI (SVG) of the QR code for a tracking number, for use in an <img src>.
     */
    public function dataUri(string $trackingNo): string
    {
        $qr = new QrCode(
            data: self::trackingUrl($trackingNo),
            errorCorrectionLevel: ErrorCorrectionLevel::Medium,
            size: 600,
            margin: 0,
        );

        return (new SvgWriter())->write($qr)->getDataUri();
    }
}
