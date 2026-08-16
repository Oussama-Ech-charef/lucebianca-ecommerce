<?php

namespace App\Core;

/**
 * Request — immutable wrapper around the incoming HTTP request.
 *
 * Normalizes access to the HTTP method, normalized path, query string,
 * JSON body and headers so controllers never touch PHP superglobals directly.
 */
final class Request
{
    public function __construct(
        private string $method,
        private string $path,
        private array $query,
        private array $body,
        private array $headers
    ) {
    }

    /**
     * Builds a Request from the current superglobals.
     */
    public static function fromGlobals(): self
    {
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $uri    = $_SERVER['REQUEST_URI'] ?? '/';

        // Strip query string, then normalize trailing slashes.
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        $path = rtrim($path, '/');
        if ($path === '') {
            $path = '/';
        }

        $rawBody = file_get_contents('php://input');
        $body    = (array) json_decode((string) $rawBody, true);

        return new self(
            strtoupper($method),
            $path,
            array_merge($_GET, $_POST),
            $body,
            self::headersFromServer()
        );
    }

    /**
     * Rebuilds request headers from $_SERVER so the wrapper works on any
     * server (Apache, PHP built-in dev server, nginx+php-fpm...).
     *
     * @return array<string, string>
     */
    private static function headersFromServer(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($key, 5)))));
                $headers[$name] = (string) $value;
            }
        }

        // Keys that arrive outside HTTP_* on some servers.
        foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $serverKey => $name) {
            if (isset($_SERVER[$serverKey])) {
                $headers[$name] = (string) $_SERVER[$serverKey];
            }
        }

        return $headers;
    }

    public function method(): string
    {
        return $this->method;
    }

    public function path(): string
    {
        return $this->path;
    }

    /**
     * Returns a query parameter value or the default.
     *
     * @param mixed $default Value returned when the key is missing.
     */
    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Returns a JSON body field or the default.
     */
    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    /**
     * Returns the full JSON body as an array.
     */
    public function all(): array
    {
        return $this->body;
    }

    /**
     * Returns a request header value or the default.
     */
    public function header(string $name, mixed $default = null): mixed
    {
        $searchKey = strtolower($name);
        foreach ($this->headers as $key => $value) {
            if (strtolower((string) $key) === $searchKey) {
                return $value;
            }
        }

        return $default;
    }

    /**
     * Authorization header value ("Bearer <token>") or null.
     */
    public function bearerToken(): ?string
    {
        $header = $this->header('Authorization');
        if (!is_string($header) || preg_match('/^Bearer\s+(.+)$/i', trim($header), $m) !== 1) {
            return null;
        }

        return $m[1];
    }
}