<?php

namespace App\Core;

/**
 * CsrfProtection — CSRF token generation and validation.
 *
 * Tokens are generated using HMAC-SHA256 with a secret key, combined with a
 * timestamp for automatic expiration. This is a stateless approach (no session
 * storage required).
 *
 * Usage:
 *   // Generate token to send to client
 *   $token = CsrfProtection::generateToken();
 *
 *   // Validate token from client request
 *   if (!CsrfProtection::validateToken($token)) {
 *       // reject as CSRF attack
 *   }
 */
final class CsrfProtection
{
    private const TOKEN_LIFETIME = 3600; // 1 hour in seconds

    private function __construct()
    {
    }

    /**
     * Generate a CSRF token valid for TOKEN_LIFETIME seconds.
     *
     * @return string Base64-encoded token containing timestamp and signature
     */
    public static function generateToken(): string
    {
        $timestamp = time();
        $signature = self::sign($timestamp);

        // Encode timestamp:signature as base64 for clean transport
        return base64_encode($timestamp . ':' . $signature);
    }

    /**
     * Validate a CSRF token.
     *
     * @param string $token The token to validate
     * @return bool True if valid and not expired, false otherwise
     */
    public static function validateToken(string $token): bool
    {
        if ($token === '') {
            return false;
        }

        // Decode the token
        $decoded = base64_decode($token, true);
        if ($decoded === false) {
            return false;
        }

        // Split into timestamp and signature
        $parts = explode(':', $decoded, 2);
        if (count($parts) !== 2) {
            return false;
        }

        [$timestamp, $signature] = $parts;

        // Check timestamp is numeric
        if (!ctype_digit($timestamp)) {
            return false;
        }

        $timestamp = (int) $timestamp;

        // Check token hasn't expired
        if (time() - $timestamp > self::TOKEN_LIFETIME) {
            return false;
        }

        // Verify signature
        $expectedSignature = self::sign($timestamp);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Sign a timestamp with the secret key.
     *
     * @param int $timestamp Unix timestamp
     * @return string HMAC-SHA256 signature
     */
    private static function sign(int $timestamp): string
    {
        $secret = Env::get('JWT_SECRET'); // Reuse JWT secret for CSRF signing
        if ($secret === null) {
            throw new \RuntimeException('JWT_SECRET environment variable not set');
        }

        return hash_hmac('sha256', (string) $timestamp, $secret);
    }

    /**
     * Get CSRF token from request header or body.
     *
     * @param Request $request The request object
     * @return string The token, or empty string if not found
     */
    public static function getTokenFromRequest(Request $request): string
    {
        // Check X-CSRF-Token header first (preferred)
        $headerToken = $request->header('X-CSRF-Token');
        if ($headerToken !== null) {
            return $headerToken;
        }

        // Fallback to body field (for form submissions)
        $bodyToken = $request->input('_csrf_token');
        return $bodyToken !== null ? (string) $bodyToken : '';
    }
}
