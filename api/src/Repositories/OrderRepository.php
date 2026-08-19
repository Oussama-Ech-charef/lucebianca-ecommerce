<?php

namespace App\Repositories;

use App\Models\Order;

/**
 * OrderRepository — persistence for orders + order_items.
 *
 * All queries are PDO prepared statements (security requirement 3.1).
 * Stock decrements use a guarded UPDATE (WHERE stock_quantity >= :qty) so a
 * variant's stock can never be driven below zero, even under a race.
 * Callers place the insert+decrement sequence inside one transaction.
 */
final class OrderRepository extends Repository
{
    /**
     * Inserts an orders row (guest checkout: user_id NULL, status 'pending',
     * payment_status 'pending').
     *
     * @param array{
     *   total_amount: string, shipping_address: string,
     *   customer_name: string, phone: string, payment_method: string
     * } $data Order columns.
     *
     * @return int Auto-increment id of the new record.
     */
    public function insertOrder(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO orders
               (user_id, status, total_amount, shipping_address, customer_name, phone, payment_method, payment_status)
             VALUES
               (NULL, \'pending\', :total_amount, :shipping_address, :customer_name, :phone, :payment_method, \'pending\')'
        );
        $stmt->bindValue(':total_amount', $data['total_amount']);
        $stmt->bindValue(':shipping_address', $data['shipping_address']);
        $stmt->bindValue(':customer_name', $data['customer_name']);
        $stmt->bindValue(':phone', $data['phone']);
        $stmt->bindValue(':payment_method', $data['payment_method']);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Inserts one order_items line with the server-computed price at purchase
     * time (spec section 4: price_at_purchase records the price even if the
     * price changes later).
     *
     * @param int    $orderId         Parent order id.
     * @param int    $variantId       Ordered variant id.
     * @param int    $quantity        Ordered quantity.
     * @param string $priceAtPurchase Unit price captured at order time.
     */
    public function insertItem(int $orderId, int $variantId, int $quantity, string $priceAtPurchase): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO order_items (order_id, product_variant_id, quantity, price_at_purchase)
             VALUES (:order_id, :product_variant_id, :quantity, :price_at_purchase)'
        );
        $stmt->bindValue(':order_id', $orderId, \PDO::PARAM_INT);
        $stmt->bindValue(':product_variant_id', $variantId, \PDO::PARAM_INT);
        $stmt->bindValue(':quantity', $quantity, \PDO::PARAM_INT);
        $stmt->bindValue(':price_at_purchase', $priceAtPurchase);
        $stmt->execute();
    }

    /**
     * Decrements a variant's stock, never below zero.
     *
     * The WHERE stock_quantity >= :quantity guard makes this safe against
     * concurrent checkouts: when a race has already drained the stock, the
     * UPDATE affects 0 rows and the caller rolls the order back.
     *
     * @return bool True only when a row was actually decremented.
     */
    public function decrementStock(int $variantId, int $quantity): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE product_variants
                SET stock_quantity = stock_quantity - :quantity
              WHERE id = :id AND stock_quantity >= :min_quantity'
        );
        $stmt->bindValue(':quantity', $quantity, \PDO::PARAM_INT);
        $stmt->bindValue(':min_quantity', $quantity, \PDO::PARAM_INT);
        $stmt->bindValue(':id', $variantId, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Finds an order by primary key.
     *
     * @return Order|null Null when the id does not exist.
     */
    public function findById(int $id): ?Order
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Order::fromRow($row);
    }

    /**
     * Enriched line items for an order (variant + product + main image).
     *
     * Used by GET /api/orders/{id} and the order-confirmation page: each line
     * carries the product name/slug/image, size, color and sku alongside the
     * stored quantity and price_at_purchase.
     *
     * @return array<int, array<string, mixed>>
     */
    public function itemsForOrder(int $orderId): array
    {
        $stmt = $this->db->prepare(
            'SELECT oi.id, oi.product_variant_id, oi.quantity, oi.price_at_purchase,
                    v.size, v.color, v.sku,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug,
                    (SELECT pi.image_url
                       FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_main DESC, pi.sort_order, pi.id
                      LIMIT 1) AS image_url
               FROM order_items oi
               JOIN product_variants v ON v.id = oi.product_variant_id
               JOIN products p ON p.id = v.product_id
              WHERE oi.order_id = :order_id
              ORDER BY oi.id'
        );
        $stmt->bindValue(':order_id', $orderId, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Variants joined with their product (name, slug, base price, active
     * flag) and main image, keyed by variant id.
     *
     * The authoritative per-variant price rule lives here: variant.price when
     * set, otherwise the product's base_price — the same rule the storefront
     * detail page uses.
     *
     * @param array<int, int> $variantIds
     *
     * @return array<int, array<string, mixed>> Keyed by variant id.
     */
    public function variantsWithProducts(array $variantIds): array
    {
        if ($variantIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($variantIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT v.*,
                    p.id AS product_id, p.name AS product_name, p.slug AS product_slug,
                    p.base_price, p.is_active,
                    (SELECT pi.image_url
                       FROM product_images pi
                      WHERE pi.product_id = p.id
                      ORDER BY pi.is_main DESC, pi.sort_order, pi.id
                      LIMIT 1) AS image_url
               FROM product_variants v
               JOIN products p ON p.id = v.product_id
              WHERE v.id IN ({$placeholders})"
        );
        $stmt->execute(array_values($variantIds));

        $rows = [];
        foreach ($stmt->fetchAll() as $row) {
            $rows[(int) $row['id']] = $row;
        }

        return $rows;
    }
}