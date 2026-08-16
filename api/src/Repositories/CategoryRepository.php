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
}