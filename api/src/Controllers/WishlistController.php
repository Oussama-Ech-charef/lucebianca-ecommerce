<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Repositories\ProductRepository;
use App\Repositories\WishlistRepository;

/**
 * WishlistController — authenticated customer wishlist.
 */
final class WishlistController extends Controller
{
    private WishlistRepository $wishlist;
    private ProductRepository $products;

    public function __construct()
    {
        $this->wishlist = new WishlistRepository();
        $this->products = new ProductRepository();
    }

    /**
     * GET /api/wishlist — get user's wishlist.
     */
    public function index(Request $request): never
    {
        $userId = $this->requireAuth($request);
        $items = $this->wishlist->getByUser($userId);

        $this->json(['data' => $items]);
    }

    /**
     * POST /api/wishlist — add product to wishlist.
     *
     * Body: {"product_id": 123}
     */
    public function store(Request $request): never
    {
        $userId = $this->requireAuth($request);
        $productId = (int) $request->input('product_id', 0);

        if ($productId < 1) {
            $this->error('Product ID is required.', 422);
        }

        $product = $this->products->findById($productId);
        if ($product === null) {
            $this->error('Product not found.', 404);
        }

        $added = $this->wishlist->add($userId, $productId);

        $this->json([
            'data' => [
                'added' => $added,
                'message' => $added ? 'Added to wishlist.' : 'Already in wishlist.',
            ],
        ], $added ? 201 : 200);
    }

    /**
     * DELETE /api/wishlist/{productId} — remove product from wishlist.
     */
    public function destroy(Request $request, array $params): never
    {
        $userId = $this->requireAuth($request);
        $productId = (int) ($params['productId'] ?? 0);

        if ($productId < 1) {
            $this->error('Invalid product ID.', 400);
        }

        $this->wishlist->remove($userId, $productId);
        $this->noContent();
    }

    /**
     * Require authentication and return user ID.
     */
    private function requireAuth(Request $request): int
    {
        $token = $request->bearerToken();
        if ($token === null) {
            $this->error('Authentication required.', 401);
        }

        try {
            $claims = Auth::verify($token);
        } catch (\RuntimeException) {
            $this->error('Invalid or expired token.', 401);
        }

        if (($claims['role'] ?? '') !== 'user') {
            $this->error('Only customers can use wishlist.', 403);
        }

        return (int) $claims['sub'];
    }
}
