<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Policies\DocumentPolicy;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Top-bar quick search (FR-8): an exact tracking number the user may view opens the
 * document; anything else becomes a keyword search over documents the user can access.
 */
final class SearchController extends Controller
{
    public function go(Request $request): Response
    {
        $user = Auth::user() ?? throw new HttpException(403);
        $q = mb_substr($this->queryString($request, 'q'), 0, 100);
        if ($q === '') {
            return Response::redirect('/documents?tab=all-accessible');
        }

        $document = (new Document())->findByTrackingNo($q);
        if ($document !== null) {
            $route = (new DocumentMovement())->routeDepartmentIds((int) $document['id']);
            if ((new DocumentPolicy())->canView($user, $document, $route)) {
                return Response::redirect('/documents/' . $document['id']);
            }
        }

        return Response::redirect(url('/documents', ['tab' => 'all-accessible', 'q' => $q]));
    }
}
