<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;

/**
 * OrdersController — admin order management (protected by JWT admin role).
 *
 * Phase 9 implements listing + status changes.
 */
final class OrdersController extends Controller
{
    /**
     * GET /api/admin/orders — all orders with pagination and status filter.
     */
    public function index(Request $request): never
    {
        $this->notImplemented('Admin order list');
    }

    /**
     * PUT /api/admin/orders/{id} — change order status.
     */
    public function update(Request $request, array $params): never
    {
        $this->notImplemented('Admin order update');
    }
}