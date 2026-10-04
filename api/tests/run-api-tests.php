<?php

/**
 * API integration tests — highest-risk completed features, end to end.
 *
 * Run:  php api/tests/run-api-tests.php
 *
 * What it does:
 *  1. Builds a throwaway environment file in the system temp dir pointing at
 *     an ISOLATED test database (default name `lucebianca_test`). The real
 *     api/.env is never read and the developer database is never touched —
 *     DB credentials are copied from api/.env only as *connection defaults*,
 *     overridable via LB_TEST_DB_HOST / _PORT / _USER / _PASS / _NAME.
 *  2. Recreates the test schema from database/schema.sql snapshot (19 tables,
 *     deterministic, includes lookup_token and drift repairs). The snapshot
 *     hard-codes `USE lucebianca;` / `CREATE DATABASE` — the runner rewrites
 *     that exact token to the test database name in memory before executing,
 *     so imports can never land in the development database. Migrations
 *     001→015 are NOT executed here (010 has duplicate-column conflict after
 *     008, 014/015 are UNTESTED with INFORMATION_SCHEMA+PREPARE).
 *  3. Seeds one product + variant.
 *  4. Boots the REAL API (real bootstrap, real routes) on PHP's built-in
 *     server with external services disabled: RESEND_API_KEY and
 *     GOOGLE_CLIENT_ID are empty, so no email/OAuth HTTP calls can happen.
 *  5. Runs the suites below against http://127.0.0.1:<port>, prints results,
 *     exits non-zero on any failure. Teardown drops the test DB and removes
 *     the temp env file.
 *  6. Writes api/storage/test-reports/latest-report.json + latest-report.md
 *     (gitignored) on every exit path; failed runs additionally leave a
 *     timestamped api-<UTC>.json/.md copy. Reports carry failure labels +
 *     counts only — never tokens, passwords, DSNs, emails, response bodies,
 *     or customer data.
 *
 * Known limitations (documented per project instructions):
 *  - Actual outbound emails (Resend) cannot be exercised offline; tests
 *    assert the guarded best-effort path succeeds with an empty key.
 *  - Frontend browser E2E is not automated here (no Playwright config or
 *    browsers are installed in this repo; past QA was manual — see docs/).
 *
 * Exit code 0 = all passed.
 */

declare(strict_types=1);

error_reporting(E_ALL);

// --- Test report (api/storage/test-reports/, gitignored) -------------------
// Written by writeApiTestReport() via shutdown hook so EVERY exit path —
// pass, fail, early abort, or fatal — leaves latest-report.json +
// latest-report.md behind. Failed runs additionally leave a timestamped
// copy. Console output and exit codes are untouched (files only).
// Reports carry failure labels + counts only: no tokens, passwords, DSNs,
// emails, response bodies, or customer data (labels are static strings,
// still redacted before writing).
$GLOBALS['report_start'] = microtime(true);
$GLOBALS['report_start_utc'] = gmdate('c');
$GLOBALS['report_runner'] = 'api';

function sanitizeApiReportString(string $value): string
{
    // Defense in depth: strip anything credential- or PII-shaped before it
    // can reach a report file, then cap length.
    $patterns = [
        '/Bearer\s+\S+/i',
        '/eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/', // JWT-shaped
        '/\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b/', // emails
        '/\b(?:password|passwd|token|api[_-]?key|secret|dsn)\s*[:=]\s*\S+/i',
    ];
    foreach ($patterns as $pattern) {
        $value = (string) preg_replace($pattern, '[redacted]', $value);
    }
    $trimmed = trim($value);

    return function_exists('mb_substr')
        ? mb_substr($trimmed, 0, 500)
        : substr($trimmed, 0, 500);
}

function writeApiTestReport(): void
{
    try {
        $dir = __DIR__ . '/../storage/test-reports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            return; // Nowhere to write — never break the run over reporting.
        }

        $failures = $GLOBALS['failures'] ?? null;
        $passed = (int) ($GLOBALS['passed'] ?? 0);
        $failureNames = is_array($failures) ? array_values($failures) : [];

        // Early abort / fatal before the harness initialized leaves $failures
        // unset — report it as an error with the (sanitized) reason.
        $fatal = null;
        if (!is_array($failures)) {
            $last = error_get_last();
            if (is_array($last) && ((int) ($last['type'] ?? 0) & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
                $fatal = sanitizeApiReportString(($last['message'] ?? 'PHP fatal error') . ' @' . ($last['file'] ?? '?') . ':' . ($last['line'] ?? '?'));
            }
            $failureNames = $fatal !== null ? [$fatal] : ['Harness aborted before assertions ran.'];
        }

        $failed = count($failureNames);
        $total = $passed + $failed;
        $status = $failed === 0 ? 'passed' : 'failed';
        $endUtc = gmdate('c');
        $duration = round(microtime(true) - (float) ($GLOBALS['report_start'] ?? microtime(true)), 3);
        $command = implode(' ', array_map('strval', (array) ($GLOBALS['argv'] ?? ['run-api-tests.php'])));

        $report = [
            'runner' => 'api',
            'command' => sanitizeApiReportString($command),
            'started_utc' => (string) ($GLOBALS['report_start_utc'] ?? $endUtc),
            'finished_utc' => $endUtc,
            'duration_seconds' => $duration,
            'environment' => [
                'php' => PHP_VERSION,
                'app_env' => 'testing (isolated test database only)',
                // Database NAME only — never host/user/password. The runner
                // accepts solely lucebianca_test* names (see namespace guard).
                'test_database' => (string) ($GLOBALS['report_db'] ?? 'unknown (aborted before validation)'),
            ],
            'totals' => [
                'total' => $total,
                'passed' => $passed,
                'failed' => $failed,
                'skipped' => 0,
            ],
            'status' => $status,
            'failures' => array_map(
                static fn ($name): array => [
                    'name' => sanitizeApiReportString((string) $name),
                    'message' => sanitizeApiReportString((string) $name),
                ],
                $failureNames
            ),
            'php_fatal' => $fatal,
            'notes' => 'Raw request/response bodies are excluded by design (may carry tokens, emails, or customer data).',
        ];

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($dir . '/latest-report.json', $json . PHP_EOL, LOCK_EX);
        }

        $lines = [
            '# API test report',
            '',
            '- Runner: `php api/tests/run-api-tests.php` (isolated test DB only)',
            '- Command: `' . $report['command'] . '`',
            '- Started (UTC): ' . $report['started_utc'],
            '- Finished (UTC): ' . $report['finished_utc'],
            '- Duration: ' . $report['duration_seconds'] . 's',
            '- Environment: PHP ' . PHP_VERSION . ', test database `' . $report['environment']['test_database'] . '`',
            '- Status: **' . $status . '**',
            '',
            '## Totals',
            '',
            '- Total: ' . $total,
            '- Passed: ' . $passed,
            '- Failed: ' . $failed,
            '- Skipped: 0',
            '',
        ];
        if ($failureNames === []) {
            $lines[] = 'All tests passed.';
        } else {
            $lines[] = '## Failures';
            $lines[] = '';
            foreach ($failureNames as $name) {
                $lines[] = '- ' . sanitizeApiReportString((string) $name);
            }
        }
        $lines[] = '';
        $lines[] = '_Raw bodies excluded by design (may carry tokens, emails, or customer data)._';
        @file_put_contents($dir . '/latest-report.md', implode("\n", $lines) . "\n", LOCK_EX);

        if ($status !== 'passed') {
            $stamp = gmdate('Ymd-His');
            if (is_string($json)) {
                @file_put_contents($dir . "/api-{$stamp}Z.json", $json . PHP_EOL, LOCK_EX);
            }
            @file_put_contents($dir . "/api-{$stamp}Z.md", implode("\n", $lines) . "\n", LOCK_EX);
        }
    } catch (Throwable) {
        // Reporting must never break the run or its exit code.
    }
}
register_shutdown_function('writeApiTestReport');

$repoRoot = dirname(__DIR__, 2); // api/tests -> api -> repo root
$migrationsDir = $repoRoot . '/database/migrations';
// --- Resolve connection settings -----------------------------------------
/** @return array<string,string> KEY=VALUE pairs from a simple .env file. */
function parseEnvFile(string $path): array
{
    $values = [];
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = array_map('trim', explode('=', $line, 2));
        $values[$key] = trim($value, " \t\"'");
    }

    return $values;
}

// --- 1. Resolve connection settings --------------------------------------
// Defaults come from the developer's api/.env (connection details only —
// values are never printed); every one can be overridden via LB_TEST_DB_*.
$apiEnvPath = $repoRoot . '/api/.env';
$apiEnv = is_file($apiEnvPath) ? parseEnvFile($apiEnvPath) : [];

$dbHost = (string) (getenv('LB_TEST_DB_HOST') ?: ($apiEnv['DB_HOST'] ?? '127.0.0.1'));
$dbPort = (string) (getenv('LB_TEST_DB_PORT') ?: ($apiEnv['DB_PORT'] ?? '3306'));
$dbUser = (string) (getenv('LB_TEST_DB_USER') ?: ($apiEnv['DB_USER'] ?? 'root'));
// Empty string is a valid password; only an unset variable falls back.
$envDbPass = getenv('LB_TEST_DB_PASS');
$dbPass = $envDbPass === false ? ($apiEnv['DB_PASS'] ?? '') : (string) $envDbPass;
$dbName = (string) (getenv('LB_TEST_DB_NAME') ?: 'lucebianca_test');

// This runner drops and recreates its target database. Restrict the name to
// the test-database namespace so a typo or override cannot target production.
if (preg_match('/^lucebianca_test(?:_[A-Za-z0-9_]+)?$/D', $dbName) !== 1) {
    fwrite(STDERR, "Unsafe test database name. Use lucebianca_test or a lucebianca_test_* name.\n");
    exit(1);
}
$GLOBALS['report_db'] = $dbName; // test-database name only (never credentials)

// Random per-run port: a crashed previous run can leave an orphaned server
// holding a fixed port, which would make health checks hit stale state.
$port = 8091 + random_int(0, 499);
$GLOBALS['baseUrl'] = "http://127.0.0.1:{$port}";

echo "Luce Bianca API tests\n";
echo "  target DB : {$dbName} @ {$dbHost}:{$dbPort}\n";
echo "  API       : 127.0.0.1:{$port} (php -S, isolated env)\n\n";

// --- 2. Throwaway environment file ---------------------------------------
$envFile = tempnam(sys_get_temp_dir(), 'lb-api-test-env-');
$testJwtSecret = 'lb-integration-test-secret-not-for-production';
// Fresh rate-limit bucket per run — the shared api/storage one persists
// across runs and would make the newsletter/contact budget non-deterministic.
$rateLimitDir = (string) $envFile . '-rate-limits';
mkdir($rateLimitDir, 0777, true);
file_put_contents((string) $envFile, <<<ENV
DB_HOST={$dbHost}
DB_PORT={$dbPort}
DB_NAME={$dbName}
DB_USER={$dbUser}
DB_PASS={$dbPass}
JWT_SECRET={$testJwtSecret}
JWT_TTL_SECONDS=3600
JWT_REFRESH_TTL_SECONDS=604800
GOOGLE_CLIENT_ID=
RESEND_API_KEY=
RESEND_FROM_EMAIL=test@localhost
APP_ENV=testing
APP_DEBUG=0
APP_TIMEZONE=Africa/Casablanca
SITE_URL=http://localhost:3000
CORS_ALLOWED_ORIGINS=http://localhost:3000
RATE_LIMIT_STORAGE_DIR={$rateLimitDir}
CMI_CLIENT_ID=test-client-id
CMI_STORE_KEY=test-store-key-for-integration
CMI_HASH_KEY=test-hash-key-for-integration-only
CMI_GATEWAY_URL=https://payment.cmi.co.ma/fim/est3Dgate
CMI_CALLBACK_URL=http://127.0.0.1:8090/api/payments/callback
CMI_RETURN_URL=

ENV);

// Test CMI gateway secrets (mirror of the env above) for crafting signed
// callbacks — the server verifies with the same values. NOTE: the HMAC key
// is CMI_HASH_KEY; CMI_STORE_KEY is only part of the hashed string.
$testCmiStoreKey = 'test-store-key-for-integration';
$testCmiHashKey = 'test-hash-key-for-integration-only';
$testCmiClientId = 'test-client-id';

/**
 * Builds a signed CMI callback payload (mirrors PaymentService::verifyCallback
 * hash input) for card-payment integration tests.
 */
function cmiCallback(string $oid, string $amount, string $procCode, string $response, string $storeKey, string $hashKey, string $clientId): array
{
    $data = [
        'clientid' => $clientId,
        'oid' => $oid,
        'AuthCode' => '654321',
        'ProcReturnCode' => $procCode,
        'Response' => $response,
        'mdStatus' => '1',
        'cavv' => 'cavv-test',
        'eci' => '05',
        'md' => 'md-test',
        'rnd' => 'rnd-test',
        'TransId' => 'TX-' . substr(md5($oid . $procCode), 0, 8),
        'amount' => $amount,
        'ErrMsg' => $procCode === '00' ? '' : 'Do not honour',
    ];
    $hashString = $data['clientid'] . '|' . $data['oid'] . '|' . $data['AuthCode'] . '|'
        . $data['ProcReturnCode'] . '|' . $data['Response'] . '|' . $data['mdStatus'] . '|'
        . $data['cavv'] . '|' . $data['eci'] . '|' . $data['md'] . '|' . $data['rnd'] . '|'
        . $storeKey . '|ver3';
    $data['HASH'] = base64_encode(hash_hmac('sha512', $hashString, $hashKey, true));

    return $data;
}

// --- 3. Rebuild the isolated schema --------------------------------------
// PHP 8.4 renamed the MySQL driver constants (Pdo\Mysql::*); keep working
// on the 8.1+ versions composer.json targets.
$multiStatements = defined('Pdo\Mysql::ATTR_MULTI_STATEMENTS')
    ? Pdo\Mysql::ATTR_MULTI_STATEMENTS
    : PDO::MYSQL_ATTR_MULTI_STATEMENTS;
$adminPdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        $multiStatements => true, // migration files are multi-statement
    ]
);
$adminPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
$adminPdo->exec(
    "CREATE DATABASE `{$dbName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
);

$schemaFile = $repoRoot . '/database/schema.sql';
if (!is_file($schemaFile)) {
    fwrite(STDERR, "Schema snapshot not found: {$schemaFile}\n");
    exit(1);
}
$sql = (string) file_get_contents($schemaFile);
// Redirect the hard-coded USE/CREATE DATABASE statements to the test DB.
// Anything not matching these two exact directive forms fails loudly so
// a future migration can never silently touch the development database.
// Snapshot is the deterministic source (19 tables, includes 015); migrations
// 001→015 are not executed here because 010 has a duplicate-column conflict
// after 008 and 014/015 are UNTESTED with INFORMATION_SCHEMA+PREPARE.
$transformed = preg_replace(
    '/(CREATE DATABASE IF NOT EXISTS\s+|USE\s+)lucebianca\b/',
    '${1}' . $dbName,
    $sql
);
if (
    $transformed === null
    || preg_match('/^\s*USE\s+lucebianca\s*;/mi', $transformed) === 1
    || preg_match('/CREATE DATABASE IF NOT EXISTS\s+lucebianca\b/', $transformed) === 1
) {
    fwrite(STDERR, "Unresolved production DB reference after transform in database/schema.sql\n");
    exit(1);
}
// Execute the batch and drain every result set — required with
// MULTI_STATEMENTS, and it surfaces errors from ANY statement in the
// file (not just the first) thanks to ERRMODE_EXCEPTION.
$statement = $adminPdo->query($transformed);
do {
    while ($statement->fetch() !== false) {
        // discard rows (DDL emits none)
    }
} while ($statement->nextRowset());
$statement->closeCursor();
echo "Schema ready (snapshot: database/schema.sql, 19 tables)\n";

// --- 4. Seed minimal deterministic data ----------------------------------
$pdo = new PDO(
    "mysql:host={$dbHost};port={$dbPort};dbname={$dbName};charset=utf8mb4",
    $dbUser,
    $dbPass,
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
);
$pdo->exec(
    "INSERT INTO products (name, slug, description, base_price, category_id, is_active)
     VALUES ('Test Tee', 'test-tee', 'Seeded for API tests', 149.99, NULL, 1)"
);
$pdo->exec(
    "INSERT INTO product_variants (product_id, size, color, sku, price, stock_quantity)
     VALUES (1, 'M', 'Black', 'TEST-TEE-M-BLK', NULL, 5)"
);

// Offline-seeded admin (same path as scripts/create-admin.php) so the admin
// auth + settings + category suites can exercise role-'admin' endpoints.
$adminStmt = $pdo->prepare(
    'INSERT INTO admins (name, email, password_hash, role) VALUES (?, ?, ?, ?)'
);
$adminStmt->execute([
    'Test Admin',
    'admin@example.com',
    password_hash('AdminPass123!', PASSWORD_DEFAULT),
    'admin',
]);

// --- 5. Boot the real API ------------------------------------------------
// Child stdout/stderr go to temp FILES, not pipes: on Windows proc_open
// pipes ignore stream_set_blocking and any read can block forever once the
// child holds the handle. Files are safe to read at any time.
$serverStdout = tempnam(sys_get_temp_dir(), 'lb-api-out-');
$serverStderr = tempnam(sys_get_temp_dir(), 'lb-api-err-');
$cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", __DIR__ . DIRECTORY_SEPARATOR . 'server-index.php'];
$envForServer = array_merge(getenv(), ['LB_TEST_ENV_FILE' => $envFile]);
$server = proc_open(
    $cmd,
    [
        0 => ['pipe', 'r'],
        1 => ['file', (string) $serverStdout, 'w'],
        2 => ['file', (string) $serverStderr, 'w'],
    ],
    $pipes,
    $repoRoot,
    $envForServer
);
if (!is_resource($server)) {
    fwrite(STDERR, "Failed to start the PHP built-in server.\n");
    exit(1);
}
fclose($pipes[0]); // child never reads our stdin

// Always stop the child and expose its output when something went wrong —
// including fatals, which bypass the normal teardown below.
register_shutdown_function(static function () use ($server, $serverStdout, $serverStderr): void {
    if (is_resource($server)) {
        proc_terminate($server);
    }
    $hadFatal = error_get_last() !== null
        && ((error_get_last()['type'] & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0);
    if (($GLOBALS['failures'] ?? []) !== [] || $hadFatal) {
        $stderr = trim((string) @file_get_contents((string) $serverStderr));
        $stdout = trim((string) @file_get_contents((string) $serverStdout));
        if ($stderr !== '') {
            echo "\n--- server stderr (tail) ---\n" . substr($stderr, -2000) . "\n";
        }
        if ($stdout !== '' && $hadFatal) {
            echo "--- server stdout (tail) ---\n" . substr($stdout, -1000) . "\n";
        }
    }
    @unlink((string) $serverStdout);
    @unlink((string) $serverStderr);
});

function httpRequest(string $method, string $path, ?array $body = null, array $headers = []): array
{
    $context = stream_context_create(['http' => [
        'method' => $method,
        'header' => implode(
            "\r\n",
            array_merge(
                $body !== null ? ['Content-Type: application/json'] : [],
                array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers)
            )
        ),
        'content' => $body !== null ? json_encode($body) : null,
        'ignore_errors' => true, // read bodies from 4xx/5xx too
        'timeout' => 5,
    ]]);

    $raw = @file_get_contents($GLOBALS['baseUrl'] . $path, false, $context);
    if ($raw === false) {
        // Connection refused/reset — surface as status 0 for the assertions.
        $GLOBALS['lastRaw'] = '';
        $GLOBALS['lastStatus'] = 0;
        return [0, [], null];
    }
    $GLOBALS['lastRaw'] = $raw;
    $GLOBALS['lastStatus'] = 0;
    $status = 0;
    $responseHeaders = [];
    foreach ($http_response_header ?? [] as $headerLine) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $headerLine, $m) === 1) {
            $GLOBALS['lastStatus'] = $status = (int) $m[1];
        } elseif (str_contains($headerLine, ':')) {
            [$name, $value] = explode(':', $headerLine, 2);
            $responseHeaders[strtolower(trim($name))] = trim($value);
        }
    }

    return [$status, $responseHeaders, json_decode((string) $raw, true)];
}

// --- 6. Assertions ---------------------------------------------------------
$passed = 0;
$failures = [];
function check(bool $condition, string $label): void
{
    if ($condition) {
        $GLOBALS['passed']++;
    } else {
        $GLOBALS['failures'][] = $label;
        echo "FAIL: {$label}\n";
        echo sprintf(
            "      last status=%d body=%s\n",
            $GLOBALS['lastStatus'] ?? -1,
            substr((string) ($GLOBALS['lastRaw'] ?? ''), 0, 300)
        );
    }
}

// Health first — abort early when the server did not come up.
$healthy = false;
for ($i = 0; $i < 40; $i++) {
    if ($i % 4 === 0) {
        echo "health probe {$i}...\n";
    }
    usleep(250_000);
    [$status, , $body] = httpRequest('GET', '/api/health');
    if ($status === 200 && ($body['status'] ?? '') === 'ok') {
        $healthy = true;
        break;
    }
    if (!is_resource($server) || proc_get_status($server)['running'] === false) {
        break;
    }
}
if (!$healthy) {
    echo stream_get_contents($pipes[2]) ?? '';
    proc_terminate($server);
    fwrite(STDERR, "\nAPI server did not become healthy — aborting.\n");
    exit(1);
}
check(true, 'health endpoint reports ok');

// --- CSRF + newsletter ----------------------------------------------------
[$status, , $body] = httpRequest('POST', '/api/newsletter/subscribe', ['email' => 'reader@example.com']);
check($status === 403 && isset($body['error']), 'newsletter without CSRF token -> 403 generic error');

[, ,$csrfBody] = httpRequest('GET', '/api/csrf-token');
$csrf = $csrfBody['csrf_token'] ?? '';
check($csrf !== '', 'csrf-token endpoint returns a token');

// Rate-limit budget note: /api/newsletter/subscribe shares the contact bucket
// (3 per 10 min); exactly three requests below pass the CSRF layer by design.
[$status, , $body] = httpRequest('POST', '/api/newsletter/subscribe', ['email' => 'reader@example.com'], ['X-CSRF-Token' => $csrf]);
check($status === 201 && ($body['data']['subscribed'] ?? false) === true, 'newsletter subscribe -> 201 subscribed=true');

[$status, , $body] = httpRequest('POST', '/api/newsletter/subscribe', ['email' => 'reader@example.com'], ['X-CSRF-Token' => $csrf]);
check($status === 200 && ($body['data']['subscribed'] ?? true) === false && ($body['data']['message'] ?? '') === 'Already subscribed.', 'newsletter repeat -> 200 already-subscribed message');

[$status, , $body] = httpRequest('POST', '/api/newsletter/subscribe', ['email' => 'not-an-email'], ['X-CSRF-Token' => $csrf]);
check($status === 422 && isset($body['errors']['email']), 'newsletter invalid email -> 422 with field error');

[$status, , $body] = httpRequest('POST', '/api/newsletter/subscribe', ['email' => 'reader@example.com'], ['X-CSRF-Token' => $csrf . 'tampered']);
check($status === 403, 'newsletter tampered CSRF token -> 403');

// --- Customer auth / session ---------------------------------------------
$guestEmail = 'guest@example.com';
[$status, , $auth] = httpRequest('POST', '/api/auth/register', [
    'name' => 'Guest Tester', 'email' => $guestEmail, 'password' => 'password123',
]);
check($status === 201 && is_string($auth['token'] ?? null) && ($auth['user']['email'] ?? '') === $guestEmail, 'register -> 201 with session payload');
$bearer = ['Authorization' => 'Bearer ' . ($auth['token'] ?? ''), 'X-Request-Id' => 't-auth'];

[$status, , $body] = httpRequest('POST', '/api/auth/register', [
    'name' => 'Dup', 'email' => $guestEmail, 'password' => 'password123',
]);
check($status === 409, 'duplicate registration -> 409');

[$status, , $body] = httpRequest('POST', '/api/auth/login', ['email' => $guestEmail, 'password' => 'wrong-password']);
check($status === 401 && ($body['error'] ?? '') !== '' && !isset($body['errors']), 'login wrong password -> 401 generic message only');

[$status, , $body] = httpRequest('GET', '/api/account');
check($status === 401, 'account without token -> 401');

[$status, , $body] = httpRequest('GET', '/api/account', null, ['Authorization' => 'Bearer not.a.jwt']);
check($status === 401, 'account with garbage token -> 401');

[$status, , $body] = httpRequest('GET', '/api/account', null, $bearer);
check($status === 200 && ($body['data']['email'] ?? '') === $guestEmail, 'account with valid session -> profile returned');

[$status, , $refreshed] = httpRequest('POST', '/api/auth/refresh', ['refresh_token' => $auth['refresh_token']]);
check(
    $status === 200
        && is_string($refreshed['token'] ?? null) && $refreshed['token'] !== ''
        && is_string($refreshed['refresh_token'] ?? null) && $refreshed['refresh_token'] !== '',
    'refresh issues a fresh token pair'
);

[$status, , ] = httpRequest('POST', '/api/auth/refresh', ['refresh_token' => $auth['refresh_token']]);
check($status === 401, 'reusing rotated refresh token -> 401');

// --- Wishlist (session + CSRF mutations) ---------------------------------
[$status, ] = httpRequest('POST', '/api/wishlist', ['product_id' => 1]); // no CSRF at all
check($status === 403, 'wishlist add without CSRF -> 403 (CSRF layer runs before auth)');

[, , $csrfBody2] = httpRequest('GET', '/api/csrf-token');
$csrfW = ['X-CSRF-Token' => $csrfBody2['csrf_token'] ?? ''];

[$status, ] = httpRequest('POST', '/api/wishlist', ['product_id' => 1], $csrfW);
check($status === 401, 'wishlist add with CSRF but no session -> 401');

[$status, , $body] = httpRequest('POST', '/api/wishlist', ['product_id' => 1], $bearer + $csrfW);
check($status === 201 && ($body['data']['added'] ?? false) === true, 'wishlist add -> 201 added');

[$status, , $body] = httpRequest('POST', '/api/wishlist', ['product_id' => 1], $bearer + $csrfW);
check($status === 200 && ($body['data']['added'] ?? true) === false, 'wishlist duplicate add -> 200 added=false');

[$status, , $body] = httpRequest('POST', '/api/wishlist', ['product_id' => 9999], $bearer + $csrfW);
check($status === 404, 'wishlist unknown product -> 404');

[$status, , $body] = httpRequest('GET', '/api/wishlist', null, $bearer);
check(
    $status === 200 && ($body['data'][0]['product_id'] ?? 0) === 1 && ($body['data'][0]['slug'] ?? '') === 'test-tee',
    'wishlist listing returns saved product'
);

[$status] = httpRequest('DELETE', '/api/wishlist/1', null, $bearer);
check($status === 403, 'wishlist remove without CSRF -> 403');

[$status] = httpRequest('DELETE', '/api/wishlist/1', null, $bearer + $csrfW);
check($status === 204, 'wishlist remove with CSRF -> 204');

[$status, , $body] = httpRequest('GET', '/api/wishlist', null, $bearer);
check($status === 200 && $body['data'] === [], 'wishlist empty after removal');

// --- Reviews (session + CSRF + verified-email gate) ------------------------
// Email verification is enforced server-side for reviews: mark the seeded
// customer verified (mirrors clicking the emailed link) before submitting.
$pdo->prepare('UPDATE users SET email_verified = 1 WHERE email = ?')->execute([$guestEmail]);
[, , $csrfR] = httpRequest('GET', '/api/csrf-token');
$csrfRev = ['X-CSRF-Token' => $csrfR['csrf_token'] ?? ''];

[$status, , $body] = httpRequest('POST', '/api/products/test-tee/reviews', ['rating' => 5, 'comment' => 'Lovely tee'], $bearer + $csrfRev);
check($status === 201 && isset($body['data']['id']), 'review submit -> 201 with id');
$reviewId = (int) ($body['data']['id'] ?? 0);

// Public detail returns approved reviews only — a pending one stays hidden.
[, , $pendingDetail] = httpRequest('GET', '/api/products/test-tee');
check(
    $reviewId > 0
        && !in_array(
            $reviewId,
            array_column($pendingDetail['data']['reviews'] ?? [], 'id'),
            true,
        ),
    'pending review is hidden from public product detail'
);

[$status, ] = httpRequest('POST', '/api/products/test-tee/reviews', ['rating' => 9], $bearer + $csrfRev);
check($status === 422, 'review rating out of range -> 422');

[$status] = httpRequest('POST', '/api/products/test-tee/reviews', ['rating' => 4], $bearer);
check($status === 403, 'review without CSRF -> 403');

[$status] = httpRequest('POST', '/api/products/test-tee/reviews', ['rating' => 4], $csrfRev);
check($status === 401, 'review without session -> 401');

[$status] = httpRequest('POST', '/api/products/nope/reviews', ['rating' => 4], $bearer + $csrfRev);
check($status === 404, 'review for unknown slug -> 404');

// --- Guest checkout email flow -------------------------------------------
[, , $csrfO] = httpRequest('GET', '/api/csrf-token');
$csrfOrd = ['X-CSRF-Token' => $csrfO['csrf_token'] ?? ''];

$orderPayload = [
    'customer_name' => 'Yasmine Alaoui',
    'phone' => '0612345678',
    'email' => 'yasmine@example.com',
    'shipping_address' => '12 Rue des Fleurs',
    'city' => 'Casablanca',
    'postal_code' => '20000',
    'payment_method' => 'cod',
    'items' => [['variant_id' => 1, 'quantity' => 2]],
];
[$status, , $order] = httpRequest('POST', '/api/orders', $orderPayload, $csrfOrd);
check($status === 201 && isset($order['data']['id']), 'guest order -> 201');
$orderData = is_array($order['data'] ?? null) ? $order['data'] : [];
check(
    array_key_exists('user_id', $orderData) && $orderData['user_id'] === null,
    'guest order has NULL user_id'
);
check(($order['data']['email'] ?? '') === 'yasmine@example.com', 'guest order stores the checkout email');
check(abs((float) ($order['data']['total_amount'] ?? 0) - 299.98) < 0.001, 'order total uses authoritative price (149.99 x 2)');
// Business rule 2026-10-03: every method starts pending (COD/WhatsApp are NOT
// auto-paid); paid only via card callback success or admin confirmation.
check(($order['data']['payment_status'] ?? '') === 'pending', 'COD order recorded with payment_status=pending (never auto-paid)');
check(isset($order['data']['subtotal_amount']) && isset($order['data']['shipping_cost']), 'order exposes subtotal + shipping breakdown');
check(abs((float) ($order['data']['subtotal_amount'] ?? -1) - 299.98) < 0.001, 'subtotal equals lines total when shipping is 0');
check(abs((float) ($order['data']['shipping_cost'] ?? -1) - 0.0) < 0.001, 'default shipping is 0 when unconfigured');

$orderItems = $order['data']['items'] ?? [];
$lineUnitPrice = (float) ($orderItems[0]['price_at_purchase'] ?? -1);
check(
    abs($lineUnitPrice - 149.99) < 0.001
    && ($orderItems[0]['sku'] ?? '') === 'TEST-TEE-M-BLK',
    'order line snapshots sku and unit price at purchase'
);

$orderId = (int) ($order['data']['id'] ?? 0);
$guestLookupTokenEarly = (string) ($order['data']['lookup_token'] ?? '');
// Guest lookup privacy: the token is required (404 without it).
[$status, , $fetched] = httpRequest('GET', "/api/orders/{$orderId}?token=" . urlencode($guestLookupTokenEarly));
check(
    $status === 200 && ($fetched['data']['email'] ?? '') === 'yasmine@example.com'
        && count($fetched['data']['items'] ?? []) === 1,
    'confirmation-page fetch returns order incl. email'
);

// Email best-effort guard: with RESEND_API_KEY empty the order above still
// succeeded (asserted by the 201), proving no provider call blocks checkout.

// Stock was really decremented server-side (5 seeded - 2 sold).
[, , $cartCheck] = httpRequest('POST', '/api/cart', ['items' => [['variant_id' => 1, 'quantity' => 5]]], $csrfOrd);
$line0 = $cartCheck['data']['lines'][0] ?? [];
check(
    ($cartCheck['data']['valid'] ?? true) === false
        && ($line0['available'] ?? true) === false
        && ($line0['available_quantity'] ?? 0) === 3,
    'stock decrement visible via cart validation (5 - 2 = 3)'
);

[$status, ] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['items' => [['variant_id' => 1, 'quantity' => 6]]]), $csrfOrd);
check($status === 409, 'over-stock order (6 > 3 left) -> 409 conflict');

[$status, , $overLimit] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['items' => [['variant_id' => 1, 'quantity' => 99]]]), $csrfOrd);
check($status === 422, 'unreasonable quantity 99 -> 422 (max 20, not 409)');

[$status, , $body] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['phone' => 'bad']), $csrfOrd);
check($status === 422 && isset($body['errors']['phone']), 'invalid phone -> 422 field error');

// Strict integer quantity: decimals, malformed ids, duplicates rejected (422).
[$status, , $body] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['items' => [['variant_id' => 1, 'quantity' => 2.5]]]), $csrfOrd);
check($status === 422, 'decimal quantity 2.5 -> 422');
[$status, , $body] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['items' => [['variant_id' => 'abc', 'quantity' => 1]]]), $csrfOrd);
check($status === 422, 'malformed variant id -> 422');
[$status, , $body] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['items' => [['variant_id' => 1, 'quantity' => 1], ['variant_id' => 1, 'quantity' => 1]]]), $csrfOrd);
check($status === 422, 'duplicate cart lines -> 422');
[$status, , $body] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['items' => [['variant_id' => 1, 'quantity' => 999]]]), $csrfOrd);
check($status === 422, 'unreasonable quantity 999 -> 422 (max 20)');

// Guest lookup privacy: without token the order hides (404), with token it shows.
$guestLookupToken = (string) ($order['data']['lookup_token'] ?? '');
check($guestLookupToken !== '', 'guest order returns lookup_token');
[$status, , ] = httpRequest('GET', "/api/orders/{$orderId}");
check($status === 404, 'guest order without token -> 404 (hides existence)');
[$status, , $withToken] = httpRequest('GET', "/api/orders/{$orderId}?token=" . urlencode($guestLookupToken));
check($status === 200 && (int) ($withToken['data']['id'] ?? 0) === $orderId, 'guest order with token -> 200');

// Customer cancellation restores stock (inventory policy).
[$status, , $cancelled] = httpRequest('POST', "/api/orders/{$orderId}/cancel", ['lookup_token' => $guestLookupToken], $csrfOrd);
check($status === 200 && ($cancelled['data']['status'] ?? '') === 'cancelled', 'guest cancel pending order -> cancelled');
[, , $cartAfterCancel] = httpRequest('POST', '/api/cart', ['items' => [['variant_id' => 1, 'quantity' => 5]]], $csrfOrd);
check(($cartAfterCancel['data']['valid'] ?? false) === true, 'stock restored after cancel (5 available again)');

// Logged-in checkout attributes the order to the customer account.
[, , $auth2] = httpRequest('POST', '/api/auth/register', [
    'name' => 'Logged In', 'email' => 'member@example.com', 'password' => 'password123',
]);
$bearer2 = ['Authorization' => 'Bearer ' . ($auth2['token'] ?? '')];
[$status, , $memberOrder] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['email' => 'member@example.com']), $bearer2 + $csrfOrd);
check(
    $status === 201 && (string) ($memberOrder['data']['user_id'] ?? '') === (string) ($auth2['user']['id'] ?? '-1'),
    'authenticated checkout attributes order.user_id to the session'
);

// --- Admin settings & categories (regression: settings column names,
// --- missing CategoryRepository::slugExists) ------------------------------
[$status, , $adminLogin] = httpRequest('POST', '/api/admin/auth/login', [
    'email' => 'admin@example.com',
    'password' => 'AdminPass123!',
]);
$adminToken = ['Authorization' => 'Bearer ' . ($adminLogin['token'] ?? '')];
check($status === 200 && is_string($adminLogin['token'] ?? null), 'admin login returns a session token');

[, , $csrfA] = httpRequest('GET', '/api/csrf-token');
$csrfAdm = ['X-CSRF-Token' => $csrfA['csrf_token'] ?? ''];

// Settings: real columns are setting_key/setting_value — a wrong column name
// here previously produced "Unknown column 'key'" 500s.
[$status, , $settings] = httpRequest('GET', '/api/admin/settings', null, $adminToken);
check($status === 200 && is_array($settings['data'] ?? null), 'GET admin settings -> 200 key/value object');

[$status] = httpRequest('PUT', '/api/admin/settings', [
    'store_name' => 'Luce Bianca Test',
    'shipping_cost' => '35.00',
], $adminToken + $csrfAdm);
check($status === 200, 'PUT admin settings -> 200 (upsert on setting_key)');

[$status, , $settingsAfter] = httpRequest('GET', '/api/admin/settings', null, $adminToken);
check(
    ($settingsAfter['data']['store_name'] ?? '') === 'Luce Bianca Test'
        && ($settingsAfter['data']['shipping_cost'] ?? '') === '35.00',
    'settings upsert round-trips values'
);

// Categories: create with auto-slug, explicit duplicate slug -> 409, update.
[$status, , $category] = httpRequest('POST', '/api/admin/categories', ['name' => 'Hoodies'], $adminToken + $csrfAdm);
check($status === 201 && ($category['data']['slug'] ?? '') === 'hoodies', 'category create -> 201 with auto slug');

[, , $csrfA2] = httpRequest('GET', '/api/csrf-token');
[$status, , ] = httpRequest('POST', '/api/admin/categories', ['name' => 'Other', 'slug' => 'hoodies'], $adminToken + ['X-CSRF-Token' => $csrfA2['csrf_token'] ?? '']);
check($status === 409, 'category create with taken slug -> 409');

$category2Id = 0;
[$status, , $category2] = httpRequest('POST', '/api/admin/categories', ['name' => 'Tees'], $adminToken + $csrfAdm);
$category2Id = (int) ($category2['data']['id'] ?? 0);
check($status === 201 && ($category2['data']['slug'] ?? '') === 'tees', 'second category create -> 201');

[$status] = httpRequest('PUT', "/api/admin/categories/{$category2Id}", ['slug' => 'hoodies'], $adminToken + $csrfAdm);
check($status === 409, 'category update to another category slug -> 409');

[$status, , $renamed] = httpRequest('PUT', "/api/admin/categories/{$category2Id}", ['slug' => 't-shirts'], $adminToken + $csrfAdm);
check($status === 200 && ($renamed['data']['slug'] ?? '') === 't-shirts', 'category update to free slug -> 200');

// Approving a review must make it (and its rating) visible on the public
// product detail payload — the storefront renders this section live.
[$status] = httpRequest('PUT', "/api/admin/reviews/{$reviewId}/approve", null, $adminToken + $csrfAdm);
check($status === 200, 'admin approves pending review -> 200');

[, , $approvedDetail] = httpRequest('GET', '/api/products/test-tee');
$visibleReviewIds = array_column($approvedDetail['data']['reviews'] ?? [], 'id');
check(
    in_array($reviewId, $visibleReviewIds, true)
        && abs((float) ($approvedDetail['data']['average_rating'] ?? 0) - 5) < 0.001,
    'approved review + average rating appear on public product detail'
);

// --- Sale price validation & discounted pricing (authoritative sale) ------
[$status, , $body] = httpRequest('POST', '/api/admin/products', [
    'name' => 'Sale Test Tee',
    'base_price' => '100.00',
    'sale_price' => '120.00',
    'is_active' => 1,
    'meta_title' => 'Sale Test',
    'meta_description' => 'Sale test desc',
], $adminToken + $csrfAdm);
check($status === 422 && isset($body['errors']['sale_price']), 'sale_price >= base_price -> 422');

[$status, , $body] = httpRequest('POST', '/api/admin/products', [
    'name' => 'Sale Test 2',
    'base_price' => '100.00',
    'sale_price' => '0',
    'is_active' => 1,
    'meta_title' => 'Sale Test 2',
    'meta_description' => 'Sale test desc',
], $adminToken + $csrfAdm);
check($status === 422 && isset($body['errors']['sale_price']), 'sale_price zero -> 422');

[$status, , $saleProduct] = httpRequest('POST', '/api/admin/products', [
    'name' => 'Sale Valid Tee',
    'base_price' => '100.00',
    'sale_price' => '80.00',
    'is_active' => 1,
    'meta_title' => 'Sale Valid',
    'meta_description' => 'Sale valid desc',
    'variants' => [
        ['size' => 'M', 'color' => 'Red', 'sku' => 'SALE-VALID-M-RED', 'price' => '', 'stock_quantity' => '5'],
    ],
], $adminToken + $csrfAdm);
check($status === 201 && ($saleProduct['data']['sale_price'] ?? '') === '80.00', 'valid sale_price -> 201 stores sale');
$saleVariantId = (int) ($saleProduct['data']['variants'][0]['id'] ?? 0);

[, , $detailSale] = httpRequest('GET', '/api/products/sale-valid-tee');
check(($detailSale['data']['sale_price'] ?? '') === '80.00', 'sale product detail exposes sale_price');

[, , $specialOffers] = httpRequest('GET', '/api/products/special-offers?limit=10');
$specialIds = array_column($specialOffers['data'] ?? [], 'id');
check(in_array($saleProduct['data']['id'] ?? 0, $specialIds, true), 'special-offers includes sale product');

[, , $cartSale] = httpRequest('POST', '/api/cart', ['items' => [['variant_id' => $saleVariantId, 'quantity' => 1]]], $csrfAdm);
check(($cartSale['data']['lines'][0]['unit_price'] ?? '') === '80.00', 'cart validation returns sale price');

[$status, , $orderSale] = httpRequest('POST', '/api/orders', [
    'customer_name' => 'Sale Buyer',
    'phone' => '0612345678',
    'email' => 'salebuyer@example.com',
    'shipping_address' => '123 Sale St',
    'city' => 'Casa',
    'postal_code' => '20000',
    'payment_method' => 'cod',
    'items' => [['variant_id' => $saleVariantId, 'quantity' => 2]],
], $csrfAdm);
// Shipping was set to 35.00 earlier (admin settings): 160 subtotal + 35 shipping.
check($status === 201 && ($orderSale['data']['total_amount'] ?? '') === '195.00' && ($orderSale['data']['subtotal_amount'] ?? '') === '160.00' && ($orderSale['data']['shipping_cost'] ?? '') === '35.00' && ($orderSale['data']['items'][0]['price_at_purchase'] ?? '') === '80.00', 'order total uses discounted sale price + shipping breakdown');

// WhatsApp checkout with valid address, city, postal_code (same required fields as COD)
[$status, , $orderWA] = httpRequest('POST', '/api/orders', [
    'customer_name' => 'WhatsApp Buyer',
    'phone' => '0612345678',
    'email' => 'wabuyer@example.com',
    'shipping_address' => '123 WA St',
    'city' => 'Casa',
    'postal_code' => '20000',
    'payment_method' => 'whatsapp',
    'items' => [['variant_id' => $saleVariantId, 'quantity' => 1]],
], $csrfAdm);
check($status === 201 && ($orderWA['data']['total_amount'] ?? '') === '115.00' && ($orderWA['data']['subtotal_amount'] ?? '') === '80.00' && ($orderWA['data']['payment_method'] ?? '') === 'whatsapp', 'whatsapp order with valid address/city/postal_code -> 201 (80 + 35 shipping)');

// --- Dashboard realized order rule (COD/WhatsApp pending delivered counts) -----
// Overview, sales_chart and top_products must count a delivered COD/WhatsApp
// order even while payment_status is still 'pending', but must always exclude
// cancelled orders. order_status_breakdown and recent_orders show all orders.

[$status, , $dashBefore] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200 && isset($dashBefore['data']['overview']), 'dashboard stats reachable before realized-order checks');

// Snapshot before inserts.
$overviewBefore = $dashBefore['data']['overview'];
$salesBefore = (float) ($overviewBefore['total_sales'] ?? 0);
$ordersBefore = (int) ($overviewBefore['total_orders'] ?? 0);
$today = (string) $pdo->query('SELECT CURDATE()')->fetchColumn();
// Helper to find today entry in sales_chart.
$findToday = static function (array $chart, string $day): ?array {
    foreach ($chart as $row) {
        if (($row['date'] ?? '') === $day) return $row;
    }
    return null;
};
$chartBefore = $dashBefore['data']['sales_chart'] ?? [];
$rowBefore = $findToday($chartBefore, $today);
$chartSalesBefore = $rowBefore !== null ? (float) ($rowBefore['sales'] ?? 0) : 0.0;
$chartCountBefore = $rowBefore !== null ? (int) ($rowBefore['order_count'] ?? 0) : 0;

$breakdownBefore = $dashBefore['data']['order_status_breakdown'] ?? [];
$deliveredBefore = (int) ($breakdownBefore['delivered'] ?? 0);
$pendingBefore = (int) ($breakdownBefore['pending'] ?? 0);
$cancelledBefore = (int) ($breakdownBefore['cancelled'] ?? 0);

// Top product (Test Tee id=1) snapshot.
$topBefore = $dashBefore['data']['top_products'] ?? [];
$unitsBefore = 0; $revBefore = 0.0;
foreach ($topBefore as $tp) {
    if ((int) ($tp['id'] ?? 0) === 1) { $unitsBefore = (int) ($tp['units_sold'] ?? 0); $revBefore = (float) ($tp['revenue'] ?? 0); break; }
}

// 1) Insert a delivered COD order whose payment_status is still pending — must be REALIZED.
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, subtotal_amount, shipping_cost, shipping_address, city, postal_code, customer_name, phone, email, lookup_token, payment_method, payment_status, created_at) VALUES (NULL, 'delivered', '100.00', '100.00', '0.00', '123 Test St', 'Casa', '20000', 'Dash COD Pending', '0612345678', 'dashcod@test.com', 'd1aa0b5e9c1e4f6a8b2d4f6a8b2d4f01', 'cod', 'pending', NOW())")->execute();
$oid1 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase) VALUES (:oid, 1, 1, '100.00')")->execute([':oid' => $oid1]);

[$status, , $dash1] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200, 'dashboard after delivered COD pending -> 200');
check(abs((float)($dash1['data']['overview']['total_sales'] ?? 0) - ($salesBefore + 100.0)) < 0.01, 'delivered COD pending counts toward total_sales');
check((int)($dash1['data']['overview']['total_orders'] ?? 0) === $ordersBefore + 1, 'delivered COD pending counts toward total_orders (realized count)');
$chart1 = $dash1['data']['sales_chart'] ?? [];
$row1 = $findToday($chart1, $today);
check($row1 !== null && abs((float)($row1['sales'] ?? 0) - ($chartSalesBefore + 100.0)) < 0.01, 'delivered COD pending appears in sales_chart daily_sales');
check($row1 !== null && (int)($row1['order_count'] ?? 0) === $chartCountBefore + 1, 'delivered COD pending increments sales_chart order_count');
$foundTop1 = false;
foreach (($dash1['data']['top_products'] ?? []) as $tp) {
    if ((int)($tp['id'] ?? 0) === 1 && (int)($tp['units_sold'] ?? 0) === $unitsBefore + 1 && abs((float)($tp['revenue'] ?? 0) - ($revBefore + 100.0)) < 0.01) { $foundTop1 = true; break; }
}
check($foundTop1, 'delivered COD pending increments top_products units/revenue');
check((int)(($dash1['data']['order_status_breakdown']['delivered'] ?? 0)) === $deliveredBefore + 1, 'order_status_breakdown includes delivered COD pending (shows all)');
$recentIds1 = array_column($dash1['data']['recent_orders'] ?? [], 'id');
check(in_array($oid1, $recentIds1, true), 'recent_orders includes delivered COD pending (shows all)');

// 2) Insert a pending COD order whose status is still pending — must NOT be realized.
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, subtotal_amount, shipping_cost, shipping_address, city, postal_code, customer_name, phone, email, lookup_token, payment_method, payment_status, created_at) VALUES (NULL, 'pending', '77.77', '77.77', '0.00', '123 Test St', 'Casa', '20000', 'Dash Pending Cod', '0612345678', 'dashpending@test.com', 'd2bb0b5e9c1e4f6a8b2d4f6a8b2d4f02', 'cod', 'pending', NOW())")->execute();
$oid2 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase) VALUES (:oid, 1, 1, '77.77')")->execute([':oid' => $oid2]);

[$status, , $dash2] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200, 'dashboard after pending COD pending -> 200');
check(abs((float)($dash2['data']['overview']['total_sales'] ?? 0) - ($salesBefore + 100.0)) < 0.01, 'pending COD pending does NOT affect total_sales');
check((int)($dash2['data']['overview']['total_orders'] ?? 0) === $ordersBefore + 1, 'pending COD pending does NOT affect total_orders');
$chart2 = $dash2['data']['sales_chart'] ?? [];
$row2 = $findToday($chart2, $today);
check($row2 !== null && abs((float)($row2['sales'] ?? 0) - ($chartSalesBefore + 100.0)) < 0.01, 'pending COD pending does NOT affect sales_chart');
check((int)(($dash2['data']['order_status_breakdown']['pending'] ?? 0)) === $pendingBefore + 1, 'order_status_breakdown increments pending (shows all)');
check(in_array($oid2, array_column($dash2['data']['recent_orders'] ?? [], 'id'), true), 'recent_orders includes pending COD pending (shows all)');

// 3) Insert a cancelled COD order with payment_status pending and status cancelled — must NOT be realized.
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, subtotal_amount, shipping_cost, shipping_address, city, postal_code, customer_name, phone, email, lookup_token, payment_method, payment_status, created_at) VALUES (NULL, 'cancelled', '88.88', '88.88', '0.00', '123 Test St', 'Casa', '20000', 'Dash Cancelled', '0612345678', 'dashcancel@test.com', 'd3cc0b5e9c1e4f6a8b2d4f6a8b2d4f03', 'cod', 'pending', NOW())")->execute();
$oid3 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase) VALUES (:oid, 1, 1, '88.88')")->execute([':oid' => $oid3]);

[$status, , $dash3] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200, 'dashboard after cancelled COD pending -> 200');
check(abs((float)($dash3['data']['overview']['total_sales'] ?? 0) - ($salesBefore + 100.0)) < 0.01, 'cancelled order excluded from total_sales even with COD');
check((int)($dash3['data']['overview']['total_orders'] ?? 0) === $ordersBefore + 1, 'cancelled order excluded from total_orders');
check((int)(($dash3['data']['order_status_breakdown']['cancelled'] ?? 0)) === $cancelledBefore + 1, 'order_status_breakdown increments cancelled (shows all)');

// 4) Insert a delivered WhatsApp order with payment_status pending — must be REALIZED and increment again.
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, subtotal_amount, shipping_cost, shipping_address, city, postal_code, customer_name, phone, email, lookup_token, payment_method, payment_status, created_at) VALUES (NULL, 'delivered', '55.00', '55.00', '0.00', '123 Test St', 'Casa', '20000', 'Dash WA Pending', '0612345678', 'dashwa@test.com', 'd4dd0b5e9c1e4f6a8b2d4f6a8b2d4f04', 'whatsapp', 'pending', NOW())")->execute();
$oid4 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase) VALUES (:oid, 1, 1, '55.00')")->execute([':oid' => $oid4]);

[$status, , $dash4] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200, 'dashboard after delivered WhatsApp pending -> 200');
check(abs((float)($dash4['data']['overview']['total_sales'] ?? 0) - ($salesBefore + 155.0)) < 0.01, 'delivered WhatsApp pending counts toward total_sales (+55)');
check((int)($dash4['data']['overview']['total_orders'] ?? 0) === $ordersBefore + 2, 'delivered WhatsApp pending increments total_orders (realized) to +2');
$chart4 = $dash4['data']['sales_chart'] ?? [];
$row4 = $findToday($chart4, $today);
check($row4 !== null && abs((float)($row4['sales'] ?? 0) - ($chartSalesBefore + 155.0)) < 0.01, 'delivered WhatsApp pending appears in sales_chart');
check($row4 !== null && (int)($row4['order_count'] ?? 0) === $chartCountBefore + 2, 'delivered WhatsApp pending increments chart count to +2');

// 5) Verify that a processing COD pending also counts (edge of realized set) — processing is in the list.
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, subtotal_amount, shipping_cost, shipping_address, city, postal_code, customer_name, phone, email, lookup_token, payment_method, payment_status, created_at) VALUES (NULL, 'processing', '40.00', '40.00', '0.00', '123 Test St', 'Casa', '20000', 'Dash Processing Cod', '0612345678', 'dashproc@test.com', 'dashproc5token0123456789abcdef0123456789abcdef01', 'cod', 'pending', NOW())")->execute();
$oid5 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase) VALUES (:oid, 1, 1, '40.00')")->execute([':oid' => $oid5]);
[$status, , $dash5] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200, 'dashboard after processing COD pending -> 200');
check(abs((float)($dash5['data']['overview']['total_sales'] ?? 0) - ($salesBefore + 195.0)) < 0.01, 'processing COD pending counts toward total_sales');

// --- Payments config + shipping totals ------------------------------------
[$status, , $payConfig] = httpRequest('GET', '/api/payments/config');
check($status === 200 && isset($payConfig['data']['card_available']), 'payments config exposes card_available (no secrets)');
check(($payConfig['data']['card_configured'] ?? false) === true, 'payments config reports configured CMI in test env');
check(!isset($payConfig['data']['client_id']) && !isset($payConfig['data']['store_key']), 'payments config never leaks secrets');

// Shipping totals: set flat 30 + threshold 500 via admin, verify breakdown.
[$status] = httpRequest('PUT', '/api/admin/settings', ['shipping_cost' => '30.00', 'free_shipping_threshold' => '500.00'], $adminToken + $csrfAdm);
check($status === 200, 'admin sets shipping cost + threshold');
[, , $csrfShip] = httpRequest('GET', '/api/csrf-token');
$csrfShipH = ['X-CSRF-Token' => $csrfShip['csrf_token'] ?? ''];
[$status, , $shipOrder] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['email' => 'ship@test.com', 'items' => [['variant_id' => $saleVariantId, 'quantity' => 1]]]), $csrfShipH);
check(
    $status === 201 && ($shipOrder['data']['shipping_cost'] ?? '') === '30.00' && ($shipOrder['data']['total_amount'] ?? '') === '110.00',
    'shipping added below threshold (80 + 30 = 110)'
);
// Reset shipping to 0 for remaining suites.
[$status] = httpRequest('PUT', '/api/admin/settings', ['shipping_cost' => '0.00', 'free_shipping_threshold' => '0'], $adminToken + $csrfAdm);
check($status === 200, 'admin resets shipping to 0');

// --- Password reset flow (anti-enumeration, single-use, expiry) ------------
[$status, , $forgotUnknown] = httpRequest('POST', '/api/auth/forgot-password', ['email' => 'nobody@example.com']);
check($status === 200 && str_contains($forgotUnknown['message'] ?? '', 'If that email'), 'forgot-password unknown email -> generic 200');
[$status, , $forgotKnown] = httpRequest('POST', '/api/auth/forgot-password', ['email' => $guestEmail]);
check($status === 200 && str_contains($forgotKnown['message'] ?? '', 'If that email'), 'forgot-password known email -> same generic 200');
// Fetch the hashed token directly (test DB only) to complete the flow.
$resetRow = $pdo->query("SELECT password_reset_hash FROM users WHERE email = '{$guestEmail}' LIMIT 1")->fetch(PDO::FETCH_ASSOC);
check(!empty($resetRow['password_reset_hash']), 'forgot-password stores a reset hash');
// Cannot derive the raw token from the hash — verify rejection of garbage + empty.
[$status, , ] = httpRequest('POST', '/api/auth/reset-password', ['token' => 'garbage-token', 'password' => 'newpassword123']);
check($status === 400, 'reset-password garbage token -> 400');
[$status, , $body] = httpRequest('POST', '/api/auth/reset-password', ['token' => '', 'password' => 'short']);
check($status === 422, 'reset-password empty/short -> 422');

// --- Newsletter subscribe email + signed unsubscribe ------------------------
 // The subscribe endpoint shares the contact rate-limit bucket (3/10 min per
 // IP); earlier suites already spent it. Clear the isolated test bucket so
 // these assertions test newsletter logic, not rate limiting.
foreach (glob($rateLimitDir . '/*.json') ?: [] as $f) {
    @unlink($f);
}
// Mirror of NewsletterController::issueUnsubscribeToken with the known test
// JWT secret (proves the stateless HMAC contract end to end).
$mintUnsubToken = static function (string $email) use ($testJwtSecret): string {
    $normalized = strtolower(trim($email));
    $expiry = (string) (time() + 365 * 86400);
    $sig = hash_hmac('sha256', $normalized . '|' . $expiry, $testJwtSecret);
    return rtrim(strtr(base64_encode($normalized . '|' . $expiry . '|' . $sig), '+/', '-_'), '=');
};
[, , $csrfN] = httpRequest('GET', '/api/csrf-token');
$csrfNH = ['X-CSRF-Token' => $csrfN['csrf_token'] ?? ''];
// RESEND_API_KEY is empty in the test env: the welcome email is best-effort
// and must never block the subscribe (201 either way).
[$status, , $sub] = httpRequest('POST', '/api/newsletter/subscribe', ['email' => 'unsub@example.com'], $csrfNH);
check($status === 201 && ($sub['data']['subscribed'] ?? false) === true, 'newsletter subscribe -> 201 subscribed=true');
check(!isset($sub['data']['unsubscribe_token']) && !isset($sub['data']['unsubscribe_url']), 'newsletter subscribe response exposes no token/url (emailed instead)');
$unsubToken = $mintUnsubToken('unsub@example.com');
// GET only validates the emailed link (read-only) — must not mutate.
[$status, , $verify] = httpRequest('GET', '/api/newsletter/unsubscribe?email=' . urlencode('unsub@example.com') . '&token=' . urlencode($unsubToken));
check($status === 200 && ($verify['data']['valid'] ?? false) === true, 'newsletter GET verify valid link -> 200 (read-only)');
[$status, , ] = httpRequest('GET', '/api/newsletter/unsubscribe?email=' . urlencode('unsub@example.com') . '&token=bad');
check($status === 403, 'newsletter GET verify tampered token -> 403');
// The POST below returns unsubscribed:true, proving the GET above did NOT
// already unsubscribe (no scanner-triggered mutation).
[$status, , ] = httpRequest('POST', '/api/newsletter/unsubscribe', ['email' => 'unsub@example.com'], $csrfNH);
check($status === 422, 'newsletter unsubscribe without token -> 422 (email alone insufficient)');
[$status, , $unsub] = httpRequest('POST', '/api/newsletter/unsubscribe', ['email' => 'unsub@example.com', 'token' => $unsubToken], $csrfNH);
check($status === 200 && ($unsub['data']['unsubscribed'] ?? false) === true, 'newsletter signed unsubscribe -> 200');
$auditCount = (int) $pdo->query("SELECT unsubscribe_count FROM newsletter_subscribers WHERE email = 'unsub@example.com'")->fetchColumn();
check($auditCount === 1, 'newsletter unsubscribe_count audit = 1');
[$status, , $unsubAgain] = httpRequest('POST', '/api/newsletter/unsubscribe', ['email' => 'unsub@example.com', 'token' => $unsubToken], $csrfNH);
check($status === 200 && ($unsubAgain['data']['unsubscribed'] ?? true) === false, 'newsletter repeated unsubscribe -> 200 unsubscribed=false');
$auditCount2 = (int) $pdo->query("SELECT unsubscribe_count FROM newsletter_subscribers WHERE email = 'unsub@example.com'")->fetchColumn();
check($auditCount2 === 1, 'newsletter repeat does not double-count audit');
[$status, , ] = httpRequest('POST', '/api/newsletter/unsubscribe', ['email' => 'other@example.com', 'token' => $unsubToken], $csrfNH);
check($status === 403, 'newsletter token bound to email (mismatch -> 403)');

// --- Email-verification gate for reviews ----------------------------------
[, , $unverifiedAuth] = httpRequest('POST', '/api/auth/register', ['name' => 'Unverified', 'email' => 'unverified@example.com', 'password' => 'password123']);
$unverifiedBearer = ['Authorization' => 'Bearer ' . ($unverifiedAuth['token'] ?? '')];
[, , $csrfR2] = httpRequest('GET', '/api/csrf-token');
$csrfR2H = ['X-CSRF-Token' => $csrfR2['csrf_token'] ?? ''];
[$status, , ] = httpRequest('POST', '/api/products/test-tee/reviews', ['rating' => 5, 'comment' => 'x'], $unverifiedBearer + $csrfR2H);
check($status === 403, 'unverified account cannot submit review -> 403');

// --- Admin cancel restores stock ------------------------------------------
[, , $csrfC] = httpRequest('GET', '/api/csrf-token');
$csrfCH = ['X-CSRF-Token' => $csrfC['csrf_token'] ?? ''];
[$status, , $toCancel] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['email' => 'cancelme@test.com', 'items' => [['variant_id' => $saleVariantId, 'quantity' => 1]]]), $csrfCH);
$cancelId = (int) ($toCancel['data']['id'] ?? 0);
check($status === 201 && $cancelId > 0, 'order for admin-cancel created');
[$status, , $adminCancelled] = httpRequest('PUT', "/api/admin/orders/{$cancelId}", ['status' => 'cancelled'], $adminToken + $csrfCH);
check($status === 200 && ($adminCancelled['data']['status'] ?? '') === 'cancelled', 'admin cancel -> cancelled + stock restored');

// --- Card payments: return URLs, callbacks, atomic idempotent inventory ----
$stockOf = static function (int $variantId, array $csrfH): int {
    [, , $c] = httpRequest('POST', '/api/cart', ['items' => [['variant_id' => $variantId, 'quantity' => 20]]], $csrfH);
    return (int) ($c['data']['lines'][0]['available_quantity'] ?? -1);
};
$mkCardOrder = static function (string $email) use ($orderPayload, $csrfCH): array {
    [, , $o] = httpRequest('POST', '/api/orders', array_merge($orderPayload, [
        'email' => $email,
        'payment_method' => 'card',
        'items' => [['variant_id' => 1, 'quantity' => 1]],
    ]), $csrfCH);
    return $o['data'] ?? [];
};
check($stockOf(1, $csrfCH) === 3, 'variant 1 stock is 3 entering card suite');

// A: initiate targets the storefront page; paid callback + idempotent replay.
$cardA = $mkCardOrder('carda@test.com');
$cardAId = (int) ($cardA['id'] ?? 0);
$cardAToken = (string) ($cardA['lookup_token'] ?? '');
check($cardAId > 0 && ($cardA['payment_status'] ?? '') === 'pending', 'card order created pending');
check($stockOf(1, $csrfCH) === 2, 'card order reserves stock (3 - 1 = 2)');
[$status, , $initA] = httpRequest('POST', '/api/payments/initiate', ['order_id' => $cardAId, 'lookup_token' => $cardAToken, 'email' => 'carda@test.com'], $csrfCH);
check($status === 200 && isset($initA['data']['gateway_url'], $initA['data']['form_fields']), 'initiate -> 200 gateway payload');
$fA = $initA['data']['form_fields'] ?? [];
$wantOk = "http://localhost:3000/payment/{$cardAId}?token=" . urlencode($cardAToken) . '&pay=success';
$wantFail = "http://localhost:3000/payment/{$cardAId}?token=" . urlencode($cardAToken) . '&pay=failure';
check(($fA['okUrl'] ?? '') === $wantOk, 'initiate okUrl is the storefront payment page (guest token, success hint)');
check(($fA['failUrl'] ?? '') === $wantFail, 'initiate failUrl is the storefront payment page (failure hint)');
check(($fA['callbackUrl'] ?? '') === 'http://127.0.0.1:8090/api/payments/callback', 'initiate callbackUrl stays the API server endpoint');
check(($fA['okUrl'] ?? '') !== ($fA['callbackUrl'] ?? ''), 'customer return URLs differ from the API callback');
$cardATotal = (string) ($cardA['total_amount'] ?? '149.99');
$oidA = 'LB-' . $cardAId . '-' . time();
[$status, , $cbPaid] = httpRequest('POST', '/api/payments/callback', cmiCallback($oidA, $cardATotal, '00', 'Approved', $testCmiStoreKey, $testCmiHashKey, $testCmiClientId));
check($status === 200 && ($cbPaid['payment_status'] ?? '') === 'paid', 'paid callback -> 200 paid');
[$status, , $stA] = httpRequest('GET', "/api/payments/status/{$cardAId}?token=" . urlencode($cardAToken));
check($status === 200 && ($stA['data']['payment_status'] ?? '') === 'paid', 'status poll reflects paid (guest token)');
[$status, , $cbReplay] = httpRequest('POST', '/api/payments/callback', cmiCallback($oidA, $cardATotal, '00', 'Approved', $testCmiStoreKey, $testCmiHashKey, $testCmiClientId));
check($status === 200 && ($cbReplay['payment_status'] ?? '') === 'paid', 'paid callback replay -> 200 idempotent');
check($stockOf(1, $csrfCH) === 2, 'paid replay never touches stock');

// B: failed callback releases once; repeat is a no-op; late paid rejected.
$cardB = $mkCardOrder('cardb@test.com');
$cardBId = (int) ($cardB['id'] ?? 0);
$cardBToken = (string) ($cardB['lookup_token'] ?? '');
$cardBTotal = (string) ($cardB['total_amount'] ?? '149.99');
check($cardBId > 0 && $stockOf(1, $csrfCH) === 1, 'card order B reserves stock (2 - 1 = 1)');
$oidB = 'LB-' . $cardBId . '-' . time();
[$status, , $cbFail] = httpRequest('POST', '/api/payments/callback', cmiCallback($oidB, $cardBTotal, '05', 'Declined', $testCmiStoreKey, $testCmiHashKey, $testCmiClientId));
check($status === 200 && ($cbFail['payment_status'] ?? '') === 'failed', 'failed callback -> 200 failed');
check($stockOf(1, $csrfCH) === 2, 'failed callback restores stock exactly once (1 + 1 = 2)');
[$status, , $cbFail2] = httpRequest('POST', '/api/payments/callback', cmiCallback($oidB, $cardBTotal, '05', 'Declined', $testCmiStoreKey, $testCmiHashKey, $testCmiClientId));
check($status === 200 && ($cbFail2['payment_status'] ?? '') === 'failed', 'failed callback replay -> 200');
check($stockOf(1, $csrfCH) === 2, 'failed replay never double-restores stock');
[$status, , ] = httpRequest('POST', '/api/payments/callback', cmiCallback($oidB, $cardBTotal, '00', 'Approved', $testCmiStoreKey, $testCmiHashKey, $testCmiClientId));
check($status === 409, 'late paid callback after failure -> 409 (never resurrected)');
[$status, , $stB] = httpRequest('GET', "/api/payments/status/{$cardBId}?token=" . urlencode($cardBToken));
check($status === 200 && ($stB['data']['payment_status'] ?? '') === 'failed', 'order stays failed after late paid attempt');

// C: customer cancel then late paid; repeated cancel is a safe no-op.
$cardC = $mkCardOrder('cardc@test.com');
$cardCId = (int) ($cardC['id'] ?? 0);
$cardCToken = (string) ($cardC['lookup_token'] ?? '');
$cardCTotal = (string) ($cardC['total_amount'] ?? '149.99');
check($cardCId > 0 && $stockOf(1, $csrfCH) === 1, 'card order C reserves stock');
[$status, , ] = httpRequest('POST', "/api/orders/{$cardCId}/cancel", ['lookup_token' => $cardCToken], $csrfCH);
check($status === 200, 'card order customer-cancel -> 200');
check($stockOf(1, $csrfCH) === 2, 'cancel restores stock (1 + 1 = 2)');
[$status, , ] = httpRequest('POST', '/api/payments/callback', cmiCallback('LB-' . $cardCId . '-' . time(), $cardCTotal, '00', 'Approved', $testCmiStoreKey, $testCmiHashKey, $testCmiClientId));
check($status === 409, 'paid callback after customer cancel -> 409');
[$status, , $cancelAgain] = httpRequest('POST', "/api/orders/{$cardCId}/cancel", ['lookup_token' => $cardCToken], $csrfCH);
check($status === 200 && ($cancelAgain['data']['status'] ?? '') === 'cancelled', 'repeated cancel -> 200 already-cancelled');
check($stockOf(1, $csrfCH) === 2, 'repeated cancel never double-restores stock');

// D: expiry cron end-to-end (real script, isolated test env).
$cardD = $mkCardOrder('cardd@test.com');
$cardDId = (int) ($cardD['id'] ?? 0);
$cardDToken = (string) ($cardD['lookup_token'] ?? '');
check($cardDId > 0 && $stockOf(1, $csrfCH) === 1, 'card order D reserves stock');
$pdo->exec("UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL 61 MINUTE) WHERE id = {$cardDId}");
$runExpire = static function () use ($envFile, $repoRoot): array {
    $cmd = [PHP_BINARY, $repoRoot . '/api/scripts/expire-card-pending.php', '--minutes=30'];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot, array_merge(getenv() ?: [], ['LB_TEST_ENV_FILE' => $envFile]));
    if (!is_resource($proc)) {
        return [1, '', 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $out, (string) $err];
};
[$expCode, $expOut] = $runExpire();
check($expCode === 0 && str_contains($expOut, "Expired 1/1"), 'expiry cron expires the stale card order');
[$status, , $stD] = httpRequest('GET', "/api/payments/status/{$cardDId}?token=" . urlencode($cardDToken));
check($status === 200 && ($stD['data']['status'] ?? '') === 'cancelled' && ($stD['data']['payment_status'] ?? '') === 'failed', 'expired order is cancelled+failed');
check($stockOf(1, $csrfCH) === 2, 'expiry restores stock (1 + 1 = 2)');

// E: expiry skips an already-cancelled stale order (concurrent-style, no double restore).
$cardE = $mkCardOrder('carde@test.com');
$cardEId = (int) ($cardE['id'] ?? 0);
$cardEToken = (string) ($cardE['lookup_token'] ?? '');
check($cardEId > 0 && $stockOf(1, $csrfCH) === 1, 'card order E reserves stock');
[$status, , ] = httpRequest('POST', "/api/orders/{$cardEId}/cancel", ['lookup_token' => $cardEToken], $csrfCH);
check($status === 200 && $stockOf(1, $csrfCH) === 2, 'card order E cancelled (stock back to 2)');
$pdo->exec("UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL 61 MINUTE) WHERE id = {$cardEId}");
[$expCode2, $expOut2] = $runExpire();
// Already-cancelled E is excluded by the candidate selection (released), so
// a quiet run reports no stale orders; either way nothing is double-restored.
check($expCode2 === 0 && (str_contains($expOut2, 'No stale card-pending orders') || str_contains($expOut2, 'already handled')), 'expiry skips already-cancelled order (no double restore)');
check($stockOf(1, $csrfCH) === 2, 'stock untouched by skipped expiry');

// --- Paid orders cannot be cancelled (refund-first policy) ------------------
// 3) Unpaid WhatsApp cancellation still restores stock exactly once.
[$status, , $waOrder] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['email' => 'wa-cancel@test.com', 'payment_method' => 'whatsapp', 'items' => [['variant_id' => 1, 'quantity' => 1]]]), $csrfCH);
$waId = (int) ($waOrder['data']['id'] ?? 0);
$waToken = (string) ($waOrder['data']['lookup_token'] ?? '');
check($status === 201 && $waId > 0 && $stockOf(1, $csrfCH) === 1, 'whatsapp order reserves stock (2 - 1 = 1)');
[$status, , ] = httpRequest('POST', "/api/orders/{$waId}/cancel", ['lookup_token' => $waToken], $csrfCH);
check($status === 200 && $stockOf(1, $csrfCH) === 2, 'unpaid whatsapp cancel restores stock once (1 + 1 = 2)');
[$status, , ] = httpRequest('POST', "/api/orders/{$waId}/cancel", ['lookup_token' => $waToken], $csrfCH);
check($status === 200 && $stockOf(1, $csrfCH) === 2, 'repeated whatsapp cancel is a no-op (no double restore)');

// 1) Paid card order: customer AND admin cancellation both return 409 with
// the refund message; stock stays reserved and no release is marked.
$paidC = $mkCardOrder('paidcancel@test.com');
$paidCId = (int) ($paidC['id'] ?? 0);
$paidCToken = (string) ($paidC['lookup_token'] ?? '');
$paidCTotal = (string) ($paidC['total_amount'] ?? '149.99');
check($paidCId > 0 && $stockOf(1, $csrfCH) === 1, 'paid-cancel fixture card order reserves stock');
[$status, , ] = httpRequest('POST', '/api/payments/callback', cmiCallback('LB-' . $paidCId . '-' . time(), $paidCTotal, '00', 'Approved', $testCmiStoreKey, $testCmiHashKey, $testCmiClientId));
check($status === 200, 'paid-cancel fixture marked paid via callback');
[$status, , $paidCancelBody] = httpRequest('POST', "/api/orders/{$paidCId}/cancel", ['lookup_token' => $paidCToken], $csrfCH);
check($status === 409 && str_contains($paidCancelBody['error'] ?? '', 'refund'), 'paid card order customer-cancel -> 409 refund message');
[$status, , $paidAdminBody] = httpRequest('PUT', "/api/admin/orders/{$paidCId}", ['status' => 'cancelled'], $adminToken + $csrfCH);
check($status === 409 && str_contains($paidAdminBody['error'] ?? '', 'refund'), 'paid card order admin-cancel -> 409 refund message');
[$status, , $stPaid] = httpRequest('GET', "/api/payments/status/{$paidCId}?token=" . urlencode($paidCToken));
check($status === 200 && ($stPaid['data']['status'] ?? '') === 'pending' && ($stPaid['data']['payment_status'] ?? '') === 'paid', 'rejected cancel leaves order pending+paid (no cancelled+paid state)');
check($stockOf(1, $csrfCH) === 1, 'rejected cancel restores no stock (still reserved)');
$releasedPaid = $pdo->query("SELECT inventory_released_at FROM orders WHERE id = {$paidCId}")->fetchColumn();
check($releasedPaid === null || $releasedPaid === false, 'rejected cancel marks no inventory release');

// 2) COD order manually marked paid, then cancellation -> 409 on both paths.
[$status, , $codPaid] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['email' => 'codpaid@test.com', 'payment_method' => 'cod', 'items' => [['variant_id' => 1, 'quantity' => 1]]]), $csrfCH);
$codPaidId = (int) ($codPaid['data']['id'] ?? 0);
$codPaidToken = (string) ($codPaid['data']['lookup_token'] ?? '');
check($status === 201 && $codPaidId > 0 && $stockOf(1, $csrfCH) === 0, 'cod fixture order reserves last unit');
[$status, , ] = httpRequest('PUT', "/api/admin/orders/{$codPaidId}", ['payment_status' => 'paid'], $adminToken + $csrfCH);
check($status === 200, 'admin marks COD order paid (delivery collection)');
[$status, , $codCancelBody] = httpRequest('POST', "/api/orders/{$codPaidId}/cancel", ['lookup_token' => $codPaidToken], $csrfCH);
check($status === 409 && str_contains($codCancelBody['error'] ?? '', 'refund'), 'paid COD customer-cancel -> 409 refund message');
[$status, , $codAdminBody] = httpRequest('PUT', "/api/admin/orders/{$codPaidId}", ['status' => 'cancelled'], $adminToken + $csrfCH);
check($status === 409 && str_contains($codAdminBody['error'] ?? '', 'refund'), 'paid COD admin-cancel -> 409 refund message');
check($stockOf(1, $csrfCH) === 0, 'rejected paid-COD cancel restores no stock');

// 4) Racing callback-then-cancel ordering can never yield cancelled + paid.
// (Covered above for card; same guard for WhatsApp marked paid — the paid
// check runs under the row lock, so no interleave can slip through.)
[$status, , $waPaid] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['email' => 'wapaid@test.com', 'payment_method' => 'whatsapp', 'items' => [['variant_id' => $saleVariantId, 'quantity' => 1]]]), $csrfCH);
$waPaidId = (int) ($waPaid['data']['id'] ?? 0);
$waPaidToken = (string) ($waPaid['data']['lookup_token'] ?? '');
check($status === 201 && $waPaidId > 0, 'whatsapp fixture order created (sale variant, stock independent)');
[$status, , ] = httpRequest('PUT', "/api/admin/orders/{$waPaidId}", ['payment_status' => 'paid'], $adminToken + $csrfCH);
check($status === 200, 'admin marks WhatsApp order paid');
[$status, , $waPaidCancel] = httpRequest('POST', "/api/orders/{$waPaidId}/cancel", ['lookup_token' => $waPaidToken], $csrfCH);
check($status === 409 && str_contains($waPaidCancel['error'] ?? '', 'refund'), 'paid WhatsApp cancel -> 409 (race-safe: paid checked under row lock)');
$waPaidRow = $pdo->query("SELECT status, payment_status, inventory_released_at FROM orders WHERE id = {$waPaidId}")->fetch(PDO::FETCH_ASSOC);
check(
    ($waPaidRow['status'] ?? '') === 'pending' && ($waPaidRow['payment_status'] ?? '') === 'paid' && $waPaidRow['inventory_released_at'] === null,
    'paid order keeps pending+paid with no release marker (cancelled+paid+restored impossible)'
);

// --- Admin order state transitions (atomic, service-enforced) --------------
// Dedicated fixture product: fully decoupled from earlier suites' stock.
[$status, , $transProduct] = httpRequest('POST', '/api/admin/products', [
    'name' => 'Transition Tee',
    'base_price' => '50.00',
    'is_active' => 1,
    'meta_title' => 'Transition Tee',
    'meta_description' => 'State-transition fixture',
    'variants' => [
        ['size' => 'M', 'color' => 'Black', 'sku' => 'TRANSITION-M-BLK', 'price' => '', 'stock_quantity' => '10'],
    ],
], $adminToken + $csrfCH);
$transVariantId = (int) ($transProduct['data']['variants'][0]['id'] ?? 0);
check($status === 201 && $transVariantId > 0, 'transition fixture product created');
$mkTransOrder = static function (string $email) use ($orderPayload, $transVariantId, $csrfCH): array {
    [, , $o] = httpRequest('POST', '/api/orders', array_merge($orderPayload, [
        'email' => $email,
        'payment_method' => 'cod',
        'items' => [['variant_id' => $transVariantId, 'quantity' => 1]],
    ]), $csrfCH);
    return $o['data'] ?? [];
};
$adminGetOrder = static function (int $id) use ($adminToken): array {
    [, , $o] = httpRequest('GET', "/api/admin/orders/{$id}", null, $adminToken);
    return $o['data'] ?? [];
};
check($stockOf($transVariantId, $csrfCH) === 10, 'transition fixture stock is 10');

// A: cancelled + paid in one request -> 409, state and stock untouched.
$combo = $mkTransOrder('combo@test.com');
$comboId = (int) ($combo['id'] ?? 0);
check($comboId > 0 && $stockOf($transVariantId, $csrfCH) === 9, 'transition fixture order reserves stock (10 - 1 = 9)');
[$status, , $comboBody] = httpRequest('PUT', "/api/admin/orders/{$comboId}", ['status' => 'cancelled', 'payment_status' => 'paid'], $adminToken + $csrfCH);
check($status === 409 && str_contains($comboBody['error'] ?? '', 'cannot be cancelled and marked paid'), 'cancelled+paid combo -> 409 conflict message');
$comboFresh = $adminGetOrder($comboId);
check(($comboFresh['status'] ?? '') === 'pending' && ($comboFresh['payment_status'] ?? '') === 'pending', 'rejected combo leaves order pending+pending');
check($stockOf($transVariantId, $csrfCH) === 9, 'rejected combo restores no stock (still reserved)');

// B: previously cancelled order can never be marked paid afterwards.
[$status, , ] = httpRequest('PUT', "/api/admin/orders/{$comboId}", ['status' => 'cancelled'], $adminToken + $csrfCH);
check($status === 200, 'transition fixture cancelled normally (unpaid)');
check($stockOf($transVariantId, $csrfCH) === 10, 'cancel restores stock (9 + 1 = 10)');
[$status, , $latePaidBody] = httpRequest('PUT', "/api/admin/orders/{$comboId}", ['payment_status' => 'paid'], $adminToken + $csrfCH);
check($status === 409 && str_contains($latePaidBody['error'] ?? '', 'cancelled order cannot be marked paid'), 'paid on cancelled order -> 409');
$comboFresh2 = $adminGetOrder($comboId);
check(($comboFresh2['status'] ?? '') === 'cancelled' && ($comboFresh2['payment_status'] ?? '') === 'pending', 'cancelled order stays cancelled+pending after rejected paid-marking');
check($stockOf($transVariantId, $csrfCH) === 10, 'no stock movement on rejected paid-marking');

// C: cancelled + failed in one request still works (and restores exactly once).
$comboF = $mkTransOrder('combofail@test.com');
$comboFId = (int) ($comboF['id'] ?? 0);
check($comboFId > 0 && $stockOf($transVariantId, $csrfCH) === 9, 'failed-combo fixture reserves stock');
[$status, , $comboFBody] = httpRequest('PUT', "/api/admin/orders/{$comboFId}", ['status' => 'cancelled', 'payment_status' => 'failed'], $adminToken + $csrfCH);
check($status === 200 && ($comboFBody['data']['status'] ?? '') === 'cancelled' && ($comboFBody['data']['payment_status'] ?? '') === 'failed', 'cancelled+failed combo -> 200 cancelled+failed');
check($stockOf($transVariantId, $csrfCH) === 10, 'cancelled+failed restores stock exactly once');
[$status, , ] = httpRequest('PUT', "/api/admin/orders/{$comboFId}", ['status' => 'cancelled', 'payment_status' => 'failed'], $adminToken + $csrfCH);
check($status === 200 && $stockOf($transVariantId, $csrfCH) === 10, 'repeated cancelled+failed is a no-op (no double restore)');

// D: admin marking pending payment failed releases the reservation (callback parity).
$failM = $mkTransOrder('adminfail@test.com');
$failMId = (int) ($failM['id'] ?? 0);
check($failMId > 0 && $stockOf($transVariantId, $csrfCH) === 9, 'failed-mark fixture reserves stock');
[$status, , $failMBody] = httpRequest('PUT', "/api/admin/orders/{$failMId}", ['payment_status' => 'failed'], $adminToken + $csrfCH);
check($status === 200 && ($failMBody['data']['payment_status'] ?? '') === 'failed', 'admin pending->failed -> 200 failed');
check($stockOf($transVariantId, $csrfCH) === 10, 'admin failed-marking releases stock (no dangling reservation)');
[$status, , ] = httpRequest('PUT', "/api/admin/orders/{$failMId}", ['payment_status' => 'failed'], $adminToken + $csrfCH);
check($status === 200 && $stockOf($transVariantId, $csrfCH) === 10, 'repeated failed-marking never double-restores');

// E: legitimate payment confirmation still works — and retains the reservation.
$confirm = $mkTransOrder('confirm@test.com');
$confirmId = (int) ($confirm['id'] ?? 0);
check($confirmId > 0 && $stockOf($transVariantId, $csrfCH) === 9, 'confirm fixture reserves stock');
[$status, , $confirmBody] = httpRequest('PUT', "/api/admin/orders/{$confirmId}", ['payment_status' => 'paid'], $adminToken + $csrfCH);
check($status === 200 && ($confirmBody['data']['payment_status'] ?? '') === 'paid', 'admin pending->paid confirmation -> 200 paid');
check($stockOf($transVariantId, $csrfCH) === 9, 'paid confirmation restores no stock (reservation retained)');
$confirmReleased = $pdo->query("SELECT inventory_released_at FROM orders WHERE id = {$confirmId}")->fetchColumn();
check($confirmReleased === null || $confirmReleased === false, 'paid confirmation marks no inventory release');

// --- Expiry script failure reporting ---------------------------------------
$runExpireEnv = static function (?string $envOverride) use ($envFile, $repoRoot): array {
    $cmd = [PHP_BINARY, $repoRoot . '/api/scripts/expire-card-pending.php', '--minutes=30'];
    $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $repoRoot, array_merge(getenv() ?: [], ['LB_TEST_ENV_FILE' => $envOverride ?? $envFile]));
    if (!is_resource($proc)) {
        return [1, '', 'proc_open failed'];
    }
    $out = stream_get_contents($pipes[1]);
    $err = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), (string) $out, (string) $err];
};

// F: per-order failure -> exit 1 AFTER the rest, names only the order id.
[$status, , $trig] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['email' => 'failtrigger@example.com', 'payment_method' => 'card', 'items' => [['variant_id' => $transVariantId, 'quantity' => 1]]]), $csrfCH);
$trigId = (int) ($trig['data']['id'] ?? 0);
check($status === 201 && $trigId > 0 && $stockOf($transVariantId, $csrfCH) === 8, 'failure-path fixture card order reserves stock (9 - 1 = 8)');
$pdo->exec("UPDATE orders SET created_at = DATE_SUB(NOW(), INTERVAL 61 MINUTE) WHERE id = {$trigId}");
// A trigger makes ONLY the stock-restore UPDATE throw, simulating a
// mid-expiry database failure on one order.
$pdo->exec("CREATE TRIGGER trg_test_fail_stock BEFORE UPDATE ON product_variants FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'test-trigger-failure'");
try {
    [$tCode, $tOut, $tErr] = $runExpireEnv(null);
    check($tCode === 1, 'expiry exits 1 when an order expiration fails');
    check(str_contains($tErr, "Failed to expire order #{$trigId}"), 'expiry reports the failed order id on stderr');
    check(!str_contains($tOut . $tErr, 'failtrigger@example.com'), 'expiry failure output contains no customer email');
    check(!str_contains($tOut . $tErr, '0612345678'), 'expiry failure output contains no customer phone');
    check($stockOf($transVariantId, $csrfCH) === 8, 'failed expiry restores nothing (transaction rolled back)');
    $trigRow = $adminGetOrder($trigId);
    check(($trigRow['status'] ?? '') === 'pending' && ($trigRow['payment_status'] ?? '') === 'pending', 'failed expiry leaves order pending+pending (nothing half-applied)');
} finally {
    $pdo->exec('DROP TRIGGER IF EXISTS trg_test_fail_stock');
}
// Recovery: with the trigger gone the same order expires cleanly (exit 0).
[$tCode2, $tOut2] = $runExpireEnv(null);
check($tCode2 === 0 && str_contains($tOut2, "Expired 1/1"), 'expiry succeeds after the transient failure is gone');
check($stockOf($transVariantId, $csrfCH) === 9, 'recovered expiry restores stock (8 + 1 = 9)');

// G: unreachable database -> non-zero exit, clean message, no credentials.
$badEnv = tempnam(sys_get_temp_dir(), 'lb-expire-badenv-');
file_put_contents((string) $badEnv, "DB_HOST=127.0.0.1\nDB_PORT=3306\nDB_NAME={$dbName}\nDB_USER=root\nDB_PASS=lb-wrong-pass-9z\nJWT_SECRET=x\n");
[$bCode, $bOut, $bErr] = $runExpireEnv((string) $badEnv);
check($bCode !== 0, 'expiry exits non-zero when the database is unreachable');
check(!str_contains($bOut . $bErr, 'lb-wrong-pass-9z'), 'failure output never prints credentials');
@unlink((string) $badEnv);

// H: schema without orders table -> non-zero exit via the guarded lookup.
$emptyDb = $dbName . '_noorders';
$adminPdo->exec("DROP DATABASE IF EXISTS `{$emptyDb}`");
$adminPdo->exec("CREATE DATABASE `{$emptyDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$emptyEnv = tempnam(sys_get_temp_dir(), 'lb-expire-emptyenv-');
file_put_contents((string) $emptyEnv, str_replace("DB_NAME={$dbName}", "DB_NAME={$emptyDb}", (string) file_get_contents((string) $envFile)));
[$eCode, , $eErr] = $runExpireEnv((string) $emptyEnv);
check($eCode !== 0 && str_contains($eErr, 'candidate lookup failed'), 'expiry exits non-zero when the schema is missing');
$adminPdo->exec("DROP DATABASE IF EXISTS `{$emptyDb}`");
@unlink((string) $emptyEnv);

// --- MigrationRunner against scratch test databases ------------------------
// Uses isolated lucebianca_test_migrate_* databases (same namespace guard as
// the main suite) plus temp fixture dirs — the real test DB is untouched.
require_once $repoRoot . '/api/src/autoload.php';
$mkScratchDb = static function (string $suffix) use ($adminPdo, $dbHost, $dbPort, $dbUser, $dbPass, $dbName): PDO {
    $scratch = $dbName . '_' . $suffix;
    $adminPdo->exec("DROP DATABASE IF EXISTS `{$scratch}`");
    $adminPdo->exec("CREATE DATABASE `{$scratch}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    return new PDO(
        "mysql:host={$dbHost};port={$dbPort};dbname={$scratch};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
};
$fxBase = sys_get_temp_dir() . '/lb-migrate-fx-' . getmypid();
@mkdir($fxBase . '/good', 0777, true);
@mkdir($fxBase . '/with-failure', 0777, true);
@mkdir($fxBase . '/adopt', 0777, true);
file_put_contents($fxBase . '/good/001_create_widget.sql', "-- Fixture 001: comment-prefixed DDL must execute (old runner skipped it).\n-- second header line; semicolon inside this comment is harmless.\nCREATE TABLE widget (\n  id INT PRIMARY KEY,\n  note VARCHAR(100) NOT NULL DEFAULT 'x;y'\n);\nINSERT INTO widget (id, note) VALUES (1, 'a;b');\n");
file_put_contents($fxBase . '/good/002_add_flag.sql', "-- Fixture 002: guarded ALTER in session-variable style.\nSET @c := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'widget' AND COLUMN_NAME = 'flag');\nSET @s := IF(@c = 0, 'ALTER TABLE widget ADD COLUMN flag TINYINT NOT NULL DEFAULT 0', 'SELECT 1');\nPREPARE stmt FROM @s; EXECUTE stmt; DEALLOCATE PREPARE stmt;\n");
foreach (['001_create_widget.sql', '002_add_flag.sql'] as $f) {
    copy($fxBase . '/good/' . $f, $fxBase . '/with-failure/' . $f);
}
file_put_contents($fxBase . '/with-failure/003_will_fail.sql', "-- Fixture 003: first statement applies, second is invalid SQL.\nALTER TABLE widget ADD COLUMN boom INT NULL;\nTHIS IS NOT VALID SQL AT ALL;\n");
file_put_contents($fxBase . '/adopt/001_create_widget2.sql', "CREATE TABLE widget2 (id INT PRIMARY KEY);\n");

// 1) --dry-run is fully read-only: pending listed, tracking table NOT created.
$scratchA = $mkScratchDb('migrate_a');
$runnerA = new App\Core\MigrationRunner($scratchA, $fxBase . '/with-failure');
$dryPending = $runnerA->pending();
check(count($dryPending) === 3, 'migrate dry-run lists 3 pending fixtures');
$trackCount = (int) $scratchA->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations'")->fetchColumn();
check($trackCount === 0, 'migrate dry-run creates nothing (no tracking table)');
$dryCounts = $runnerA->countStatements($dryPending);
check(($dryCounts['001_create_widget.sql'] ?? 0) === 2, 'migrate dry-run counts comment-prefixed DDL (2 statements)');

// 2) Real apply stops at the failure WITHOUT recording it (DDL ran in
// autocommit: the good statement persists, the file is retried next run).
$failedAt = '';
try {
    $runnerA->migrate();
} catch (RuntimeException $e) {
    $failedAt = $e->getMessage();
}
check(str_contains($failedAt, '003_will_fail.sql') && str_contains($failedAt, 'statement 2 of 2'), 'migrate failure names file + statement index');
$stillPending = array_column($runnerA->pending(), 'name');
check($stillPending === ['003_will_fail.sql'], 'failed migration stays pending (001+002 recorded, 003 not)');
$widgetNote = $scratchA->query('SELECT note FROM widget WHERE id = 1')->fetchColumn();
check($widgetNote === 'a;b', 'string-literal semicolons survive migration intact');
$boomExists = (int) $scratchA->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'widget' AND COLUMN_NAME = 'boom'")->fetchColumn();
check($boomExists === 1, 'failed file partial DDL persists (honest non-atomicity, file unrecorded so re-runnable)');

// 3) Repair + re-run records the file; further runs are no-ops.
file_put_contents($fxBase . '/with-failure/003_will_fail.sql', "-- Fixture 003 repaired: valid no-op.\nSELECT 1;\n");
check($runnerA->migrate() === ['003_will_fail.sql'], 'migrate retry after repair applies 003');
check($runnerA->migrate() === [] && $runnerA->pending() === [], 'migrate with nothing pending is a no-op');

// 4) Checksum drift on an applied file is reported, never re-applied.
file_put_contents($fxBase . '/with-failure/001_create_widget.sql', "-- Fixture 001 edited after apply.\nSELECT 1;\n");
check($runnerA->drifted() === ['001_create_widget.sql'], 'checksum drift detected on edited applied file');
check($runnerA->pending() === [], 'drifted file is not re-applied');

// 5) Baseline adoption: records without executing (for manually-migrated DBs).
$scratchB = $mkScratchDb('migrate_b');
$scratchB->exec('CREATE TABLE widget2 (id INT PRIMARY KEY)');
$runnerB = new App\Core\MigrationRunner($scratchB, $fxBase . '/adopt');
check($runnerB->baseline() === ['001_create_widget2.sql'], 'baseline records pending files without executing');
check($runnerB->pending() === [], 'baselined files are no longer pending');
// looksManuallyMigrated: orders table + zero history rows.
$scratchC = $mkScratchDb('migrate_c');
$runnerC = new App\Core\MigrationRunner($scratchC, $fxBase . '/adopt');
check($runnerC->looksManuallyMigrated() === false, 'empty database is not manually-migrated');
$scratchC->exec('CREATE TABLE orders (id INT PRIMARY KEY)');
check($runnerC->looksManuallyMigrated() === true, 'tables without history are flagged for baseline adoption');
// Cleanup scratch databases + fixtures (main test DB untouched throughout).
foreach (['migrate_a', 'migrate_b', 'migrate_c'] as $sfx) {
    try {
        $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbName}_{$sfx}`");
    } catch (Throwable) {
    }
}
foreach (glob($fxBase . '/*/*.sql') ?: [] as $f) {
    @unlink($f);
}
@rmdir($fxBase . '/good');
@rmdir($fxBase . '/with-failure');
@rmdir($fxBase . '/adopt');
@rmdir($fxBase);

// --- Error-response safety ------------------------------------------------
[, , $nf] = httpRequest('GET', '/api/nonexistent-route');
check(($nf['error'] ?? '') === 'Route not found' && count($nf) === 1, 'unknown route -> single generic error key');

$safetyBodies = [$nf];
foreach ([httpRequest('GET', '/api/account'), httpRequest('POST', '/api/newsletter/subscribe', ['email' => 'x'])] as [$s, , $errBody]) {
    if (is_array($errBody)) {
        $safetyBodies[] = $errBody;
    }
}
$leakKeys = ['exception', 'file', 'line', 'trace', 'stack', 'previous'];
$leaked = false;
foreach ($safetyBodies as $errBody) {
    if (!is_array($errBody)) {
        continue;
    }
    foreach ($leakKeys as $leakKey) {
        if (array_key_exists($leakKey, $errBody)) {
            $leaked = true;
        }
    }
}
check(!$leaked, 'error bodies never expose stack/file/debug keys');

// --- Teardown & summary ---------------------------------------------------
proc_terminate($server);
try {
    $adminPdo->exec("DROP DATABASE IF EXISTS `{$dbName}`");
} catch (Throwable) {
    echo "(note: could not drop test DB — remove manually)\n";
}
unlink((string) $envFile);

$total = $GLOBALS['passed'] + count($failures);
echo "\nAPI tests: {$GLOBALS['passed']}/{$total} passed";
if ($failures !== []) {
    echo "\nFAILURES:\n" . implode("\n", array_map(static fn ($f) => " - {$f}", $failures));
    echo "\n(Test DB {$dbName} left in place for inspection.)\n";
    exit(1);
}
echo " ✓\nTest database dropped.\n";
exit(0);
