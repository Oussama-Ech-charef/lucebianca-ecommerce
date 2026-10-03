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
     * Body: {"customer_name": "...", "phone": "...", "email": "...", "shipping_address": "...",
     *        "city": "...", "postal_code": "...",
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
                trim((string) $request->input('email', '')),
                trim((string) $request->input('shipping_address', '')),
                trim((string) $request->input('city', '')),
                trim((string) $request->input('postal_code', '')),
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
     * Privacy: guest orders require `?token=<lookup_token>` (single-purpose,
     * non-guessable, returned once at creation). Owned orders (user_id NOT NULL)
     * require either matching ownership (Bearer user JWT) or the same token as
     * fallback. Returns 404 on failure to hide existence (no enumeration).
     *
     * @return never 200 with the order (+ items), 404 when not found or not authorized.
     */
    public function show(Request $request, array $params): never
    {
        $orderId = (int) ($params['id'] ?? 0);
        if ($orderId < 1) {
            $this->error('Order not found.', 404);
        }

        $providedToken = $this->extractLookupToken($request);
        $authenticatedUserId = $this->authenticatedCustomerId($request);

        // Use authorized lookup to hide existence on failure.
        $order = $this->orders->getOrderAuthorized($orderId, $authenticatedUserId, $providedToken);

        if ($order === null) {
            $this->error('Order not found.', 404);
        }

        // Strip lookup_token from guest responses unless the caller
        // presented the token (they already have it). Keep it in the
        // model for internal use but don't echo it unnecessarily.
        $data = $order->toArray();
        if ($providedToken === null || $providedToken === '') {
            unset($data['lookup_token']);
        }

        $this->json(['data' => $data]);
    }

    /**
     * POST /api/orders/{id}/cancel — customer/guest cancellation with stock restore.
     *
     * Authorized the same way as show() (ownership or lookup token, 404 to
     * hide existence). Allowed only from pending/processing on UNPAID orders;
     * shipped/delivered/cancelled → 409, paid → 409 (refund first — see
     * OrderService::cancelOrder). Restores reserved stock exactly once
     * (inventory policy). Cancellation is terminal (status=cancelled).
     *
     * @return never 200 with the cancelled order, 404 when not found/unauthorized, 409 when not cancellable.
     */
    public function cancel(Request $request, array $params): never
    {
        $orderId = (int) ($params['id'] ?? 0);
        if ($orderId < 1) {
            $this->error('Order not found.', 404);
        }
        $providedToken = $this->extractLookupToken($request);
        // Body lookup_token for POST cancel.
        if ($providedToken === null) {
            $bodyToken = $request->input('lookup_token');
            if (is_string($bodyToken) && $bodyToken !== '') {
                $providedToken = trim($bodyToken);
            }
        }
        $authenticatedUserId = $this->authenticatedCustomerId($request);
        $authorized = $this->orders->getOrderAuthorized($orderId, $authenticatedUserId, $providedToken);
        if ($authorized === null) {
            $this->error('Order not found.', 404);
        }
        try {
            $cancelled = $this->orders->cancelOrder($orderId);
        } catch (ConflictException $e) {
            $this->error($e->getMessage(), 409);
        }
        $data = $cancelled->toArray();
        if ($providedToken === null || $providedToken === '') {
            unset($data['lookup_token']);
        }
        $this->json(['data' => $data]);
    }

    /**
     * Extracts the single-purpose lookup token from query `?token=` or
     * header `X-Order-Token` (narrowly scoped, not Authorization).
     */
    private function extractLookupToken(Request $request): ?string
    {
        $token = $request->query('token');
        if (is_string($token) && $token !== '') {
            return trim($token);
        }
        $header = $request->header('X-Order-Token');
        if (is_string($header) && $header !== '') {
            return trim($header);
        }
        return null;
    }
}