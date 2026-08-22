<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Repositories\UserRepository;

/**
 * DashboardController — admin overview stats.
 *
 * Returns aggregated metrics for admin dashboard: sales totals, order counts,
 * order status breakdown, top-selling products, and recent activity.
 */
final class DashboardController extends Controller
{
    private OrderRepository $orders;
    private ProductRepository $products;
    private UserRepository $users;

    public function __construct()
    {
        $this->orders = new OrderRepository();
        $this->products = new ProductRepository();
        $this->users = new UserRepository();
    }

    /**
     * GET /api/admin/dashboard-stats — overview statistics for /admin/dashboard.
     *
     * @return never 200 with dashboard metrics
     */
    public function show(Request $request): never
    {
        $stats = [
            'overview' => $this->getOverviewStats(),
            'order_status_breakdown' => $this->getOrderStatusBreakdown(),
            'top_products' => $this->getTopSellingProducts(5),
            'recent_orders' => $this->getRecentOrders(10),
            'sales_chart' => $this->getSalesChartData(30), // Last 30 days
        ];

        $this->json(['data' => $stats]);
    }

    /**
     * Get overview stats: total sales, orders, customers.
     */
    private function getOverviewStats(): array
    {
        $pdo = \App\Core\Database::get();

        // Total sales (all paid orders)
        $stmt = $pdo->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_sales, COUNT(*) as total_orders
            FROM orders
            WHERE payment_status = 'paid'
        ");
        $stmt->execute();
        $salesData = $stmt->fetch(\PDO::FETCH_ASSOC);

        // Total customers
        $stmt = $pdo->prepare("SELECT COUNT(*) as total_customers FROM users");
        $stmt->execute();
        $customerData = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_sales' => $salesData['total_sales'] ?? '0.00',
            'total_orders' => (int) ($salesData['total_orders'] ?? 0),
            'total_customers' => (int) ($customerData['total_customers'] ?? 0),
        ];
    }

    /**
     * Get order status breakdown.
     */
    private function getOrderStatusBreakdown(): array
    {
        $pdo = \App\Core\Database::get();

        $stmt = $pdo->prepare("
            SELECT status, COUNT(*) as count
            FROM orders
            GROUP BY status
        ");
        $stmt->execute();

        $breakdown = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $breakdown[$row['status']] = (int) $row['count'];
        }

        return $breakdown;
    }

    /**
     * Get top-selling products.
     */
    private function getTopSellingProducts(int $limit): array
    {
        $pdo = \App\Core\Database::get();

        $stmt = $pdo->prepare("
            SELECT
                p.id,
                p.name,
                p.slug,
                SUM(oi.quantity) as units_sold,
                SUM(oi.quantity * oi.price_at_purchase) as revenue
            FROM order_items oi
            INNER JOIN product_variants pv ON oi.product_variant_id = pv.id
            INNER JOIN products p ON pv.product_id = p.id
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE o.payment_status = 'paid'
            GROUP BY p.id, p.name, p.slug
            ORDER BY units_sold DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $products = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $products[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'units_sold' => (int) $row['units_sold'],
                'revenue' => $row['revenue'],
            ];
        }

        return $products;
    }

    /**
     * Get recent orders.
     */
    private function getRecentOrders(int $limit): array
    {
        $pdo = \App\Core\Database::get();

        $stmt = $pdo->prepare("
            SELECT
                id,
                customer_name,
                total_amount,
                status,
                payment_status,
                payment_method,
                created_at
            FROM orders
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
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

        return $orders;
    }

    /**
     * Get sales chart data for the last N days.
     */
    private function getSalesChartData(int $days): array
    {
        $pdo = \App\Core\Database::get();

        $stmt = $pdo->prepare("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as order_count,
                SUM(total_amount) as daily_sales
            FROM orders
            WHERE payment_status = 'paid'
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        $chartData = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $chartData[] = [
                'date' => $row['date'],
                'order_count' => (int) $row['order_count'],
                'sales' => $row['daily_sales'],
            ];
        }

        return $chartData;
    }
}