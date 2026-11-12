<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\LoginThrottle;
use App\Policies\DocumentPolicy;
use App\Services\TrackingNumberService;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Public tracking page (FR-14) and the target of every QR label (spec §5.2).
 *
 * Signed-in users who may view the document are taken to its detail page with the
 * QR receive banner. Everyone else sees only: tracking number, type, status, current
 * location and last update, plus the title for non-confidential documents.
 * Lookups are rate-limited per IP.
 */
final class TrackController extends Controller
{
    public const LIMIT = 30;

    public const WINDOW_MINUTES = 10;

    public function index(Request $request): Response
    {
        $no = $this->queryString($request, 'no');
        if ($no !== '') {
            return Response::redirect('/track/' . rawurlencode(strtoupper($no)));
        }

        return $this->view('track/index', ['title' => 'Track a document', 'trackingNo' => ''], 'layouts/public');
    }

    public function show(Request $request, string $trackingNo): Response
    {
        $trackingNo = strtoupper(trim($trackingNo));
        $user = Auth::user();
        $documents = new Document();

        if ($user !== null) {
            $document = $documents->findByTrackingNo($trackingNo);
            if ($document !== null) {
                $route = (new DocumentMovement())->routeDepartmentIds((int) $document['id']);
                if ((new DocumentPolicy())->canView($user, $document, $route)) {
                    return Response::redirect(url('/documents/' . $document['id'], ['via' => 'qr']));
                }
            }
        }

        $throttle = new LoginThrottle();
        $ip = $request->ip();
        if ($throttle->recentCount($ip, 'public_track', self::WINDOW_MINUTES) >= self::LIMIT) {
            $wait = $throttle->minutesUntilReset($ip, 'public_track', self::WINDOW_MINUTES);
            throw new HttpException(429, "Too many tracking lookups from your network. Please try again in $wait minute(s).");
        }
        $throttle->record($ip, 'public_track');

        $info = TrackingNumberService::isValid($trackingNo) ? $documents->publicSummary($trackingNo) : null;

        return $this->view('track/index', [
            'title' => $info === null ? 'Document not found' : $trackingNo,
            'trackingNo' => $trackingNo,
            'info' => $info,
            'searched' => true,
        ], 'layouts/public', $info === null ? 404 : 200);
    }
}
