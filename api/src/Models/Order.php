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
        public array $items = []
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
            $row['lookup_token'] ?? null
        );
    }

    /**
     * Serializes the order for JSON responses.
     */
    public function toArray(): array
    {
        $data = [
            'id'               => $this->id,
            'user_id'          => $this->userId,
            'status'           => $this->status,
            'total_amount'     => $this->totalAmount,
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