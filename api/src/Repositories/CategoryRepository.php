<?php

namespace App\Repositories;

use App\Models\Category;

/**
 * CategoryRepository — database access for product categories.
 */
final class CategoryRepository extends Repository
{
    /**
     * Returns all categories ordered by name.
     *
     * @return array<int, Category>
     */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM categories ORDER BY name ASC');

        return array_map(
            static fn (array $row): Category => Category::fromRow($row),
            $stmt->fetchAll()
        );
    }

    /**
     * Whether a category id exists (used to validate category_id on admin
     * product create/update before the FK constraint can reject it).
     */
    public function exists(int $id): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM categories WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }
}