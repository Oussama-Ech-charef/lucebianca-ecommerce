<?php

namespace App\Models;

/**
 * Variant — data holder for the product_variants table.
 * A variant is one color/size combination of a product.
 */
final class Variant
{
    public function __construct(
        public int $id,
        public int $productId,
        public string $size,
        public string $color,
        public string $sku,
        public ?string $price,          // null = fall back to product base_price
        public int $stockQuantity,
        public string $createdAt
    ) {
    }

    /**
     * Builds a Variant from a database row.
     */
    public static function fromRow(array $row): self
    {
        return new self(
            (int) $row['id'],
            (int) $row['product_id'],
            (string) $row['size'],
            (string) $row['color'],
            (string) $row['sku'],
            $row['price'] ?? null,
            (int) $row['stock_quantity'],
            (string) $row['created_at']
        );
    }

    /**
     * Serializes the variant for JSON responses.
     */
    public function toArray(): array
    {
        return [
            'id'             => $this->id,
            'product_id'     => $this->productId,
            'size'           => $this->size,
            'color'          => $this->color,
            'sku'            => $this->sku,
            'price'          => $this->price,
            'stock_quantity' => $this->stockQuantity,
        ];
    }
}