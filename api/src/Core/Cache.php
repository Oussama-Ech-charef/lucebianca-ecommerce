<?php

namespace App\Core;

/**
 * Cache — simple file-based cache for frequently accessed data.
 *
 * Reduces database load by caching expensive queries (product listings,
 * category trees, etc.). Uses TTL-based expiration.
 */
final class Cache
{
    private string $cacheDir;
    private int $defaultTtl;

    public function __construct(?string $cacheDir = null, int $defaultTtl = 300)
    {
        $this->cacheDir = $cacheDir ?? __DIR__ . '/../../storage/cache';
        $this->defaultTtl = $defaultTtl;

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
    }

    /**
     * Get cached value by key.
     *
     * @return mixed|null Cached value or null if expired/missing
     */
    public function get(string $key)
    {
        $file = $this->getFilePath($key);

        if (!file_exists($file)) {
            return null;
        }

        $data = @unserialize(file_get_contents($file));

        if ($data === false || !is_array($data)) {
            return null;
        }

        // Check expiration
        if ($data['expires_at'] < time()) {
            @unlink($file);
            return null;
        }

        return $data['value'];
    }

    /**
     * Set cache value with optional TTL.
     *
     * @param string $key Cache key
     * @param mixed $value Value to cache
     * @param int|null $ttl Time to live in seconds (null = default)
     */
    public function set(string $key, $value, ?int $ttl = null): void
    {
        $ttl = $ttl ?? $this->defaultTtl;
        $file = $this->getFilePath($key);

        $data = [
            'value' => $value,
            'expires_at' => time() + $ttl,
        ];

        @file_put_contents($file, serialize($data), LOCK_EX);
    }

    /**
     * Delete cached value.
     */
    public function delete(string $key): void
    {
        $file = $this->getFilePath($key);
        @unlink($file);
    }

    /**
     * Clear all cache.
     */
    public function clear(): void
    {
        $files = glob($this->cacheDir . '/*.cache');
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    /**
     * Get or set cached value (lazy evaluation).
     *
     * @param string $key Cache key
     * @param callable $callback Function to call if cache miss
     * @param int|null $ttl Time to live in seconds
     * @return mixed Cached or freshly computed value
     */
    public function remember(string $key, callable $callback, ?int $ttl = null)
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Get cache file path for key.
     */
    private function getFilePath(string $key): string
    {
        $hash = md5($key);
        return $this->cacheDir . '/' . $hash . '.cache';
    }
}
