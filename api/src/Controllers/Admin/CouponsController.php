<?php

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\Request;
use App\Core\ValidationException;
use App\Repositories\CouponRepository;

/**
 * CouponsController — admin coupon management (CRUD).
 *
 * Only the schema's fields are accepted: code, discount_type,
 * discount_value, expires_at, is_active. No usage limits or minimum-spend
 * rules are invented because the coupons table has no columns for them.
 */
final class CouponsController extends Controller
{
    private CouponRepository $coupons;

    public function __construct()
    {
        $this->coupons = new CouponRepository();
    }

    /**
     * GET /api/admin/coupons — all coupons (active, inactive and expired).
     */
    public function index(Request $request): never
    {
        $this->json([
            'data' => array_map(
                static fn ($coupon): array => $coupon->toArray(),
                $this->coupons->all()
            ),
        ]);
    }

    /**
     * POST /api/admin/coupons — create a coupon.
     */
    public function store(Request $request): never
    {
        $body = $request->all();
        $errors = [];

        $code = $this->normalizeCode($body['code'] ?? '');
        $this->validateCode($code, $errors);
        if ($code !== '' && !isset($errors['code']) && $this->coupons->codeExists($code)) {
            $this->error('Coupon code already exists.', 409, [
                'errors' => ['code' => 'This coupon code is already in use.'],
            ]);
        }

        $type = (string) ($body['discount_type'] ?? '');
        if (!in_array($type, ['percentage', 'fixed'], true)) {
            $errors['discount_type'] = 'Discount type must be "percentage" or "fixed".';
        }

        $value = $this->parseDecimal($body['discount_value'] ?? null, $errors);
        $this->validateDiscountValue($type, $value, $errors);

        $expiresAt = $this->parseExpiresAt($body['expires_at'] ?? null, $errors);

        $isActive = true;
        if (array_key_exists('is_active', $body)) {
            $parsed = $this->parseBool($body['is_active']);
            if ($parsed === null) {
                $errors['is_active'] = 'Active must be true/false or 1/0.';
            } else {
                $isActive = $parsed;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->coupons->create([
            'code'           => $code,
            'discount_type'  => $type,
            'discount_value' => $this->formatAmount((float) $value),
            'expires_at'     => $expiresAt,
            'is_active'      => $isActive ? 1 : 0,
        ]);

        $coupon = $this->coupons->findById($id);
        $this->json(['data' => $coupon?->toArray()], 201);
    }

    /**
     * PUT /api/admin/coupons/{id} — update supplied coupon fields.
     */
    public function update(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        $coupon = $this->coupons->findById($id);
        if ($coupon === null) {
            $this->error('Coupon not found.', 404);
        }

        $body = $request->all();
        $errors = [];
        $fields = [];

        if (array_key_exists('code', $body)) {
            $code = $this->normalizeCode($body['code']);
            $this->validateCode($code, $errors);
            if ($code !== '' && !isset($errors['code'])) {
                if ($this->coupons->codeExists($code, $id)) {
                    $this->error('Coupon code already exists.', 409, [
                        'errors' => ['code' => 'This coupon code is already in use.'],
                    ]);
                }
                $fields['code'] = $code;
            }
        }

        $type = array_key_exists('discount_type', $body)
            ? (string) $body['discount_type']
            : $coupon->discountType;
        if (array_key_exists('discount_type', $body) && !in_array($type, ['percentage', 'fixed'], true)) {
            $errors['discount_type'] = 'Discount type must be "percentage" or "fixed".';
        }

        $value = array_key_exists('discount_value', $body)
            ? $this->parseDecimal($body['discount_value'], $errors)
            : (float) $coupon->discountValue;
        $this->validateDiscountValue($type, $value, $errors);

        if (array_key_exists('discount_type', $body) && !isset($errors['discount_type'])) {
            $fields['discount_type'] = $type;
        }
        if (array_key_exists('discount_value', $body) && !isset($errors['discount_value'])) {
            $fields['discount_value'] = $this->formatAmount((float) $value);
        }

        if (array_key_exists('expires_at', $body)) {
            $expiresAt = $this->parseExpiresAt($body['expires_at'], $errors);
            if (!isset($errors['expires_at'])) {
                $fields['expires_at'] = $expiresAt;
            }
        }

        if (array_key_exists('is_active', $body)) {
            $parsed = $this->parseBool($body['is_active']);
            if ($parsed === null) {
                $errors['is_active'] = 'Active must be true/false or 1/0.';
            } else {
                $fields['is_active'] = $parsed ? 1 : 0;
            }
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($fields === []) {
            throw new ValidationException(['_general' => 'No coupon fields provided.']);
        }

        $this->coupons->update($id, $fields);
        $updated = $this->coupons->findById($id);

        $this->json(['data' => $updated?->toArray()]);
    }

    /**
     * DELETE /api/admin/coupons/{id} — delete a coupon.
     *
     * Existing orders keep their coupon_code + discount_amount snapshots;
     * only orders.coupon_id becomes NULL (ON DELETE SET NULL).
     */
    public function destroy(Request $request, array $params): never
    {
        $id = (int) ($params['id'] ?? 0);
        if ($this->coupons->findById($id) === null) {
            $this->error('Coupon not found.', 404);
        }

        $this->coupons->delete($id);
        $this->noContent();
    }

    private function normalizeCode(mixed $raw): string
    {
        return is_string($raw) ? strtoupper(trim($raw)) : '';
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateCode(string $code, array &$errors): void
    {
        if ($code === '') {
            $errors['code'] = 'Coupon code is required.';
            return;
        }
        if (mb_strlen($code) < 2 || mb_strlen($code) > 40) {
            $errors['code'] = 'Coupon code must be 2-40 characters.';
            return;
        }
        if (preg_match('/^[A-Z0-9_-]+$/', $code) !== 1) {
            $errors['code'] = 'Coupon code may contain letters, numbers, hyphens and underscores only.';
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function parseDecimal(mixed $raw, array &$errors): float
    {
        if (!is_string($raw) && !is_int($raw) && !is_float($raw)) {
            $errors['discount_value'] = 'Discount value must be a number.';
            return 0.0;
        }
        $value = trim((string) $raw);
        if ($value === '' || preg_match('/^\d{1,8}(\.\d{1,2})?$/', $value) !== 1) {
            $errors['discount_value'] = 'Discount value must be a positive amount with at most 2 decimals.';
            return 0.0;
        }

        return (float) $value;
    }

    /**
     * @param array<string, string> $errors
     */
    private function validateDiscountValue(string $type, float $value, array &$errors): void
    {
        if (isset($errors['discount_value'])) {
            return;
        }
        if ($value <= 0) {
            $errors['discount_value'] = 'Discount value must be greater than 0.';
            return;
        }
        if ($type === 'percentage' && $value > 100) {
            $errors['discount_value'] = 'Percentage discounts cannot exceed 100.';
        }
        if ($type === 'fixed' && $value > 99999999.99) {
            $errors['discount_value'] = 'Fixed discount is too large.';
        }
    }

    /**
     * @param array<string, string> $errors
     */
    private function parseExpiresAt(mixed $raw, array &$errors): ?string
    {
        if ($raw === null || $raw === '') {
            return null;
        }
        if (!is_string($raw)) {
            $errors['expires_at'] = 'Expiry must be a date/time string.';
            return null;
        }

        $value = trim($raw);
        foreach (['Y-m-d\TH:i:s', 'Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
            $date = \DateTimeImmutable::createFromFormat('!' . $format, $value);
            if ($date === false) {
                continue;
            }

            // createFromFormat() normalizes impossible calendar dates (for
            // example 2026-02-30 becomes 2026-03-02) and reports that through
            // getLastErrors(). Reject those warnings instead of persisting a
            // silently different expiry than the admin submitted.
            $parseErrors = \DateTimeImmutable::getLastErrors();
            $warningCount = is_array($parseErrors) ? (int) ($parseErrors['warning_count'] ?? 0) : 0;
            $errorCount = is_array($parseErrors) ? (int) ($parseErrors['error_count'] ?? 0) : 0;

            if ($warningCount === 0 && $errorCount === 0) {
                return $date->format('Y-m-d H:i:s');
            }
        }

        $errors['expires_at'] = 'Expiry must be a valid date/time.';
        return null;
    }

    private function parseBool(mixed $raw): ?bool
    {
        if (is_bool($raw)) {
            return $raw;
        }
        if ($raw === 1 || $raw === '1' || $raw === 'true') {
            return true;
        }
        if ($raw === 0 || $raw === '0' || $raw === 'false') {
            return false;
        }
        return null;
    }

    private function formatAmount(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
