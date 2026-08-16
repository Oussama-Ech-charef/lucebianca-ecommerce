<?php

/**
 * Database configuration — EXAMPLE.
 *
 * This file lives at api/config/database.example.php. The real values are
 * NOT committed to git (see .gitignore — "config/database.php" is ignored).
 * Create api/config/database.php from this template if you prefer hardcoded
 * credentials over the .env file. The simplest setup is to use .env only
 * and let App\Core\Database read from it — no file is needed here then.
 *
 * Expected structure if you create the real file:
 *
 *   return [
 *       'host' => '127.0.0.1',
 *       'port' => 3306,
 *       'database' => 'lucebianca',
 *       'username' => 'root',
 *       'password' => '',
 *       'charset'  => 'utf8mb4',
 *   ];
 */

return [
    'host' => getenv('DB_HOST') ?: '127.0.0.1',
    'port' => getenv('DB_PORT') ?: '3306',
    'database' => getenv('DB_NAME') ?: 'lucebianca',
    'username' => getenv('DB_USER') ?: 'root',
    'password' => getenv('DB_PASS') ?: '',
    'charset'  => 'utf8mb4',
];