<?php

namespace App\Core;

use App\Core\ErrorLogger;
use App\Core\Request;
use InvalidArgumentException;

/**
 * Router — maps HTTP method + URL pattern to a controller action.
 *
 * Patterns support named placeholders: "/api/products/{slug}". Match
 * results become the last argument passed to the controller action.
 * Optional middleware (e.g. AuthMiddleware) run before the action.
 */
final class Router
{
    /** @var array<int, array{method:string, pattern:string, regex:string, handler:array, middleware:array<int,object>}> */
    private array $routes = [];

    /**
     * Registers a route.
     *
     * @param string          $method     HTTP method (GET, POST, PUT, DELETE...).
     * @param string          $pattern    URL pattern, e.g. "/api/products/{slug}".
     * @param array           $handler    [Controller::class, 'method'] callable pair.
     * @param array<int,object> $middleware Middleware instances to run before the action.
     */
    public function add(string $method, string $pattern, array $handler, array $middleware = []): void
    {
        $regex = self::compile($pattern);
        $this->routes[] = [
            'method'     => strtoupper($method),
            'pattern'    => $pattern,
            'regex'      => $regex,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /** Registers a GET route. */
    public function get(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('GET', $pattern, $handler, $middleware);
    }

    /** Registers a POST route. */
    public function post(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('POST', $pattern, $handler, $middleware);
    }

    /** Registers a PUT route. */
    public function put(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('PUT', $pattern, $handler, $middleware);
    }

    /** Registers a DELETE route. */
    public function delete(string $pattern, array $handler, array $middleware = []): void
    {
        $this->add('DELETE', $pattern, $handler, $middleware);
    }

    /**
     * Matches a request against all routes and dispatches the first hit.
     */
    public function dispatch(Request $request): void
    {
        // Front door: one correlation id per request. Stored in
        // RequestContext so every response header (via Response) and every
        // log line (via ErrorLogger::currentRequestId) shares it — including
        // middleware early-exits and the bootstrap 500 handler. Refreshed on
        // every call, so long-lived processes never leak ids across requests.
        RequestContext::set('request_id', ErrorLogger::generateRequestId($request));

        foreach ($this->routes as $route) {
            if ($route['method'] !== $request->method()) {
                continue;
            }

            if (preg_match($route['regex'], $request->path(), $matches) !== 1) {
                continue;
            }

            // Named params (e.g. {slug}) — drop the full-string capture.
            $params = array_filter(
                $matches,
                fn ($key) => !is_int($key),
                ARRAY_FILTER_USE_KEY
            );

            foreach ($route['middleware'] as $middleware) {
                $middleware->handle($request);
            }

            [$controllerClass, $action] = $route['handler'];
            $controller = new $controllerClass();
            $controller->{$action}($request, $params);

            return;
        }

        $requestId = ErrorLogger::currentRequestId($request);
        Response::notFound($requestId);
    }

    /**
     * Converts a pattern with {name} placeholders into a regex.
     */
    private static function compile(string $pattern): string
    {
        $normalized = rtrim($pattern, '/') ?: '/';
        $regex = preg_replace(
            '/\{(\w+)\}/',
            '(?P<$1>[^/]+)',
            $normalized
        );

        if ($regex === null) {
            throw new InvalidArgumentException("Invalid route pattern: {$pattern}");
        }

        return '#^' . $regex . '$#';
    }
}