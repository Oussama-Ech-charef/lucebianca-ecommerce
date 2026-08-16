<?php

namespace App\Core;

/**
 * Loads KEY=VALUE pairs from the .env file and exposes them globally.
 *
 * Secret values (DB credentials, JWT secret) live in .env, which is
 * gitignored. This is the single entry point for environment variables
 * across the API.
 */
final class Env
{
    private static array $values = [];

    /** Loads the .env file once (idempotent). */
    public static function load(string $filePath): void
    {
        if (self::$values !== [] || !is_file($filePath)) {
            return;
        }

        foreach (file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = array_map('trim', explode('=', $line, 2));
            $value = trim($value, " \t\n\r\0\x0B\"'");
            self::$values[$key] = $value;
        }
    }

    /**
     * Returns an environment value or the given default.
     *
     * @param string $key     Variable name (e.g. "DB_HOST").
     * @param mixed  $default Returned when the variable is not set.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$values[$key] ?? $default;
    }

    /** True when the stored APP_ENV is "production". */
    public static function isProduction(): bool
    {
        return self::get('APP_ENV') === 'production';
    }
}