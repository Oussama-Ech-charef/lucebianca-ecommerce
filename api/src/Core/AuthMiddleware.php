<?php

namespace App\Core;

use RuntimeException;

/**
 * AuthMiddleware — protects routes behind a valid JWT.
 *
 * Reads the "Authorization: Bearer <token>" header, verifies the token,
 * and attaches the decoded claims to the request so controllers can read
 * the authenticated user id / role without re-verifying.
 *
 * Pass "admin" as the required role to restrict a route to admins only.
 */
final class AuthMiddleware implements Middleware
{
    private ?string $role;

    public function __construct(?string $role = null)
    {
        $this->role = $role;
    }

    /**
     * Verifies the bearer token and optionally enforces a role.
     */
    public function handle(Request $request): void
    {
        $token = $request->bearerToken();
        if ($token === null) {
            Response::error('Authentication required.', 401);
        }

        try {
            $claims = Auth::verify($token);
        } catch (RuntimeException) {
            Response::error('Invalid or expired token.', 401);
        }

        if ($this->role !== null && ($claims['role'] ?? '') !== $this->role) {
            Response::error('Insufficient permissions.', 403);
        }

        RequestContext::set('auth', $claims);
    }
}