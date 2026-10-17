<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\OrderRepository;
use App\Repositories\UserRepository;

/**
 * CustomersController — admin customer management.
 */
final class CustomersController extends Controller
{
    private UserRepository $users;
    private OrderRepository $orders;

    public function __construct()
    {
        $this->users = new UserRepository();
        $this->orders = new OrderRepository();
    }

    /**
     * GET /api/admin/customers — list all customers with pagination.
     */
    public function index(Request $request): never
    {
        $page = max(1, (int) $request->query('page', 1));
        $perPage = min(100, max(1, (int) $request->query('per_page', 20)));

        $result = $this->users->paginate($page, $perPage);

        $this->json([
            'data' => $result['items'],
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'pages' => (int) ceil($result['total'] / $perPage),
            ],
        ]);
    }

    /**
     * GET /api/admin/customers/{id} — single customer with orders.
     */
    public function show(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $user = $this->users->findById($id);

        if ($user === null) {
            $this->error('Customer not found.', 404);
        }

        // Get customer's orders via repository
        $userOrders = $this->orders->paginateForUser($id, 1, 100);
        $orders = [];
        foreach ($userOrders['items'] as $row) {
            $orders[] = [
                'id' => (int) $row['id'],
                'customer_name' => $row['customer_name'],
                'total_amount' => $row['total_amount'],
                'status' => $row['status'],
                'payment_status' => $row['payment_status'],
                'payment_method' => $row['payment_method'],
                'created_at' => $row['created_at'],
            ];
        }

        $this->json([
            'data' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'email_verified' => $user->emailVerified,
                'created_at' => $user->createdAt,
                'orders' => $orders,
            ],
        ]);
    }
}
