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
 */
final class ErrorLogger
{
    /** Log destination, relative to api/ (gitignored — see self docblock). */
    private static string $directory = __DIR__ . '/../../storage/logs';

    /** Hard cap on stored message length (keeps lines small and greppable). */
    private const MAX_MESSAGE_LENGTH = 500;

    private function __construct()
    {
    }

    /**
     * Logs an unexpected throwable as one structured JSON line.
     *
     * @param Throwable    $e       The uncaught throwable.
     * @param Request|null $request Current request when available (null-safe).
     * @param int          $status  HTTP status the client receives.
     * @return string The correlation ID used for this entry.
     */
    public static function logThrowable(
        Throwable $e,
        ?Request $request = null,
        int $status = 500
    ): string {
        $requestId = self::currentRequestId($request);

        $entry = [
            'timestamp'   => gmdate('c'),
            'level'       => 'error',
            'request_id'  => $requestId,
            'method'      => $request?->method(),
            'path'        => $request?->path(),
            'http_status' => $status,
            'exception'   => $e::class,
            'message'     => self::sanitizeMessage($e->getMessage()),
        ];

        // Logging must never break error responses: every failure here is
        // swallowed on purpose (disk full, permissions, ...).
        try {
            if (!is_dir(self::$directory)) {
                @mkdir(self::$directory, 0755, true);
            }

            $line = json_encode(
                $entry,
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
            );

            if ($line !== false) {
                @file_put_contents(
                    self::$directory . '/app-error.log',
                    $line . PHP_EOL,
                    FILE_APPEND | LOCK_EX
                );
            }
        } catch (Throwable) {
            // Intentionally ignored — see note above.
        }

        return $requestId;
    }

    /**
     * Correlation ID: honors a well-formed inbound X-Request-Id (e.g. set by
     * a reverse proxy) so traces can span systems; otherwise generates one.
     */
    private static function currentRequestId(?Request $request): string
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
     * Trims the message and redacts credential-looking substrings before it
     * touches disk. Normal framework messages pass through unchanged.
     */
    private static function sanitizeMessage(string $message): string
    {
        $patterns = [
            '/Bearer\s+\S+/i',
            '/\b(?:password|passwd|token|api[_-]?key|secret)\s*[:=]\s*\S+/i',
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
}
