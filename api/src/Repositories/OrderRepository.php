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
     * payment_status ALWAYS 'pending' at creation).
     *
     * Business rule (2026-10-03): COD and WhatsApp orders are NOT auto-paid.
     * payment_status starts 'pending' for every method and becomes 'paid'
     * only via (a) card callback success, or (b) an explicit admin
     * confirmation (PUT /api/admin/orders/{id} {payment_status:'paid'}).
     * This is enforced consistently in UI (Pending confirmation), admin
     * (Pending badge), dashboard realized-order rule, and docs.
     *
     * @param array{
     *   total_amount: string, subtotal_amount?: string, shipping_cost?: string,
     *   coupon_id?: int|null, coupon_code?: string|null, discount_amount?: string,
     *   shipping_address: string, city: string, postal_code: string,
     *   customer_name: string, phone: string, email: string, payment_method: string
     * } $data Order columns.
     * @param int|null $userId Optional authenticated customer id — NULL for
     *                         guest checkout (orders.user_id is nullable).
     *
     * @return int Auto-increment id of the new record.
     */
    /**
     * Generates a non-guessable, single-purpose lookup token for guest
     * order confirmation. Caller must return it to the client once (store
     * response) and thereafter require it for any guest read.
     */
    public static function generateLookupToken(): string
    {
        return bin2hex(random_bytes(32)); // 64 hex chars, 256-bit
    }

    public function insertOrder(array $data, ?int $userId = null): int
    {
        // Prefer caller-supplied token (service generates once), else create.
        $lookupToken = $data['lookup_token'] ?? self::generateLookupToken();

        $stmt = $this->db->prepare(
            'INSERT INTO orders
               (user_id, status, total_amount, subtotal_amount, shipping_cost, coupon_id, coupon_code, discount_amount, shipping_address, city, postal_code, customer_name, phone, email, lookup_token, payment_method, payment_status)
             VALUES
               (:user_id, \'pending\', :total_amount, :subtotal_amount, :shipping_cost, :coupon_id, :coupon_code, :discount_amount, :shipping_address, :city, :postal_code, :customer_name, :phone, :email, :lookup_token, :payment_method, \'pending\')'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':total_amount', $data['total_amount']);
        $stmt->bindValue(':subtotal_amount', $data['subtotal_amount'] ?? $data['total_amount']);
        $stmt->bindValue(':shipping_cost', $data['shipping_cost'] ?? '0.00');
        $stmt->bindValue(
            ':coupon_id',
            $data['coupon_id'] ?? null,
            isset($data['coupon_id']) ? \PDO::PARAM_INT : \PDO::PARAM_NULL
        );
        $stmt->bindValue(
            ':coupon_code',
            $data['coupon_code'] ?? null,
            isset($data['coupon_code']) ? \PDO::PARAM_STR : \PDO::PARAM_NULL
        );
        $stmt->bindValue(':discount_amount', $data['discount_amount'] ?? '0.00');
        $stmt->bindValue(':shipping_address', $data['shipping_address']);
        $stmt->bindValue(':city', $data['city'] ?? '');
        $stmt->bindValue(':postal_code', $data['postal_code'] ?? '');
        $stmt->bindValue(':customer_name', $data['customer_name']);
        $stmt->bindValue(':phone', $data['phone']);
        $stmt->bindValue(':email', $data['email']);
        $stmt->bindValue(':lookup_token', $lookupToken);
        $stmt->bindValue(':payment_method', $data['payment_method']);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Checks whether the supplied token matches the order's stored
     * lookup_token (guest) or whether the authenticated user owns the
     * order. Returns the Order on success, null on 404 (to hide
     * existence on failure).
     */
    public function findAuthorized(int $orderId, ?int $authenticatedUserId, ?string $providedToken): ?Order
    {
        $order = $this->findById($orderId);
        if ($order === null) {
            return null;
        }

        // Owned by a registered user → require ownership.
        if ($order->userId !== null) {
            if ($authenticatedUserId !== null && $authenticatedUserId === $order->userId) {
                return $order;
            }
            // Allow guest token as fallback if the owner still has the link
            if ($providedToken !== null && $providedToken !== '' && $order->lookupToken !== null && hash_equals($order->lookupToken, $providedToken)) {
                return $order;
            }
            return null;
        }

        // Guest order (user_id NULL) → require exact token.
        if ($providedToken === null || $providedToken === '' || $order->lookupToken === null) {
            return null;
        }

        return hash_equals($order->lookupToken, $providedToken) ? $order : null;
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
     * Restores stock for a variant (inventory policy: failed, cancelled and
     * expired card-payment orders must not permanently lose stock).
     * Unbounded increment — callers must only restore quantities previously
     * decremented for the same order (see OrderService::restoreStockForOrder).
     */
    public function incrementStock(int $variantId, int $quantity): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_variants
                SET stock_quantity = stock_quantity + :quantity
              WHERE id = :id'
        );
        $stmt->bindValue(':quantity', $quantity, \PDO::PARAM_INT);
        $stmt->bindValue(':id', $variantId, \PDO::PARAM_INT);
        $stmt->execute();
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
            "SELECT o.id, o.customer_name, o.phone, o.total_amount, o.subtotal_amount, o.shipping_cost,
                    o.coupon_code, o.discount_amount, o.status,
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
     * Paginated order list scoped to one customer (the /account page).
     *
     * Same light payload as paginate() (summary fields + item_count, no line
     * items) but always filtered by orders.user_id, so a customer can only
     * ever see their own orders.
     *
     * @param int $userId  Owner account id.
     * @param int $page    1-based page number.
     * @param int $perPage Items per page (capped by the controller).
     *
     * @return array{items: array<int, array<string, mixed>>, total: int}
     */
    public function paginateForUser(int $userId, int $page, int $perPage): array
    {
        $countStmt = $this->db->prepare(
            'SELECT COUNT(*) AS total FROM orders o WHERE o.user_id = :user_id'
        );
        $countStmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        $offset = ($page - 1) * $perPage;
        $stmt   = $this->db->prepare(
            'SELECT o.id, o.customer_name, o.phone, o.total_amount, o.subtotal_amount, o.shipping_cost,
                    o.coupon_code, o.discount_amount, o.status,
                    o.payment_method, o.payment_status, o.created_at,
                    (SELECT COUNT(*) FROM order_items oi WHERE oi.order_id = o.id) AS item_count
               FROM orders o
              WHERE o.user_id = :user_id
              ORDER BY o.created_at DESC, o.id DESC
              LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':user_id', $userId, \PDO::PARAM_INT);
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    /**
     * Finds an order by primary key, locking the row for the current
     * transaction (SELECT ... FOR UPDATE).
     *
     * The caller MUST already be inside a transaction on the shared
     * connection; the lock serializes concurrent release attempts
     * (failed callback vs. expiry cron vs. cancellations) on one order.
     *
     * @return Order|null Null when the id does not exist.
     */
    public function findByIdForUpdate(int $id): ?Order
    {
        $stmt = $this->db->prepare('SELECT * FROM orders WHERE id = :id LIMIT 1 FOR UPDATE');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Order::fromRow($row);
    }

    /**
     * Atomically updates whitelisted order columns AND marks inventory
     * released — but only when the order has not released inventory yet.
     *
     * This single conditional UPDATE is the idempotency backstop: even if
     * two requests both pass the SELECT ... FOR UPDATE check (e.g. lock
     * ordering across connections), only the first one claims the release.
     *
     * @param int   $id     Order id.
     * @param array $fields Subset of: status, payment_status (validated by
     *                      the caller against Order::STATUSES /
     *                      Order::PAYMENT_STATUSES).
     *
     * @return bool True when this call claimed the release; false when
     *              another request already released (caller must NOT restore
     *              stock again).
     */
    public function updateStatusAndRelease(int $id, array $fields): bool
    {
        $allowed = ['status' => true, 'payment_status' => true];

        $sets = ['inventory_released_at = NOW()'];
        $bindings = [];
        foreach ($fields as $key => $value) {
            if (isset($allowed[$key])) {
                $sets[] = "{$key} = :{$key}";
                $bindings[":{$key}"] = (string) $value;
            }
        }

        $stmt = $this->db->prepare(
            'UPDATE orders SET ' . implode(', ', $sets)
            . ' WHERE id = :id AND inventory_released_at IS NULL'
        );
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        foreach ($bindings as $param => $value) {
            $stmt->bindValue($param, $value);
        }
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Atomically marks inventory released without changing status columns
     * (used when the status transition was already applied and only the
     * stock restoration remains, e.g. legacy rows).
     *
     * @return bool True when this call claimed the release.
     */
    public function claimInventoryRelease(int $id): bool
    {
        $stmt = $this->db->prepare(
            'UPDATE orders SET inventory_released_at = NOW()
              WHERE id = :id AND inventory_released_at IS NULL'
        );
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
    }

    /**
     * Atomically clears the release marker to re-reserve stock (card-payment
     * retry after a failure released the reservation) — only when the order
     * is still in a reservable state. Pair with guarded stock decrements in
     * the same transaction (see OrderService).
     *
     * @return bool True when the marker was cleared (reservation re-opened).
     */
    public function clearInventoryRelease(int $id): bool
    {
        $stmt = $this->db->prepare(
            "UPDATE orders SET inventory_released_at = NULL
              WHERE id = :id AND inventory_released_at IS NOT NULL
                AND status = 'pending' AND payment_status = 'failed'"
        );
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->rowCount() > 0;
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
     * Variants joined with their product (name, slug, base price, sale_price,
     * active flag) and main image, keyed by variant id.
     *
     * The authoritative per-variant price rule lives here: variant.price when
     * set, otherwise the product's sale_price (if valid), otherwise the product's
     * base_price — the same rule the storefront detail page uses.
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
                    p.base_price, p.sale_price, p.is_active,
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

    /**
     * Get realized sales and order count overview.
     *
     * @return array{total_sales: string, total_orders: int}
     */
    public function getSalesOverview(): array
    {
        $stmt = $this->db->prepare("
            SELECT COALESCE(SUM(total_amount), 0) as total_sales, COUNT(*) as total_orders
            FROM orders
            WHERE status != 'cancelled'
              AND (
                payment_status = 'paid'
                OR (payment_method IN ('cod', 'whatsapp') AND status IN ('processing', 'shipped', 'delivered'))
              )
        ");
        $stmt->execute();
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        return [
            'total_sales' => $row['total_sales'] ?? '0.00',
            'total_orders' => (int) ($row['total_orders'] ?? 0),
        ];
    }

    /**
     * Get order count grouped by status.
     *
     * @return array<string, int>
     */
    public function getStatusBreakdown(): array
    {
        $stmt = $this->db->prepare("
            SELECT status, COUNT(*) as count
            FROM orders
            GROUP BY status
        ");
        $stmt->execute();

        $breakdown = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $breakdown[$row['status']] = (int) $row['count'];
        }

        return $breakdown;
    }

    /**
     * Get top-selling products by units sold from realized orders.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getTopSellingProducts(int $limit): array
    {
        $stmt = $this->db->prepare("
            SELECT
                p.id,
                p.name,
                p.slug,
                SUM(oi.quantity) as units_sold,
                SUM(oi.quantity * oi.price_at_purchase) as revenue
            FROM order_items oi
            INNER JOIN product_variants pv ON oi.product_variant_id = pv.id
            INNER JOIN products p ON pv.product_id = p.id
            INNER JOIN orders o ON oi.order_id = o.id
            WHERE o.status != 'cancelled'
              AND (
                o.payment_status = 'paid'
                OR (o.payment_method IN ('cod', 'whatsapp') AND o.status IN ('processing', 'shipped', 'delivered'))
              )
            GROUP BY p.id, p.name, p.slug
            ORDER BY units_sold DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $products = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $products[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'units_sold' => (int) $row['units_sold'],
                'revenue' => $row['revenue'],
            ];
        }

        return $products;
    }

    /**
     * Get recent orders for admin overview.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getRecent(int $limit): array
    {
        $stmt = $this->db->prepare("
            SELECT
                id,
                customer_name,
                total_amount,
                status,
                payment_status,
                payment_method,
                created_at
            FROM orders
            ORDER BY created_at DESC
            LIMIT :limit
        ");
        $stmt->bindValue(':limit', $limit, \PDO::PARAM_INT);
        $stmt->execute();

        $orders = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $orders[] = [
                'id' => (int) $row['id'],
                'customer_name' => $row['customer_name'],
                'total_amount' => $row['total_amount'],
                'status' => $row['status'],
                'payment_status' => $row['payment_status'],
                'payment_method' => $row['payment_method'],
                'created_at' => $row['created_at'],
            ];
        }

        return $orders;
    }

    /**
     * Get sales chart aggregated data for the last N days (realized orders).
     *
     * @return array<int, array<string, mixed>>
     */
    public function getSalesChartData(int $days): array
    {
        $stmt = $this->db->prepare("
            SELECT
                DATE(created_at) as date,
                COUNT(*) as order_count,
                SUM(total_amount) as daily_sales
            FROM orders
            WHERE status != 'cancelled'
              AND (
                payment_status = 'paid'
                OR (payment_method IN ('cod', 'whatsapp') AND status IN ('processing', 'shipped', 'delivered'))
              )
              AND created_at >= DATE_SUB(CURDATE(), INTERVAL :days DAY)
            GROUP BY DATE(created_at)
            ORDER BY date ASC
        ");
        $stmt->bindValue(':days', $days, \PDO::PARAM_INT);
        $stmt->execute();

        $chartData = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $chartData[] = [
                'date' => $row['date'],
                'order_count' => (int) $row['order_count'],
                'sales' => $row['daily_sales'],
            ];
        }

        return $chartData;
    }
}