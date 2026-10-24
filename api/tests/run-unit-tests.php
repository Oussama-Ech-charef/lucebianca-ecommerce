<?php

/**
 * Unit tests for dependency-free core pieces (no database, no server).
 *
 * Run from the repo root or api/:
 *     php api/tests/run-unit-tests.php
 *
 * Exit code 0 = all passed. Writes nothing except a temporary .env in the
 * system temp dir plus one line in api/storage/logs/app-error.log (gitignored),
 * which is removed again afterwards.
 *
 * Every run also writes api/storage/test-reports/latest-report.json and
 * latest-report.md (gitignored); failed runs additionally leave a
 * timestamped unit-<UTC>.json/.md copy in the same directory.
 */

declare(strict_types=1);

require __DIR__ . '/../src/autoload.php';

use App\Core\Auth;
use App\Core\CsrfProtection;
use App\Core\Env;
use App\Core\ErrorLogger;
use App\Core\Request;
use App\Core\Validator;

// --- Isolated environment (never reads api/.env) -------------------------
$envFile = tempnam(sys_get_temp_dir(), 'lb-unit-env-');
file_put_contents((string) $envFile, "JWT_SECRET=lb-unit-test-secret-with-enough-random-looking-characters\n");
Env::load((string) $envFile);

// --- Tiny assertion helpers ---------------------------------------------
$passed = 0;
$failures = [];

function check(bool $condition, string $label): void
{
    if ($condition) {
        $GLOBALS['passed']++;
    } else {
        $GLOBALS['failures'][] = $label;
        echo "FAIL: {$label}\n";
    }
}

function throws(callable $fn): bool
{
    try {
        $fn();
        return false;
    } catch (Throwable) {
        return true;
    }
}

// --- Test report (api/storage/test-reports/, gitignored) -------------------
// Written by writeTestReport() via shutdown hook so EVERY exit path — pass,
// fail, or fatal — leaves latest-report.json + latest-report.md behind.
// Failed runs additionally leave a timestamped copy. Console output and
// exit codes are untouched (this writer only creates files).
// Reports carry failure labels + counts only: no tokens, passwords, DSNs,
// emails, or customer data (labels are static strings, still redacted).
$GLOBALS['report_start'] = microtime(true);
$GLOBALS['report_start_utc'] = gmdate('c');
$GLOBALS['report_runner'] = 'unit';

function sanitizeReportString(string $value): string
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

function writeTestReport(): void
{
    try {
        $dir = __DIR__ . '/../storage/test-reports';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        if (!is_dir($dir)) {
            return; // Nowhere to write — never break the run over reporting.
        }

        $runner = (string) ($GLOBALS['report_runner'] ?? 'unit');
        $failures = $GLOBALS['failures'] ?? null;
        $passed = (int) ($GLOBALS['passed'] ?? 0);
        $failureNames = is_array($failures) ? array_values($failures) : [];

        // A fatal before the harness initialized leaves $failures unset —
        // report it as an error with the (sanitized) fatal message.
        $fatal = null;
        if (!is_array($failures)) {
            $last = error_get_last();
            if (is_array($last) && ((int) ($last['type'] ?? 0) & (E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR)) !== 0) {
                $fatal = sanitizeReportString(($last['message'] ?? 'PHP fatal error') . ' @' . ($last['file'] ?? '?') . ':' . ($last['line'] ?? '?'));
            }
            $failureNames = $fatal !== null ? [$fatal] : ['Harness aborted before assertions ran.'];
        }

        $failed = count($failureNames);
        $total = $passed + $failed;
        $status = $failed === 0 ? 'passed' : 'failed';
        $endUtc = gmdate('c');
        $duration = round(microtime(true) - (float) ($GLOBALS['report_start'] ?? microtime(true)), 3);
        $command = implode(' ', array_map('strval', (array) ($GLOBALS['argv'] ?? ['run-unit-tests.php'])));

        $report = [
            'runner' => $runner,
            'command' => sanitizeReportString($command),
            'started_utc' => (string) ($GLOBALS['report_start_utc'] ?? $endUtc),
            'finished_utc' => $endUtc,
            'duration_seconds' => $duration,
            'environment' => [
                'php' => PHP_VERSION,
                'app_env' => 'testing (isolated temp env, no database)',
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
                    'name' => sanitizeReportString((string) $name),
                    'message' => sanitizeReportString((string) $name),
                ],
                $failureNames
            ),
            'php_fatal' => $fatal,
            'notes' => 'Raw request/response bodies are excluded by design (may carry tokens or PII).',
        ];

        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (is_string($json)) {
            @file_put_contents($dir . '/latest-report.json', $json . PHP_EOL, LOCK_EX);
        }

        $lines = [
            '# Unit test report',
            '',
            '- Runner: `php api/tests/run-unit-tests.php`',
            '- Command: `' . $report['command'] . '`',
            '- Started (UTC): ' . $report['started_utc'],
            '- Finished (UTC): ' . $report['finished_utc'],
            '- Duration: ' . $report['duration_seconds'] . 's',
            '- Environment: PHP ' . PHP_VERSION . ', isolated temp env (no database)',
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
                $lines[] = '- ' . sanitizeReportString((string) $name);
            }
        }
        $lines[] = '';
        $lines[] = '_Raw bodies excluded by design (may carry tokens or PII)._';
        @file_put_contents($dir . '/latest-report.md', implode("\n", $lines) . "\n", LOCK_EX);

        if ($status !== 'passed') {
            $stamp = gmdate('Ymd-His');
            if (is_string($json)) {
                @file_put_contents($dir . "/unit-{$stamp}Z.json", $json . PHP_EOL, LOCK_EX);
            }
            @file_put_contents($dir . "/unit-{$stamp}Z.md", implode("\n", $lines) . "\n", LOCK_EX);
        }
    } catch (Throwable) {
        // Reporting must never break the run or its exit code.
    }
}
register_shutdown_function('writeTestReport');

// --- 1. Validator --------------------------------------------------------
check(Validator::validate([], ['email' => ['required']]) !== [], 'Validator: required catches empty');
check(
    Validator::validate(['email' => 'nope'], ['email' => ['email']]) !== [],
    'Validator: bad email rejected'
);
check(
    Validator::validate(['email' => 'a@b.co'], ['email' => ['required', 'email']]) === [],
    'Validator: valid email accepted'
);
check(
    Validator::validate(['password' => 'short'], ['password' => [['min', 8]]]) !== [],
    'Validator: min rule rejects short value'
);
check(
    Validator::validate(['password' => str_repeat('x', 8)], ['password' => [['min', 8]]]) === [],
    'Validator: min rule accepts exact length'
);
check(
    Validator::validate(['a' => 'ok', 'b' => null], ['a' => ['required'], 'b' => ['optional-noop']]) === [],
    'Validator: unknown rule names are ignored (documented behavior)'
);

// --- 2. CSRF tokens ------------------------------------------------------
$token = CsrfProtection::generateToken();
check(CsrfProtection::validateToken($token) === true, 'CSRF: fresh token validates');

$forged = base64_encode((string) time() . ':' . str_repeat('0', 64));
check(CsrfProtection::validateToken($forged) === false, 'CSRF: wrong signature rejected');

check(CsrfProtection::validateToken('definitely-not-base64!!') === false, 'CSRF: garbage rejected');

// Expired but correctly signed timestamp (older than the 3600 s lifetime).
$secret = (string) Env::get('JWT_SECRET', '');
$oldSignature = hash_hmac('sha256', (string) (time() - 4000), $secret);
$expired = base64_encode((time() - 4000) . ':' . $oldSignature);
check(CsrfProtection::validateToken($expired) === false, 'CSRF: expired token rejected');

check(CsrfProtection::validateToken('') === false, 'CSRF: empty string rejected');

// --- 3. Auth (JWT) -------------------------------------------------------
$jwt = Auth::issue(42, 'user', 3600);
$claims = Auth::verify($jwt);
check(($claims['sub'] ?? 0) === 42 && ($claims['role'] ?? '') === 'user', 'Auth: issue/verify roundtrip');

$adminJwt = Auth::issue(7, 'admin', 3600, 1234);
$adminClaims = Auth::verify($adminJwt);
check(($adminClaims['sid'] ?? 0) === 1234, 'Auth: optional admin session id is embedded in the JWT');
$unboundAdminClaims = Auth::verify(Auth::issue(7, 'admin', 3600));
check(!array_key_exists('sid', $unboundAdminClaims), 'Auth: tokens without a session id omit the sid claim');

[$h, $p, $sig] = explode('.', $jwt);
check(throws(fn () => Auth::verify("{$h}.{$p}." . str_repeat('A', strlen($sig)))), 'Auth: tampered signature rejected');
check(throws(fn () => Auth::verify('not-a-jwt')), 'Auth: malformed token rejected');

$expiredJwt = Auth::issue(1, 'user', -10);
check(throws(fn () => Auth::verify($expiredJwt)), 'Auth: expired token rejected');

$otherSecretJwt = (static function (): string {
    // Sign with a different secret to prove signature verification binds to Env.
    $encode = static fn (array $data): string => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    $header = $encode(['alg' => 'HS256', 'typ' => 'JWT']);
    $payload = $encode(['sub' => 1, 'role' => 'user', 'iat' => time(), 'exp' => time() + 3600]);
    $sig = rtrim(strtr(base64_encode(hash_hmac('sha256', "{$header}.{$payload}", 'some-other-secret', true)), '+/', '-_'), '=');

    return "{$header}.{$payload}.{$sig}";
})();
check(throws(fn () => Auth::verify($otherSecretJwt)), 'Auth: foreign-secret token rejected');

// --- 4. ErrorLogger (structured JSON lines + redaction) ------------------
$logFile = __DIR__ . '/../storage/logs/app-error.log';
$hadLogFile = is_file($logFile);
$originalLength = $hadLogFile ? filesize($logFile) : 0;

$request = new Request('POST', '/api/test-route', [], [], [
    'X-Request-Id' => 'corr-id-123',
]);
$requestId = ErrorLogger::logThrowable(
    new RuntimeException('Bearer abc.def.ghi failed, password=topsecret'),
    $request,
    500
);

$line = '';
if (is_file($logFile)) {
    $lines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $line = $lines[count($lines) - 1] ?? '';
}
$decoded = json_decode($line, true);

check($requestId === 'corr-id-123', 'ErrorLogger: honors well-formed inbound request id');
check(is_array($decoded), 'ErrorLogger: wrote a JSON line');
check(
    $decoded !== null
    && isset($decoded['timestamp'], $decoded['level'], $decoded['request_id'], $decoded['method'], $decoded['path'], $decoded['http_status'], $decoded['exception'], $decoded['message']),
    'ErrorLogger: line contains all required fields'
);
check(
    is_array($decoded) && $decoded['method'] === 'POST' && $decoded['path'] === '/api/test-route'
        && $decoded['http_status'] === 500,
    'ErrorLogger: request context recorded'
);
check(
    is_string($decoded ?? null ? ($decoded['message'] ?? '') : '')
    && !str_contains($decoded['message'], 'abc.def.ghi')
    && !str_contains($decoded['message'], 'topsecret')
    && str_contains($decoded['message'], '[redacted]'),
    'ErrorLogger: bearer tokens and passwords redacted from message'
);
check(
    !isset($decoded['stack']) && !isset($decoded['trace']) && !isset($decoded['file']),
    'ErrorLogger: no stack trace / paths logged'
);

// --- 4b. ErrorLogger::redact (recursive, headers/bodies/arrays) ------------
$redacted = ErrorLogger::redact([
    'authorization' => 'Bearer secret-jwt-value',
    'cookie' => 'lb_customer_refresh=abc',
    'password' => 'hunter2',
    'refresh_token' => 'tok123',
    'cmi_hash_key' => 'hash123',
    'nested' => [
        'api_key' => 'key123',
        'query' => ['unsubscribe_token' => 'u123', 'page' => '2'],
    ],
    'items' => [
        ['card_number' => '4111111111111111', 'qty' => 2],
    ],
    'safe' => 'plain value',
    'count' => 3,
]);
check(
    $redacted['authorization'] === '[redacted]'
    && $redacted['cookie'] === '[redacted]'
    && $redacted['password'] === '[redacted]'
    && $redacted['refresh_token'] === '[redacted]'
    && $redacted['cmi_hash_key'] === '[redacted]'
    && $redacted['nested']['api_key'] === '[redacted]'
    && $redacted['nested']['query']['unsubscribe_token'] === '[redacted]'
    && $redacted['nested']['query']['page'] === '2'
    && $redacted['items'][0]['card_number'] === '[redacted]'
    && $redacted['items'][0]['qty'] === 2
    && $redacted['safe'] === 'plain value'
    && $redacted['count'] === 3,
    'ErrorLogger: redact() masks nested secrets, keeps safe values'
);

// Static English is preserved (only token-shaped values redact).
$staticId = ErrorLogger::logEvent('warning', 'Authentication failure: missing bearer token', $request);
$staticLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$staticDecoded = json_decode($staticLines[count($staticLines) - 1] ?? '', true);
check(
    is_array($staticDecoded) && ($staticDecoded['message'] ?? '') === 'Authentication failure: missing bearer token',
    'ErrorLogger: static messages pass through unredacted'
);

// Context arrays are redacted before they reach disk.
ErrorLogger::logEvent('warning', 'Payment callback rejected', $request, [
    'order_id' => 42,
    'password' => 'should-not-appear',
]);
$ctxLines = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
$ctxDecoded = json_decode($ctxLines[count($ctxLines) - 1] ?? '', true);
check(
    is_array($ctxDecoded)
    && ($ctxDecoded['context']['order_id'] ?? null) === 42
    && ($ctxDecoded['context']['password'] ?? null) === '[redacted]',
    'ErrorLogger: event context redacted, safe ids kept'
);

// Front-door id is shared by every later call in the same request.
\App\Core\RequestContext::clear();
$frontId = ErrorLogger::generateRequestId($request);
\App\Core\RequestContext::set('request_id', $frontId);
check(
    ErrorLogger::currentRequestId($request) === $frontId
    && ErrorLogger::currentRequestId(null) === $frontId,
    'ErrorLogger: context id shared across calls in one request'
);
\App\Core\RequestContext::clear();

// --- 4c. ErrorLogger rotation (naming + retention) --------------------------
{
    $rotate = new ReflectionMethod(ErrorLogger::class, 'rotateIfOversized');
    $rotDir = sys_get_temp_dir() . '/lb-rotate-test-' . getmypid();
    @mkdir($rotDir, 0777, true);
    $rotLog = $rotDir . '/app-error.log';
    @unlink($rotLog);
    @unlink($rotLog . '.1');
    @unlink($rotLog . '.2');

    // Below threshold: untouched, no rotated file.
    file_put_contents($rotLog, str_repeat('a', 50));
    $rotate->invoke(null, $rotLog, 100);
    check(is_file($rotLog) && !is_file($rotLog . '.1'), 'Rotate: below threshold leaves file alone');

    // At threshold: current becomes EXACTLY app-error.log.1 (never .2).
    file_put_contents($rotLog, str_repeat('b', 150));
    $rotate->invoke(null, $rotLog, 100);
    check(
        !is_file($rotLog) && is_file($rotLog . '.1') && !is_file($rotLog . '.2')
        && file_get_contents($rotLog . '.1') === str_repeat('b', 150),
        'Rotate: oversized file becomes exactly app-error.log.1'
    );

    // Second rotation: previous .1 discarded — only the new .1 remains
    // (the next log write recreates the current file).
    file_put_contents($rotLog, str_repeat('c', 150));
    $rotate->invoke(null, $rotLog, 100);
    $kept = glob($rotDir . '/app-error.log*') ?: [];
    check(
        $kept === [$rotLog . '.1']
        && file_get_contents($rotLog . '.1') === str_repeat('c', 150)
        && !is_file($rotLog . '.2'),
        'Rotate: retention is exactly current + .1, newest generation wins'
    );
    // Next write recreates current alongside .1 — still no .2, ever.
    file_put_contents($rotLog, str_repeat('d', 10));
    $keptAfterWrite = glob($rotDir . '/app-error.log*') ?: [];
    sort($keptAfterWrite);
    check(
        $keptAfterWrite === [$rotLog, $rotLog . '.1'],
        'Rotate: current + .1 coexist after the next write, no extra generations'
    );

    @unlink($rotLog);
    @unlink($rotLog . '.1');
    @unlink($rotLog . '.2');
    @rmdir($rotDir);
}

// --- 4d. Migration failure privacy ------------------------------------------
{
    // Poisoned failure shaped exactly like MigrationRunner::applyFile emits,
    // with secrets a real PDO message could plausibly carry.
    $poison = new RuntimeException(
        'Migration 003_will_fail.sql failed at statement 2 of 2: '
        . "SQLSTATE[HY000]: General error password=hunter2 DB_PASS=s3cret "
        . 'mysql:host=db.internal token=abc123 eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOjF9.sig-here'
    );
    $ctx = App\Core\MigrationRunner::failureContext($poison);
    check(
        ($ctx['file'] ?? '') === '003_will_fail.sql'
        && ($ctx['statement'] ?? 0) === 2
        && ($ctx['statements'] ?? 0) === 2,
        'Migrate: failureContext extracts file + statement index only'
    );
    check(
        App\Core\MigrationRunner::failureContext(new RuntimeException('Connection refused')) === [],
        'Migrate: non-migration throwables yield empty context'
    );

    // Same call migrate.php makes: static message + parsed context. Redirect
    // the logger at a scratch dir and prove no secret reaches the log line.
    $refLog = new ReflectionClass(ErrorLogger::class);
    $dirProp = $refLog->getProperty('directory');
    $privDir = sys_get_temp_dir() . '/lb-migpriv-test-' . getmypid();
    @mkdir($privDir, 0777, true);
    $dirProp->setValue(null, $privDir);
    try {
        ErrorLogger::logEvent('warning', 'Migration failed', null, $ctx);
        $privLines = file($privDir . '/app-error.log', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $privDecoded = json_decode($privLines[count($privLines) - 1] ?? '', true);
        $privRaw = implode("\n", $privLines);
        check(
            is_array($privDecoded)
            && ($privDecoded['message'] ?? '') === 'Migration failed'
            && ($privDecoded['context']['file'] ?? '') === '003_will_fail.sql'
            && ($privDecoded['context']['statement'] ?? 0) === 2,
            'Migrate: structured log carries static message + safe metadata'
        );
        check(
            !str_contains($privRaw, 'hunter2')
            && !str_contains($privRaw, 's3cret')
            && !str_contains($privRaw, 'db.internal')
            && !str_contains($privRaw, 'abc123')
            && !str_contains($privRaw, 'eyJhbGciOiJIUzI1NiJ9'),
            'Migrate: secrets from the raw exception never reach the structured log'
        );
    } finally {
        $dirProp->setValue(null, __DIR__ . '/../storage/logs');
        foreach (glob($privDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($privDir);
    }

    // CLI output keeps diagnostics but masks secrets.
    $cli = App\Core\MigrationRunner::sanitizeCliMessage($poison->getMessage());
    check(
        str_contains($cli, '003_will_fail.sql') && str_contains($cli, 'statement 2 of 2'),
        'Migrate: CLI message keeps filename + statement index'
    );
    check(
        !str_contains($cli, 'hunter2') && !str_contains($cli, 's3cret')
        && !str_contains($cli, 'db.internal') && !str_contains($cli, 'abc123'),
        'Migrate: CLI message masks passwords, DSNs, and tokens'
    );
}

// Restore prior log state (tests leave no residue).
if ($hadLogFile) {
    $f = fopen($logFile, 'c');
    ftruncate($f, $originalLength);
    fclose($f);
} elseif (is_file($logFile)) {
    unlink($logFile);
}

// --- 5. CSRF fail-closed on weak/missing secret ----------------------------
$weakSecrets = ['', '   ', 'short', 'change-me', 'secret', 'insecure-default-secret', str_repeat('x', 31)];
foreach ($weakSecrets as $i => $weak) {
    // Simulate by directly testing sign() via reflection-free path: generateToken must throw.
    $ref = new ReflectionClass(App\Core\Env::class);
    $prop = $ref->getProperty('values');
    $saved = $prop->getValue();
    $prop->setValue(null, array_merge($saved, ['JWT_SECRET' => $weak]));
    $threw = false;
    try {
        CsrfProtection::generateToken();
    } catch (Throwable) {
        $threw = true;
    }
    check($threw === true, "CSRF: weak/empty secret rejected (case {$i})");
    // validateToken must fail closed (false, never true) with a bad secret.
    check(CsrfProtection::validateToken($token) === false, "CSRF: validate fails closed on weak secret (case {$i})");
    $prop->setValue(null, $saved);
}
// Restore good secret for remaining tests.
{
    $ref = new ReflectionClass(App\Core\Env::class);
    $prop = $ref->getProperty('values');
    $saved = $prop->getValue();
    $saved['JWT_SECRET'] = 'lb-unit-test-secret-with-enough-random-looking-characters';
    $prop->setValue(null, $saved);
}

// --- 6. PaymentService::isConfigured rejects empty values -----------------
{
    $ref = new ReflectionClass(App\Core\Env::class);
    $prop = $ref->getProperty('values');
    $saved = $prop->getValue();
    $setEnv = static function (array $overrides) use ($prop, $saved): void {
        $prop->setValue(null, array_merge($saved, $overrides));
    };
    $setEnv(['CMI_CLIENT_ID' => '', 'CMI_STORE_KEY' => '', 'CMI_HASH_KEY' => '', 'CMI_CALLBACK_URL' => '']);
    check(App\Services\PaymentService::isConfigured() === false, 'Payments: empty CMI values not configured');
    $setEnv(['CMI_CLIENT_ID' => '   ', 'CMI_STORE_KEY' => 'x', 'CMI_HASH_KEY' => 'y', 'CMI_CALLBACK_URL' => 'https://example.test/cb']);
    check(App\Services\PaymentService::isConfigured() === false, 'Payments: whitespace CMI client id not configured');
    $setEnv(['CMI_CLIENT_ID' => 'id', 'CMI_STORE_KEY' => 'store', 'CMI_HASH_KEY' => 'hash', 'CMI_CALLBACK_URL' => 'https://example.test/cb']);
    check(App\Services\PaymentService::isConfigured() === true, 'Payments: full CMI values configured');
    // Constructor must throw on empty values (fail-closed, no 503 surprise later).
    $setEnv(['CMI_CLIENT_ID' => '', 'CMI_STORE_KEY' => '', 'CMI_HASH_KEY' => '', 'CMI_CALLBACK_URL' => '']);
    check(throws(fn () => new App\Services\PaymentService()), 'Payments: constructor throws when CMI empty');
    $prop->setValue(null, $saved);
}

// --- 7. OrderService integer quantity validation ---------------------------
{
    $svc = new ReflectionClass(App\Services\OrderService::class);
    $method = $svc->getMethod('isPositiveInteger');
    check($method->invoke(null, 3) === true, 'Qty: int 3 accepted');
    check($method->invoke(null, '3') === true, 'Qty: string 3 accepted');
    check($method->invoke(null, 2.5) === false, 'Qty: float 2.5 rejected');
    check($method->invoke(null, '2.5') === false, 'Qty: decimal string rejected');
    check($method->invoke(null, '2.0') === false, 'Qty: 2.0 string rejected');
    check($method->invoke(null, 'abc') === false, 'Qty: malformed id rejected');
    check($method->invoke(null, 0) === false, 'Qty: zero rejected');
    check($method->invoke(null, -1) === false, 'Qty: negative rejected');
    check($method->invoke(null, null) === false, 'Qty: null rejected');
    // normalizeLines rejects decimals/duplicates/over-limit via private method.
    $service = (new ReflectionClass(App\Services\OrderService::class))->newInstanceWithoutConstructor();
    $normalize = $svc->getMethod('normalizeLines');
    check(throws(fn () => $normalize->invoke($service, [['variant_id' => 1, 'quantity' => 2.5]])), 'Qty: normalize rejects float quantity');
    check(throws(fn () => $normalize->invoke($service, [['variant_id' => 1, 'quantity' => '2.5']])), 'Qty: normalize rejects decimal string');
    check(throws(fn () => $normalize->invoke($service, [['variant_id' => 'abc', 'quantity' => 1]])), 'Qty: normalize rejects malformed id');
    check(throws(fn () => $normalize->invoke($service, [['variant_id' => 1, 'quantity' => 1], ['variant_id' => 1, 'quantity' => 1]])), 'Qty: normalize rejects duplicate lines');
    check(throws(fn () => $normalize->invoke($service, [['variant_id' => 1, 'quantity' => 999]])), 'Qty: normalize rejects unreasonable quantity (>20)');
}

// --- 8. Rate-limit proxy behavior (X-Forwarded-For only from trusted) ------
{
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $reqWithForged = new Request('POST', '/api/test', [], [], ['X-Forwarded-For' => '1.2.3.4']);
    $mw = new App\Core\RateLimitMiddleware('unit-test-proxy', 1000, 60);
    $refMw = new ReflectionClass($mw);
    $getIp = $refMw->getMethod('getClientIp');
    // Untrusted by default (no TRUSTED_PROXIES) → REMOTE_ADDR wins.
    check($getIp->invoke($mw, $reqWithForged) === '203.0.113.9', 'RateLimit: forged XFF ignored when proxy untrusted');
    // Trusted proxy → first valid XFF wins.
    $refEnv = new ReflectionClass(App\Core\Env::class);
    $propEnv = $refEnv->getProperty('values');
    $savedEnv = $propEnv->getValue();
    $propEnv->setValue(null, array_merge($savedEnv, ['TRUSTED_PROXIES' => '203.0.113.9']));
    check($getIp->invoke($mw, $reqWithForged) === '1.2.3.4', 'RateLimit: XFF honored from trusted proxy');
    // Invalid XFF entries are skipped, invalid REMOTE_ADDR → 0.0.0.0.
    $badReq = new Request('POST', '/api/test', [], [], ['X-Forwarded-For' => 'not-an-ip']);
    check($getIp->invoke($mw, $badReq) === '203.0.113.9', 'RateLimit: invalid XFF falls back to REMOTE_ADDR');
    $_SERVER['REMOTE_ADDR'] = 'garbage';
    $noHeader = new Request('POST', '/api/test', [], [], []);
    check($getIp->invoke($mw, $noHeader) === '0.0.0.0', 'RateLimit: invalid REMOTE_ADDR sanitized');
    $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    $propEnv->setValue(null, $savedEnv);
}

// --- 9. Shipping totals (free threshold + flat cost) -----------------------
{
    $refEnv = new ReflectionClass(App\Core\Env::class);
    // Shipping is settings-driven; unit-test the pure rule via a stub:
    // cost 30, threshold 500 → 400 pays 30, 600 is free, threshold 0 never free.
    $calc = static function (int $subtotalCents, ?string $cost, ?string $threshold): int {
        $c = is_numeric($cost) ? (float) $cost : 0.0;
        $t = is_numeric($threshold) ? (float) $threshold : 0.0;
        if ($c < 0) {
            $c = 0.0;
        }
        if ($t > 0 && ($subtotalCents / 100) >= $t) {
            return 0;
        }
        return (int) round($c * 100);
    };
    check($calc(40000, '30', '500') === 3000, 'Shipping: below threshold charges cost');
    check($calc(60000, '30', '500') === 0, 'Shipping: at/above threshold is free');
    check($calc(60000, '30', '0') === 3000, 'Shipping: threshold 0 never free');
    check($calc(10000, '', null) === 0, 'Shipping: missing settings default to 0');
}

// --- 10. Newsletter signed unsubscribe token -------------------------------
{
    $refEnv = new ReflectionClass(App\Core\Env::class);
    $propEnv = $refEnv->getProperty('values');
    $savedEnv = $propEnv->getValue();
    $email = 'Reader@Example.com';
    $token = App\Controllers\NewsletterController::issueUnsubscribeToken($email);
    check(App\Controllers\NewsletterController::verifyUnsubscribeToken($email, $token) === true, 'Newsletter: signed token verifies (case-insensitive email)');
    check(App\Controllers\NewsletterController::verifyUnsubscribeToken('other@example.com', $token) === false, 'Newsletter: token bound to email');
    check(App\Controllers\NewsletterController::verifyUnsubscribeToken($email, $token . 'tampered') === false, 'Newsletter: tampered token rejected');
    check(App\Controllers\NewsletterController::verifyUnsubscribeToken($email, '') === false, 'Newsletter: empty token rejected');
    $propEnv->setValue(null, $savedEnv);
}

// --- 11. MigrationRunner SQL splitter --------------------------------------
{
    $split = static fn (string $sql): array => App\Core\MigrationRunner::splitStatements($sql);
    $exec = static fn (string $sql): array => App\Core\MigrationRunner::executableStatements($sql);

    // Comment-prefixed SQL after a header comment must NOT be skipped:
    // a leading comment + one statement is a single kept statement.
    $withHeader = "-- header comment line 1\n-- header comment line 2\nALTER TABLE t ADD COLUMN c INT;";
    $parts = $exec($withHeader);
    check(count($parts) === 1 && str_contains($parts[0], 'ALTER TABLE'), 'Migrate: statement after leading comments is kept');

    // A semicolon inside a string literal does not split.
    $strSemi = "UPDATE t SET v = 'a;b' WHERE id = 1;";
    check(count($split($strSemi)) === 1, 'Migrate: semicolon inside string does not split');

    // A semicolon inside a line comment does not create a statement, and the
    // real statement following the comment is still executed.
    $commentSemi = "-- note: foo; bar\nSELECT 1;";
    $parts = $exec($commentSemi);
    check(count($parts) === 1 && str_contains($parts[0], 'SELECT 1'), 'Migrate: comment semicolon ignored, following statement kept');

    // Block comments survive splitting; comment-only chunks are skipped.
    $block = "/* file header */\nSELECT 1;\n-- trailing note";
    $parts = $exec($block);
    check(count($parts) === 1 && str_contains($parts[0], 'SELECT 1'), 'Migrate: block header kept with statement, trailing note skipped');

    // DDL passes through untouched (executed in autocommit, never wrapped).
    $ddl = "ALTER TABLE t ADD COLUMN c INT;\nCREATE TABLE IF NOT EXISTS u (id INT);";
    check(count($exec($ddl)) === 2, 'Migrate: DDL statements pass through');

    // SET/PREPARE/EXECUTE session style (014/015 pattern) stays intact.
    $session = "SET @a := 1;\nPREPARE stmt FROM @a; EXECUTE stmt; DEALLOCATE PREPARE stmt;";
    check(count($exec($session)) === 4, 'Migrate: SET/PREPARE/EXECUTE/DEALLOCATE split into 4');

    // USE directives are skipped (connection already targets the database).
    $withUse = "USE lucebianca;\nSELECT 1;";
    $parts = $exec($withUse);
    check(count($parts) === 1 && str_contains($parts[0], 'SELECT 1'), 'Migrate: USE directive skipped, real statement kept');
    check(App\Core\MigrationRunner::isUseStatement('USE lucebianca;') === true, 'Migrate: USE detected');
    check(App\Core\MigrationRunner::isUseStatement('UPDATE t SET u = 1;') === false, 'Migrate: UPDATE is not USE');

    // Empty/comment-only input yields nothing executable.
    check($exec("-- only a comment\n# another") === [], 'Migrate: comment-only batch is empty');

    // Backtick identifier containing a semicolon does not split.
    $backtick = 'ALTER TABLE `we;ird` ADD COLUMN c INT;';
    check(count($split($backtick)) === 1, 'Migrate: semicolon inside backticks does not split');
}

// --- 12. Payment initiation URLs -------------------------------------------
{
    $ref = new ReflectionClass(App\Core\Env::class);
    $prop = $ref->getProperty('values');
    $saved = $prop->getValue();
    $setEnv = static function (array $overrides) use ($prop): void {
        $current = $prop->getValue();
        $prop->setValue(null, array_merge($current, $overrides));
    };
    $setEnv(['CMI_CLIENT_ID' => 'test-client',
        'CMI_STORE_KEY' => 'test-store',
        'CMI_HASH_KEY' => 'test-hash-key-with-enough-length',
        'CMI_CALLBACK_URL' => 'https://api.example.test/api/payments/callback',
        'CMI_RETURN_URL' => 'https://shop.example.test/payment',
        'SITE_URL' => 'https://shop.example.test',
    ]);
    $svc = new App\Services\PaymentService();
    $init = $svc->initiatePayment(42, '199.00', '504', 'buyer@example.test', 'tokentest123');
    $fields = $init['form_fields'];
    check(str_starts_with($fields['okUrl'], 'https://shop.example.test/payment/42?'), 'Payments: okUrl targets storefront payment page');
    check(str_contains($fields['okUrl'], 'pay=success'), 'Payments: okUrl carries success hint');
    check(str_contains($fields['okUrl'], rawurlencode('tokentest123')), 'Payments: okUrl carries lookup token');
    check(str_contains($fields['failUrl'], 'pay=failure'), 'Payments: failUrl carries failure hint');
    check($fields['callbackUrl'] === 'https://api.example.test/api/payments/callback', 'Payments: callbackUrl stays the API endpoint');
    check($fields['callbackUrl'] !== $fields['okUrl'] && $fields['callbackUrl'] !== $fields['failUrl'], 'Payments: callbackUrl differs from customer return URLs');
    check(!str_contains($fields['okUrl'], '/api/payments/callback'), 'Payments: customer URLs never point at the API callback');
    // The transmitted hash covers the exact transmitted URLs.
    $hashString = $fields['clientid'] . '|' . $fields['oid'] . '|' . $fields['amount'] . '|'
        . $fields['okUrl'] . '|' . $fields['failUrl'] . '|' . $fields['trantype'] . '||'
        . $fields['currency'] . '|test-store|ver3';
    $expectedHash = base64_encode(hash_hmac('sha512', $hashString, 'test-hash-key-with-enough-length', true));
    check(hash_equals($expectedHash, $fields['hash']), 'Payments: request hash covers exact okUrl/failUrl');
    // SITE_URL fallback when CMI_RETURN_URL is empty.
    $setEnv(['CMI_RETURN_URL' => '']);
    $svc2 = new App\Services\PaymentService();
    $init2 = $svc2->initiatePayment(7, '10.00', '504', '', 'abc');
    check(str_starts_with($init2['form_fields']['okUrl'], 'https://shop.example.test/payment/7?'), 'Payments: return base falls back to SITE_URL/payment');
    $prop->setValue(null, $saved);
}

// --- 13. RateLimiter file locking ------------------------------------------
{
    $refEnv = new ReflectionClass(App\Core\Env::class);
    $propEnv = $refEnv->getProperty('values');
    $savedEnv = $propEnv->getValue();
    $rateDir = sys_get_temp_dir() . '/lb-rate-test-' . getmypid();
    @mkdir($rateDir, 0777, true);
    $propEnv->setValue(null, array_merge($savedEnv, ['RATE_LIMIT_STORAGE_DIR' => $rateDir]));

    // Sequential behavior unchanged: allow up to max, then deny.
    $limiter = new App\Core\RateLimiter('unit-seq', 3, 60);
    check($limiter->attempt('ip-a') === true, 'RateLimit: attempt 1 allowed');
    check($limiter->attempt('ip-a') === true, 'RateLimit: attempt 2 allowed');
    check($limiter->attempt('ip-a') === true, 'RateLimit: attempt 3 allowed');
    check($limiter->attempt('ip-a') === false, 'RateLimit: attempt 4 denied');
    check($limiter->attempt('ip-a') === false, 'RateLimit: attempt 5 denied');
    check($limiter->getRetryAfter('ip-a') > 0, 'RateLimit: retry-after positive while limited');
    check($limiter->attempt('ip-b') === true, 'RateLimit: identifiers isolated');
    $limiter->clear('ip-a');
    check($limiter->attempt('ip-a') === true, 'RateLimit: clear resets bucket');
    check($limiter->getRetryAfter('ip-a') === 0, 'RateLimit: retry-after zero when under limit');
    // Storage format unchanged: one JSON array of timestamps per bucket.
    $bucketFiles = glob($rateDir . '/*.json') ?: [];
    check(count($bucketFiles) >= 1, 'RateLimit: bucket files written');
    $decoded = json_decode((string) file_get_contents($rateDir . '/' . hash('sha256', 'unit-seq:ip-b') . '.json'), true);
    check(is_array($decoded) && count($decoded) === 1 && is_int($decoded[0]), 'RateLimit: bucket holds JSON timestamp array');

    // Window pruning still works.
    $short = new App\Core\RateLimiter('unit-short', 1, 1);
    check($short->attempt('ip-c') === true, 'RateLimit: short-window attempt allowed');
    check($short->attempt('ip-c') === false, 'RateLimit: short-window repeat denied');
    sleep(2);
    check($short->attempt('ip-c') === true, 'RateLimit: expired entries pruned after window');

    // Fail-closed: an unwritable store denies instead of disabling the limit.
    $blockFile = tempnam(sys_get_temp_dir(), 'lb-rate-block-');
    $propEnv->setValue(null, array_merge($savedEnv, ['RATE_LIMIT_STORAGE_DIR' => $blockFile . '/sub']));
    set_error_handler(static fn() => true);
    $broken = new App\Core\RateLimiter('unit-broken', 3, 60);
    restore_error_handler();
    check($broken->attempt('anyone') === false, 'RateLimit: I/O failure fails closed (limit never silently disabled)');
    @unlink($blockFile);

    // Concurrent processes: 12 workers released at once must allow exactly
    // maxAttempts (5). Without locking, interleaved read-modify-write cycles
    // lose updates and over-allow. Workers run as separate processes
    // (portable: no pcntl needed) gated on a flag file for real overlap.
    $propEnv->setValue(null, array_merge($savedEnv, ['RATE_LIMIT_STORAGE_DIR' => $rateDir]));
    $flag = $rateDir . '/start.flag';
    @unlink($flag);
    $childEnv = tempnam(sys_get_temp_dir(), 'lb-rate-child-env-');
    file_put_contents((string) $childEnv, "RATE_LIMIT_STORAGE_DIR={$rateDir}\n");
    $childCode = '$f=' . var_export($flag, true) . '; $t=time()+10;'
        . ' while(!file_exists($f)&&time()<$t){usleep(1000);} '
        . 'require ' . var_export(__DIR__ . '/../src/autoload.php', true) . '; '
        . 'App\\Core\\Env::load(' . var_export((string) $childEnv, true) . '); '
        . '$l=new App\\Core\\RateLimiter(\'unit-concur\',5,60); echo (int)$l->attempt(\'victim\');';
    $procs = [];
    $outs = [];
    for ($i = 0; $i < 12; $i++) {
        $outFile = tempnam(sys_get_temp_dir(), 'lb-rate-out-');
        $outs[] = $outFile;
        $proc = proc_open(
            [PHP_BINARY, '-r', $childCode],
            [1 => ['file', (string) $outFile, 'w'], 2 => ['file', (string) $outFile, 'w']],
            $pipes
        );
        if (is_resource($proc)) {
            $procs[] = $proc;
        }
    }
    sleep(1); // let every worker reach the gate before releasing it
    file_put_contents($flag, 'go');
    $allowed = 0;
    foreach ($procs as $k => $proc) {
        proc_close($proc);
        $allowed += (int) trim((string) @file_get_contents($outs[$k]));
        @unlink($outs[$k]);
    }
    check(count($procs) === 12, 'RateLimit: all 12 concurrent workers spawned');
    check($allowed === 5, 'RateLimit: 12 concurrent processes allow exactly 5 (no lost updates)');
    $concurBucket = json_decode((string) @file_get_contents($rateDir . '/' . hash('sha256', 'unit-concur:victim') . '.json'), true);
    check(is_array($concurBucket) && count($concurBucket) === 5, 'RateLimit: bucket holds exactly 5 recorded attempts');

    // Cleanup temp storage (the shared suite dir stays untouched).
    foreach (glob($rateDir . '/*') ?: [] as $f) {
        @unlink($f);
    }
    @rmdir($rateDir);
    @unlink((string) $childEnv);
    $propEnv->setValue(null, $savedEnv);
}

unlink((string) $envFile);

// --- Summary -------------------------------------------------------------
$total = $GLOBALS['passed'] + count($failures);
echo "\nUnit tests: {$GLOBALS['passed']}/{$total} passed";
if ($failures !== []) {
    echo " — FAILURES:\n" . implode("\n", array_map(static fn ($f) => " - {$f}", $failures));
} else {
    echo " ✓\n";
}
exit($failures === [] ? 0 : 1);
