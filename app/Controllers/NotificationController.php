<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Notification;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * In-app notifications (FR-9): list, open (marks read), mark one / all read, and the
 * bell's polling endpoint. Users only ever see and change their own notifications.
 */
final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        $userId = $this->userId();
        $unreadOnly = $request->query('filter') === 'unread';
        $notifications = new Notification();

        return $this->view('notifications/index', [
            'title' => 'Notifications',
            'result' => $notifications->pageFor($userId, $unreadOnly, $this->page($request)),
            'unreadOnly' => $unreadOnly,
            'unreadCount' => $notifications->unreadCount($userId),
        ]);
    }

    /**
     * GET /notifications/{id}/open: mark read and go to the document (or back to the list).
     */
    public function open(Request $request, string $id): Response
    {
        $userId = $this->userId();
        $notifications = new Notification();
        $notification = $notifications->findForUser((int) $id, $userId) ?? throw new HttpException(404);
        $notifications->markRead((int) $notification['id'], $userId);

        return Response::redirect($notification['document_id'] !== null ? '/documents/' . $notification['document_id'] : '/notifications');
    }

    public function read(Request $request, string $id): Response
    {
        $userId = $this->userId();
        $notifications = new Notification();
        $notifications->findForUser((int) $id, $userId) ?? throw new HttpException(404);
        $notifications->markRead((int) $id, $userId);

        return $request->isAjax()
            ? Response::json(['unread' => $notifications->unreadCount($userId)])
            : $this->back($request, '/notifications');
    }

    public function readAll(Request $request): Response
    {
        $userId = $this->userId();
        $count = (new Notification())->markAllRead($userId);

        return $request->isAjax()
            ? Response::json(['unread' => 0, 'marked' => $count])
            : $this->back($request, '/notifications')->with('success', $count > 0 ? "$count notification(s) marked as read." : 'No unread notifications.');
    }

    /**
     * GET /api/notifications/unread-count: polled by the bell every 60 seconds.
     * Also returns the latest five so the dropdown stays current.
     */
    public function unreadCount(Request $request): Response
    {
        $userId = $this->userId();
        $notifications = new Notification();

        $latest = array_map(static fn (array $n): array => [
            'id' => (int) $n['id'],
            'type' => $n['type'],
            'message' => $n['message'],
            'tracking_no' => $n['tracking_no'],
            'is_read' => (int) $n['is_read'] === 1,
            'time' => strip_tags(time_ago((string) $n['created_at'])),
            'url' => url('/notifications/' . $n['id'] . '/open'),
        ], $notifications->latestFor($userId, 5));

        return Response::json(['count' => $notifications->unreadCount($userId), 'latest' => $latest])
            ->header('Cache-Control', 'private, no-store');
    }

    private function userId(): int
    {
        return Auth::id() ?? throw new HttpException(403);
    }
}
