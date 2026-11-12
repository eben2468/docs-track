<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Attachment;
use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentType;
use App\Policies\DocumentPolicy;
use App\Services\AuditService;
use App\Services\DocumentService;
use App\Services\QrService;
use App\Services\ServiceException;
use App\Services\UploadService;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Document registration, detail, QR label, attachments and cancellation (FR-4, FR-5, §5).
 */
final class DocumentController extends Controller
{
    public function create(Request $request): Response
    {
        return $this->view('documents/create', [
            'title' => 'Register document',
            'types' => (new DocumentType())->where(['is_active' => 1], 'name ASC'),
            'maxUploadMb' => (int) setting('max_upload_mb'),
            'departmentName' => (string) ((new Department())->find((int) $this->user()['department_id'])['name'] ?? ''),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $this->validate($request, [
            'title' => 'required|max:255',
            'document_type_id' => 'required|integer|exists:document_types,id',
            'reference_no' => 'max:100',
            'description' => 'max:5000',
            'priority' => 'required|in:normal,urgent',
            'confidentiality' => 'required|in:normal,confidential',
            'format' => 'required|in:physical,digital,both',
            'pages' => 'integer|min:1|max:9999',
        ], ['document_type_id' => 'Document type', 'reference_no' => 'Reference number', 'pages' => 'Number of pages']);

        try {
            $id = (new DocumentService())->register([
                'title' => (string) $data['title'],
                'document_type_id' => (int) $data['document_type_id'],
                'reference_no' => $data['reference_no'],
                'description' => $data['description'],
                'priority' => (string) $data['priority'],
                'confidentiality' => (string) $data['confidentiality'],
                'format' => (string) $data['format'],
                'pages' => $data['pages'] === null ? null : (int) $data['pages'],
            ], UploadService::normalise($request->file('attachments')), $this->user());
        } catch (ServiceException $e) {
            return $this->failed($request, $e, '/documents/create');
        }

        return Response::redirect("/documents/$id/registered");
    }

    public function registered(Request $request, string $id): Response
    {
        [$document] = $this->viewable((int) $id);

        return $this->view('documents/registered', ['title' => 'Document registered', 'document' => $document]);
    }

    public function show(Request $request, string $id): Response
    {
        [$document, $user] = $this->viewable((int) $id);
        $policy = new DocumentPolicy();

        if ($document['confidentiality'] === 'confidential') {
            (new AuditService())->log('document.viewed', ['tracking_no' => $document['tracking_no']], (int) $document['id']);
        }

        return $this->view('documents/show', [
            'title' => $document['tracking_no'],
            'document' => $document,
            'attachments' => (new Attachment())->forDocument((int) $document['id'], $policy->canManageHiddenAttachments($user)),
            'canEdit' => $policy->canEdit($user, $document),
            'canCancel' => $policy->canCancel($user, $document),
            'canManageHidden' => $policy->canManageHiddenAttachments($user),
            'maxUploadMb' => (int) setting('max_upload_mb'),
        ]);
    }

    public function label(Request $request, string $id): Response
    {
        [$document] = $this->viewable((int) $id);
        $size = $request->query('size') === 'sticker' ? 'sticker' : 'a4';

        return $this->view('documents/label', [
            'title' => 'Label ' . $document['tracking_no'],
            'document' => $document,
            'size' => $size,
            'qr' => (new QrService())->dataUri((string) $document['tracking_no']),
            'trackingUrl' => QrService::trackingUrl((string) $document['tracking_no']),
        ], 'layouts/print');
    }

    public function cancel(Request $request, string $id): Response
    {
        [$document, $user] = $this->viewable((int) $id);

        try {
            (new DocumentService())->cancel((int) $document['id'], $user);
        } catch (ServiceException $e) {
            return $this->failed($request, $e, "/documents/{$document['id']}");
        }

        return Response::redirect("/documents/{$document['id']}")
            ->with('success', "Document {$document['tracking_no']} has been cancelled.");
    }

    public function attachments(Request $request, string $id): Response
    {
        [$document, $user] = $this->viewable((int) $id);

        try {
            $count = (new DocumentService())->addAttachments(
                (int) $document['id'],
                UploadService::normalise($request->file('attachments')),
                $user
            );
        } catch (ServiceException $e) {
            return $this->failed($request, $e, "/documents/{$document['id']}");
        }

        return Response::redirect("/documents/{$document['id']}")->with('success', "$count attachment(s) added.");
    }

    /**
     * Load a document the current user may view: 404 if missing, 403 if not allowed.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [document, user]
     */
    private function viewable(int $id): array
    {
        $document = (new Document())->findDetailed($id) ?? throw new HttpException(404);
        $user = $this->user();
        $route = (new DocumentMovement())->routeDepartmentIds($id);

        if (!(new DocumentPolicy())->canView($user, $document, $route)) {
            throw new HttpException(403, 'This document is not available to your department.');
        }

        return [$document, $user];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        return Auth::user() ?? throw new HttpException(403);
    }
}
