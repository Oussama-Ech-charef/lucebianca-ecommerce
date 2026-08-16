<?php

namespace App\Core;

/**
 * Validator — small server-side input validation helper.
 *
 * Satisfies spec 3.1 ("Input validation enforced strictly on every
 * endpoint, server-side, not just client-side"). Returns a per-field
 * error map that controllers turn into a 422 response; validation
 * rules can be reused across endpoints (DRY, single responsibility).
 */
final class Validator
{
    private function __construct()
    {
    }

    /**
     * Validates $data against $rules.
     *
     * Rules format: field => list of rule names, e.g.
     *   ['email' => ['required', 'email'],
     *    'password' => ['required', ['min', 8]]]
     * Supported rules: 'required', 'email', 'min' (needs a numeric param).
     *
     * @param array<string, mixed>             $data  Field values to check.
     * @param array<string, list<string|array>> $rules Field => rules.
     *
     * @return array<string, string> Field => first error message (empty when valid).
     */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];

        foreach ($rules as $field => $fieldRules) {
            $value = $data[$field] ?? null;

            foreach ($fieldRules as $rule) {
                $name  = is_array($rule) ? (string) $rule[0] : (string) $rule;
                $param = is_array($rule) ? $rule[1] : null;
                $label = ucfirst($field);

                switch ($name) {
                    case 'required':
                        if ($value === null || (is_string($value) && trim($value) === '')) {
                            $errors[$field] = "{$label} is required.";
                        }
                        break;

                    case 'email':
                        if ($value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                            $errors[$field] = "{$label} must be a valid email address.";
                        }
                        break;

                    case 'min':
                        if ($value !== null && strlen((string) $value) < (int) $param) {
                            $errors[$field] = "{$label} must be at least {$param} characters.";
                        }
                        break;
                }

                if (isset($errors[$field])) {
                    break; // keep the first error per field
                }
            }
        }

        return $errors;
    }
}