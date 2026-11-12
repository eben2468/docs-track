<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Attachment;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Policies\DocumentPolicy;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\ServiceException;
use App\Services\UploadService;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Attachment downloads (only through this controller, after a permission check) and
 * admin hide/unhide. Files live in storage/uploads, outside the web root.
 */
final class AttachmentController extends Controller
{
    public function download(Request $request, string $id): Response
    {
        $user = Auth::user() ?? throw new HttpException(403);
        $attachment = (new Attachment())->find((int) $id) ?? throw new HttpException(404);
        $document = (new Document())->find((int) $attachment['document_id']) ?? throw new HttpException(404);
        $policy = new DocumentPolicy();

        $route = (new DocumentMovement())->routeDepartmentIds((int) $document['id']);
        if (!$policy->canView($user, $document, $route)) {
            throw new HttpException(403);
        }
        if ((int) $attachment['is_hidden'] === 1 && !$policy->canManageHiddenAttachments($user)) {
            throw new HttpException(404);
        }

        $path = UploadService::absolutePath((string) $attachment['stored_path']);
        if ($path === null || !is_file($path)) {
            throw new HttpException(404, 'The file could not be found on the server.');
        }

        (new AuditService())->log('attachment.downloaded', [
            'attachment_id' => (int) $attachment['id'],
            'name' => $attachment['original_name'],
        ], (int) $document['id']);

        return Response::file($path, (string) $attachment['original_name'], (string) $attachment['mime_type']);
    }

    public function visibility(Request $request, string $id): Response
    {
        $user = Auth::user() ?? throw new HttpException(403);
        $attachment = (new Attachment())->find((int) $id) ?? throw new HttpException(404);
        $hidden = $request->input('hidden') === '1';

        try {
            (new DocumentService())->setAttachmentHidden((int) $attachment['id'], $hidden, $user);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, "/documents/{$attachment['document_id']}");
        }

        return Response::redirect("/documents/{$attachment['document_id']}")
            ->with('success', "\"{$attachment['original_name']}\" " . ($hidden ? 'hidden.' : 'is visible again.'));
    }
}
