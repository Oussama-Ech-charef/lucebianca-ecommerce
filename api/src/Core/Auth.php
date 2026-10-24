<?php

namespace App\Core;

/**
 * Auth — stateless JWT (HS256) handling.
 *
 * Issues and verifies access + refresh tokens. Does NOT depend on an
 * external library: token structure follows RFC 7519 and signatures are
 * HMAC-SHA256 over base64url(header).base64url(payload). The signing
 * secret comes from the gitignored .env (JWT_SECRET).
 */
final class Auth
{
    private const HEADER = ['alg' => 'HS256', 'typ' => 'JWT'];

    private function __construct()
    {
    }

    /**
     * Issues a signed JWT.
     *
     * @param int      $userId    Identifier stored in the "sub" claim.
     * @param string   $role      "user" or "admin" (used for admin route protection).
     * @param int      $ttl       Lifetime in seconds.
     * @param int|null $sessionId Optional server-side session id stored in
     *                            the "sid" claim. Admin access tokens use the
     *                            admin_refresh_tokens row so a revoked session
     *                            also invalidates the access token.
     *
     * @return string The signed token.
     */
    public static function issue(int $userId, string $role = 'user', int $ttl = 3600, ?int $sessionId = null): string
    {
        $now = time();
        $payload = [
            'sub'  => $userId,
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + $ttl,
        ];
        if ($sessionId !== null) {
            $payload['sid'] = $sessionId;
        }

        return self::sign($payload);
    }

    /**
     * Verifies a token's signature and claims.
     *
     * Validates header `alg` (must be HS256), `sub` (>0), `role` (user/admin),
     * `iat`/`exp` (exp must be future, iat not in future, exp > iat). Fails
     * closed when JWT_SECRET is missing/weak (via getSecret()).
     *
     * @return array<string, mixed> Decoded payload claims on success.
     *
     * @throws \RuntimeException When the token is malformed, signature invalid, or claims invalid.
     */
    public static function verify(string $token): array
    {
        [$header, $payload, $signature] = self::split($token);

        // Validate header before signature (alg must be HS256, typ JWT).
        $headerData = self::decodePayload($header);
        if (($headerData['alg'] ?? null) !== 'HS256' || ($headerData['typ'] ?? null) !== 'JWT') {
            throw new \RuntimeException('Invalid token header.');
        }

        $expected = self::signature($header, $payload);

        // hash_equals: constant-time comparison, safe against timing attacks.
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid token signature.');
        }

        $claims = self::decodePayload($payload);

        // Required claims: sub, role, iat, exp
        if (!isset($claims['sub']) || !is_numeric($claims['sub']) || (int) $claims['sub'] < 1) {
            throw new \RuntimeException('Invalid token subject.');
        }
        if (!isset($claims['role']) || !in_array($claims['role'], ['user', 'admin'], true)) {
            throw new \RuntimeException('Invalid token role.');
        }
        if (!isset($claims['iat']) || !is_numeric($claims['iat'])) {
            throw new \RuntimeException('Invalid token issued-at.');
        }
        if (!isset($claims['exp']) || !is_numeric($claims['exp'])) {
            throw new \RuntimeException('Invalid token expiry.');
        }

        $iat = (int) $claims['iat'];
        $exp = (int) $claims['exp'];
        $now = time();

        // iat must not be in the future (allow 60s clock skew), exp must be > iat and future.
        if ($iat > $now + 60) {
            throw new \RuntimeException('Token issued in the future.');
        }
        if ($exp <= $iat) {
            throw new \RuntimeException('Token expiry must be after issued-at.');
        }
        if ($exp < $now) {
            throw new \RuntimeException('Token has expired.');
        }

        return $claims;
    }

    /**
     * Builds the base64url header.payload.signature triple.
     */
    private static function sign(array $payload): string
    {
        $header  = self::encodePayload(self::HEADER);
        $payload = self::encodePayload($payload);
        $sig     = self::signature($header, $payload);

        return "{$header}.{$payload}.{$sig}";
    }

    private static function getSecret(): string
    {
        $secret = Env::get('JWT_SECRET');
        if (!is_string($secret) || trim($secret) === '') {
            throw new \RuntimeException('JWT_SECRET is not configured — failing closed.');
        }
        $trimmed = trim($secret);
        // Reject default/placeholder and short secrets (would allow brute force or accidental deploy with dev value).
        $weak = ['insecure-default-secret', 'change-me', 'secret', 'changeme'];
        if (in_array(strtolower($trimmed), $weak, true) || mb_strlen($trimmed) < 32) {
            throw new \RuntimeException('JWT_SECRET is missing or too weak — failing closed (need ≥32 random chars).');
        }
        return $trimmed;
    }

    private static function signature(string $header, string $payload): string
    {
        $secret = self::getSecret();
        $data   = $header . '.' . $payload;

        return self::base64UrlEncode(hash_hmac('sha256', $data, $secret, true));
    }

    /**
     * @return array{0:string,1:string,2:string} [header, payload, signature]
     */
    private static function split(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Malformed token.');
        }

        return $parts;
    }

    private static function encodePayload(array $payload): string
    {
        return self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    private static function decodePayload(string $encoded): array
    {
        $json = base64_decode(strtr($encoded, '-_', '+/'), true);
        if ($json === false) {
            throw new \RuntimeException('Malformed token payload.');
        }

        $data = json_decode($json, true);

        return is_array($data) ? $data : [];
    }

    private static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}