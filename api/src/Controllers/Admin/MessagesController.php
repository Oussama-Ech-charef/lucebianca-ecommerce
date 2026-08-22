<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\ContactMessageRepository;

/**
 * MessagesController — admin contact message management.
 */
final class MessagesController extends Controller
{
    private ContactMessageRepository $messages;

    public function __construct()
    {
        $this->messages = new ContactMessageRepository();
    }

    /**
     * GET /api/admin/messages — list messages with optional filter.
     */
    public function index(Request $request): never
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $status = $request->query('status');

        $isRead = null;
        if ($status === 'unread') {
            $isRead = 0;
        } elseif ($status === 'read') {
            $isRead = 1;
        }

        $result = $this->messages->paginate($page, $perPage, $isRead);

        $this->json([
            'data' => $result['items'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * PUT /api/admin/messages/{id}/mark-read — mark message as read.
     */
    public function markRead(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $this->messages->markAsRead($id);
        $this->json(['message' => 'Message marked as read.']);
    }

    /**
     * DELETE /api/admin/messages/{id} — delete a message.
     */
    public function destroy(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $this->messages->delete($id);
        $this->noContent();
    }
}
