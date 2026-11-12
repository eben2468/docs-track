<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Attachment;
use App\Models\Document;
use App\Models\DocumentType;
use App\Policies\DocumentPolicy;
use Core\Database;
use Throwable;

/**
 * Document registration, attachments and cancellation (FR-4, FR-5, spec §4.2 "Cancel").
 *
 * Every change runs in one transaction, locks the document row (SELECT ... FOR UPDATE),
 * re-checks DocumentPolicy and writes an audit row.
 */
final class DocumentService
{
    private readonly Database $db;

    public function __construct(
        private readonly Document $documents = new Document(),
        private readonly DocumentType $types = new DocumentType(),
        private readonly Attachment $attachments = new Attachment(),
        private readonly UploadService $uploads = new UploadService(),
        private readonly DocumentPolicy $policy = new DocumentPolicy(),
        private readonly AuditService $audit = new AuditService(),
        ?Database $db = null,
    ) {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Register a new document as a draft held by the user's department, with an optional
     * set of attachments. Returns the new document's ID.
     *
     * @param array{title: string, document_type_id: int, reference_no: ?string, description: ?string,
     *              priority: string, confidentiality: string, format: string, pages: ?int} $data Validated input
     * @param list<array{name: string, tmp_name: string, error: int, size: int}> $files
     * @param array<string, mixed> $user The registering user
     * @throws ServiceException on an inactive document type or invalid attachments
     */
    public function register(array $data, array $files, array $user): int
    {
        $type = $this->types->find($data['document_type_id']);
        if ($type === null || (int) $type['is_active'] !== 1) {
            throw new ServiceException('Please choose an active document type.', 'document_type_id');
        }
        $this->assertValidFiles($files);

        $departmentId = (int) $user['department_id'];
        $stored = [];

        try {
            return $this->db->transaction(function () use ($data, $files, $user, $departmentId, &$stored): int {
                $trackingNo = (new TrackingNumberService($this->db))->next($departmentId);

                $id = $this->documents->insert($data + [
                    'tracking_no' => $trackingNo,
                    'origin_department_id' => $departmentId,
                    'current_department_id' => $departmentId,
                    'created_by' => (int) $user['id'],
                    'status' => 'draft',
                ]);

                $this->audit->log('document.created', [
                    'tracking_no' => $trackingNo,
                    'title' => $data['confidentiality'] === 'confidential' ? null : $data['title'],
                    'confidentiality' => $data['confidentiality'],
                ], $id, (int) $user['id']);

                $this->storeAttachments($id, $files, $user, $stored);

                return $id;
            });
        } catch (Throwable $e) {
            $this->removeFiles($stored);
            throw $e;
        }
    }

    /**
     * Add attachments to a document held by the user's department (before completion).
     *
     * @param list<array{name: string, tmp_name: string, error: int, size: int}> $files
     * @param array<string, mixed> $user
     * @return int Number of files attached
     */
    public function addAttachments(int $documentId, array $files, array $user): int
    {
        if ($files === []) {
            throw new ServiceException('Please choose at least one file.', 'attachments');
        }
        $this->assertValidFiles($files);
        $stored = [];

        try {
            $this->db->transaction(function () use ($documentId, $files, $user, &$stored): void {
                $document = $this->documents->lockForUpdate($documentId) ?? throw new ServiceException('Document not found.');
                if (!$this->policy->canEdit($user, $document)) {
                    throw new ServiceException('Only the department holding this document can add attachments, and only before it is completed.');
                }
                $this->storeAttachments($documentId, $files, $user, $stored);
            });
        } catch (Throwable $e) {
            $this->removeFiles($stored);
            throw $e;
        }

        return count($files);
    }

    /**
     * Void a draft registered in error (creator or admin). Spec §4.2: draft → cancelled.
     *
     * @param array<string, mixed> $user
     */
    public function cancel(int $documentId, array $user): void
    {
        $this->db->transaction(function () use ($documentId, $user): void {
            $document = $this->documents->lockForUpdate($documentId) ?? throw new ServiceException('Document not found.');

            if ($document['status'] !== 'draft') {
                throw new ServiceException('Only a draft that has never been sent can be cancelled.');
            }
            if (!$this->policy->canCancel($user, $document)) {
                throw new ServiceException('Only the person who registered this document, or an administrator, can cancel it.');
            }

            $this->documents->update($documentId, ['status' => 'cancelled']);
            $this->audit->log('document.cancelled', ['tracking_no' => $document['tracking_no']], $documentId, (int) $user['id']);
        });
    }

    /**
     * Hide or unhide an attachment (admin only). Files are never deleted.
     *
     * @param array<string, mixed> $user
     */
    public function setAttachmentHidden(int $attachmentId, bool $hidden, array $user): void
    {
        if (!$this->policy->canManageHiddenAttachments($user)) {
            throw new ServiceException('Only administrators can hide attachments.');
        }

        $this->db->transaction(function () use ($attachmentId, $hidden, $user): void {
            $attachment = $this->attachments->find($attachmentId) ?? throw new ServiceException('Attachment not found.');
            if ((bool) $attachment['is_hidden'] === $hidden) {
                return;
            }
            $this->attachments->update($attachmentId, ['is_hidden' => $hidden ? 1 : 0]);
            $this->audit->log($hidden ? 'attachment.hidden' : 'attachment.unhidden', [
                'attachment_id' => $attachmentId,
                'name' => $attachment['original_name'],
            ], (int) $attachment['document_id'], (int) $user['id']);
        });
    }

    /**
     * @param list<array{name: string, tmp_name: string, error: int, size: int}> $files
     */
    private function assertValidFiles(array $files): void
    {
        $errors = $this->uploads->validate($files, max(1, (int) setting('max_upload_mb')));
        if ($errors !== []) {
            throw new ServiceException(implode(' ', $errors), 'attachments');
        }
    }

    /**
     * Move files into storage and record them. Paths are appended to $stored as soon as
     * each file is moved, so the caller can delete them if the transaction fails.
     *
     * @param list<array{name: string, tmp_name: string, error: int, size: int}> $files
     * @param array<string, mixed> $user
     * @param list<string> $stored
     */
    private function storeAttachments(int $documentId, array $files, array $user, array &$stored): void
    {
        foreach ($files as $file) {
            $saved = $this->uploads->store($file);
            $stored[] = $saved['stored_path'];

            $attachmentId = $this->attachments->insert($saved + [
                'document_id' => $documentId,
                'uploaded_by' => (int) $user['id'],
            ]);
            $this->audit->log('attachment.uploaded', [
                'attachment_id' => $attachmentId,
                'name' => $saved['original_name'],
                'size_bytes' => $saved['size_bytes'],
            ], $documentId, (int) $user['id']);
        }
    }

    /**
     * @param list<string> $storedPaths
     */
    private function removeFiles(array $storedPaths): void
    {
        foreach ($storedPaths as $path) {
            $this->uploads->delete($path);
        }
    }
}
