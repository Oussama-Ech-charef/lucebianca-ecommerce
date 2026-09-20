<?php

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\OrderService;
use App\Services\PaymentService;

/**
 * PaymentController — CMI/Payzone card payment integration.
 *
 * Handles payment initiation (generates form data for gateway redirect) and
 * payment callbacks (verifies signature and updates order status).
 *
 * Flow:
 * 1. POST /api/payments/initiate → returns gateway URL + form fields
 * 2. Frontend redirects customer to CMI payment page
 * 3. Customer completes payment
 * 4. CMI POSTs to /api/payments/callback → we verify and update order
 */
final class PaymentController extends Controller
{
    private PaymentService $payments;
    private OrderService $orders;

    public function __construct()
    {
        $this->payments = new PaymentService();
        $this->orders = new OrderService();
    }

    /**
     * POST /api/payments/initiate — generates payment request for CMI gateway.
     *
     * Body: {"order_id": 123, "email": "customer@example.com"}
     *
     * Returns gateway URL and form fields to POST (frontend auto-submits form).
     *
     * @return never 200 with payment initiation data, 404 if order not found,
     *               422 on validation errors, 400 if order already paid
     */
    public function initiate(Request $request): never
    {
        $orderId = $request->input('order_id');
        $email = trim((string) $request->input('email', ''));

        if (!is_numeric($orderId) || (int) $orderId < 1) {
            $this->error('Order ID is required.', 422, [
                'errors' => ['order_id' => 'Order ID must be a positive integer.'],
            ]);
        }

        $orderIdInt = (int) $orderId;
        $providedToken = $this->extractLookupToken($request);
        $authenticatedUserId = $this->authenticatedCustomerId($request);
        $order = $this->orders->getOrderAuthorized($orderIdInt, $authenticatedUserId, $providedToken);
        if ($order === null) {
            $this->error('Order not found.', 404);
        }

        // Only allow payment initiation for pending payments
        if ($order->paymentStatus === 'paid') {
            $this->error('This order has already been paid.', 400);
        }

        if ($order->paymentMethod !== 'card') {
            $this->error('This order does not use card payment.', 400);
        }

        try {
            $paymentData = $this->payments->initiatePayment(
                (int) $orderId,
                $order->totalAmount,
                '504', // MAD currency code
                $email
            );
        } catch (\RuntimeException $e) {
            $this->error('Payment gateway is not configured. Please contact support.', 503);
        }

        $this->json([
            'data' => $paymentData,
        ]);
    }

    /**
     * POST /api/payments/callback — receives payment status from CMI gateway.
     *
     * This endpoint is called by CMI after payment completion (success or failure).
     * Verifies the signature and updates the order's payment_status accordingly.
     *
     * @return never 200 with status message (CMI expects a response)
     */
    public function callback(Request $request): never
    {
        // Get all POST data from CMI callback
        $callbackData = $_POST;

        if (empty($callbackData)) {
            $this->error('No callback data received.', 400);
        }

        $result = $this->payments->verifyCallback($callbackData);

        if (!$result['valid']) {
            $this->error($result['message'] ?? 'Invalid payment callback.', 400);
        }

        if ($result['order_id'] === null) {
            $this->error('Order ID not found in callback.', 400);
        }

        // Verify order existence before any state checks (hide enumeration via generic 400/404)
        $existing = $this->orders->getOrder($result['order_id']);
        if ($existing === null) {
            $this->error('Order not found.', 404);
        }

        // Payment method must be card (prevent COD/whatsapp being marked paid via forged callback)
        if ($existing->paymentMethod !== 'card') {
            $this->error('Order does not use card payment.', 400);
        }

        // Amount equality: callback amount must match order total (prevent tampering, format to 2 decimals)
        $callbackAmount = isset($result['amount']) ? (string) $result['amount'] : null;
        if ($callbackAmount !== null && $callbackAmount !== '') {
            $expected = number_format((float) $existing->totalAmount, 2, '.', '');
            $received = number_format((float) $callbackAmount, 2, '.', '');
            if ($expected !== $received) {
                $this->error('Payment amount mismatch.', 400);
            }
        }

        // Valid state transitions: only pending → paid/failed.
        // Idempotency: if already in target state, treat as success without re-update (replay protection).
        $current = $existing->paymentStatus;
        $target = $result['status']; // 'paid' or 'failed'
        if ($current === $target) {
            // Idempotent replay — already in this state, return without state change.
            $order = $existing;
        } elseif ($current !== 'pending') {
            $this->error('Invalid payment state transition.', 409);
        } else {
            // Update order payment status
            $order = $this->orders->updateStatus(
                $result['order_id'],
                ['payment_status' => $target]
            );

            if ($order === null) {
                $this->error('Order not found.', 404);
            }
        }

        // Log successful payment for admin records
        if ($result['status'] === 'paid') {
            error_log(sprintf(
                '[Payment] Order #%d paid successfully. Transaction: %s, Amount: %s MAD',
                $result['order_id'],
                $result['transaction_id'] ?? 'N/A',
                $result['amount'] ?? 'N/A'
            ));
        }

        // Return success response to CMI
        $this->json([
            'status' => 'received',
            'order_id' => $result['order_id'],
            'payment_status' => $result['status'],
            'message' => $result['message'],
        ]);
    }

    /**
     * GET /api/payments/status/{orderId} — check payment status for an order.
     *
     * Public endpoint for frontend to poll payment status after redirect.
     *
     * @return never 200 with order payment status, 404 if order not found
     */
    public function status(Request $request, array $params): never
    {
        $orderId = (int) ($params['orderId'] ?? 0);

        if ($orderId < 1) {
            $this->error('Invalid order ID.', 400);
        }

        $providedToken = $this->extractLookupToken($request);
        $authenticatedUserId = $this->authenticatedCustomerId($request);
        $order = $this->orders->getOrderAuthorized($orderId, $authenticatedUserId, $providedToken);
        if ($order === null) {
            $this->error('Order not found.', 404);
        }

        $this->json([
            'data' => [
                'order_id' => $order->id,
                'payment_status' => $order->paymentStatus,
                'payment_method' => $order->paymentMethod,
                'total_amount' => $order->totalAmount,
            ],
        ]);
    }

    private function extractLookupToken(Request $request): ?string
    {
        // Body lookup_token for POST, query token or header for GET
        $bodyToken = $request->input('lookup_token');
        if (is_string($bodyToken) && $bodyToken !== '') {
            return trim($bodyToken);
        }
        $queryToken = $request->query('token');
        if (is_string($queryToken) && $queryToken !== '') {
            return trim($queryToken);
        }
        $header = $request->header('X-Order-Token');
        if (is_string($header) && $header !== '') {
            return trim($header);
        }
        return null;
    }

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
}
