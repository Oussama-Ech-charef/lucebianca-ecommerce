<?php

namespace App\Core;

/**
 * RateLimiter — file-based rate limiting to prevent brute-force attacks.
 *
 * Tracks request counts per IP address within a sliding time window. Uses
 * file-based storage (storage/rate-limits/) for simplicity; Redis would be
 * a drop-in replacement for high-traffic scenarios.
 *
 * Concurrency: every read-modify-write cycle runs under an exclusive
 * advisory lock (`flock(LOCK_EX)`) on the bucket file, so concurrent
 * requests cannot interleave reads and overwrite one another's updates
 * (lost updates would silently let more than maxAttempts through). Lock or
 * I/O failures fail CLOSED (the attempt is denied) rather than silently
 * disabling the limit. Storage format (JSON array of timestamps) is
 * unchanged.
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

        // Optional override (used by the test suite for isolated buckets);
        // defaults to the shared runtime directory.
        $configured = Env::get('RATE_LIMIT_STORAGE_DIR', '');
        $this->storageDir = is_string($configured) && $configured !== ''
            ? $configured
            : __DIR__ . '/../../storage/rate-limits';

        if (!is_dir($this->storageDir)) {
            mkdir($this->storageDir, 0755, true);
        }
    }

    /**
     * Attempt to record a request. Returns false if rate limit exceeded.
     *
     * Atomic across read, prune, check, and write: the bucket file is held
     * under LOCK_EX for the whole cycle. Returns false (denied) on any
     * lock or I/O failure so a broken store can never silently disable the
     * limit. Denied attempts are not recorded, so Retry-After still points
     * at the oldest recorded attempt's expiry.
     *
     * @param string $identifier Usually the client IP address
     * @return bool True if request is allowed, false if limit exceeded
     */
    public function attempt(string $identifier): bool
    {
        $handle = $this->openLocked($this->getFilePath($identifier), LOCK_EX);
        if ($handle === null) {
            return false;
        }

        try {
            $now = time();
            $attempts = $this->readLocked($handle);

            // Remove expired attempts (outside the window)
            $cutoff = $now - $this->windowSeconds;
            $attempts = array_values(array_filter($attempts, fn($timestamp) => $timestamp > $cutoff));

            // Check if limit exceeded
            if (count($attempts) >= $this->maxAttempts) {
                return false;
            }

            // Record this attempt
            $attempts[] = $now;
            if (!$this->writeLocked($handle, $attempts)) {
                return false;
            }

            return true;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Get the number of seconds until the rate limit resets.
     *
     * Read under a shared lock for a consistent snapshot. Informational
     * only (drives the Retry-After header), so I/O failure returns 0
     * rather than denying anything.
     *
     * @param string $identifier Usually the client IP address
     * @return int Seconds until oldest attempt expires (0 if not limited)
     */
    public function getRetryAfter(string $identifier): int
    {
        $handle = $this->openLocked($this->getFilePath($identifier), LOCK_SH);
        if ($handle === null) {
            return 0;
        }

        try {
            $attempts = $this->readLocked($handle);
            if (empty($attempts)) {
                return 0;
            }

            $now = time();
            $cutoff = $now - $this->windowSeconds;
            $attempts = array_filter($attempts, fn($timestamp) => $timestamp > $cutoff);

            if (count($attempts) < $this->maxAttempts) {
                return 0;
            }

            // Time until the oldest attempt expires
            $oldest = min($attempts);
            return max(0, ($oldest + $this->windowSeconds) - $now);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * Clear all attempts for an identifier (e.g., after successful login).
     *
     * Truncates under an exclusive lock instead of unlinking, so a
     * concurrent attempt() holding the same file cannot write to an
     * unlinked inode and lose its update.
     *
     * @param string $identifier Usually the client IP address
     */
    public function clear(string $identifier): void
    {
        $handle = $this->openLocked($this->getFilePath($identifier), LOCK_EX);
        if ($handle === null) {
            return;
        }

        try {
            ftruncate($handle, 0);
            fflush($handle);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
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
     * Opens a bucket file and acquires an advisory lock, blocking until the
     * lock is available. Mode 'c+' creates the file if missing without
     * truncating it.
     *
     * @param int $lock LOCK_EX for read-modify-write, LOCK_SH for reads.
     * @return resource|null Locked file handle, or null on any failure.
     */
    private function openLocked(string $file, int $lock)
    {
        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return null;
        }
        if (!flock($handle, $lock)) {
            fclose($handle);
            return null;
        }

        return $handle;
    }

    /**
     * Reads attempts from an already-locked bucket handle.
     *
     * @param resource $handle Locked file handle positioned anywhere.
     * @return int[] Array of timestamps (empty on missing/corrupt data).
     */
    private function readLocked($handle): array
    {
        rewind($handle);
        $contents = stream_get_contents($handle);
        if ($contents === false || $contents === '') {
            return [];
        }

        $data = json_decode($contents, true);
        return is_array($data) ? array_values($data) : [];
    }

    /**
     * Atomically replaces a locked bucket's contents (truncate + write +
     * flush while still holding the lock).
     *
     * @param resource $handle Locked file handle.
     * @param int[] $attempts Array of timestamps.
     */
    private function writeLocked($handle, array $attempts): bool
    {
        $encoded = json_encode(array_values($attempts));
        if ($encoded === false) {
            return false;
        }

        rewind($handle);
        if (!ftruncate($handle, 0)) {
            return false;
        }
        if (fwrite($handle, $encoded) === false) {
            return false;
        }

        return fflush($handle);
    }

}
