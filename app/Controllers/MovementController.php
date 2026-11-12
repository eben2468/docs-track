<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Document;
use App\Models\DocumentMovement;
use App\Policies\DocumentPolicy;
use App\Services\MovementService;
use App\Services\ServiceException;
use Core\Auth;
use Core\HttpException;
use Core\Request;
use Core\Response;

/**
 * Document movement actions (FR-6, FR-7). All rules live in MovementService; this
 * controller validates input shape, calls the service and reports the outcome.
 */
final class MovementController extends Controller
{
    /**
     * POST /documents/{id}/send: send a draft or forward a received document.
     */
    public function send(Request $request, string $id): Response
    {
        $document = $this->document($id);
        $data = $this->validate($request, [
            'to_department_id' => 'required|integer',
            'to_user_id' => 'integer',
            'note' => 'required|max:500',
            'due_date' => 'date',
        ], ['to_department_id' => 'Destination department', 'to_user_id' => 'Recipient', 'note' => 'Action note', 'due_date' => 'Due date']);

        $input = [
            'to_department_id' => (int) $data['to_department_id'],
            'to_user_id' => $data['to_user_id'] === null ? null : (int) $data['to_user_id'],
            'note' => (string) $data['note'],
            'due_date' => $data['due_date'],
        ];

        $service = new MovementService();
        $forward = $document['status'] === 'received';

        return $this->run($request, $document, fn (array $user) => $forward
            ? $service->forward((int) $document['id'], $input, $user)
            : $service->send((int) $document['id'], $input, $user), $forward ? 'forwarded' : 'sent');
    }

    public function receive(Request $request, string $id): Response
    {
        $document = $this->document($id);
        $data = $this->validate($request, ['receipt_note' => 'max:500', 'method' => 'in:click,qr'], ['receipt_note' => 'Receipt note']);

        return $this->run(
            $request,
            $document,
            fn (array $user) => (new MovementService())->receive((int) $document['id'], $user, $data['receipt_note'], (string) ($data['method'] ?? 'click')),
            'received'
        );
    }

    public function receiveBulk(Request $request): Response
    {
        $ids = $request->input('document_ids');
        $ids = is_array($ids) ? array_values(array_filter($ids, static fn ($v): bool => is_string($v) && ctype_digit($v))) : [];
        if ($ids === []) {
            return $this->back($request)->with('warning', 'Select at least one document to receive.');
        }
        $data = $this->validate($request, ['receipt_note' => 'max:500'], ['receipt_note' => 'Receipt note']);

        $result = (new MovementService())->receiveBulk(array_map('intval', $ids), $this->user(), $data['receipt_note']);

        $response = $this->back($request);
        if ($result['received'] !== []) {
            $response->with('success', count($result['received']) . ' document(s) received: ' . implode(', ', $result['received']) . '.');
        }
        foreach ($result['failed'] as $trackingNo => $reason) {
            $response->with('danger', "$trackingNo: $reason");
        }

        return $response;
    }

    public function returnToSender(Request $request, string $id): Response
    {
        $document = $this->document($id);
        $data = $this->validate($request, ['reason' => 'required|max:500'], ['reason' => 'Reason']);

        return $this->run(
            $request,
            $document,
            fn (array $user) => (new MovementService())->returnToSender((int) $document['id'], (string) $data['reason'], $user),
            'returned'
        );
    }

    public function recall(Request $request, string $id): Response
    {
        $document = $this->document($id);
        $data = $this->validate($request, ['reason' => 'required|max:500'], ['reason' => 'Reason']);

        return $this->run(
            $request,
            $document,
            fn (array $user) => (new MovementService())->recall((int) $document['id'], (string) $data['reason'], $user),
            'recalled'
        );
    }

    public function complete(Request $request, string $id): Response
    {
        $document = $this->document($id);

        return $this->run(
            $request,
            $document,
            fn (array $user) => (new MovementService())->complete((int) $document['id'], $user),
            'marked completed'
        );
    }

    /**
     * Run a service action and redirect to the document with a success or error flash.
     *
     * @param array<string, mixed> $document
     * @param callable(array<string, mixed>): mixed $action
     */
    private function run(Request $request, array $document, callable $action, string $pastTense): Response
    {
        $target = "/documents/{$document['id']}";

        try {
            $action($this->user());
        } catch (ServiceException $e) {
            return $this->failed($request, $e, $target);
        }

        return Response::redirect($target)->with('success', "{$document['tracking_no']} has been $pastTense.");
    }

    /**
     * The document, if the user may view it (404 if missing, 403 if not), so direct POSTs
     * never reveal anything about documents outside the user's route.
     *
     * @return array<string, mixed>
     */
    private function document(string $id): array
    {
        $document = (new Document())->find((int) $id) ?? throw new HttpException(404);
        $route = (new DocumentMovement())->routeDepartmentIds((int) $document['id']);
        if (!(new DocumentPolicy())->canView($this->user(), $document, $route)) {
            throw new HttpException(403);
        }

        return $document;
    }

    /**
     * @return array<string, mixed>
     */
    private function user(): array
    {
        return Auth::user() ?? throw new HttpException(403);
    }
}
