<?php

namespace App\Repositories;

use App\Models\Product;

/**
 * ProductRepository — all database access for products.
 *
 * Every query uses a PDO prepared statement with bound values. Pagination
 * is mandatory for list endpoints (scalability requirement 3.2); the
 * storefront endpoint filters on is_active = 1 only.
 */
final class ProductRepository extends Repository
{
    /**
     * Paginated, filtered list of ACTIVE products.
     *
     * @param int   $page    1-based page number.
     * @param int   $perPage Items per page (capped by the controller).
     * @param array $filters Optional: category, size, color, min_price, max_price.
     *
     * @return array{items: array<int, Product>, total: int}
     */
    public function paginate(int $page, int $perPage, array $filters = []): array
    {
        $where  = ['p.is_active = 1'];
        $params = [];

        if (!empty($filters['category'])) {
            $where[]            = 'c.slug = :category';
            $params[':category'] = $filters['category'];
        }

        if (!empty($filters['size'])) {
            $where[]         = 'v.size = :size';
            $params[':size'] = $filters['size'];
        }

        if (!empty($filters['color'])) {
            $where[]          = 'v.color = :color';
            $params[':color'] = $filters['color'];
        }

        if (isset($filters['min_price']) && $filters['min_price'] !== '') {
            $where[]              = 'p.base_price >= :min_price';
            $params[':min_price'] = $filters['min_price'];
        }

        if (isset($filters['max_price']) && $filters['max_price'] !== '') {
            $where[]              = 'p.base_price <= :max_price';
            $params[':max_price'] = $filters['max_price'];
        }

        $whereSql = implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        // Total count (same filters, no limit).
        $countStmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT p.id) AS total
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN product_variants v ON v.product_id = p.id
             WHERE {$whereSql}"
        );
        foreach ($params as $key => $value) {
            $countStmt->bindValue($key, $value);
        }
        $countStmt->execute();
        $total = (int) $countStmt->fetchColumn();

        // Distinct products, one row per product (deduped across variants).
        $stmt = $this->db->prepare(
            "SELECT p.*
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN product_variants v ON v.product_id = p.id
             WHERE {$whereSql}
             GROUP BY p.id
             ORDER BY p.created_at DESC
             LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', $perPage, \PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, \PDO::PARAM_INT);
        $stmt->execute();

        $items = array_map(
            static fn (array $row): Product => Product::fromRow($row),
            $stmt->fetchAll()
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Finds a single ACTIVE product by its slug (used by /product/[slug]).
     *
     * @return Product|null Null when not found or the product is paused.
     */
    public function findActiveBySlug(string $slug): ?Product
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM products WHERE slug = :slug AND is_active = 1 LIMIT 1'
        );
        $stmt->bindValue(':slug', $slug);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Product::fromRow($row);
    }
}