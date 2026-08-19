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
     * Paginated, optionally status-filtered order list for the admin panel.
     *
     * The list payload stays light (scalability requirement 3.2 + phase-9
     * scope): each row is the order summary the orders table shows — no
     * order_items here, just an item_count. Full items come from
     * GET /api/orders/{id} when an admin expands a row.
     *
     * @param int         $page   1-based page number.
     * @param int         $perPage Items per page (capped by the controller).
     * @param string|null $status  Optional status filter (must already be a
     *                             valid Order::STATUSES value — the controller
     *                             validates before calling).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function paginate(int $page, int $perPage, ?string $status = null): array
    {
        $whereSql = '';
        if ($status !== null && $status !== '') {
            $whereSql = ' WHERE o.status = :status';
        }

        $countStmt = $this->db->prepare(
            "SELECT COUNT(*) AS total
               FROM orders o
              {$whereSql}"
        );
        if ($whereSql !== '') {
            $countStmt->bindValue(':status', $status);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            "SELECT o.id, o.customer_name, o.phone, o.total_amount, o.status,
                    o.payment_method, o.payment_status, o.created_at,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
               FROM orders o
              {$whereSql}
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT :limit OFFSET :offset"
        );
        if ($whereSql !== '') {
            $stmt->bindValue(':status', $status);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Updates only the provided whitelisted order columns.
     *
     * @param int   $id     Order id to update.
     * @param array $fields Subset of: status, payment_status. Caller must have
     *                      validated both against Order::STATUSES /
     *                      Order::PAYMENT_STATUSES — this method never accepts
     *                      arbitrary strings (spec 3.1 strict validation).
     */
    public function updateStatus(int $id, array $fields): void
    {
        $allowed = ['status' => true, 'payment_status' => true];

        $sets = [];
        foreach ($fields as $key => $value) {
            if (isset($allowed[$key])) {
                $sets[] = "{$key} = :{$key}";
            }
        }
        if ($sets === []) {
            return; // nothing whitelisted to change
        }

        $stmt = $this->db->prepare('UPDATE orders SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        foreach ($fields as $key => $value) {
            if (isset($allowed[$key])) {
                $stmt->bindValue(":{$key}", (string) $value);
            }
        }
        $stmt->execute();
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