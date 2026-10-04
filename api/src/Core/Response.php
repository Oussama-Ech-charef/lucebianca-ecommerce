<?php

namespace App\Core;

/**
 * Response — helpers for producing JSON API responses.
 *
 * Ends the request by echoing and exiting; keeps formatting (content type,
 * HTTP status, structured error shape) consistent across the whole API.
 */
final class Response
{
    private function __construct()
    {
    }

    /**
     * Sends a JSON response and terminates the script.
     *
     * @param mixed $data       Payload returned to the client.
     * @param int   $status     HTTP status code.
     * @param string|null $requestId Optional correlation ID; when omitted the
     *                        front-door id from RequestContext is used so
     *                        EVERY response carries X-Request-Id.
     */
    public static function json(mixed $data, int $status = 200, ?string $requestId = null): never
    {
        $requestId ??= self::contextRequestId();
        if ($requestId !== null) {
            header('X-Request-Id: ' . $requestId);
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    /**
     * Sends an error in a consistent shape.
     *
     * @param string $message Human-readable error description.
     * @param int    $status  HTTP status code (4xx/5xx).
     * @param array  $extra   Optional additional fields (e.g. validation errors).
     * @param string|null $requestId Optional correlation ID to include in response headers.
     */
    public static function error(string $message, int $status = 400, array $extra = [], ?string $requestId = null): never
    {
        $payload = array_merge(['error' => $message], $extra);
        self::json($payload, $status, $requestId);
    }

    /**
     * 404 — used by the router when no route matches.
     */
    public static function notFound(?string $requestId = null): never
    {
        self::error('Route not found', 404, [], $requestId);
    }

    /**
     * 204 No Content — success with an intentionally empty body
     * (e.g. logout). No JSON is echoed.
     */
    public static function noContent(?string $requestId = null): never
    {
        $requestId ??= self::contextRequestId();
        if ($requestId !== null) {
            header('X-Request-Id: ' . $requestId);
        }
        http_response_code(204);
        exit;
    }

    /**
     * Front-door correlation id stored by Router::dispatch, if any.
     * Lets every response carry X-Request-Id without threading $request
     * through ~150 controller call sites.
     */
    private static function contextRequestId(): ?string
    {
        $id = RequestContext::get('request_id');

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * Sets an HttpOnly refresh-token cookie (safer than localStorage for
     * the long-lived credential — JS cannot read it, mitigating XSS theft).
     * Access JWTs stay in memory/localStorage (short-lived, sent as Bearer).
     *
     * @param string $name  Cookie name (lb_customer_refresh / lb_admin_refresh).
     * @param string $value Raw refresh token (empty + past expiry to clear).
     * @param int    $ttl   Seconds until expiry (0 = session, negative = delete).
     */
    public static function refreshCookie(string $name, string $value, int $ttl): void
    {
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        // SameSite=Lax keeps top-level navigation (CMI return, email links)
        // working while blocking third-party POST CSRF. HttpOnly blocks JS.
        $parts = [
            rawurlencode($name) . '=' . rawurlencode($value),
            'Path=/',
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($secure) {
            $parts[] = 'Secure';
        }
        if ($ttl > 0) {
            $parts[] = 'Max-Age=' . $ttl;
            $parts[] = 'Expires=' . gmdate('D, d M Y H:i:s T', time() + $ttl);
        } elseif ($ttl < 0) {
            $parts[] = 'Max-Age=0';
            $parts[] = 'Expires=Thu, 01 Jan 1970 00:00:00 GMT';
        }
        header('Set-Cookie: ' . implode('; ', $parts), false);
    }
}