<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\DocumentType;
use Core\Database;

/**
 * Document type management (FR-3). Types are deactivated, never deleted.
 */
final class DocumentTypeService
{
    public function __construct(
        private readonly DocumentType $types = new DocumentType(),
        private readonly AuditService $audit = new AuditService(),
    ) {
    }

    /**
     * @param array{name: string, sla_days: int} $data
     */
    public function create(array $data): int
    {
        return Database::instance()->transaction(function () use ($data): int {
            $id = $this->types->insert($data);
            $this->audit->log('document_type.created', ['document_type_id' => $id] + $data);

            return $id;
        });
    }

    /**
     * @param array{name: string, sla_days: int} $data
     */
    public function update(int $id, array $data): void
    {
        Database::instance()->transaction(function () use ($id, $data): void {
            $before = $this->types->find($id) ?? throw new ServiceException('Document type not found.');
            $changes = AuditService::diff($before, $data);
            if ($changes === []) {
                return;
            }
            $this->types->update($id, $data);
            $this->audit->log('document_type.updated', ['document_type_id' => $id, 'changes' => $changes]);
        });
    }

    public function setActive(int $id, bool $active): void
    {
        Database::instance()->transaction(function () use ($id, $active): void {
            $type = $this->types->find($id) ?? throw new ServiceException('Document type not found.');
            if ((bool) $type['is_active'] === $active) {
                return;
            }
            $this->types->update($id, ['is_active' => $active ? 1 : 0]);
            $this->audit->log($active ? 'document_type.activated' : 'document_type.deactivated', [
                'document_type_id' => $id,
                'name' => $type['name'],
            ]);
        });
    }
}
