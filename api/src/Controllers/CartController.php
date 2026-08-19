<?php

namespace App\Controllers;

use App\Core\Request;
use App\Core\ValidationException;
use App\Services\OrderService;

/**
 * CartController — POST /api/cart.
 *
 * The cart itself is client-side (React Context + localStorage); this
 * endpoint does NOT persist a server session. It validates a submitted cart
 * against live variant data — authoritative prices and remaining stock — so
 * the frontend can catch "price changed" / "someone else bought the last one"
 * cases between add-to-cart and checkout. Each line gets its own status.
 */
final class CartController extends Controller
{
    private OrderService $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    /**
     * POST /api/cart — validate cart contents before checkout.
     *
     * Body: {"items": [{"variant_id": 6, "quantity": 2, "unit_price": "39.99"}, ...]}
     * unit_price is optional and only used to flag price changes.
     *
     * Returns 200 with a per-line report + overall "valid" flag, so the
     * frontend can show exactly which lines need adjustment.
     */
    public function store(Request $request): never
    {
        $lines = $request->input('items');

        if (!is_array($lines) || $lines === []) {
            $this->error('Validation failed.', 422, ['errors' => ['items' => 'At least one cart line is required.']]);
        }

        try {
            $result = $this->orders->validateCart($lines);
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        }

        $this->json(['data' => $result]);
    }
}