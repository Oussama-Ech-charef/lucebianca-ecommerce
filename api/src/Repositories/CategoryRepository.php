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
     * Returns one category or null.
     */
    public function findById(int $id): ?Category
    {
        $stmt = $this->db->prepare('SELECT * FROM categories WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();

        $row = $stmt->fetch();

        return $row !== false ? Category::fromRow($row) : null;
    }

    /**
     * Whether a category slug is already taken.
     *
     * @param string   $slug      Candidate slug.
     * @param int|null $excludeId Category id to ignore (self, during update).
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = 'SELECT 1 FROM categories WHERE slug = :slug'
            . ($excludeId === null ? ' LIMIT 1' : ' AND id <> :exclude LIMIT 1');
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':slug', $slug, \PDO::PARAM_STR);
        if ($excludeId !== null) {
            $stmt->bindValue(':exclude', $excludeId, \PDO::PARAM_INT);
        }
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Creates a category and returns its id.
     */
    public function create(string $name, string $slug): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO categories (name, slug) VALUES (:name, :slug)'
        );
        $stmt->bindValue(':name', $name, \PDO::PARAM_STR);
        $stmt->bindValue(':slug', $slug, \PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates the provided subset of fields ('name' and/or 'slug').
     *
     * @param array<string, string> $fields
     */
    public function update(int $id, array $fields): void
    {
        $allowed = ['name', 'slug'];
        $sets = [];
        $params = [':id' => $id];

        foreach ($fields as $column => $value) {
            if (!in_array($column, $allowed, true)) {
                continue;
            }
            $placeholder = ':' . $column;
            $sets[] = "{$column} = {$placeholder}";
            $params[$placeholder] = $value;
        }

        if ($sets === []) {
            return;
        }

        $sql = 'UPDATE categories SET ' . implode(', ', $sets) . ' WHERE id = :id';
        $stmt = $this->db->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value, \PDO::PARAM_STR);
        }
        $stmt->execute();
    }

    /**
     * Deletes a category. Products keep existing with category_id NULL
     * (ON DELETE SET NULL in the schema).
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM categories WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
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