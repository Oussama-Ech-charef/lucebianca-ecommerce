<?php

namespace App\Core;

/**
 * RateLimiter — file-based rate limiting to prevent brute-force attacks.
 *
 * Tracks request counts per IP address within a sliding time window. Uses
 * file-based storage (storage/rate-limits/) for simplicity; Redis would be
 * a drop-in replacement for high-traffic scenarios.
 *
 * Usage:
 *   $limiter = new RateLimiter('login', 5, 300); // 5 attempts per 5 minutes
 *   if (!$limiter->attempt($ip)) {
 *       // respond with 429 Too Many Requests
 *   }
 */
final class RateLimiter
{
    private string $key;
    private int $maxAttempts;
    private int $windowSeconds;
    private string $storageDir;

    /**
     * @param string $key Unique identifier for this limit (e.g., 'login', 'register', 'contact')
     * @param int $maxAttempts Maximum allowed attempts within the window
     * @param int $windowSeconds Time window in seconds
     */
    public function __construct(string $key, int $maxAttempts, int $windowSeconds)
    {
        $this->key = $key;
        $this->maxAttempts = $maxAttempts;
        $this->windowSeconds = $windowSeconds;
        $this->storageDir = __DIR__ . '/../../storage/rate-limits';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Attempt to record a request. Returns false if rate limit exceeded.
     *
     * @param string $identifier Usually the client IP address
     * @return bool True if request is allowed, false if limit exceeded
     */
    public function attempt(string $identifier): bool
    {
        $file = $this->getFilePath($identifier);
        $now = time();

        // Read existing attempts
        $attempts = $this->readAttempts($file);

        // Remove expired attempts (outside the window)
        $cutoff = $now - $this->windowSeconds;
        $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);

        // Check if limit exceeded
        if (count($attempts) >= $this->maxAttempts) {
            return false;
        }

        // Record this attempt
        $attempts[] = $now;
        $this->writeAttempts($file, $attempts);

        return true;
    }

    /**
     * Get the number of seconds until the rate limit resets.
     *
     * @param string $identifier Usually the client IP address
     * @return int Seconds until oldest attempt expires (0 if not limited)
     */
    public function getRetryAfter(string $identifier): int
    {
        $file = $this->getFilePath($identifier);
        $now = time();

        $attempts = $this->readAttempts($file);
        if (empty($attempts)) {
            return 0;
        }

        $cutoff = $now - $this->windowSeconds;
        $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);

        if (count($attempts) < $this->maxAttempts) {
            return 0;
        }

        // Time until the oldest attempt expires
        $oldest = min($attempts);
        return max(0, ($oldest + $this->windowSeconds) - $now);
    }

    /**
     * Clear all attempts for an identifier (e.g., after successful login).
     *
     * @param string $identifier Usually the client IP address
     */
    public function clear(string $identifier): void
    {
        $file = $this->getFilePath($identifier);
        if (file_exists($file)) {
            unlink($file);
        }
    }

    /**
     * Get the file path for storing attempts.
     */
    private function getFilePath(string $identifier): string
    {
        $hash = hash('sha256', $this->key . ':' . $identifier);
        return $this->storageDir . '/' . $hash . '.json';
    }

    /**
     * Read attempts from file.
     *
     * @return int[] Array of timestamps
     */
    private function readAttempts(string $file): array
    {
        if (!file_exists($file)) {
            return [];
        }

        $contents = file_get_contents($file);
        if ($contents === false) {
            return [];
        }

        $data = json_decode($contents, true);
        return is_array($data) ? $data : [];
    }

    /**
     * Write attempts to file.
     *
     * @param int[] $attempts Array of timestamps
     */
    private function writeAttempts(string $file, array $attempts): void
    {
        file_put_contents($file, json_encode($attempts), LOCK_EX);
    }
}
