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
use App\Services\MovementService;
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
        $movements = new DocumentMovement();
        $docId = (int) $document['id'];
        $open = $movements->openFor($docId);
        $cameFrom = $document['status'] === 'received' ? $movements->lastReceivedFor($docId) : null;

        if ($document['confidentiality'] === 'confidential') {
            (new AuditService())->log('document.viewed', ['tracking_no' => $document['tracking_no']], $docId);
        }

        $canSend = $policy->canSend($user, $document, $open);
        $history = $movements->historyFor($docId);
        $openDetailed = null;
        foreach ($history as $m) {
            if ($m['status'] === 'in_transit') {
                $openDetailed = $m;
                break;
            }
        }

        return $this->view('documents/show', [
            'title' => $document['tracking_no'],
            'document' => $document,
            'attachments' => (new Attachment())->forDocument($docId, $policy->canManageHiddenAttachments($user)),
            'history' => $history,
            'openMovement' => $openDetailed,
            'cameFrom' => $cameFrom,
            'can' => [
                'edit' => $policy->canEdit($user, $document),
                'cancel' => $policy->canCancel($user, $document),
                'send' => $canSend,
                'receive' => $policy->canReceive($user, $document, $open),
                'return' => $policy->canReturn($user, $document, $cameFrom),
                'recall' => $policy->canRecall($user, $document, $open),
                'complete' => $policy->canComplete($user, $document),
                'manageHidden' => $policy->canManageHiddenAttachments($user),
            ],
            'destinations' => $canSend ? array_values(array_filter(
                (new Department())->active(),
                static fn (array $d): bool => (int) $d['id'] !== (int) $document['current_department_id']
            )) : [],
            'defaultDueDate' => $canSend ? (new MovementService())->defaultDueDate((int) $document['document_type_id'])->format('Y-m-d') : null,
            'cameFromDepartment' => $cameFrom === null ? null : (new Department())->find((int) $cameFrom['from_department_id']),
            'maxUploadMb' => (int) setting('max_upload_mb'),
            'viaQr' => $request->query('via') === 'qr',
            'alertHours' => max(1, (int) setting('unreceived_alert_hours')),
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
