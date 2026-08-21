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
use App\Core\CsrfMiddleware;
use App\Core\RateLimitMiddleware;
use App\Core\Request;
use App\Core\Router;
use App\Controllers\AccountController;
use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\OrdersController;
use App\Controllers\Admin\ProductsController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CategoryController;
use App\Controllers\ContactController;
use App\Controllers\CsrfController;
use App\Controllers\HealthController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\ProductController;

$router = new Router();

// --- Security middleware ---
// Rate limiting: 5 attempts per 5 minutes for login/register, 3 per 10 minutes for contact
$loginRateLimit = new RateLimitMiddleware('login', 5, 300);
$registerRateLimit = new RateLimitMiddleware('register', 5, 300);
$contactRateLimit = new RateLimitMiddleware('contact', 3, 600);

// CSRF protection for state-changing operations
$csrfProtection = new CsrfMiddleware();

// --- Health / smoke test ---
$router->get('/api/health', [HealthController::class, 'show']);

// --- CSRF token endpoint (public, used by frontend) ---
$router->get('/api/csrf-token', [CsrfController::class, 'show']);

// --- Public storefront ---
$router->get('/api/products', [ProductController::class, 'index']);
$router->get('/api/products/{slug}', [ProductController::class, 'show']);
$router->get('/api/categories', [CategoryController::class, 'index']);

// --- Cart + checkout (CSRF protected for state-changing operations) ---
$router->post('/api/cart', [CartController::class, 'store'], [$csrfProtection]);
$router->post('/api/orders', [OrderController::class, 'store'], [$csrfProtection]);
$router->get('/api/orders/{id}', [OrderController::class, 'show']);

// --- Payments (CMI/Payzone card payment gateway) ---
$router->post('/api/payments/initiate', [PaymentController::class, 'initiate'], [$csrfProtection]);
$router->post('/api/payments/callback', [PaymentController::class, 'callback']); // CMI callback, no CSRF
$router->get('/api/payments/status/{orderId}', [PaymentController::class, 'status']);

// --- Auth (rate-limited login/register to prevent brute-force) ---
$router->post('/api/auth/register', [AuthController::class, 'register'], [$registerRateLimit]);
$router->post('/api/auth/login', [AuthController::class, 'login'], [$loginRateLimit]);
$router->post('/api/auth/refresh', [AuthController::class, 'refresh']);
$router->post('/api/auth/logout', [AuthController::class, 'logout']);
$router->post('/api/auth/google', [AuthController::class, 'google']);
$router->get('/api/auth/verify-email', [AuthController::class, 'verifyEmail']);
$router->post('/api/auth/resend-verification', [AuthController::class, 'resendVerification']);

// --- Customer account (JWT role "user" on every route) ---
$customerMiddleware = [new AuthMiddleware('user')];
$router->get('/api/account', [AccountController::class, 'me'], $customerMiddleware);
$router->get('/api/account/orders', [AccountController::class, 'orders'], $customerMiddleware);

// --- Contact form (rate-limited + CSRF protected) ---
$router->post('/api/contact', [ContactController::class, 'store'], [$contactRateLimit, $csrfProtection]);

// --- Admin (JWT + role check on every admin route) ---
$adminMiddleware = [new AuthMiddleware('admin')];

// Admin login is PUBLIC (no middleware) — the one exception above.
// There is no public admin registration; admins are seeded offline via
// api/scripts/create-admin.php.
$router->post('/api/admin/auth/login', [AdminAuthController::class, 'login']);

// Admin CRUD operations (JWT auth + CSRF protection on state-changing)
$adminCsrf = array_merge($adminMiddleware, [$csrfProtection]);

$router->get('/api/admin/products', [ProductsController::class, 'index'], $adminMiddleware);
$router->post('/api/admin/products', [ProductsController::class, 'store'], $adminCsrf);
$router->put('/api/admin/products/{id}', [ProductsController::class, 'update'], $adminCsrf);
$router->delete('/api/admin/products/{id}', [ProductsController::class, 'destroy'], $adminCsrf);
$router->post('/api/admin/products/{id}/images', [ProductsController::class, 'uploadImages'], $adminCsrf);
$router->get('/api/admin/orders', [OrdersController::class, 'index'], $adminMiddleware);
$router->put('/api/admin/orders/{id}', [OrdersController::class, 'update'], $adminCsrf);
$router->get('/api/admin/dashboard-stats', [DashboardController::class, 'show'], $adminMiddleware);

$router->dispatch(Request::fromGlobals());