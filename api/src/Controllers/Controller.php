<?php

namespace App\Controllers;

use App\Core\ErrorLogger;
use App\Core\Request;
use App\Core\Response;

/**
 * Controller — shared base for all HTTP controllers.
 *
 * Responsibilities: read validated input, delegate to Services/Repositories,
 * and return JSON. Controllers never hold SQL. The `json()` helper keeps
 * response formatting consistent.
 */
abstract class Controller
{
    /**
     * Sends a JSON response.
     *
     * @param mixed $data   Payload returned to the client.
     * @param int   $status HTTP status code (default 200).
     * @param Request|null $request Optional request for correlation ID.
     */
    protected function json(mixed $data, int $status = 200, ?Request $request = null): never
    {
        $requestId = $request !== null ? ErrorLogger::currentRequestId($request) : null;
        Response::json($data, $status, $requestId);
    }

    /**
     * Sends a consistent error response.
     *
     * @param string $message Human-readable error description.
     * @param int    $status  HTTP status code (default 400).
     * @param array  $extra   Optional extra fields (e.g. validation errors).
     * @param Request|null $request Optional request for correlation ID.
     */
    protected function error(string $message, int $status = 400, array $extra = [], ?Request $request = null): never
    {
        $requestId = $request !== null ? ErrorLogger::currentRequestId($request) : null;
        Response::error($message, $status, $extra, $requestId);
    }

    /**
     * "Not implemented yet" marker for endpoints planned in later phases.
     */
    protected function notImplemented(string $feature, ?Request $request = null): never
    {
        $requestId = $request !== null ? ErrorLogger::currentRequestId($request) : null;
        Response::error("$feature is not implemented yet (phase not reached).", 501, [], $requestId);
    }

    /**
     * 204 No Content — success with an intentionally empty body.
     */
    protected function noContent(?Request $request = null): never
    {
        $requestId = $request !== null ? ErrorLogger::currentRequestId($request) : null;
        Response::noContent($requestId);
    }
}