<?php

namespace App\Controllers;

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
     */
    protected function json(mixed $data, int $status = 200): never
    {
        Response::json($data, $status);
    }

    /**
     * Sends a consistent error response.
     *
     * @param string $message Human-readable error description.
     * @param int    $status  HTTP status code (default 400).
     * @param array  $extra   Optional extra fields (e.g. validation errors).
     */
    protected function error(string $message, int $status = 400, array $extra = []): never
    {
        Response::error($message, $status, $extra);
    }

    /**
     * "Not implemented yet" marker for endpoints planned in later phases.
     */
    protected function notImplemented(string $feature): never
    {
        Response::error("$feature is not implemented yet (phase not reached).", 501);
    }

    /**
     * 204 No Content — success with an intentionally empty body.
     */
    protected function noContent(): never
    {
        Response::noContent();
    }
}