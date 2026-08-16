<?php

/**
 * Bootstrap — everything needed before a route is dispatched.
 *
 * Loads the autoloader + environment, converts PHP errors/exceptions into
 * JSON, and applies CORS headers for the Next.js frontend.
 */

declare(strict_types=1);

use App\Core\Env;
use App\Core\Response;

require __DIR__ . '/src/autoload.php';

Env::load(__DIR__ . '/.env');

date_default_timezone_set((string) Env::get('APP_TIMEZONE', 'Africa/Casablanca'));

// --- Error handling: PHP errors become JSON 500s (JSON 400 on bad JSON body) ---
set_exception_handler(static function (Throwable $e): void {
    $debug = Env::get('APP_DEBUG', false);
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
}
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Max-Age: 86400');

// Handle CORS preflight before any routing.
if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(204);
    exit;
}

return true;