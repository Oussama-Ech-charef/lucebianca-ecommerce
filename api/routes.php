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
use App\Controllers\Admin\AccountsController;
use App\Controllers\Admin\AuthController as AdminAuthController;
use App\Controllers\Admin\CategoriesController;
use App\Controllers\Admin\ColorsController as AdminColorsController;
use App\Controllers\Admin\CouponsController;
use App\Controllers\Admin\CustomersController;
use App\Controllers\Admin\DashboardController;
use App\Controllers\Admin\MessagesController;
use App\Controllers\Admin\OrdersController;
use App\Controllers\Admin\ProductsController;
use App\Controllers\Admin\ReviewsController;
use App\Controllers\Admin\SettingsController;
use App\Controllers\Admin\SizesController as AdminSizesController;
use App\Controllers\AuthController;
use App\Controllers\CartController;
use App\Controllers\CategoryController;
use App\Controllers\ColorController;
use App\Controllers\ContactController;
use App\Controllers\CouponController;
use App\Controllers\CsrfController;
use App\Controllers\HealthController;
use App\Controllers\NewsletterController;
use App\Controllers\OrderController;
use App\Controllers\PaymentController;
use App\Controllers\ProductController;
use App\Controllers\ReviewController;
use App\Controllers\SizeController;
use App\Controllers\WishlistController;

$router = new Router();

// --- Security middleware ---
// Rate limiting: 5 attempts per 5 minutes for login/register, 3 per 10 minutes for contact, 5 per 5 min for admin login
$loginRateLimit = new RateLimitMiddleware('login', 5, 300);
$registerRateLimit = new RateLimitMiddleware('register', 5, 300);
$contactRateLimit = new RateLimitMiddleware('contact', 3, 600);
$adminLoginRateLimit = new RateLimitMiddleware('admin_login', 5, 300);
$passwordResetRateLimit = new RateLimitMiddleware('password_reset', 5, 900);
// Coupon previews are CSRF-protected but still code-guessable by a scripted
// client. 20 attempts per 5 minutes leaves room for normal apply/remove
// retries while bounding code probing and per-request cart/stock work.
$couponValidationRateLimit = new RateLimitMiddleware('coupon_validate', 20, 300);

// CSRF protection for state-changing operations
$csrfProtection = new CsrfMiddleware();

// --- Health / smoke test ---
$router->get('/api/health', [HealthController::class, 'show']);

// --- CSRF token endpoint (public, used by frontend) ---
$router->get('/api/csrf-token', [CsrfController::class, 'show']);

// --- Public storefront ---
$router->get('/api/products', [ProductController::class, 'index']);
$router->get('/api/products/new-arrivals', [ProductController::class, 'newArrivals']);
$router->get('/api/products/special-offers', [ProductController::class, 'specialOffers']);
$router->get('/api/products/{slug}', [ProductController::class, 'show']);
$router->get('/api/categories', [CategoryController::class, 'index']);
$router->get('/api/sizes', [SizeController::class, 'index']);
$router->get('/api/colors', [ColorController::class, 'index']);

// --- Reviews (public submission requires auth) ---
$router->post('/api/products/{slug}/reviews', [ReviewController::class, 'store'], [$csrfProtection]);

// --- Wishlist (authenticated customers only) ---
$router->get('/api/wishlist', [WishlistController::class, 'index']);
$router->post('/api/wishlist', [WishlistController::class, 'store'], [$csrfProtection]);
$router->delete('/api/wishlist/{productId}', [WishlistController::class, 'destroy'], [$csrfProtection]);

// --- Newsletter (public) ---
// Subscribe is CSRF-guarded + rate-limited; unsubscribe is a signed-token
// POST (state-changing, never GET — scanners/prefetchers must not mutate).
// GET only validates the emailed link (read-only) for the confirm page.
$router->post('/api/newsletter/subscribe', [NewsletterController::class, 'subscribe'], [$csrfProtection, $contactRateLimit]);
$router->post('/api/newsletter/unsubscribe', [NewsletterController::class, 'unsubscribe']);
$router->get('/api/newsletter/unsubscribe', [NewsletterController::class, 'verify']);

// --- Cart + checkout (CSRF protected for state-changing operations) ---
$router->post('/api/cart', [CartController::class, 'store'], [$csrfProtection]);
$router->post('/api/orders', [OrderController::class, 'store'], [$csrfProtection]);
$router->post('/api/coupons/validate', [CouponController::class, 'validate'], [$csrfProtection, $couponValidationRateLimit]);
$router->get('/api/orders/{id}', [OrderController::class, 'show']);
$router->post('/api/orders/{id}/cancel', [OrderController::class, 'cancel'], [$csrfProtection]);

// --- Payments (CMI/Payzone card payment gateway) ---
$router->get('/api/payments/config', [PaymentController::class, 'config']);
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
$router->post('/api/auth/forgot-password', [AuthController::class, 'forgotPassword'], [$passwordResetRateLimit]);
$router->post('/api/auth/reset-password', [AuthController::class, 'resetPassword'], [$passwordResetRateLimit]);

// --- Customer account (JWT role "user" on every route) ---
$customerMiddleware = [new AuthMiddleware('user')];
$router->get('/api/account', [AccountController::class, 'me'], $customerMiddleware);
$router->get('/api/account/orders', [AccountController::class, 'orders'], $customerMiddleware);

// --- Public settings (safe subset for storefront - WhatsApp, payment toggles, store info) ---
$router->get('/api/settings/public', [SettingsController::class, 'publicIndex']);

// --- Contact form (rate-limited + CSRF protected) ---
$router->post('/api/contact', [ContactController::class, 'store'], [$contactRateLimit, $csrfProtection]);

// --- Admin (JWT + role check on every admin route) ---
$adminMiddleware = [new AuthMiddleware('admin')];

// Admin login is PUBLIC (no middleware) — the one exception above.
// There is no public admin registration: the first account is seeded offline
// via api/scripts/create-admin.php, and additional accounts can only be
// created by an authenticated admin (POST /api/admin/accounts).
$router->post('/api/admin/auth/login', [AdminAuthController::class, 'login'], [$adminLoginRateLimit]);
$router->get('/api/admin/auth/me', [AdminAuthController::class, 'me'], $adminMiddleware);

// Admin CRUD operations (JWT auth + CSRF protection on state-changing)
$adminCsrf = array_merge($adminMiddleware, [$csrfProtection]);

$router->get('/api/admin/products', [ProductsController::class, 'index'], $adminMiddleware);
$router->get('/api/admin/products/{id}', [ProductsController::class, 'show'], $adminMiddleware);
$router->post('/api/admin/products', [ProductsController::class, 'store'], $adminCsrf);
$router->put('/api/admin/products/{id}', [ProductsController::class, 'update'], $adminCsrf);
$router->delete('/api/admin/products/{id}', [ProductsController::class, 'destroy'], $adminCsrf);
$router->post('/api/admin/products/{id}/images', [ProductsController::class, 'uploadImages'], $adminCsrf);
$router->post('/api/admin/products/{id}/variants', [ProductsController::class, 'storeVariant'], $adminCsrf);
$router->put('/api/admin/variants/{id}', [ProductsController::class, 'updateVariant'], $adminCsrf);
$router->delete('/api/admin/variants/{id}', [ProductsController::class, 'destroyVariant'], $adminCsrf);
    $router->get('/api/admin/orders', [OrdersController::class, 'index'], $adminMiddleware);
    $router->get('/api/admin/orders/{id}', [OrdersController::class, 'show'], $adminMiddleware);
    $router->put('/api/admin/orders/{id}', [OrdersController::class, 'update'], $adminCsrf);
$router->get('/api/admin/dashboard-stats', [DashboardController::class, 'show'], $adminMiddleware);

// Customers
$router->get('/api/admin/customers', [CustomersController::class, 'index'], $adminMiddleware);
$router->get('/api/admin/customers/{id}', [CustomersController::class, 'show'], $adminMiddleware);

// Categories
$router->get('/api/admin/categories', [CategoriesController::class, 'index'], $adminMiddleware);
$router->post('/api/admin/categories', [CategoriesController::class, 'store'], $adminCsrf);
$router->put('/api/admin/categories/{id}', [CategoriesController::class, 'update'], $adminCsrf);
$router->delete('/api/admin/categories/{id}', [CategoriesController::class, 'destroy'], $adminCsrf);

// Catalog: Sizes
$router->get('/api/admin/sizes', [AdminSizesController::class, 'index'], $adminMiddleware);
$router->post('/api/admin/sizes', [AdminSizesController::class, 'store'], $adminCsrf);
$router->delete('/api/admin/sizes/{id}', [AdminSizesController::class, 'destroy'], $adminCsrf);

// Catalog: Colors
$router->get('/api/admin/colors', [AdminColorsController::class, 'index'], $adminMiddleware);
$router->post('/api/admin/colors', [AdminColorsController::class, 'store'], $adminCsrf);
$router->delete('/api/admin/colors/{id}', [AdminColorsController::class, 'destroy'], $adminCsrf);

// Reviews
$router->get('/api/admin/reviews', [ReviewsController::class, 'index'], $adminMiddleware);
$router->put('/api/admin/reviews/{id}/approve', [ReviewsController::class, 'approve'], $adminCsrf);
$router->put('/api/admin/reviews/{id}/reject', [ReviewsController::class, 'reject'], $adminCsrf);
$router->delete('/api/admin/reviews/{id}', [ReviewsController::class, 'destroy'], $adminCsrf);

// Contact Messages
$router->get('/api/admin/messages', [MessagesController::class, 'index'], $adminMiddleware);
$router->put('/api/admin/messages/{id}/mark-read', [MessagesController::class, 'markRead'], $adminCsrf);
$router->delete('/api/admin/messages/{id}', [MessagesController::class, 'destroy'], $adminCsrf);

// Settings
$router->get('/api/admin/settings', [SettingsController::class, 'index'], $adminMiddleware);
$router->put('/api/admin/settings', [SettingsController::class, 'update'], $adminCsrf);

// Coupons (schema-only fields: code, type, value, expiry, active flag)
$router->get('/api/admin/coupons', [CouponsController::class, 'index'], $adminMiddleware);
$router->post('/api/admin/coupons', [CouponsController::class, 'store'], $adminCsrf);
$router->put('/api/admin/coupons/{id}', [CouponsController::class, 'update'], $adminCsrf);
$router->delete('/api/admin/coupons/{id}', [CouponsController::class, 'destroy'], $adminCsrf);

// Admin accounts (name/email/password only; self-delete + last-admin guards)
$router->get('/api/admin/accounts', [AccountsController::class, 'index'], $adminMiddleware);
$router->post('/api/admin/accounts', [AccountsController::class, 'store'], $adminCsrf);
$router->put('/api/admin/accounts/{id}', [AccountsController::class, 'update'], $adminCsrf);
$router->delete('/api/admin/accounts/{id}', [AccountsController::class, 'destroy'], $adminCsrf);

$router->dispatch(Request::fromGlobals());