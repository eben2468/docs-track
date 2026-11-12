<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Request;
use Core\Response;

/**
 * Camera QR scanner page (spec §5.2). Decoding happens in the browser; the scanned
 * label URL (/track/{no}) then routes the user to the document.
 */
final class ScanController extends Controller
{
    public function index(Request $request): Response
    {
        return $this->view('scan/index', ['title' => 'Scan QR label']);
    }
}
