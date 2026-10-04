<?php

namespace App\Core;

use Throwable;

/**
 * ErrorLogger — structured JSON-lines logging for unexpected server errors.
 *
 * Where logs are written
 * ----------------------
 * One JSON object per line is appended to:
 *
 *   api/storage/logs/app-error.log
 *
 * The directory is created automatically. Log files are covered by the
 * project's .gitignore (*.log / storage/logs/) so they are never committed.
 * To inspect locally:
 *
 *   # PowerShell
 *   Get-Content api\storage\logs\app-error.log -Tail 50 -Wait
 *   # bash
 *   tail -f api/storage/logs/app-error.log
 *
 * Each line contains: timestamp (UTC ISO-8601), level, request_id,
 * method, path, http_status, exception (class name) and message.
 *
 * Privacy rules
 * -------------
 * Only the fields above are recorded. Request bodies, query strings,
 * headers (Authorization/Cookie), passwords/tokens/API keys and payment
 * data are NEVER logged; exception messages additionally pass through a
 * redaction pass that masks credential-looking substrings. Stack traces,
 * previous exceptions and exception metadata ($e->getTrace()) are never
 * written — the class name plus a trimmed message is enough to locate
 * the failing code path.
 *
 * Retention / rotation
 * --------------------
 * Log file is capped at ~10 MB (10485760 bytes). When the cap is reached
 * the current file is rotated to app-error.log.1 and a new file starts.
 * Only one rotated file is kept (total ~20 MB max). This is a simple
 * bounded retention suitable for the current volume; replace with a
 * dedicated log shipper in production if needed.
 */
final class ErrorLogger
{
    /** Log destination, relative to api/ (gitignored — see self docblock). */
    private static string $directory = __DIR__ . '/../../storage/logs';

    /** Hard cap on stored message length (keeps lines small and greppable). */
    private const MAX_MESSAGE_LENGTH = 500;

    /** Maximum log file size before rotation (10 MB). */
    private const MAX_FILE_SIZE = 10485760;

    private function __construct()
    {
    }

    /**
     * Logs an unexpected throwable as one structured JSON line.
     *
     * @param Throwable    $e       The uncaught throwable.
     * @param Request|null $request Current request when available (null-safe).
     * @param int          $status  HTTP status the client receives.
     * @param array|null   $context Additional safe context to log (already sanitized).
     * @return string The correlation ID used for this entry.
     */
    public static function logThrowable(
        Throwable $e,
        ?Request $request = null,
        int $status = 500,
        ?array $context = null
    ): string {
        // Single logging point: only the global handler (bootstrap.php) calls
        // this, so each uncaught throwable is logged exactly ONCE per request.
        // No cross-request dedup here on purpose — every 500 response header
        // carries the SAME request_id as its log line, and suppressing repeat
        // occurrences in long-lived processes would break that correlation
        // and hide recurrence frequency.
        $requestId = self::currentRequestId($request);

        $entry = [
            'timestamp'   => gmdate('c'),
            'level'       => 'error',
            'request_id'  => $requestId,
            'method'      => $request?->method(),
            'path'        => self::safeRoute($request?->path()),
            'http_status' => $status,
            'exception'   => $e::class,
            'message'     => self::sanitizeMessage($e->getMessage()),
        ];

        // Add authenticated user ID if available (from request context or Auth)
        $userId = self::extractUserId($request);
        if ($userId !== null) {
            $entry['user_id'] = $userId;
        }

        // Add safe sanitized context if provided (recursive redaction)
        if ($context !== null && $context !== []) {
            $entry['context'] = self::redact($context);
        }

        self::writeEntry($entry);

        return $requestId;
    }

    /**
     * Logs a structured warning/info event (not an exception).
     * Use for: rate-limit hits, auth failures, payment callbacks, inventory events, cron results, etc.
     *
     * @param string       $level   'warning' | 'info' | 'notice'
     * @param string       $message Human-readable event description (will be sanitized).
     * @param Request|null $request Current request when available.
     * @param array|null   $context Additional safe context (already sanitized).
     * @return string The correlation ID used for this entry.
     */
    public static function logEvent(
        string $level,
        string $message,
        ?Request $request = null,
        ?array $context = null
    ): string {
        $allowedLevels = ['warning', 'info', 'notice'];
        if (!in_array($level, $allowedLevels, true)) {
            $level = 'notice';
        }

        $requestId = self::currentRequestId($request);

        $entry = [
            'timestamp'  => gmdate('c'),
            'level'      => $level,
            'request_id' => $requestId,
            'method'     => $request?->method(),
            'path'       => self::safeRoute($request?->path()),
            'message'    => self::sanitizeMessage($message),
        ];

        $userId = self::extractUserId($request);
        if ($userId !== null) {
            $entry['user_id'] = $userId;
        }

        if ($context !== null && $context !== []) {
            $entry['context'] = self::redact($context);
        }

        self::writeEntry($entry);

        return $requestId;
    }

    /**
     * Correlation ID: honors a well-formed inbound X-Request-Id (e.g. set by
     * a reverse proxy) so traces can span systems; otherwise generates one.
     *
     * Public so bootstrap/middleware/controllers stamp the SAME id on every
     * response (header + log line) within one request lifecycle: the front
     * door (Router::dispatch) stores a fresh id in RequestContext first, and
     * every later call in the same request reuses it.
     */
    public static function currentRequestId(?Request $request): string
    {
        // Front-door stamp (Router::dispatch sets this first): every
        // response header and every log line produced while handling one
        // request shares this id, so header and log line always correlate.
        $contextId = RequestContext::get('request_id');
        if (
            is_string($contextId)
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $contextId) === 1
        ) {
            return $contextId;
        }

        return self::generateRequestId($request);
    }

    /**
     * Fresh correlation id for ONE request, ignoring RequestContext.
     * Used ONLY by the front door (Router::dispatch), which stores the
     * result in context; everyone else must call currentRequestId() so the
     * whole request shares a single id. Honors an inbound X-Request-Id.
     */
    public static function generateRequestId(?Request $request): string
    {
        $inbound = $request?->header('X-Request-Id');

        if (
            is_string($inbound)
            && preg_match('/^[A-Za-z0-9_-]{1,64}$/', $inbound) === 1
        ) {
            return $inbound;
        }

        return bin2hex(random_bytes(16));
    }

    /**
     * Single write path for every log line: mkdir if needed, rotate when
     * oversized, then append one JSON line. The size check, rotation, and
     * append all run under ONE exclusive lock (a sidecar lock file, since
     * the log file itself must be closed for rename on Windows), so
     * concurrent PHP workers can neither interleave lines nor rotate over
     * each other. Every failure (disk full, permissions, ...) is swallowed
     * on purpose — logging must never break the error response or CLI run
     * it is called from.
     */
    private static function writeEntry(array $entry): void
    {
        try {
            if (!is_dir(self::$directory)) {
                @mkdir(self::$directory, 0755, true);
            }

            $line = json_encode(
                $entry,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($line === false) {
                return;
            }

            $logFile = self::$directory . '/app-error.log';
            $lock = @fopen(self::$directory . '/app-error.lock', 'c');
            if ($lock === false) {
                // Lock unavailable — single locked append is still safe.
                @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                return;
            }

            try {
                if (flock($lock, LOCK_EX)) {
                    try {
                        self::rotateIfOversized($logFile, self::MAX_FILE_SIZE);
                        @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                    } finally {
                        flock($lock, LOCK_UN);
                    }
                } else {
                    @file_put_contents($logFile, $line . PHP_EOL, FILE_APPEND | LOCK_EX);
                }
            } finally {
                fclose($lock);
            }
        } catch (Throwable) {
            // Intentionally ignored — logging must never break responses.
        }
    }

    /**
     * Extract authenticated user ID from request if available.
     * Checks Auth middleware context first, then falls back to Authorization header.
     */
    private static function extractUserId(?Request $request): ?int
    {
        if ($request === null) {
            return null;
        }

        // Prefer claims already verified by AuthMiddleware this request;
        // fall back to verifying the Bearer token when middleware has not
        // run (e.g. failure happened before auth, or in CLI contexts).
        $claims = RequestContext::get('auth');
        if (is_array($claims) && isset($claims['sub']) && is_numeric($claims['sub'])) {
            return (int) $claims['sub'];
        }

        $authHeader = $request->header('Authorization');
        if (is_string($authHeader) && str_starts_with($authHeader, 'Bearer ')) {
            try {
                $token = substr($authHeader, 7);
                $claims = \App\Core\Auth::verify($token);
                if (isset($claims['sub']) && is_numeric($claims['sub'])) {
                    return (int) $claims['sub'];
                }
            } catch (Throwable) {
                // Invalid/expired token — not an error, just no user ID
            }
        }

        return null;
    }

    /**
     * Sanitizes route paths into a safe route template for log grouping:
     * query strings are dropped and concrete ids/slugs become placeholders,
     * so logs aggregate by route pattern and never contain token values.
     * Request::path() is already query-free (see fromGlobals); the query
     * strip is defense in depth for callers that pass a raw URI.
     */
    private static function safeRoute(?string $path): ?string
    {
        if ($path === null || $path === '') {
            return $path;
        }

        // Defense in depth: drop any query string (tokens never reach disk).
        $path = explode('?', $path, 2)[0];

        // Replace concrete ids (numeric, uuid-like) and product slugs with
        // placeholders, keeping the static route prefix for grouping.
        // Static routes first so they are never collapsed into {slug}.
        $patterns = [
            '#/api/products/new-arrivals$#i'           => '/api/products/new-arrivals',
            '#/api/products/special-offers$#i'         => '/api/products/special-offers',
            '#/api/payments/status/[^/]+$#i'      => '/api/payments/status/{id}',
            '#/api/payments/initiate#i'             => '/api/payments/initiate',
            '#/api/payments/callback#i'             => '/api/payments/callback',
            '#/api/admin/orders/[^/]+$#i'         => '/api/admin/orders/{id}',
            '#/api/admin/products/[^/]+$#i'       => '/api/admin/products/{id}',
            '#/api/admin/categories/[^/]+$#i'     => '/api/admin/categories/{id}',
            '#/api/admin/customers/[^/]+$#i'      => '/api/admin/customers/{id}',
            '#/api/admin/reviews/[^/]+/approve$#i'=> '/api/admin/reviews/{id}/approve',
            '#/api/admin/reviews/[^/]+$#i'        => '/api/admin/reviews/{id}',
            '#/api/orders/[^/]+$#i'                => '/api/orders/{id}',
            '#/api/products/(?!new-arrivals$|special-offers$)[^/]+$#i' => '/api/products/{slug}',
            '#/api/products/[^/]+/reviews$#i'     => '/api/products/{slug}/reviews',
            '#/api/wishlist/[^/]+$#i'              => '/api/wishlist/{id}',
        ];

        foreach ($patterns as $pattern => $replacement) {
            $path = (string) preg_replace($pattern, $replacement, $path);
        }

        return $path;
    }

    /**
     * Trims the message and redacts credential-looking substrings before it
     * touches disk. Normal framework messages pass through unchanged.
     */
    private static function sanitizeMessage(string $message): string
    {
        $patterns = [
            // Bearer + token-looking value (≥8 chars so plain English like
            // "bearer token" in our own static messages is left alone).
            '#Bearer\s+[A-Za-z0-9\-_.~+/=:.]{8,}#i',
            '/\b(?:password|passwd|token|api[_-]?key|secret)\s*[:=]\s*\S+/i',
            '/\b(?:client[_-]?id|client[_-]?secret|store[_-]?key|hash[_-]?key)\s*[:=]\s*\S+/i',
            '/\b(?:cmi[_-]?(?:client|store|hash|key))\s*[:=]\s*\S+/i',
            '/\b(?:refresh[_-]?token|access[_-]?token|csrf[_-]?token)\s*[:=]\s*\S+/i',
            '/\b(?:reset[_-]?token|unsubscribe[_-]?token)\s*[:=]\s*\S+/i',
            '/\b(?:dsn|database[_-]?(?:url|host|pass|name|user))\s*[:=]\s*\S+/i',
        ];

        foreach ($patterns as $pattern) {
            $message = (string) preg_replace($pattern, '[redacted]', $message);
        }

        // mbstring is an optional extension here (see composer.json) — fall
        // back to byte-based substr; JSON_INVALID_UTF8_SUBSTITUTE repairs any
        // multibyte character a hard cut might split.
        $trimmed = trim($message);

        return function_exists('mb_substr')
            ? mb_substr($trimmed, 0, self::MAX_MESSAGE_LENGTH)
            : substr($trimmed, 0, self::MAX_MESSAGE_LENGTH);
    }

    /**
     * Recursively redacts sensitive values from arrays/objects.
     * Use for sanitizing context arrays before logging.
     *
     * @param mixed $value The value to sanitize (array, object, or scalar).
     * @return mixed The sanitized value with sensitive keys redacted.
     */
    public static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $val) {
                if (self::isSensitiveKey($key)) {
                    $result[$key] = '[redacted]';
                } else {
                    $result[$key] = self::redact($val);
                }
            }
            return $result;
        }

        if (is_object($value)) {
            // Convert to array, redact, return as object
            $array = (array) $value;
            return (object) self::redact($array);
        }

        // For strings, also redact embedded sensitive patterns
        if (is_string($value)) {
            return self::sanitizeMessage($value);
        }

        return $value;
    }

    /**
     * Checks if a key name indicates sensitive data that should be redacted.
     */
    private static function isSensitiveKey(string $key): bool
    {
        $sensitivePatterns = [
            '/^password$/i',
            '/^passwd$/i',
            '/^token$/i',
            '/^api[_-]?key$/i',
            '/^secret$/i',
            '/^client[_-]?id$/i',
            '/^client[_-]?secret$/i',
            '/^store[_-]?key$/i',
            '/^hash[_-]?key$/i',
            '/^cmi[_-]?(?:client(?:[_-]?id)?|store(?:[_-]?key)?|hash(?:[_-]?key)?|key)$/i',
            '/^refresh[_-]?token$/i',
            '/^access[_-]?token$/i',
            '/^csrf[_-]?token$/i',
            '/^reset[_-]?token$/i',
            '/^unsubscribe[_-]?token$/i',
            '/^authorization$/i',
            '/^cookie$/i',
            '/^dsn$/i',
            '/^database[_-]?(?:url|host|pass|name|user)$/i',
            '/^db[_-]?pass$/i',
            '/^jwt[_-]?secret$/i',
            '/^resend[_-]?api[_-]?key$/i',
            '/^google[_-]?client[_-]?id$/i',
            '/^cloudinary[_-]?api[_-]?secret$/i',
            '/^card[_-]?(?:number|cvv|expiry|pan)$/i',
            '/^payment[_-]?(?:data|info|details)$/i',
        ];

        foreach ($sensitivePatterns as $pattern) {
            if (preg_match($pattern, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Rotates the log file when it reaches $maxBytes: the previous
     * app-error.log.1 generation (if any) is discarded and the current
     * file becomes the new app-error.log.1 — exactly one current file
     * plus one rotated file, never .2 or higher.
     *
     * MUST be called holding the write lock (see writeEntry()): the size
     * check, unlink, and rename are one atomic step only under that lock.
     */
    private static function rotateIfOversized(string $logFile, int $maxBytes): void
    {
        clearstatcache(true, $logFile);
        if (!is_file($logFile)) {
            return;
        }

        $size = filesize($logFile);
        if ($size === false || $size < $maxBytes) {
            return;
        }

        @unlink($logFile . '.1'); // discard the previous generation
        @rename($logFile, $logFile . '.1'); // current becomes the retained one
    }
}