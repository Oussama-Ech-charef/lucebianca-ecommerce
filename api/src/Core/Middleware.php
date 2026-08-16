<?php

namespace App\Core;

/**
 * Middleware — contract for request pre-processing.
 *
 * A middleware inspects the request and either lets it continue or short-
 * circuits it (usually by calling Response::error and exiting).
 */
interface Middleware
{
    /**
     * Runs before the controller action.
     */
    public function handle(Request $request): void;
}