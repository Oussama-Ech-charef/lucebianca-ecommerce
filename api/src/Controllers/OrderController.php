<?php

namespace App\Controllers;

use App\Core\Request;

/**
 * OrderController — placing and reading orders.
 *
 * Guest checkout supported (user_id NULL). Phase 7 implements store/show,
 * including order confirmation emails and the WhatsApp variant.
 */
final class OrderController extends Controller
{
    /**
     * POST /api/orders — place an order (card / COD / WhatsApp).
     */
    public function store(Request $request): never
    {
        $this->notImplemented('Order placement');
    }

    /**
     * GET /api/orders/{id} — retrieve a single order.
     */
    public function show(Request $request, array $params): never
    {
        $this->notImplemented('Order retrieval');
    }
}