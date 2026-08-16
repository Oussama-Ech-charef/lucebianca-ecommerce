<?php

namespace App\Core;

use PDO;

/**
 * Database (Singleton) — a single shared PDO connection.
 *
 * Security requirements (section 3.1):
 *  - PDO only, with ERRMODE_EXCEPTION so every failure is a catchable exception.
 *  - Prepared Statements via bindParam/bindValue live in the Repositories.
 *  - One shared connection instead of one per query.
 *
 * Returns the same instance for the whole request lifecycle.
 */
final class Database
{
    private static ?PDO $connection = null;

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    /**
     * Returns the single shared PDO instance.
     */
    public static function connection(): PDO
    {
        if (self::$connection instanceof PDO) {
            return self::$connection;
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $name = Env::get('DB_NAME', 'lucebianca');
        $user = Env::get('DB_USER', 'root');
        $pass = (string) (Env::get('DB_PASS') ?? '');

        // utf8mb4: full Arabic character support (schema requirement).
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $host,
            $port,
            $name
        );

        self::$connection = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ]);

        return self::$connection;
    }

    /** PDO instance directly, for Repositories. */
    public static function get(): PDO
    {
        return self::connection();
    }
}