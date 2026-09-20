<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Repositories\SettingsRepository;

/**
 * SettingsController — admin settings management.
 *
 * Provides a strict, whitelisted key-value settings API. Settings stored in
 * the `settings` table affect live site behavior only when a runtime consumer
 * explicitly reads them (see docs/decisions for supported vs deferred keys).
 *
 * Security:
 * - Only whitelisted keys are accepted; arbitrary keys (including any
 *   secret-like `*key*`, `*secret*`, `*password*`, `*token*`, `*hash*`,
 *   `*cmi*`, `*jwt*`) are rejected with 422.
 * - Payment secrets (CMI keys) are **never** stored in `settings`; they
 *   live in `.env`/`.env` only. GET never returns secret-like values.
 * - Validation checks types, lengths, formats and ranges per key.
 */
final class SettingsController extends Controller
{
    private SettingsRepository $settings;

    /**
     * Strict whitelist — only these keys may be read/written via the API.
     * Deferred keys (coupons, analytics, admin accounts) are intentionally
     * excluded and will be added in a later step.
     */
    public const ALLOWED_KEYS = [
        'store_name',
        'store_email',
        'store_phone',
        'store_address',
        'whatsapp_number',
        'shipping_cost',
        'free_shipping_threshold',
        'payment_cod_enabled',
        'payment_whatsapp_enabled',
        'payment_card_enabled',
        'notify_admin_new_order',
        'notify_admin_low_stock',
        'notify_customer_order_confirmation',
        'notify_customer_shipping_update',
    ];

    /**
     * Subset of ALLOWED_KEYS safe to expose to the public storefront
     * (no notification toggles, no internal flags that could fingerprint
     * admin config). Used by GET /api/settings/public.
     */
    public const PUBLIC_KEYS = [
        'store_name',
        'store_email',
        'store_phone',
        'store_address',
        'whatsapp_number',
        'shipping_cost',
        'free_shipping_threshold',
        'payment_cod_enabled',
        'payment_whatsapp_enabled',
        'payment_card_enabled',
    ];

    /**
     * Keys that must never be exposed to the browser, even if an old
     * row exists in the table (defense-in-depth).
     */
    private const NEVER_EXPOSE_PATTERNS = [
        '/secret/i',
        '/password/i',
        '/passwd/i',
        '/token/i',
        '/hash/i',
        '/cmi/i',
        '/jwt/i',
        '/key/i',
        '/client_id/i',
        '/store_key/i',
        '/api_key/i',
    ];

    public function __construct()
    {
        $this->settings = new SettingsRepository();
    }

    /**
     * GET /api/admin/settings — get all whitelisted settings (admin only).
     * Secret-like keys are never returned, even if present in DB.
     */
    public function index(Request $request): never
    {
        $all = $this->settings->getAll();
        $safe = $this->filterForExposure($all, self::ALLOWED_KEYS);
        $this->json(['data' => $safe]);
    }

    /**
     * GET /api/settings/public — public safe settings for the storefront.
     * No auth. Used for WhatsApp number, payment toggles, store info.
     */
    public function publicIndex(Request $request): never
    {
        $values = $this->settings->getMultiple(self::PUBLIC_KEYS);
        $safe = $this->filterForExposure($values, self::PUBLIC_KEYS);
        $this->json(['data' => $safe]);
    }

    /**
     * PUT /api/admin/settings — update multiple settings.
     *
     * Body: {"store_name": "Luce Bianca", "store_email": "...", ...}
     * Strict: unknown keys, secret-like keys, and invalid values → 422
     * with per-field errors. Nothing is written on validation failure.
     */
    public function update(Request $request): never
    {
        $body = $request->all();

        if (!is_array($body) || $body === []) {
            $this->error('Validation failed.', 422, ['errors' => ['_general' => 'No valid settings provided.']]);
        }

        // Reject any key containing secret-like patterns before whitelist check
        foreach (array_keys($body) as $key) {
            if (!is_string($key)) {
                $this->error('Validation failed.', 422, ['errors' => ['_general' => 'Invalid key type.']]);
            }
            foreach (self::NEVER_EXPOSE_PATTERNS as $pattern) {
                if (preg_match($pattern, $key) === 1) {
                    $this->error('Validation failed.', 422, ['errors' => [$key => 'This setting key is not allowed.']]);
                }
            }
        }

        $unknown = [];
        foreach (array_keys($body) as $key) {
            if (!in_array($key, self::ALLOWED_KEYS, true)) {
                $unknown[$key] = 'Unknown setting key.';
            }
        }
        if ($unknown !== []) {
            $this->error('Validation failed.', 422, ['errors' => $unknown]);
        }

        $errors = [];
        $filtered = [];

        foreach ($body as $key => $value) {
            // Allow numeric booleans to be normalized to string
            if (!is_string($value) && !is_numeric($value)) {
                $errors[$key] = 'Value must be a string.';
                continue;
            }
            $str = trim((string) $value);
            $err = $this->validateValue($key, $str, $body);
            if ($err !== null) {
                $errors[$key] = $err;
                continue;
            }
            $filtered[$key] = $str;
        }

        if ($errors !== []) {
            $this->error('Validation failed.', 422, ['errors' => $errors]);
        }

        if (empty($filtered)) {
            $this->error('No valid settings provided.', 422);
        }

        $this->settings->setMultiple($filtered);

        $this->json(['message' => 'Settings updated successfully.']);
    }

    /**
     * Validates a single setting value.
     *
     * @return string|null Error message or null on success.
     */
    private function validateValue(string $key, string $value, array $all): ?string
    {
        // Empty string is allowed to clear optional settings (except booleans)
        $isBooleanKey = in_array($key, [
            'payment_cod_enabled',
            'payment_whatsapp_enabled',
            'payment_card_enabled',
            'notify_admin_new_order',
            'notify_admin_low_stock',
            'notify_customer_order_confirmation',
            'notify_customer_shipping_update',
        ], true);

        if ($isBooleanKey) {
            if (!in_array($value, ['0', '1'], true)) {
                return 'Must be "0" or "1".';
            }
            return null;
        }

        // Optional string keys: empty allowed to clear
        if ($value === '') {
            return null;
        }

        switch ($key) {
            case 'store_name':
                if (mb_strlen($value) < 2 || mb_strlen($value) > 100) {
                    return 'Store name must be 2-100 characters.';
                }
                break;
            case 'store_email':
                if (!filter_var($value, FILTER_VALIDATE_EMAIL) || mb_strlen($value) > 150) {
                    return 'Store email must be a valid email (max 150).';
                }
                break;
            case 'store_phone':
                if (!preg_match('/^[0-9+()\-\s]{8,20}$/', $value)) {
                    return 'Phone must be 8-20 digits (+, spaces, dashes, parens allowed).';
                }
                break;
            case 'store_address':
                if (mb_strlen($value) < 5 || mb_strlen($value) > 500) {
                    return 'Address must be 5-500 characters.';
                }
                break;
            case 'whatsapp_number':
                // International format, digits only, 8-15 digits after optional +, 212 prefix common but not required
                if (!preg_match('/^\+?[0-9]{8,20}$/', $value)) {
                    return 'WhatsApp number must be 8-20 digits (optional leading +).';
                }
                break;
            case 'shipping_cost':
            case 'free_shipping_threshold':
                if (!preg_match('/^\d{1,4}(\.\d{1,2})?$/', $value)) {
                    return 'Must be a valid amount (max 4 digits, 2 decimals).';
                }
                $num = (float) $value;
                if ($num < 0 || $num > 99999) {
                    return 'Must be between 0 and 99999.';
                }
                // Cross-field: threshold should be >= cost if both set
                if ($key === 'free_shipping_threshold' && isset($all['shipping_cost']) && $all['shipping_cost'] !== '') {
                    $cost = (float) $all['shipping_cost'];
                    if ($num > 0 && $num < $cost) {
                        return 'Free-shipping threshold must be >= shipping cost.';
                    }
                }
                break;
            default:
                return 'Unknown setting key.';
        }

        return null;
    }

    /**
     * Filters raw settings for safe exposure (whitelist + never-expose patterns).
     *
     * @param array<string,string> $all
     * @param string[] $allowList
     * @return array<string,string>
     */
    private function filterForExposure(array $all, array $allowList): array
    {
        $safe = [];
        foreach ($all as $k => $v) {
            if (!in_array($k, $allowList, true)) {
                continue;
            }
            $blocked = false;
            foreach (self::NEVER_EXPOSE_PATTERNS as $pattern) {
                if (preg_match($pattern, $k) === 1) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }
            $safe[$k] = $v;
        }
        return $safe;
    }
}
