<?php

/**
 * Bootstrap — everything needed before a route is dispatched.
 *
 * Loads the autoloader + environment, converts PHP errors/exceptions into
 * JSON, and applies CORS headers for the Next.js frontend.
 */

declare(strict_types=1);

use App\Core\ConflictException;
use App\Core\Env;
use App\Core\ErrorLogger;
use App\Core\InvalidCredentialsException;
use App\Core\Request;
use App\Core\Response;
use App\Core\ValidationException;

require __DIR__ . '/src/autoload.php';

Env::load(__DIR__ . '/.env');

date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Africa/Casablanca'));

// --- Error handling: PHP errors become JSON (4xx for expected user errors, 500 for unexpected) ---
set_exception_handler(static function (Throwable $e): void {
    // Extract request early for correlation ID on all error paths.
    try {
        $request = Request::fromGlobals();
    } catch (Throwable) {
        $request = null; // Super unavailable — log without request context.
    }
    $requestId = ErrorLogger::currentRequestId($request);

    // Expected user errors → consistent 4xx JSON, no stack trace, no SQL leak.
    // These exceptions are thrown by Services/Repositories/Controllers for
    // validation, duplicate, or auth failures. They must not become 500.
    if ($e instanceof ValidationException) {
        header('X-Request-Id: ' . $requestId);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(422);
        echo json_encode(
            ['error' => $e->getMessage(), 'errors' => $e->errors()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if ($e instanceof ConflictException) {
        header('X-Request-Id: ' . $requestId);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(409);
        echo json_encode(
            ['error' => $e->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    if ($e instanceof InvalidCredentialsException) {
        header('X-Request-Id: ' . $requestId);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(
            ['error' => $e->getMessage()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    // PDOException with SQLSTATE 23000 is a unique/foreign-key violation —
    // an expected conflict (duplicate slug, SKU, size/color combo, etc.).
    // Map it to 409 with a generic message; never expose SQL, table or column names.
    if ($e instanceof PDOException && (string) $e->getCode() === '23000') {
        header('X-Request-Id: ' . $requestId);
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(409);
        echo json_encode(
            ['error' => 'A record already exists.'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }

    // All other throwables are unexpected server errors → 500.
    $debug = Env::get('APP_DEBUG', false);

    // Structured server-side log of every uncaught throwable — one JSON line
    // in api/storage/logs/app-error.log (gitignored). Inputs are never
    // logged; see ErrorLogger for the recorded fields. The returned id is
    // the SAME id stored in the log line — the response header below must
    // use it so clients/support can correlate the two.
    $requestId = ErrorLogger::logThrowable($e, $request);

    // Correlation header: what the client sees matches one log line.
    header('X-Request-Id: ' . $requestId);

    $payload = ['error' => $debug ? $e->getMessage() : 'Internal server error.'];

    if ($debug) {
        $payload['exception'] = get_class($e);
        $payload['file'] = $e->getFile();
        $payload['line'] = $e->getLine();
    }

    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

// --- CORS ---
$allowedOrigins = array_filter(array_map('trim', explode(',', (string) Env::get('CORS_ALLOWED_ORIGINS', ''))));
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';

if ($origin !== '' && in_array($origin, $allowedOrigins, true)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Vary: Origin');
    // Required for HttpOnly refresh cookies (credentials:include).
    header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token, X-Order-Token');
header('Access-Control-Max-Age: 86400');

// Handle CORS preflight before any routing.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

return true;