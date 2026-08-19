<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Models\Order;
use App\Repositories\OrderRepository;
use App\Services\OrderService;

/**
 * OrdersController — admin order management (protected by JWT admin role).
 *
 * index() lists orders (light payload: summary fields + item count, never the
 * full line items — a detail view uses the existing public GET /api/orders/{id}).
 * update() changes status / payment_status, validating against the schema's
 * ENUM values before anything is written (spec 3.1 strict validation).
 */
final class OrdersController extends Controller
{
    private OrderRepository $orders;
    private OrderService $orderService;

    public function __construct()
    {
        $this->orders       = new OrderRepository();
        $this->orderService = new OrderService();
    }

    /**
     * GET /api/admin/orders — paginated order list with optional status filter.
     *
     * Query params: page, per_page, status (one of Order::STATUSES).
     * Same {data, meta} pagination shape as the product listing endpoints.
     *
     * @return never 200 with {data, meta}, or 422 on an invalid status filter.
     */
    public function index(Request $request): never
    {
        $page    = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 12)));

        $status = (string) $request->query('status', '');
        if ($status !== '' && !in_array($status, Order::STATUSES, true)) {
            $this->error('Validation failed.', 422, [
                'errors' => ['status' => 'Status must be one of: ' . implode(', ', Order::STATUSES) . '.'],
            ]);
        }

        $result = $this->orders->paginate($page, $perPage, $status !== '' ? $status : null);

        $this->json([
            'data' => $result['items'],
            'meta' => [
                'page'     => $page,
                'per_page' => $perPage,
                'total'    => $result['total'],
                'pages'    => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * PUT /api/admin/orders/{id} — change order status (and optionally
     * payment_status).
     *
     * Body: {"status": "shipped"} and/or {"payment_status": "paid"}.
     * Both values are validated against the schema's ENUM lists (section 4):
     * anything else is rejected with 422 before it can reach the column.
     * Responds with the updated order + full items (same shape as
     * GET /api/orders/{id}, reusing OrderService::getOrder).
     *
     * @return never 200 with the updated order, 404 when missing,
     *               422 on invalid/missing fields.
     */
    public function update(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->orderService->getOrder($id) === null) {
            $this->error('Order not found.', 404);
        }

        $body   = $request->all();
        $errors = [];
        $fields = [];

        if (array_key_exists('status', $body)) {
            $status = (string) $body['status'];
            if (!in_array($status, Order::STATUSES, true)) {
                $errors['status'] = 'Status must be one of: ' . implode(', ', Order::STATUSES) . '.';
            } else {
                $fields['status'] = $status;
            }
        }

        if (array_key_exists('payment_status', $body)) {
            $paymentStatus = (string) $body['payment_status'];
            if (!in_array($paymentStatus, Order::PAYMENT_STATUSES, true)) {
                $errors['payment_status'] = 'Payment status must be one of: ' . implode(', ', Order::PAYMENT_STATUSES) . '.';
            } else {
                $fields['payment_status'] = $paymentStatus;
            }
        }

        if ($fields === [] && $errors === []) {
            $errors['status'] = 'At least one of status or payment_status is required.';
        }

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        $updated = $this->orderService->updateStatus($id, $fields);

        $this->json(['data' => $updated->toArray()]);
    }
}