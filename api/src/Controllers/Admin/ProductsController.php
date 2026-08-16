<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;

/**
 * ProductsController — admin product management (protected by JWT admin role).
 *
 * Phase 4 implements full CRUD + multi-image upload (Cloudinary).
 * The destroy() action must refuse hard-deletion when any variant of the
 * product appears in order_items (see note at the end of spec section 4):
 * such products can only be deactivated via is_active.
 */
final class ProductsController extends Controller
{
    /**
     * GET /api/admin/products — all products (active and paused) with pagination.
     */
    public function index(Request $request): never
    {
        $this->notImplemented('Admin product list');
    }

    /**
     * POST /api/admin/products — create a product.
     */
    public function store(Request $request): never
    {
        $this->notImplemented('Admin product create');
    }

    /**
     * PUT /api/admin/products/{id} — update a product.
     */
    public function update(Request $request, array $params): never
    {
        $this->notImplemented('Admin product update');
    }

    /**
     * DELETE /api/admin/products/{id} — hard-delete (blocked if ordered).
     */
    public function destroy(Request $request, array $params): never
    {
        $this->notImplemented('Admin product delete');
    }

    /**
     * POST /api/admin/products/{id}/images — upload multiple images to Cloudinary.
     */
    public function uploadImages(Request $request, array $params): never
    {
        $this->notImplemented('Admin image upload');
    }
}