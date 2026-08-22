<?php

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * ReviewRepository — review data access.
 */
final class ReviewRepository extends Repository
{
    /**
     * Create a review.
     */
    public function create(int $productId, ?int $userId, int $rating, ?string $comment): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO reviews (product_id, user_id, rating, comment)
            VALUES (:product_id, :user_id, :rating, :comment)
        ");
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id', $userId, $userId === null ? PDO::PARAM_NULL : PDO::PARAM_INT);
        $stmt->bindValue(':rating', $rating, PDO::PARAM_INT);
        $stmt->bindValue(':comment', $comment, $comment === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Get approved reviews for a product.
     */
    public function getApprovedByProduct(int $productId): array
    {
        $stmt = $this->db->prepare("
            SELECT r.id, r.rating, r.comment, r.created_at,
                   u.name as user_name
            FROM reviews r
            LEFT JOIN users u ON r.user_id = u.id
            WHERE r.product_id = :product_id AND r.is_approved = 1
            ORDER BY r.created_at DESC
        ");
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get average rating for a product.
     */
    public function getAverageRating(int $productId): ?float
    {
        $stmt = $this->db->prepare("
            SELECT AVG(rating) as avg_rating
            FROM reviews
            WHERE product_id = :product_id AND is_approved = 1
        ");
        $stmt->bindValue(':product_id', $productId, PDO::PARAM_INT);
        $stmt->execute();

        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        return $result['avg_rating'] !== null ? (float) $result['avg_rating'] : null;
    }

    /**
     * Get all reviews with pagination (admin).
     */
    public function paginate(int $page, int $perPage, ?int $approvalStatus = null): array
    {
        $offset = ($page - 1) * $perPage;

        $whereClause = '';
        if ($approvalStatus !== null) {
            $whereClause = 'WHERE r.is_approved = :is_approved';
        }

        // Count total
        $countSql = "SELECT COUNT(*) FROM reviews r $whereClause";
        $stmt = $this->db->prepare($countSql);
        if ($approvalStatus !== null) {
            $stmt->bindValue(':is_approved', $approvalStatus, PDO::PARAM_INT);
        }
        $stmt->execute();
        $total = (int) $stmt->fetchColumn();

        // Fetch paginated
        $sql = "
            SELECT r.id, r.product_id, r.user_id, r.rating, r.comment,
                   r.is_approved, r.created_at,
                   p.name as product_name, p.slug as product_slug,
                   u.name as user_name
            FROM reviews r
            INNER JOIN products p ON r.product_id = p.id
            LEFT JOIN users u ON r.user_id = u.id
            $whereClause
            ORDER BY r.created_at DESC
            LIMIT :limit OFFSET :offset
        ";
        $stmt = $this->db->prepare($sql);
        if ($approvalStatus !== null) {
            $stmt->bindValue(':is_approved', $approvalStatus, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
        ];
    }

    /**
     * Update review approval status.
     */
    public function updateApproval(int $id, int $isApproved): void
    {
        $stmt = $this->db->prepare("
            UPDATE reviews
            SET is_approved = :is_approved
            WHERE id = :id
        ");
        $stmt->bindValue(':is_approved', $isApproved, PDO::PARAM_INT);
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }

    /**
     * Delete a review.
     */
    public function delete(int $id): void
    {
        $stmt = $this->db->prepare("DELETE FROM reviews WHERE id = :id");
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        $stmt->execute();
    }
}
