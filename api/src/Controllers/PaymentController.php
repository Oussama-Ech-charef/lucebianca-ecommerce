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
    private ?PaymentService $payments = null;
    private OrderService $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    /**
     * Lazily creates the gateway client (throws 503 when unconfigured).
     * The config/status endpoints must work without CMI credentials.
     */
    private function gateway(): PaymentService
    {
        if ($this->payments === null) {
            $this->payments = new PaymentService();
        }
        return $this->payments;
    }

    /**
     * POST /api/payments/initiate — generates payment request for CMI gateway.
     *
     * Body: {"order_id": 123, "email": "customer@example.com"}
     *
     * Returns gateway URL and form fields to POST (frontend auto-submits form).
     * The customer return URLs (okUrl/failUrl) point at the storefront
     * payment page (/payment/{id}?token=...&pay=...); only callbackUrl (the
     * server-to-server endpoint) points at the API.
     *
     * @return never 200 with payment initiation data, 404 if order not found,
     *               422 on validation errors, 400 if order already paid,
     *               409 if the order cannot be (re)tried
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

        if ($order->status === 'cancelled') {
            $this->error('This order was cancelled. Please place a new order.', 409);
        }

        // Retry after a failure released the reservation: re-reserve stock
        // atomically when every line is still available, else a clear 409.
        if ($order->paymentStatus === 'failed') {
            try {
                $this->orders->reacquireInventoryForRetry($orderIdInt);
            } catch (\App\Core\ConflictException $e) {
                $this->error($e->getMessage(), 409);
            }
        }

        try {
            $paymentData = $this->gateway()->initiatePayment(
                (int) $orderId,
                $order->totalAmount,
                '504', // MAD currency code
                $email,
                $order->lookupToken,
                null // return-URL base comes from CMI_RETURN_URL (see PaymentService)
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
     * This endpoint is called server-to-server by CMI after payment
     * completion (success or failure). It is NEVER the customer-facing
     * return page: customers return to the storefront payment page via
     * okUrl/failUrl (see PaymentService). Verifies the signature and updates
     * the order's payment_status accordingly.
     *
     * Idempotent: replays of the same result return success without
     * double-updating or double-restoring stock (the inventory release is
     * claimed atomically — see OrderService). A late success for an already
     * failed/cancelled/released order is rejected (409), never resurrected
     * to paid.
     *
     * @return never 200 with status message (CMI expects a response)
     */
    public function callback(Request $request): never
    {
        // CMI posts form-encoded (application/x-www-form-urlencoded), not
        // JSON — Request::fromGlobals() keeps $_POST in query(), JSON in
        // body(). Merge both so verification sees every field.
        $callbackData = array_merge($request->all(), $_POST);
        // $_GET status hint (?status=success/failure) is informational only —
        // the authoritative result is ProcReturnCode/Response after HMAC check.
        foreach ($_GET as $k => $v) {
            if (!array_key_exists($k, $callbackData)) {
                $callbackData[$k] = $v;
            }
        }

        if (empty($callbackData)) {
            $this->error('No callback data received.', 400);
        }

        try {
            $gateway = new PaymentService();
        } catch (\RuntimeException) {
            $this->error('Payment gateway is not configured.', 503);
        }
        $result = $gateway->verifyCallback($callbackData);

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

        // Amount is required and must match the order total (prevent tampering).
        $callbackAmount = isset($result['amount']) ? (string) $result['amount'] : '';
        if ($callbackAmount === '') {
            $this->error('Payment amount missing.', 400);
        }
        $expected = number_format((float) $existing->totalAmount, 2, '.', '');
        $received = number_format((float) $callbackAmount, 2, '.', '');
        if ($expected !== $received) {
            $this->error('Payment amount mismatch.', 400);
        }

        // State transitions run inside atomic service methods (locked row +
        // conditional inventory-release claim in one transaction):
        //  - paid: allowed only from pending on an unreleased, uncancelled
        //    order; replays return the paid order (200); late successes
        //    after failure/cancellation/expiry are 409, never paid.
        //  - failed: allowed from pending (even on a concurrently cancelled
        //    order — the payment truth is recorded while the stock claim
        //    stays idempotent); replays return the failed order (200).
        $target = $result['status']; // 'paid' or 'failed'
        try {
            $order = $target === 'paid'
                ? $this->orders->markCardPaymentPaid($result['order_id'])
                : $this->orders->markCardPaymentFailed($result['order_id']);
        } catch (\App\Core\ConflictException $e) {
            // Genuine replays never throw (the service returns the already
            // terminal order unchanged) — anything thrown here is a real
            // conflict (late paid after release/cancel, paid→failed, ...).
            // Re-read once: if the stored state already equals the callback
            // target, treat the duplicate delivery as acknowledged.
            $fresh = $this->orders->getOrder($result['order_id']);
            if ($fresh !== null && $fresh->paymentStatus === $target) {
                $order = $fresh;
            } else {
                $this->error($e->getMessage(), 409);
            }
        }

        if ($order === null) {
            $this->error('Order not found.', 404);
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
                'subtotal_amount' => $order->subtotalAmount,
                'shipping_cost' => $order->shippingCost,
                'status' => $order->status,
            ],
        ]);
    }

    /**
     * GET /api/payments/config — public card-availability flag (no secrets).
     *
     * Returns whether card payment is enabled in settings AND the CMI gateway
     * is configured. The checkout uses this to decide whether to offer the
     * card option instead of a hard-coded "coming soon" placeholder.
     *
     * @return never 200 with {card_enabled, card_configured, card_available}
     */
    public function config(Request $request): never
    {
        $settings = new \App\Repositories\SettingsRepository();
        try {
            $enabled = $settings->get('payment_card_enabled');
        } catch (\Throwable) {
            $enabled = null;
        }
        $cardEnabled = $enabled !== '0';
        $cardConfigured = \App\Services\PaymentService::isConfigured();
        $this->json(['data' => [
            'card_enabled' => $cardEnabled,
            'card_configured' => $cardConfigured,
            'card_available' => $cardEnabled && $cardConfigured,
        ]]);
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
