<?php

namespace App\Repositories;

/**
 * ProductColorRepository — catalog for variant colors.
 */
final class ProductColorRepository extends Repository
{
    /**
     * @return array<int, array{id:int, name:string, created_at:string}>
     */
    public function all(): array
    {
        $stmt = $this->db->query('SELECT * FROM product_colors ORDER BY name ASC');
        return $stmt->fetchAll();
    }

    public function findById(int $id): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM product_colors WHERE id = :id LIMIT 1');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function findByName(string $name): ?array
    {
        $stmt = $this->db->prepare('SELECT * FROM product_colors WHERE name = :name LIMIT 1');
        $stmt->bindValue(':name', $name);
        $stmt->execute();
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    public function create(string $name): int
    {
        $stmt = $this->db->prepare('INSERT INTO product_colors (name) VALUES (:name)');
        $stmt->bindValue(':name', $name);
        $stmt->execute();
        return (int) $this->db->lastInsertId();
    }

    public function delete(int $id): void
    {
        $stmt = $this->db->prepare('DELETE FROM product_colors WHERE id = :id');
        $stmt->bindValue(':id', $id, \PDO::PARAM_INT);
        $stmt->execute();
    }

    public function isUsed(string $name): bool
    {
        $stmt = $this->db->prepare('SELECT 1 FROM product_variants WHERE color = :name LIMIT 1');
        $stmt->bindValue(':name', $name);
        $stmt->execute();
        return $stmt->fetchColumn() !== false;
    }
}
