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
     *
     * "Realized" order definition (shared with top_products + sales_chart):
     *   status != 'cancelled'
     *   AND (payment_status = 'paid'
     *        OR (payment_method IN ('cod','whatsapp') AND status IN ('processing','shipped','delivered')))
     *
     * This ensures COD/WhatsApp orders that are delivered while payment_status
     * is still 'pending' count toward sales, while cancelled orders never do.
     */
    private function getOverviewStats(): array
    {
        $salesData = $this->orders->getSalesOverview();
        $totalCustomers = $this->users->countAll();

        return [
            'total_sales' => $salesData['total_sales'],
            'total_orders' => $salesData['total_orders'],
            'total_customers' => $totalCustomers,
        ];
    }

    /**
     * Get order status breakdown.
     */
    private function getOrderStatusBreakdown(): array
    {
        return $this->orders->getStatusBreakdown();
    }

    /**
     * Get top-selling products (only from realized orders).
     */
    private function getTopSellingProducts(int $limit): array
    {
        return $this->orders->getTopSellingProducts($limit);
    }

    /**
     * Get recent orders.
     */
    private function getRecentOrders(int $limit): array
    {
        return $this->orders->getRecent($limit);
    }

    /**
     * Get sales chart data for the last N days (only realized orders).
     */
    private function getSalesChartData(int $days): array
    {
        return $this->orders->getSalesChartData($days);
    }
}