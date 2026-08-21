<?php

namespace App\Core;

/**
 * RateLimitMiddleware — enforces rate limits on endpoints.
 *
 * Checks the request against a configured rate limit and returns 429 Too Many
 * Requests if exceeded. Uses the client's IP address as the identifier.
 *
 * Usage in routes.php:
 *   $limiter = new RateLimitMiddleware('login', 5, 300);
 *   $router->post('/api/auth/login', [AuthController::class, 'login'], [$limiter]);
 */
final class RateLimitMiddleware implements Middleware
{
    private RateLimiter $limiter;

    /**
     * @param string $key Unique identifier for this limit
     * @param int $maxAttempts Maximum allowed attempts within the window
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(string $key, int $maxAttempts, int $windowSeconds)
    {
        $this->limiter = new RateLimiter($key, $maxAttempts, $windowSeconds);
    }

    /**
     * Check rate limit before processing request.
     */
    public function handle(Request $request): void
    {
        $ip = $this->getClientIp($request);

        if (!$this->limiter->attempt($ip)) {
            $retryAfter = $this->limiter->getRetryAfter($ip);

            // Set Retry-After header before sending response
            header('Retry-After: ' . $retryAfter);

            Response::json([
                'error' => 'Too many requests. Please try again later.',
                'retry_after' => $retryAfter,
            ], 429);
        }
    }

    /**
     * Get the client's IP address, respecting reverse proxy headers.
     */
    private function getClientIp(Request $request): string
    {
        // In production behind a reverse proxy (nginx, Cloudflare), check X-Forwarded-For
        // Security: Only trust this header if you control the reverse proxy
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor !== null) {
            // X-Forwarded-For can contain multiple IPs: "client, proxy1, proxy2"
            // Take the first one (leftmost) as the original client IP
            $ips = array_map('trim', explode(',', $forwardedFor));
            if (!empty($ips)) {
                return $ips[0];
            }
        }

        // Fallback to REMOTE_ADDR (direct connection)
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}
