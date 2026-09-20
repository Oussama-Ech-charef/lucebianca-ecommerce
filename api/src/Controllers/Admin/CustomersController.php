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

        $pdo = \App\Core\Database::get();

        // Count total
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM users");
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        // Fetch paginated
        $offset = ($page - 1) * $perPage;
        $stmt = $pdo->prepare("
            SELECT id, name, email, phone, email_verified, created_at
            FROM users
            ORDER BY created_at DESC
            LIMIT :limit OFFSET :offset
        ");
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $customers = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $customers[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'email' => $row['email'],
                'phone' => $row['phone'],
                'email_verified' => (int) $row['email_verified'],
                'created_at' => $row['created_at'],
            ];
        }

        $this->json([
            'data' => $customers,
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
                'pages' => (int) ceil($total / $perPage),
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

        // Get customer's orders
        $pdo = \App\Core\Database::get();
        $stmt = $pdo->prepare("
            SELECT id, customer_name, total_amount, status, payment_status, payment_method, created_at
            FROM orders
            WHERE user_id = :user_id
            ORDER BY created_at DESC
        ");
        $stmt->bindValue(':user_id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $orders = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
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
