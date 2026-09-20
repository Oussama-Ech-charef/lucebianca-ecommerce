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
 *  2. Recreates the test schema from database/schema.sql snapshot (18 tables,
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
$apiEnvPath = $repoRoot . '/.env';
$apiEnv = is_file($apiEnvPath) ? parseEnvFile($apiEnvPath) : [];

$dbHost = (string) (getenv('LB_TEST_DB_HOST') ?: ($apiEnv['DB_HOST'] ?? '127.0.0.1'));
$dbPort = (string) (getenv('LB_TEST_DB_PORT') ?: ($apiEnv['DB_PORT'] ?? '3306'));
$dbUser = (string) (getenv('LB_TEST_DB_USER') ?: ($apiEnv['DB_USER'] ?? 'root'));
// Empty string is a valid password; only an unset variable falls back.
$envDbPass = getenv('LB_TEST_DB_PASS');
$dbPass = $envDbPass === false ? ($apiEnv['DB_PASS'] ?? '') : (string) $envDbPass;
$dbName = (string) (getenv('LB_TEST_DB_NAME') ?: 'lucebianca_test');

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

ENV);

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
// Snapshot is the deterministic source (18 tables, includes 015); migrations
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
echo "Schema ready (snapshot: database/schema.sql, 18 tables)\n";

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

// --- Reviews (session + CSRF mutations) ----------------------------------
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
// Repository records non-card orders as paid at placement (COD collected on
// delivery); only card starts pending until the CMI flow completes.
check(($order['data']['payment_status'] ?? '') === 'paid', 'COD order recorded with payment_status=paid');

$orderItems = $order['data']['items'] ?? [];
$lineUnitPrice = (float) ($orderItems[0]['price_at_purchase'] ?? -1);
check(
    abs($lineUnitPrice - 149.99) < 0.001
    && ($orderItems[0]['sku'] ?? '') === 'TEST-TEE-M-BLK',
    'order line snapshots sku and unit price at purchase'
);

$orderId = (int) ($order['data']['id'] ?? 0);
[$status, , $fetched] = httpRequest('GET', "/api/orders/{$orderId}");
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

[$status, ] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['items' => [['variant_id' => 1, 'quantity' => 99]]]), $csrfOrd);
check($status === 409, 'over-stock order -> 409 conflict');

[$status, , $body] = httpRequest('POST', '/api/orders', array_merge($orderPayload, ['phone' => 'bad']), $csrfOrd);
check($status === 422 && isset($body['errors']['phone']), 'invalid phone -> 422 field error');

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
check($status === 201 && ($orderSale['data']['total_amount'] ?? '') === '160.00' && ($orderSale['data']['items'][0]['price_at_purchase'] ?? '') === '80.00', 'order total uses discounted sale price');

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
check($status === 201 && ($orderWA['data']['total_amount'] ?? '') === '80.00' && ($orderWA['data']['payment_method'] ?? '') === 'whatsapp', 'whatsapp order with valid address/city/postal_code -> 201');

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
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, shipping_address, city, postal_code, customer_name, phone, email, payment_method, payment_status, created_at) VALUES (NULL, 'delivered', '100.00', '123 Test St', 'Casa', '20000', 'Dash COD Pending', '0612345678', 'dashcod@test.com', 'cod', 'pending', NOW())")->execute();
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
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, shipping_address, city, postal_code, customer_name, phone, email, payment_method, payment_status, created_at) VALUES (NULL, 'pending', '77.77', '123 Test St', 'Casa', '20000', 'Dash Pending Cod', '0612345678', 'dashpending@test.com', 'cod', 'pending', NOW())")->execute();
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
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, shipping_address, city, postal_code, customer_name, phone, email, payment_method, payment_status, created_at) VALUES (NULL, 'cancelled', '88.88', '123 Test St', 'Casa', '20000', 'Dash Cancelled', '0612345678', 'dashcancel@test.com', 'cod', 'pending', NOW())")->execute();
$oid3 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase) VALUES (:oid, 1, 1, '88.88')")->execute([':oid' => $oid3]);

[$status, , $dash3] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200, 'dashboard after cancelled COD pending -> 200');
check(abs((float)($dash3['data']['overview']['total_sales'] ?? 0) - ($salesBefore + 100.0)) < 0.01, 'cancelled order excluded from total_sales even with COD');
check((int)($dash3['data']['overview']['total_orders'] ?? 0) === $ordersBefore + 1, 'cancelled order excluded from total_orders');
check((int)(($dash3['data']['order_status_breakdown']['cancelled'] ?? 0)) === $cancelledBefore + 1, 'order_status_breakdown increments cancelled (shows all)');

// 4) Insert a delivered WhatsApp order with payment_status pending — must be REALIZED and increment again.
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, shipping_address, city, postal_code, customer_name, phone, email, payment_method, payment_status, created_at) VALUES (NULL, 'delivered', '55.00', '123 Test St', 'Casa', '20000', 'Dash WA Pending', '0612345678', 'dashwa@test.com', 'whatsapp', 'pending', NOW())")->execute();
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
$pdo->prepare("INSERT INTO orders (user_id, status, total_amount, shipping_address, city, postal_code, customer_name, phone, email, payment_method, payment_status, created_at) VALUES (NULL, 'processing', '40.00', '123 Test St', 'Casa', '20000', 'Dash Processing Cod', '0612345678', 'dashproc@test.com', 'cod', 'pending', NOW())")->execute();
$oid5 = (int) $pdo->lastInsertId();
$pdo->prepare("INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase) VALUES (:oid, 1, 1, '40.00')")->execute([':oid' => $oid5]);
[$status, , $dash5] = httpRequest('GET', '/api/admin/dashboard-stats', null, $adminToken);
check($status === 200, 'dashboard after processing COD pending -> 200');
check(abs((float)($dash5['data']['overview']['total_sales'] ?? 0) - ($salesBefore + 195.0)) < 0.01, 'processing COD pending counts toward total_sales');

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
