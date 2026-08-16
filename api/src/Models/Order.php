<?php

namespace App\Models;

/**
 * Order — data holder for the orders table.
 * user_id is nullable because Guest Checkout is supported.
 */
final class Order
{
    public function __construct(
        public int $id,
        public ?int $userId,
        public string $status,
        public string $totalAmount,
        public string $shippingAddress,
        public string $customerName,
        public string $phone,
        public string $paymentMethod,
        public string $paymentStatus,
        public string $createdAt,
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
            (string) $row['customer_name'],
            (string) $row['phone'],
            (string) $row['payment_method'],
            (string) $row['payment_status'],
            (string) $row['created_at']
        );
    }

    /**
     * Serializes the order for JSON responses.
     */
    public function toArray(): array
    {
        return [
            'id'               => $this->id,
            'user_id'          => $this->userId,
            'status'           => $this->status,
            'total_amount'     => $this->totalAmount,
            'shipping_address' => $this->shippingAddress,
            'customer_name'    => $this->customerName,
            'phone'            => $this->phone,
            'payment_method'   => $this->paymentMethod,
            'payment_status'   => $this->paymentStatus,
            'created_at'       => $this->createdAt,
            'items'            => $this->items,
        ];
    }
}