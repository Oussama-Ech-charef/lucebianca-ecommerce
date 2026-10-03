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
     * Get the client's IP address, trusting X-Forwarded-For only from
     * configured trusted proxies. Otherwise REMOTE_ADDR is used so a
     * client cannot spoof the rate-limit identifier.
     *
     * Configure via TRUSTED_PROXIES (comma-separated IPs, e.g.
     * "10.0.0.1, 127.0.0.1"). Empty = never trust the header (direct
     * connections / local dev default, fail-closed).
     */
    private function getClientIp(Request $request): string
    {
        $remoteAddr = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';

        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor !== null && $this->isTrustedProxy($remoteAddr)) {
            // X-Forwarded-For can contain multiple IPs: "client, proxy1, proxy2"
            // Take the first one (leftmost) as the original client IP, but
            // only after validating it is a real IP address.
            $ips = array_map('trim', explode(',', $forwardedFor));
            foreach ($ips as $candidate) {
                if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                    return $candidate;
                }
            }
        }

        // Fallback to REMOTE_ADDR (direct connection) — validated, else 0.0.0.0.
        if (filter_var($remoteAddr, FILTER_VALIDATE_IP) === false) {
            return '0.0.0.0';
        }
        return $remoteAddr;
    }

    /**
     * Whether REMOTE_ADDR is a configured trusted proxy allowed to supply
     * X-Forwarded-For. Exact match on trimmed entries; supports CIDR /24, /16
     * via prefix check for private ranges when configured that way.
     */
    private function isTrustedProxy(string $remoteAddr): bool
    {
        $configured = Env::get('TRUSTED_PROXIES', '');
        if (!is_string($configured) || trim($configured) === '') {
            return false;
        }
        $trusted = array_filter(array_map('trim', explode(',', $configured)));
        if ($trusted === []) {
            return false;
        }
        foreach ($trusted as $entry) {
            if ($entry === $remoteAddr) {
                return true;
            }
            // CIDR support (e.g. 10.0.0.0/8, 192.168.1.0/24).
            if (str_contains($entry, '/')) {
                [$subnet, $bits] = explode('/', $entry, 2) + [null, null];
                if ($subnet !== null && $bits !== null && filter_var($subnet, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) && filter_var($remoteAddr, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                    $mask = (int) $bits;
                    if ($mask >= 0 && $mask <= 32) {
                        $subnetLong = ip2long($subnet);
                        $addrLong = ip2long($remoteAddr);
                        if ($subnetLong !== false && $addrLong !== false) {
                            $maskLong = $mask === 0 ? 0 : (~0 << (32 - $mask));
                            if (($subnetLong & $maskLong) === ($addrLong & $maskLong)) {
                                return true;
                            }
                        }
                    }
                }
            }
        }
        return false;
    }
}
