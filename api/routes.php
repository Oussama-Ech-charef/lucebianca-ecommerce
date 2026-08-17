<?php

/**
 * Route definitions — the only place routes are registered.
 *
 * Mirrors section 5 of the master spec:
 *   Public:  /api/products, /api/products/{slug}, /api/categories, /api/cart,
 *            /api/orders, /api/auth/*, /api/contact
 *   Admin:   /api/admin/* (all behind JWT + admin role check)
 *
 * Controllers referenced here must implement the corresponding handler
 * methods. Sections still pending a phase are stubs returning 501.
 */

declare(strict_types=1);

use App\Core\AuthMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\OrdersController;
use App\Controllers\Admin\ProductsController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CategoryController;
use App\Controllers\ContactController;
use App\Controllers\HealthController;
use App\Controllers\OrderController;
use App\Controllers\ProductController;

$router = new Router();

// --- Health / smoke test ---
$router->get('/api/health', [HealthController::class, 'show']);

// --- Public storefront ---
$router->get('/api/products', [ProductController::class, 'index']);
$router->get('/api/products/{slug}', [ProductController::class, 'show']);
$router->get('/api/categories', [CategoryController::class, 'index']);

// --- Cart + checkout ---
$router->post('/api/cart', [CartController::class, 'store']);
$router->post('/api/orders', [OrderController::class, 'store']);
$router->get('/api/orders/{id}', [OrderController::class, 'show']);

// --- Auth ---
$router->post('/api/auth/register', [AuthController::class, 'register']);
$router->post('/api/auth/login', [AuthController::class, 'login']);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/auth/google', [AuthController::class, 'google']);

// --- Contact form (rate limiting added in the security phase) ---
$router->post('/api/contact', [ContactController::class, 'store']);

// --- Admin (JWT + role check on every admin route) ---
$adminMiddleware = [new AuthMiddleware('admin')];

$router->get('/api/admin/products', [ProductsController::class, 'index'], $adminMiddleware);
$router->post('/api/admin/products', [ProductsController::class, 'store'], $adminMiddleware);
$router->put('/api/admin/products/{id}', [ProductsController::class, 'update'], $adminMiddleware);
$router->delete('/api/admin/products/{id}', [ProductsController::class, 'destroy'], $adminMiddleware);
$router->post('/api/admin/products/{id}/images', [ProductsController::class, 'uploadImages'], $adminMiddleware);
$router->get('/api/admin/orders', [OrdersController::class, 'index'], $adminMiddleware);
$router->put('/api/admin/orders/{id}', [OrdersController::class, 'update'], $adminMiddleware);
$router->get('/api/admin/dashboard-stats', [DashboardController::class, 'show'], $adminMiddleware);

$router->dispatch(Request::fromGlobals());