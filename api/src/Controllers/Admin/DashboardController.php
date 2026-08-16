<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;

/**
 * DashboardController — admin overview stats.
 *
 * Phase 9/10 implements real aggregates (daily/monthly sales for charts,
 * best-selling products, order-status breakdown). Until then this route
 * exists and returns a clean 501, matching the other admin stubs.
 */
final class DashboardController extends Controller
{
    /**
     * GET /api/admin/dashboard-stats — overview statistics for /admin/dashboard.
     */
    public function show(Request $request): never
    {
        $this->notImplemented('Dashboard stats');
    }
}