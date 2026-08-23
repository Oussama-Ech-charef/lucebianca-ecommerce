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
file_put_contents((string) $envFile, "JWT_SECRET=lb-unit-test-secret\n");
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

// Restore prior log state (tests leave no residue).
if ($hadLogFile) {
    $f = fopen($logFile, 'c');
    ftruncate($f, $originalLength);
    fclose($f);
} elseif (is_file($logFile)) {
    unlink($logFile);
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
