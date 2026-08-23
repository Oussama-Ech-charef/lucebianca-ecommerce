<?php

/**
 * Test-only front controller for the PHP built-in server.
 *
 * Used exclusively by tests/run-api-tests.php, which starts:
 *     php -S 127.0.0.1:<port> tests/server-index.php
 *
 * The runner provides an ISOLATED environment file (temporary .env pointing
 * at a throwaway database with external services disabled) via the
 * LB_TEST_ENV_FILE environment variable. It must be loaded BEFORE
 * bootstrap.php — Env::load() is idempotent (first load wins), so the real
 * api/.env is never read during tests.
 */

declare(strict_types=1);

$envFile = getenv('LB_TEST_ENV_FILE');
if (!is_string($envFile) || $envFile === '' || !is_file($envFile)) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Test harness misconfigured: LB_TEST_ENV_FILE missing.']);
    exit;
}

require __DIR__ . '/../src/autoload.php';

App\Core\Env::load($envFile);

require __DIR__ . '/../bootstrap.php';
require __DIR__ . '/../routes.php'; // registers the REAL routes and dispatches
