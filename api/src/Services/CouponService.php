<?php

namespace App\Services;

use App\Models\Coupon;

/**
 * CouponService — pure discount arithmetic.
 *
 * Discounts apply to merchandise only, never to shipping. The discount is
 * capped at the merchandise subtotal, so an order can never go negative or
 * reduce the shipping charge. Percentage values are validated on write
 * (0 < value <= 100); a legacy/out-of-range row is still capped defensively.
 */
final class CouponService
{
    /**
     * Discount in cents for a coupon against a merchandise subtotal.
     */
    public static function calculateDiscountCents(Coupon $coupon, int $subtotalCents): int
    {
        if ($subtotalCents <= 0) {
            return 0;
        }

        $value = (float) $coupon->discountValue;
        if ($value <= 0) {
            return 0;
        }

        $discount = $coupon->discountType === 'percentage'
            ? (int) round($subtotalCents * $value / 100)
            : (int) round($value * 100);

        return max(0, min($discount, $subtotalCents));
    }
}
