<?php

namespace App\Models;

/**
 * Order — data holder for the orders table.
 * user_id is nullable because Guest Checkout is supported.
 *
 * The two ENUM columns (spec section 4) are mirrored here as constants so the
 * controller and repository validate against the same allowed values — an
 * invalid status/payment_status is rejected before it ever reaches the DB.
 */
final class Order
{
    public const STATUSES = ['pending', 'processing', 'shipped', 'delivered', 'cancelled'];

    public const PAYMENT_STATUSES = ['pending', 'paid', 'failed'];

    public function __construct(
        public int $id,
        public ?int $userId,
        public string $status,
        public string $totalAmount,
        public string $shippingAddress,
        public string $city,
        public string $postalCode,
        public string $customerName,
        public string $phone,
        public ?string $email,
        public string $paymentMethod,
        public string $paymentStatus,
        public string $createdAt,
        public ?string $lookupToken = null,
        public array $items = [],
        public string $subtotalAmount = '0.00',
        public string $shippingCost = '0.00',
        public ?string $inventoryReleasedAt = null,
        public ?int $couponId = null,
        public ?string $couponCode = null,
        public string $discountAmount = '0.00'
    ) {
    }

    /**
     * Builds an Order from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            isset($row['user_id']) ? (int) $row['user_id'] : null,
            (string) $row['status'],
            (string) $row['total_amount'],
            (string) $row['shipping_address'],
            $row['city'] ?? '',
            $row['postal_code'] ?? '',
            (string) $row['customer_name'],
            (string) $row['phone'],
            $row['email'] ?? null,
            (string) $row['payment_method'],
            (string) $row['payment_status'],
            (string) $row['created_at'],
            $row['lookup_token'] ?? null,
            [],
            isset($row['subtotal_amount']) ? (string) $row['subtotal_amount'] : (string) $row['total_amount'],
            isset($row['shipping_cost']) ? (string) $row['shipping_cost'] : '0.00',
            $row['inventory_released_at'] ?? null,
            isset($row['coupon_id']) && $row['coupon_id'] !== null ? (int) $row['coupon_id'] : null,
            isset($row['coupon_code']) && $row['coupon_code'] !== null ? (string) $row['coupon_code'] : null,
            isset($row['discount_amount']) ? (string) $row['discount_amount'] : '0.00'
        );
    }

    /**
     * Serializes the order for JSON responses.
     *
     * Exposes subtotal/shipping breakdown alongside total_amount so every
     * surface (checkout, confirmation, WhatsApp, admin, email) renders the
     * same final total. Private fields (lookup_token) stay gated by callers.
     */
    public function toArray(): array
    {
        $data = [
            'id'               => $this->id,
            'user_id'          => $this->userId,
            'status'           => $this->status,
            'total_amount'     => $this->totalAmount,
            'subtotal_amount'  => $this->subtotalAmount,
            'shipping_cost'    => $this->shippingCost,
            'coupon_id'        => $this->couponId,
            'coupon_code'      => $this->couponCode,
            'discount_amount'  => $this->discountAmount,
            'shipping_address' => $this->shippingAddress,
            'city'             => $this->city,
            'postal_code'      => $this->postalCode,
            'customer_name'    => $this->customerName,
            'phone'            => $this->phone,
            'email'            => $this->email,
            'payment_method'   => $this->paymentMethod,
            'payment_status'   => $this->paymentStatus,
            'created_at'       => $this->createdAt,
            'items'            => $this->items,
        ];

        // Durable inventory-release marker (020). Exposed so admin tooling
        // and payment polling can distinguish "stock still reserved" from
        // "stock released" without inferring it from status combinations.
        if ($this->inventoryReleasedAt !== null) {
            $data['inventory_released_at'] = $this->inventoryReleasedAt;
        }

        // Expose lookup_token only to the creator (store response) or
        // to an authorized holder. Controllers decide whether to strip it;
        // model keeps it for internal checks.
        if ($this->lookupToken !== null) {
            $data['lookup_token'] = $this->lookupToken;
        }

        return $data;
    }

    public function toArrayWithoutLookupToken(): array
    {
        $data = $this->toArray();
        unset($data['lookup_token']);
        return $data;
    }
}