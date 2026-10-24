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

        // Admin access tokens are bound to a live server-side session row.
        // Password changes revoke every admin_refresh_tokens row for the
        // account, so this check invalidates already-issued access tokens as
        // well as refresh tokens. It also covers deleted accounts (the FK
        // cascade removes their session rows). Tokens issued before this
        // binding existed have no "sid" claim and are rejected once; admins
        // simply sign in again to receive a bound token.
        if ($this->role === 'admin') {
            $adminId = (int) ($claims['sub'] ?? 0);
            $rawSid = $claims['sid'] ?? null;
            $sessionId = is_int($rawSid) || (is_string($rawSid) && ctype_digit($rawSid))
                ? (int) $rawSid
                : 0;

            $exists = false;
            if ($adminId > 0 && $sessionId > 0) {
                $stmt = Database::get()->prepare(
                    'SELECT 1
                       FROM admin_refresh_tokens t
                       INNER JOIN admins a ON a.id = t.admin_id
                      WHERE t.id = :sid
                        AND t.admin_id = :admin_id
                        AND t.revoked_at IS NULL
                        AND t.expires_at > NOW()
                      LIMIT 1'
                );
                $stmt->bindValue(':sid', $sessionId, \PDO::PARAM_INT);
                $stmt->bindValue(':admin_id', $adminId, \PDO::PARAM_INT);
                $stmt->execute();
                $exists = $stmt->fetchColumn() !== false;
                $stmt->closeCursor();
            }

            if (!$exists) {
                ErrorLogger::logEvent('warning', 'Authentication failure: admin session is missing, revoked, or expired', $request, [
                    'admin_id' => $adminId,
                ]);
                $requestId = ErrorLogger::currentRequestId($request);
                Response::error('Your admin session has ended. Please sign in again.', 401, [], $requestId);
            }
        }

        RequestContext::set('auth', $claims);
    }
}