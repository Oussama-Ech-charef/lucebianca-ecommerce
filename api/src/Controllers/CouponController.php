<?php

namespace App\Controllers;

use App\Core\ConflictException;
use App\Core\Request;
use App\Core\ValidationException;
use App\Services\OrderService;

/**
 * CouponController — public coupon preview for checkout.
 *
 * Validation is deliberately authoritative: the client sends a code + cart
 * lines, and the server resolves live prices/stock before applying the
 * discount. The same calculation runs again inside order placement, so a
 * preview can never freeze a discount the customer is no longer entitled to.
 */
final class CouponController extends Controller
{
    private OrderService $orders;

    public function __construct()
    {
        $this->orders = new OrderService();
    }

    /**
     * POST /api/coupons/validate — preview a coupon against the cart.
     *
     * Body: {"code": "WELCOME10", "items": [{"variant_id": 6, "quantity": 2}]}
     *
     * @return never 200 with the authoritative breakdown, 422 for an invalid
     *               code/cart payload, 409 when a cart line is unavailable.
     */
    public function validate(Request $request): never
    {
        $code = trim((string) $request->input('code', ''));
        if ($code === '') {
            $this->error('Validation failed.', 422, [
                'errors' => ['code' => 'Coupon code is required.'],
            ]);
        }

        $items = $request->input('items');
        if (!is_array($items)) {
            $this->error('Validation failed.', 422, [
                'errors' => ['items' => 'Cart items must be an array.'],
            ]);
        }

        try {
            $quote = $this->orders->quoteCart($items, $code);
        } catch (ValidationException $e) {
            $this->error($e->getMessage(), 422, ['errors' => $e->errors()]);
        } catch (ConflictException $e) {
            $this->error($e->getMessage(), 409);
        }

        // $quote['coupon'] is guaranteed non-null: quoteCart only returns a
        // successful quote when the code resolved to a valid coupon.
        $coupon = $quote['coupon'] ?? [];

        $this->json([
            'data' => [
                'code'            => $coupon['code'] ?? $code,
                'discount_type'   => $coupon['discount_type'] ?? null,
                'discount_value'  => $coupon['discount_value'] ?? null,
                'subtotal_amount' => $quote['subtotal_amount'],
                'discount_amount' => $quote['discount_amount'],
                'shipping_cost'   => $quote['shipping_cost'],
                'total_amount'    => $quote['total_amount'],
            ],
        ]);
    }
}
