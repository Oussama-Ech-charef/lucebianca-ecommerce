<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\ReviewRepository;

/**
 * ReviewsController — admin review moderation.
 */
final class ReviewsController extends Controller
{
    private ReviewRepository $reviews;

    public function __construct()
    {
        $this->reviews = new ReviewRepository();
    }

    /**
     * GET /api/admin/reviews — list reviews with optional filter.
     */
    public function index(Request $request): never
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));
        $status = $request->query('status');

        $approvalStatus = null;
        if ($status === 'pending') {
            $approvalStatus = 0;
        } elseif ($status === 'approved') {
            $approvalStatus = 1;
        }

        $result = $this->reviews->paginate($page, $perPage, $approvalStatus);

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
     * PUT /api/admin/reviews/{id}/approve — approve a review.
     */
    public function approve(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $this->reviews->updateApproval($id, 1);
        $this->json(['message' => 'Review approved.']);
    }

    /**
     * PUT /api/admin/reviews/{id}/reject — reject a review.
     */
    public function reject(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $this->reviews->updateApproval($id, 0);
        $this->json(['message' => 'Review rejected.']);
    }

    /**
     * DELETE /api/admin/reviews/{id} — delete a review.
     */
    public function destroy(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $this->reviews->delete($id);
        $this->noContent();
    }
}
