<?php

declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Models\AuditLog;
use Core\Request;
use Core\Response;

/**
 * Admin: read-only audit log viewer with filters (FR-13). There is deliberately no
 * edit or delete action anywhere in the application.
 */
final class AuditLogController extends Controller
{
    private const FILTER_KEYS = ['action', 'user', 'document', 'ip', 'from', 'to'];

    public function index(Request $request): Response
    {
        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            $value = mb_substr($this->queryString($request, $key), 0, 100);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }

        $logs = new AuditLog();
        $actions = $logs->distinctActions();
        $groups = array_values(array_unique(array_map(static fn (string $a): string => explode('.', $a)[0], $actions)));

        return $this->view('admin/audit-logs/index', [
            'title' => 'Audit logs',
            'result' => $logs->search($filters, $this->page($request)),
            'filters' => $filters,
            'actions' => $actions,
            'groups' => $groups,
        ]);
    }
}
