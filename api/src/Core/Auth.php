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
     * @param int   $userId   Identifier stored in the "sub" claim.
     * @param string $role    "user" or "admin" (used for admin route protection).
     * @param int   $ttl      Lifetime in seconds.
     *
     * @return string The signed token.
     */
    public static function issue(int $userId, string $role = 'user', int $ttl = 3600): string
    {
        $now = time();
        $payload = [
            'sub'  => $userId,
            'role' => $role,
            'iat'  => $now,
            'exp'  => $now + $ttl,
        ];

        return self::sign($payload);
    }

    /**
     * Verifies a token's signature and expiry.
     *
     * @return array<string, mixed> Decoded payload claims on success.
     *
     * @throws \RuntimeException When the token is expired or the signature is invalid.
     */
    public static function verify(string $token): array
    {
        [$header, $payload, $signature] = self::split($token);

        $expected = self::signature($header, $payload);

        // hash_equals: constant-time comparison, safe against timing attacks.
        if (!hash_equals($expected, $signature)) {
            throw new \RuntimeException('Invalid token signature.');
        }

        $claims = self::decodePayload($payload);

        if (isset($claims['exp']) && (int) $claims['exp'] < time()) {
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

    private static function signature(string $header, string $payload): string
    {
        $secret = (string) Env::get('JWT_SECRET', 'insecure-default-secret');
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