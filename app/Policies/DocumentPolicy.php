<?php

declare(strict_types=1);

namespace App\Policies;

/**
 * Who may do what with a document (spec §3 and §4.2).
 *
 * Pure rules with no database access: callers pass the user row, the document row and,
 * where needed, the open movement, the last received movement or the route.
 * Every service re-checks these on the server; hiding a button is never enough.
 *
 * Document actions are department-scoped: any active user in the holder department may
 * act. Administrators can view everything, recall and cancel, but act on a document's
 * contents only when it is in their own department.
 */
final class DocumentPolicy
{
    /**
     * View details. Admins see everything. Everyone else sees documents whose route
     * includes their department: origin, current holder, or any movement from/to it.
     * The same rule applies to confidential documents (spec §3); confidentiality also
     * hides the title on labels and the public tracking page.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     * @param list<int> $routeDepartmentIds Departments the document was sent from or to
     */
    public function canView(array $user, array $document, array $routeDepartmentIds): bool
    {
        if (!$this->isActive($user)) {
            return false;
        }
        if ($user['role'] === 'admin') {
            return true;
        }

        $dept = (int) $user['department_id'];

        return $dept === (int) $document['origin_department_id']
            || $dept === (int) $document['current_department_id']
            || in_array($dept, array_map('intval', $routeDepartmentIds), true);
    }

    /**
     * Add attachments: holder department, while the document is held (draft or received).
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     */
    public function canEdit(array $user, array $document): bool
    {
        return $this->isHolder($user, $document) && in_array($document['status'], ['draft', 'received'], true);
    }

    /**
     * Send (from draft) or forward (from received): holder department, nothing in transit.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $openMovement
     */
    public function canSend(array $user, array $document, ?array $openMovement): bool
    {
        return $openMovement === null && $this->canEdit($user, $document);
    }

    /**
     * Receive: only users of the open movement's destination department.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $openMovement
     */
    public function canReceive(array $user, array $document, ?array $openMovement): bool
    {
        return $this->isActive($user)
            && $document['status'] === 'in_transit'
            && $openMovement !== null
            && $openMovement['status'] === 'in_transit'
            && (int) $openMovement['document_id'] === (int) $document['id']
            && (int) $user['department_id'] === (int) $openMovement['to_department_id'];
    }

    /**
     * Return to the department it came from: holder department, document received via a movement.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $lastReceived The movement that brought it here
     */
    public function canReturn(array $user, array $document, ?array $lastReceived): bool
    {
        return $lastReceived !== null
            && $document['status'] === 'received'
            && $this->isHolder($user, $document)
            && (int) $lastReceived['to_department_id'] === (int) $document['current_department_id'];
    }

    /**
     * Recall an unreceived send: the user who sent it, an HOD of the sending department, or an admin.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     * @param array<string, mixed>|null $openMovement
     */
    public function canRecall(array $user, array $document, ?array $openMovement): bool
    {
        if (!$this->isActive($user) || $document['status'] !== 'in_transit'
            || $openMovement === null || $openMovement['status'] !== 'in_transit') {
            return false;
        }

        return $user['role'] === 'admin'
            || (int) $openMovement['from_user_id'] === (int) $user['id']
            || ($user['role'] === 'hod' && (int) $user['department_id'] === (int) $openMovement['from_department_id']);
    }

    /**
     * Mark completed: holder department, document received.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     */
    public function canComplete(array $user, array $document): bool
    {
        return $document['status'] === 'received' && $this->isHolder($user, $document);
    }

    /**
     * Cancel a draft registered in error: its creator or an admin.
     *
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     */
    public function canCancel(array $user, array $document): bool
    {
        return $this->isActive($user)
            && $document['status'] === 'draft'
            && ($user['role'] === 'admin' || (int) $document['created_by'] === (int) $user['id']);
    }

    /**
     * See and hide/unhide attachments that an admin has hidden.
     *
     * @param array<string, mixed> $user
     */
    public function canManageHiddenAttachments(array $user): bool
    {
        return $this->isActive($user) && $user['role'] === 'admin';
    }

    /**
     * @param array<string, mixed> $user
     * @param array<string, mixed> $document
     */
    private function isHolder(array $user, array $document): bool
    {
        return $this->isActive($user) && (int) $user['department_id'] === (int) $document['current_department_id'];
    }

    /**
     * @param array<string, mixed> $user
     */
    private function isActive(array $user): bool
    {
        return (int) ($user['is_active'] ?? 0) === 1;
    }
}
