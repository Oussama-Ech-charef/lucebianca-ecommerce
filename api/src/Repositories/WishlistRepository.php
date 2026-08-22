<?php

namespace App\Repositories;

use PDO;

/**
 * WishlistRepository — customer wishlist data access.
 */
final class WishlistRepository extends Repository
{
    /**
     * Add product to wishlist.
     *
     * @return bool True if added, false if already exists
     */
    public function add(int $userId, int $productId): bool
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO wishlist (user_id, product_id)
                VALUES (:user_id, :product_id)
            ");
            $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
            $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
            $stmt->execute();
            return true;
        } catch (\PDOException $e) {
            // Duplicate entry - already in wishlist
            if ($e->getCode() === '23000') {
                return false;
            }
            throw $e;
        }
    }

    /**
     * Remove product from wishlist.
     */
    public function remove(int $userId, int $productId): void
    {
        $stmt = $this->db->prepare("
            DELETE FROM wishlist
            WHERE user_id = :user_id AND product_id = :product_id
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Get user's wishlist with product details.
     */
    public function getByUser(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT w.id, w.product_id, w.created_at,
                   p.name, p.slug, p.base_price, p.is_active
            FROM wishlist w
            INNER JOIN products p ON w.product_id = p.id
            WHERE w.user_id = :user_id
            ORDER BY w.created_at DESC
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Check if product is in user's wishlist.
     */
    public function exists(int $userId, int $productId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM wishlist
            WHERE user_id = :user_id AND product_id = :product_id
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * Get wishlist product IDs for user.
     *
     * @return int[]
     */
    public function getProductIds(int $userId): array
    {
        $stmt = $this->db->prepare("
            SELECT product_id FROM wishlist WHERE user_id = :user_id
        ");
        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->execute();

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }
}
