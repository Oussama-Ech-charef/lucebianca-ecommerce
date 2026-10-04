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
            ErrorLogger::logEvent('warning', 'Authentication failure: missing bearer token', $request, [
                'required_role' => $this->role,
            ]);
            $requestId = ErrorLogger::currentRequestId($request);
            Response::error('Authentication required.', 401, [], $requestId);
        }

        try {
            $claims = Auth::verify($token);
        } catch (RuntimeException) {
            // Never log the token itself — only that verification failed.
            ErrorLogger::logEvent('warning', 'Authentication failure: invalid or expired token', $request, [
                'required_role' => $this->role,
            ]);
            $requestId = ErrorLogger::currentRequestId($request);
            Response::error('Invalid or expired token.', 401, [], $requestId);
        }

        if ($this->role !== null && ($claims['role'] ?? '') !== $this->role) {
            ErrorLogger::logEvent('warning', 'Authorization failure: insufficient role', $request, [
                'required_role' => $this->role,
            ]);
            $requestId = ErrorLogger::currentRequestId($request);
            Response::error('Insufficient permissions.', 403, [], $requestId);
        }

        RequestContext::set('auth', $claims);
    }
}