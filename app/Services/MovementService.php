<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentMovement;
use App\Models\DocumentType;
use App\Models\User;
use App\Policies\DocumentPolicy;
use Core\Database;
use DateTimeImmutable;

/**
 * The movement engine (spec §4, FR-6, FR-7).
 *
 * Every public method:
 *  1. runs in one transaction and locks the document row (SELECT ... FOR UPDATE), then
 *     the open movement — always in that order, so concurrent requests cannot deadlock;
 *  2. re-checks the status after locking;
 *  3. enforces the §4.2 transition table and DocumentPolicy;
 *  4. enforces the invariants (one open movement, no self-sends, holder-only actions,
 *     destination-only receipt, recall before receipt, terminal states read-only);
 *  5. updates the document and movement, writes an audit row and creates notifications.
 * Violations throw ServiceException (a DomainException) with a user-friendly message.
 */
final class MovementService
{
    public const RECEIVE_METHODS = ['click', 'qr', 'bulk'];

    private readonly Database $db;

    public function __construct(
        private readonly Document $documents = new Document(),
        private readonly DocumentMovement $movements = new DocumentMovement(),
        private readonly Department $departments = new Department(),
        private readonly User $users = new User(),
        private readonly DocumentType $types = new DocumentType(),
        private readonly DocumentPolicy $policy = new DocumentPolicy(),
        private readonly WorkingDayService $workingDays = new WorkingDayService(),
        private readonly NotificationService $notifications = new NotificationService(),
        private readonly AuditService $audit = new AuditService(),
        ?Database $db = null,
    ) {
        $this->db = $db ?? Database::instance();
    }

    /**
     * Send a draft to another department (draft → in_transit). Returns the movement ID.
     *
     * @param array{to_department_id: int, to_user_id?: ?int, note: string, due_date?: ?string} $input
     * @param array<string, mixed> $user
     */
    public function send(int $documentId, array $input, array $user): int
    {
        return $this->dispatch($documentId, 'send', 'draft', $input, $user);
    }

    /**
     * Forward a received document to another department (received → in_transit).
     *
     * @param array{to_department_id: int, to_user_id?: ?int, note: string, due_date?: ?string} $input
     * @param array<string, mixed> $user
     */
    public function forward(int $documentId, array $input, array $user): int
    {
        return $this->dispatch($documentId, 'forward', 'received', $input, $user);
    }

    /**
     * Return a received document to the department it came from (received → in_transit).
     * The person who sent it becomes the specific recipient, if still active.
     *
     * @param array<string, mixed> $user
     */
    public function returnToSender(int $documentId, string $reason, array $user): int
    {
        $reason = $this->requireText($reason, 'A reason for returning the document is required.', 'reason');

        return $this->db->transaction(function () use ($documentId, $reason, $user): int {
            $document = $this->lockDocument($documentId);
            $this->assertNotTerminal($document);
            if ($document['status'] !== 'received') {
                throw new ServiceException('Only a document that your department has received can be returned.');
            }

            $cameFrom = $this->movements->lastReceivedFor($documentId);
            if (!$this->policy->canReturn($user, $document, $cameFrom)) {
                throw new ServiceException($this->isHolder($user, $document)
                    ? 'This document did not come from another department, so there is nowhere to return it.'
                    : 'Only the department currently holding this document can return it.');
            }

            $toDepartment = (int) $cameFrom['from_department_id'];
            $sender = $this->users->find((int) $cameFrom['from_user_id']);
            $toUser = ($sender !== null && (int) $sender['is_active'] === 1 && (int) $sender['department_id'] === $toDepartment)
                ? (int) $sender['id'] : null;

            return $this->createMovement($document, 'return', $toDepartment, $toUser, $reason, null, $user);
        });
    }

    /**
     * Acknowledge receipt (in_transit → received). The receiving department becomes the
     * holder and the receiving user the current holder.
     *
     * @param string $method click | qr | bulk
     * @param array<string, mixed> $user
     */
    public function receive(int $documentId, array $user, ?string $receiptNote = null, string $method = 'click'): void
    {
        if (!in_array($method, self::RECEIVE_METHODS, true)) {
            $method = 'click';
        }
        $receiptNote = $this->optionalText($receiptNote);

        $this->db->transaction(function () use ($documentId, $user, $receiptNote, $method): void {
            $document = $this->lockDocument($documentId);
            $this->assertNotTerminal($document);
            if ($document['status'] !== 'in_transit') {
                throw new ServiceException("{$document['tracking_no']} is not waiting to be received" . ($document['status'] === 'received' ? ' (it has already been received).' : '.'));
            }

            $movement = $this->movements->lockOpenFor($documentId);
            if (!$this->policy->canReceive($user, $document, $movement)) {
                throw new ServiceException("{$document['tracking_no']} was not sent to your department, so you cannot receive it.");
            }

            $now = $this->now();
            $this->movements->update((int) $movement['id'], [
                'status' => 'received',
                'received_by' => (int) $user['id'],
                'received_at' => $now,
                'receive_method' => $method,
                'receipt_note' => $receiptNote,
            ]);
            $this->documents->update($documentId, [
                'status' => 'received',
                'current_department_id' => (int) $movement['to_department_id'],
                'current_holder_id' => (int) $user['id'],
            ]);

            $this->audit->log('document.received', [
                'tracking_no' => $document['tracking_no'],
                'movement_id' => (int) $movement['id'],
                'department' => $this->departmentCode((int) $movement['to_department_id']),
                'method' => $method,
                'receipt_note' => $receiptNote,
            ], $documentId, (int) $user['id']);

            $this->notifications->notify(
                (int) $movement['from_user_id'],
                $documentId,
                'received',
                sprintf('%s was received by %s (%s).', $this->label($document), $user['full_name'], $this->departmentName((int) $movement['to_department_id'])),
                (int) $user['id']
            );
        });
    }

    /**
     * Receive several documents at once (receive_method "bulk"). Each document is handled
     * in its own transaction, so one failure does not block the others.
     *
     * @param list<int> $documentIds
     * @param array<string, mixed> $user
     * @return array{received: list<string>, failed: array<string, string>} Tracking numbers received;
     *         failures keyed by tracking number (or "#id") with the reason
     */
    public function receiveBulk(array $documentIds, array $user, ?string $receiptNote = null): array
    {
        $result = ['received' => [], 'failed' => []];

        foreach (array_values(array_unique(array_map('intval', $documentIds))) as $id) {
            $trackingNo = (string) ($this->documents->find($id)['tracking_no'] ?? "#$id");
            try {
                $this->receive($id, $user, $receiptNote, 'bulk');
                $result['received'][] = $trackingNo;
            } catch (ServiceException $e) {
                $result['failed'][$trackingNo] = $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * Cancel a send that has not been received (in_transit → previous state at the sender:
     * draft if it was never received anywhere, otherwise received).
     *
     * @param array<string, mixed> $user
     */
    public function recall(int $documentId, string $reason, array $user): void
    {
        $reason = $this->requireText($reason, 'A reason for recalling the document is required.', 'reason');

        $this->db->transaction(function () use ($documentId, $reason, $user): void {
            $document = $this->lockDocument($documentId);
            $this->assertNotTerminal($document);
            if ($document['status'] !== 'in_transit') {
                throw new ServiceException($document['status'] === 'received'
                    ? 'This document has already been received, so it can no longer be recalled.'
                    : 'Only a document that is in transit can be recalled.');
            }

            $movement = $this->movements->lockOpenFor($documentId);
            if (!$this->policy->canRecall($user, $document, $movement)) {
                throw new ServiceException('Only the person who sent this document, the HOD of the sending department, or an administrator can recall it.');
            }

            $restoredStatus = $this->movements->lastReceivedFor($documentId) === null ? 'draft' : 'received';
            $this->movements->update((int) $movement['id'], [
                'status' => 'recalled',
                'recalled_by' => (int) $user['id'],
                'recalled_at' => $this->now(),
                'recall_reason' => $reason,
            ]);
            $this->documents->update($documentId, ['status' => $restoredStatus]);

            $this->audit->log('document.recalled', [
                'tracking_no' => $document['tracking_no'],
                'movement_id' => (int) $movement['id'],
                'to_department' => $this->departmentCode((int) $movement['to_department_id']),
                'reason' => $reason,
                'restored_status' => $restoredStatus,
            ], $documentId, (int) $user['id']);

            $this->notifications->notifyDepartment(
                (int) $movement['to_department_id'],
                $documentId,
                'recalled',
                sprintf('%s was recalled by %s and will not arrive. Reason: %s', $this->label($document), $user['full_name'], $reason),
                (int) $user['id']
            );
        });
    }

    /**
     * Mark a received document as completed (received → completed). Completed documents are read-only.
     *
     * @param array<string, mixed> $user
     */
    public function complete(int $documentId, array $user): void
    {
        $this->db->transaction(function () use ($documentId, $user): void {
            $document = $this->lockDocument($documentId);
            $this->assertNotTerminal($document);
            if ($document['status'] !== 'received') {
                throw new ServiceException('Only a document that has been received can be marked completed.');
            }
            if (!$this->policy->canComplete($user, $document)) {
                throw new ServiceException('Only the department currently holding this document can mark it completed.');
            }

            $this->documents->update($documentId, [
                'status' => 'completed',
                'completed_at' => $this->now(),
                'completed_by' => (int) $user['id'],
            ]);

            $this->audit->log('document.completed', [
                'tracking_no' => $document['tracking_no'],
                'department' => $this->departmentCode((int) $document['current_department_id']),
            ], $documentId, (int) $user['id']);

            $this->notifications->notify(
                (int) $document['created_by'],
                $documentId,
                'completed',
                sprintf('%s was marked completed by %s (%s).', $this->label($document), $user['full_name'], $this->departmentName((int) $document['current_department_id'])),
                (int) $user['id']
            );
        });
    }

    /**
     * The due date a send would get by default: today plus the document type's SLA
     * (or the default SLA) in working days.
     */
    public function defaultDueDate(int $documentTypeId): DateTimeImmutable
    {
        $type = $this->types->find($documentTypeId);
        $sla = (int) ($type['sla_days'] ?? 0);
        if ($sla < 1) {
            $sla = max(1, (int) setting('default_sla_days'));
        }

        return $this->workingDays->addWorkingDays(now(), $sla);
    }

    /**
     * Shared body of send() and forward().
     *
     * @param array{to_department_id: int, to_user_id?: ?int, note: string, due_date?: ?string} $input
     * @param array<string, mixed> $user
     */
    private function dispatch(int $documentId, string $action, string $requiredStatus, array $input, array $user): int
    {
        $note = $this->requireText((string) ($input['note'] ?? ''), 'An action note is required (e.g. "For your approval").', 'note');

        return $this->db->transaction(function () use ($documentId, $action, $requiredStatus, $input, $user, $note): int {
            $document = $this->lockDocument($documentId);
            $this->assertNotTerminal($document);

            if ($document['status'] !== $requiredStatus) {
                throw new ServiceException(match (true) {
                    $document['status'] === 'in_transit' => 'This document is already in transit. It must be received or recalled before it can be sent again.',
                    $action === 'send' => 'Only a draft can be sent. Use Forward for a document your department has received.',
                    default => 'Only a document your department has received can be forwarded.',
                });
            }
            if ($this->movements->openFor($documentId) !== null) {
                throw new ServiceException('This document already has a movement waiting for receipt.');
            }
            if (!$this->policy->canSend($user, $document, null)) {
                throw new ServiceException('Only the department currently holding this document can ' . ($action === 'send' ? 'send' : 'forward') . ' it.');
            }

            return $this->createMovement(
                $document,
                $action,
                (int) $input['to_department_id'],
                isset($input['to_user_id']) && $input['to_user_id'] !== null ? (int) $input['to_user_id'] : null,
                $note,
                $input['due_date'] ?? null,
                $user
            );
        });
    }

    /**
     * Validate the destination, insert the movement, put the document in transit,
     * then audit and notify. The document row must already be locked.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $user
     */
    private function createMovement(array $document, string $action, int $toDepartmentId, ?int $toUserId, string $note, ?string $dueDate, array $user): int
    {
        $fromDepartmentId = (int) $document['current_department_id'];
        if ($toDepartmentId === $fromDepartmentId) {
            throw new ServiceException('A document cannot be sent to the department that already holds it.', 'to_department_id');
        }

        $toDepartment = $this->departments->find($toDepartmentId);
        if ($toDepartment === null || (int) $toDepartment['is_active'] !== 1) {
            throw new ServiceException('Please choose an active destination department.', 'to_department_id');
        }

        if ($toUserId !== null) {
            $recipient = $this->users->find($toUserId);
            if ($recipient === null || (int) $recipient['is_active'] !== 1 || (int) $recipient['department_id'] !== $toDepartmentId) {
                throw new ServiceException('The recipient must be an active member of the destination department.', 'to_user_id');
            }
        }

        $due = $this->resolveDueDate($dueDate, (int) $document['document_type_id']);

        $movementId = $this->movements->insert([
            'document_id' => (int) $document['id'],
            'action' => $action,
            'from_department_id' => $fromDepartmentId,
            'from_user_id' => (int) $user['id'],
            'to_department_id' => $toDepartmentId,
            'to_user_id' => $toUserId,
            'note' => $note,
            'due_date' => $due,
            'status' => 'in_transit',
            'sent_at' => $this->now(),
        ]);
        $this->documents->update((int) $document['id'], ['status' => 'in_transit']);

        $verb = ['send' => 'sent', 'forward' => 'forwarded', 'return' => 'returned'][$action];
        $this->audit->log('document.' . $verb, [
            'tracking_no' => $document['tracking_no'],
            'movement_id' => $movementId,
            'from_department' => $this->departmentCode($fromDepartmentId),
            'to_department' => $toDepartment['code'],
            'to_user_id' => $toUserId,
            'due_date' => $due,
            'note' => $note,
        ], (int) $document['id'], (int) $user['id']);

        $message = sprintf(
            '%s was %s to %s by %s (%s). %s: %s',
            $this->label($document),
            $verb,
            $toUserId !== null ? 'you' : 'your department',
            $user['full_name'],
            $this->departmentName($fromDepartmentId),
            $action === 'return' ? 'Reason' : 'Note',
            $note
        );
        $type = $action === 'return' ? 'returned' : 'incoming';
        if ($toUserId !== null) {
            $this->notifications->notify($toUserId, (int) $document['id'], $type, $message, (int) $user['id']);
        } else {
            $this->notifications->notifyDepartment($toDepartmentId, (int) $document['id'], $type, $message, (int) $user['id']);
        }

        return $movementId;
    }

    /**
     * A user-supplied due date (must be today or later), or the SLA-based default.
     */
    private function resolveDueDate(?string $dueDate, int $documentTypeId): string
    {
        if ($dueDate === null || trim($dueDate) === '') {
            return $this->defaultDueDate($documentTypeId)->format('Y-m-d');
        }

        $date = DateTimeImmutable::createFromFormat('!Y-m-d', trim($dueDate));
        if ($date === false || $date->format('Y-m-d') !== trim($dueDate)) {
            throw new ServiceException('The due date is not a valid date.', 'due_date');
        }
        if ($date->format('Y-m-d') < now()->format('Y-m-d')) {
            throw new ServiceException('The due date cannot be in the past.', 'due_date');
        }

        return $date->format('Y-m-d');
    }

    /**
     * @return array<string, mixed>
     */
    private function lockDocument(int $documentId): array
    {
        return $this->documents->lockForUpdate($documentId) ?? throw new ServiceException('Document not found.');
    }

    /**
     * @param array<string, mixed> $document
     */
    private function assertNotTerminal(array $document): void
    {
        if ($document['status'] === 'completed' || $document['status'] === 'cancelled') {
            throw new ServiceException("{$document['tracking_no']} is {$document['status']} and can no longer be changed.");
        }
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     */
    private function isHolder(array $user, array $document): bool
    {
        return (int) $user['department_id'] === (int) $document['current_department_id'];
    }

    private function requireText(string $text, string $message, string $field): string
    {
        $text = trim($text);
        if ($text === '') {
            throw new ServiceException($message, $field);
        }
        if (mb_strlen($text) > 500) {
            throw new ServiceException('Please keep it to 500 characters or fewer.', $field);
        }

        return $text;
    }

    private function optionalText(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : mb_substr($text, 0, 500);
    }

    /**
     * Tracking number plus title, or just the tracking number for confidential documents.
     *
     * @param array<string, mixed> $document
     */
    private function label(array $document): string
    {
        return $document['confidentiality'] === 'confidential'
            ? (string) $document['tracking_no']
            : sprintf('%s "%s"', $document['tracking_no'], mb_strimwidth((string) $document['title'], 0, 80, '…'));
    }

    private function departmentName(int $id): string
    {
        return (string) ($this->departments->find($id)['name'] ?? '');
    }

    private function departmentCode(int $id): string
    {
        return (string) ($this->departments->find($id)['code'] ?? '');
    }

    private function now(): string
    {
        return now()->format('Y-m-d H:i:s');
    }
}
