<?php

namespace App\Repositories;

use App\Models\Variant;

/**
 * ProductVariantRepository — persistence for product_variants.
 *
 * Every size/color combination of a product. All queries are PDO prepared
 * statements. A UNIQUE(product_id, size, color) and UNIQUE(sku) live in the
 * schema, so duplicate combos / duplicate SKUs raise an integrity error.
 */
final class ProductVariantRepository extends Repository
{
    /**
     * Creates a variant for a product.
     *
     * @param int         $productId     Parent product id.
     * @param string      $size          Size label (S, M, L...).
     * @param string      $color         Color label.
     * @param string      $sku           Unique stock reference.
     * @param string|null $price         Optional override (null = product base_price).
     * @param int         $stockQuantity Available stock.
     *
     * @return int Auto-increment id of the new record.
     */
    public function create(
        int $productId,
        string $size,
        string $color,
        string $sku,
        ?string $price,
        int $stockQuantity
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO product_variants (product_id, size, color, sku, price, stock_quantity)
             VALUES (:product_id, :size, :color, :sku, :price, :stock_quantity)'
        );
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->bindValue(':size', $size);
        $stmt->bindValue(':color', $color);
        $stmt->bindValue(':sku', $sku);
        $stmt->bindValue(':price', $price);
        $stmt->bindValue(':stock_quantity', $stockQuantity, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Lists a product's variants ordered by id.
     *
     * @return array<int, Variant>
     */
    public function listByProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM product_variants WHERE product_id = :product_id ORDER BY id'
        );
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row): Variant => Variant::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Whether any of a product's variants appear on an order line.
     *
     * Drives the hard-delete guard (destroy): if true, deleting the product
     * would cascade into product_variants and then hit order_items' explicit
     * ON DELETE RESTRICT — so the caller must refuse and demand deactivation.
     */
    public function isOrdered(int $productId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT EXISTS(
                SELECT 1 FROM order_items oi
                JOIN product_variants v ON v.id = oi.product_variant_id
                WHERE v.product_id = :product_id
             ) AS ordered'
        );
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }
}