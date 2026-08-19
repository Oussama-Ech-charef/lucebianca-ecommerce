<?php

namespace App\Controllers;

use App\Core\ConflictException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\OrderService;

/**
 * OrderController — placing and reading orders.
 *
 * Guest checkout supported (orders.user_id is NULL — spec section 4).
 * store() accepts 'cod' and 'whatsapp' this phase; 'card' is rejected clearly
 * (CMI/Payzone arrives in phase 8). show() is public per spec (guest checkout
 * has no auth) — anyone with an order id can read it; a future phase can add
 * a lookup token if tighter privacy is wanted.
 */
final class OrderController extends Controller
{
    private OrderService $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    /**
     * POST /api/orders — place an order (COD / WhatsApp).
     *
     * Body: {"customer_name": "...", "phone": "...", "shipping_address": "...",
     *        "payment_method": "cod", "items": [{"variant_id": 6, "quantity": 2}]}
     *
     * @return never 201 with the created order (+ items), 422 on invalid
     *               input, 409 on stock conflict (nothing partially created).
     */
    public function store(Request $request): never
    {
        try {
            $order = $this->orders->placeOrder(
                trim((string) $request->input('customer_name', '')),
                trim((string) $request->input('phone', '')),
                trim((string) $request->input('shipping_address', '')),
                (string) $request->input('payment_method', ''),
                is_array($request->input('items')) ? $request->input('items') : []
            );
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (ConflictException $e) {
            $this->error($e->getMessage(), 409);
        }

        $this->json(['data' => $order->toArray()], 201);
    }

    /**
     * GET /api/orders/{id} — retrieve a single order for the confirmation page.
     *
     * @return never 200 with the order (+ items), 404 when it does not exist.
     */
    public function show(Request $request, array $params): never
    {
        $order = $this->orders->getOrder((int) ($params['id'] ?? 0));

        if ($order === null) {
            $this->error('Order not found.', 404);
        }

        $this->json(['data' => $order->toArray()]);
    }
}