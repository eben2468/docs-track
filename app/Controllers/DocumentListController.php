<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Department;
use App\Models\Document;
use App\Models\DocumentType;
use App\Services\CsvExport;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Document lists with tabs, filters, sorting, pagination and CSV export (FR-12).
 */
final class DocumentListController extends Controller
{
    private const FILTER_KEYS = ['q', 'type', 'status', 'priority', 'origin', 'current', 'from', 'to', 'flag'];

    public function index(Request $request): Response
    {
        $user = $this->user();
        [$tab, $filters, $sort, $dir] = $this->params($request, $user);
        $documents = new Document();

        return $this->view('documents/index', [
            'title' => self::tabLabel($tab),
            'tab' => $tab,
            'filters' => $filters,
            'sort' => $sort,
            'dir' => $dir,
            'result' => $documents->listing($user, $tab, $filters, $sort, $dir, $this->page($request), (int) setting('unreceived_alert_hours')),
            'counts' => $documents->tabCounts($user),
            'types' => (new DocumentType())->all('name ASC'),
            'departments' => (new Department())->all('name ASC'),
            'isAdmin' => $user['role'] === 'admin',
            'userId' => (int) $user['id'],
        ]);
    }

    public function export(Request $request): Response
    {
        $user = $this->user();
        [$tab, $filters, $sort, $dir] = $this->params($request, $user);
        $rows = (new Document())->listingAll($user, $tab, $filters, $sort, $dir, (int) setting('unreceived_alert_hours'));

        $statusLabels = ['draft' => 'Draft', 'in_transit' => 'In transit', 'received' => 'Received', 'completed' => 'Completed', 'cancelled' => 'Cancelled'];
        $csv = CsvExport::build(
            ['Tracking no', 'Title', 'Type', 'Status', 'Priority', 'Confidentiality', 'Reference no', 'Origin',
             'Current department', 'Held by', 'In transit to', 'Sent at', 'Due date', 'Overdue', 'Registered at', 'Completed at'],
            array_map(static fn (array $r): array => [
                $r['tracking_no'],
                $r['title'],
                $r['type_name'],
                $statusLabels[$r['status']] ?? $r['status'],
                ucfirst((string) $r['priority']),
                ucfirst((string) $r['confidentiality']),
                $r['reference_no'],
                $r['origin_code'],
                $r['current_code'],
                $r['holder_name'],
                $r['om_to_code'],
                $r['om_sent_at'],
                $r['om_due_date'] ?? $r['lr_due_date'],
                (int) $r['is_overdue'] === 1 ? 'Yes' : ((int) $r['is_stuck'] === 1 ? 'Unreceived' : ''),
                $r['created_at'],
                $r['completed_at'],
            ], $rows)
        );

        $filename = sprintf('documents-%s-%s.csv', $tab, now()->format('Ymd-His'));

        return (new Response($csv))
            ->header('Content-Type', 'text/csv; charset=UTF-8')
            ->header('Content-Disposition', 'attachment; filename="' . $filename . '"')
            ->header('Cache-Control', 'private, no-store');
    }

    /**
     * Human-readable tab name.
     */
    public static function tabLabel(string $tab): string
    {
        return match ($tab) {
            'incoming' => 'Incoming',
            'with-us' => 'With Us',
            'outgoing' => 'Outgoing',
            'registered' => 'Registered by Us',
            'completed' => 'Completed',
            'all' => 'All documents',
            default => 'All accessible',
        };
    }

    /**
     * Validated tab, filters and sort from the query string.
     *
     * @param array<string, mixed> $user
     * @return array{0: string, 1: array<string, string>, 2: string, 3: string}
     */
    private function params(Request $request, array $user): array
    {
        $tab = $this->queryString($request, 'tab');
        if (!in_array($tab, Document::TABS, true)) {
            $tab = 'incoming';
        }
        if ($tab === 'all' && $user['role'] !== 'admin') {
            $tab = 'all-accessible';
        }

        $filters = [];
        foreach (self::FILTER_KEYS as $key) {
            $value = mb_substr($this->queryString($request, $key), 0, 100);
            if ($value !== '') {
                $filters[$key] = $value;
            }
        }
        if (isset($filters['flag']) && !in_array($filters['flag'], Document::FLAGS, true)) {
            unset($filters['flag']);
        }

        $sort = $this->queryString($request, 'sort');
        $sort = Document::isSortable($sort) ? $sort : Document::defaultSort($tab);
        $dir = $this->queryString($request, 'dir');
        $dir = in_array($dir, ['asc', 'desc'], true) ? $dir : Document::defaultDirection($tab);

        return [$tab, $filters, $sort, $dir];
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        return Auth::user() ?? throw new HttpException(403);
    }
}
