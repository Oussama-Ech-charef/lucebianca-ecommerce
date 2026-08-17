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
     * Paginated, filtered list of products.
     *
     * @param int   $page        1-based page number.
     * @param int   $perPage     Items per page (capped by the controller).
     * @param array $filters     Optional: category, size, color, min_price, max_price.
     * @param bool  $activeOnly  When true (storefront) only is_active = 1;
     *                           false for the admin panel (active AND paused).
     *
     * @return array{items: array<int, Product>, total: int}
     */
    public function paginate(int $page, int $perPage, array $filters = [], bool $activeOnly = true): array
    {
        $where  = $activeOnly ? ['p.is_active = 1'] : [];
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

        $whereSql = $where === [] ? '' : ' WHERE ' . implode(' AND ', $where);
        $offset   = ($page - 1) * $perPage;

        // Total count (same filters, no limit).
        $countStmt = $this->db->prepare(
            "SELECT COUNT(DISTINCT p.id) AS total
             FROM products p
             LEFT JOIN categories c ON c.id = p.category_id
             LEFT JOIN product_variants v ON v.product_id = p.id
             {$whereSql}"
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
             {$whereSql}
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

    /**
     * Finds a product by primary key regardless of is_active (admin use).
     *
     * @return Product|null Null when the id does not exist.
     */
    public function findById(int $id): ?Product
    {
        $stmt = $this->db->prepare('SELECT * FROM products WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row === false ? null : Product::fromRow($row);
    }

    /**
     * Whether a slug is already taken by another product.
     *
     * @param string   $slug      Slug to test.
     * @param int|null $excludeId Product id to ignore (self, during update).
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM products WHERE slug = :slug'
            . ($excludeId === null ? ' LIMIT 1' : ' AND id <> :exclude LIMIT 1');
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':slug', $slug);
        if ($excludeId !== null) {
            $stmt->bindValue(':exclude', $excludeId, \PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Creates a product row. Caller must have validated/unique slugs already.
     *
     * @param array{
     *   name: string, slug: string, base_price: string, is_active: int,
     *   meta_title: string, meta_description: string,
     *   description: ?string, category_id: ?int
     * } $data Sanitized product columns.
     *
     * @return int Auto-increment id of the new record.
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO products
               (name, slug, description, base_price, category_id, is_active, meta_title, meta_description)
             VALUES
               (:name, :slug, :description, :base_price, :category_id, :is_active, :meta_title, :meta_description)'
        );
        $stmt->bindValue(':name', $data['name']);
        $stmt->bindValue(':slug', $data['slug']);
        $stmt->bindValue(':description', $data['description'] ?? null);
        $stmt->bindValue(':base_price', $data['base_price']);
        $stmt->bindValue(':category_id', $data['category_id'] ?? null);
        $stmt->bindValue(':is_active', (int) $data['is_active'], \PDO::PARAM_INT);
        $stmt->bindValue(':meta_title', $data['meta_title']);
        $stmt->bindValue(':meta_description', $data['meta_description']);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates only the provided whitelisted product columns.
     *
     * @param int   $id     Product id to update.
     * @param array $fields Subset of: name, slug, description, base_price,
     *                      category_id, is_active, meta_title, meta_description.
     */
    public function update(int $id, array $fields): void
    {
        $allowed = [
            'name'             => 'string',
            'slug'             => 'string',
            'description'      => 'string',
            'base_price'       => 'string',
            'category_id'      => 'int|null',
            'is_active'        => 'int',
            'meta_title'       => 'string',
            'meta_description' => 'string',
        ];

        $sets    = [];
        $intKeys = ['category_id', 'is_active'];
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            $sets[] = "{$key} = :{$key}";
        }
        if ($sets === []) {
            return; // nothing whitelisted to change
        }

        $stmt = $this->db->prepare('UPDATE products SET ' . implode(', ', $sets) . ' WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        foreach ($fields as $key => $value) {
            if (!isset($allowed[$key])) {
                continue;
            }
            if (in_array($key, $intKeys, true)) {
                // Nullable category_id stays NULL; is_active is always an int.
                $stmt->bindValue(
                    ":{$key}",
                    $value === null ? null : (int) $value,
                    $value === null ? \PDO::PARAM_NULL : \PDO::PARAM_INT
                );
            } else {
                $stmt->bindValue(":{$key}", (string) $value);
            }
        }
        $stmt->execute();
    }

    /**
     * Hard-deletes a product. Safe only when no variant has been ordered
     * (see ProductVariantRepository::isOrdered); the schema cascades the
     * delete to product_variants / product_images / reviews / wishlist.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM products WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }
}