<?php

namespace App\Core;

/**
 * ValidationException — server-side validation failed.
 *
 * Carries the per-field errors so controllers can answer 422 with a
 * clear, structured body: {"error": "...", "errors": {"field": "msg"}}.
 */
final class ValidationException extends \InvalidArgumentException
{
    /**
     * @param array<string, string> $errors  Field => error message map.
     * @param string                $message Summary message returned as "error".
     */
    public function __construct(
        private readonly array $errors,
        string $message = 'Validation failed.'
    ) {
        parent::__construct($message, 422);
    }

    /**
     * Returns the per-field validation errors.
     *
     * @return array<string, string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}