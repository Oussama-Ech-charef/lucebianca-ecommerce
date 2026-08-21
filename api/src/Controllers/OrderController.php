<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\ConflictException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\OrderService;

/**
 * OrderController — placing and reading orders.
 *
 * Guest checkout supported (orders.user_id is NULL — spec section 4).
 * store() accepts 'cod', 'whatsapp', and 'card' payment methods. Card payments
 * create orders with payment_status='pending' — frontend must initiate payment
 * via POST /api/payments/initiate. show() is public per spec (guest checkout
 * has no auth) — anyone with an order id can read it; a future phase can add
 * a lookup token if tighter privacy is wanted.
 *
 * A logged-in customer's order is attributed to their account: when the
 * request carries a valid customer Bearer token, orders.user_id is set so the
 * order appears on /account. An absent or invalid token degrades gracefully
 * to guest checkout (never blocks placing an order).
 */
final class OrderController extends Controller
{
    private OrderService $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    /**
     * POST /api/orders — place an order (COD / WhatsApp / Card).
     *
     * Body: {"customer_name": "...", "phone": "...", "shipping_address": "...",
     *        "payment_method": "cod"|"whatsapp"|"card",
     *        "items": [{"variant_id": 6, "quantity": 2}]}
     *
     * For card payments: order is created with payment_status='pending'.
     * Frontend must then call POST /api/payments/initiate to redirect to CMI.
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
                is_array($request->input('items')) ? $request->input('items') : [],
                $this->authenticatedCustomerId($request)
            );
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (ConflictException $e) {
            $this->error($e->getMessage(), 409);
        }

        $this->json(['data' => $order->toArray()], 201);
    }

    /**
     * Resolves an optional authenticated customer id from the request.
     *
     * The route is public (guest checkout), so the token is never required:
     * when present AND a valid customer token, its sub becomes the order's
     * user_id; a missing, expired or non-customer token simply yields null
     * (guest order) instead of failing the checkout.
     *
     * @return int|null The customer id, or null for a guest order.
     */
    private function authenticatedCustomerId(Request $request): ?int
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return null;
        }

        try {
            $claims = Auth::verify($token);
        } catch (\RuntimeException) {
            return null;
        }

        if (($claims['role'] ?? '') !== 'user' || !isset($claims['sub'])) {
            return null;
        }

        return (int) $claims['sub'];
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