<?php

namespace App\Core;

/**
 * CsrfMiddleware — CSRF protection for state-changing requests.
 *
 * Validates CSRF tokens on POST/PUT/DELETE requests. The token can be sent
 * via X-CSRF-Token header (preferred) or _csrf_token body field.
 *
 * Usage in routes.php:
 *   $csrfProtection = new CsrfMiddleware();
 *   $router->post('/api/contact', [ContactController::class, 'store'], [$csrfProtection]);
 *
 * The client must first obtain a token via GET /api/csrf-token.
 */
final class CsrfMiddleware implements Middleware
{
    /**
     * Validate CSRF token on state-changing requests.
     */
    public function handle(Request $request): void
    {
        // Only validate state-changing methods
        $method = $request->method();
        if (!in_array($method, ['POST', 'PUT', 'DELETE', 'PATCH'], true)) {
            return;
        }

        $token = CsrfProtection::getTokenFromRequest($request);

        if (!CsrfProtection::validateToken($token)) {
            Response::error('Invalid or expired CSRF token.', 403);
        }
    }
}
