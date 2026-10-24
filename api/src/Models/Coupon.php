<?php

namespace App\Models;

/**
 * Coupon — data holder for the coupons table.
 *
 * Mirrors only the columns that exist in the schema (001):
 * code, discount_type, discount_value, expires_at, is_active.
 * There are deliberately no usage-limit / minimum-spend fields.
 */
final class Coupon
{
    public const DISCOUNT_TYPES = ['percentage', 'fixed'];

    public function __construct(
        public int $id,
        public string $code,
        public string $discountType,
        public string $discountValue,
        public ?string $expiresAt,
        public int $isActive
    ) {
    }

    /**
     * Builds a Coupon from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (string) $row['code'],
            (string) $row['discount_type'],
            (string) $row['discount_value'],
            isset($row['expires_at']) && $row['expires_at'] !== null ? (string) $row['expires_at'] : null,
            (int) $row['is_active']
        );
    }

    /**
     * Serializes the coupon for API responses (admin + public preview).
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'code'           => $this->code,
            'discount_type'  => $this->discountType,
            'discount_value' => $this->discountValue,
            'expires_at'     => $this->expiresAt,
            'is_active'      => $this->isActive,
        ];
    }
}
