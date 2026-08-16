<?php

namespace App\Core;

/**
 * RequestContext — in-memory storage shared during one request lifecycle.
 *
 * Middleware (e.g. AuthMiddleware) can drop data here (decoded JWT claims)
 * that controllers need later, without global variables.
 */
final class RequestContext
{
    /** @var array<string, mixed> */
    private static array $data = [];

    private function __construct()
    {
    }

    /**
     * Stores a value for the current request.
     */
    public static function set(string $key, mixed $value): void
    {
        self::$data[$key] = $value;
    }

    /**
     * Retrieves a stored value or the default.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$data[$key] ?? $default;
    }

    /** Clears all stored values (useful at the start of CLI/queue runs). */
    public static function clear(): void
    {
        self::$data = [];
    }
}