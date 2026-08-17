<?php

namespace App\Repositories;

/**
 * ProductImageRepository — persistence for product_images.
 *
 * Stores ONLY the Cloudinary URL (spec section 4: `image_url` is the
 * Cloudinary URL, never the image itself / a local path). is_main drives
 * the first image shown; sort_order drives gallery order (Swiper.js).
 */
final class ProductImageRepository extends Repository
{
    /**
     * Records an uploaded image for a product.
     *
     * @param int    $productId Parent product id.
     * @param string $imageUrl  Cloudinary secure URL.
     * @param int    $isMain    1 = main image, 0 = gallery image.
     * @param int    $sortOrder Display order within the gallery.
     *
     * @return int Auto-increment id of the new record.
     */
    public function create(int $productId, string $imageUrl, int $isMain, int $sortOrder): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO product_images (product_id, image_url, is_main, sort_order)
             VALUES (:product_id, :image_url, :is_main, :sort_order)'
        );
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->bindValue(':image_url', $imageUrl);
        $stmt->bindValue(':is_main', $isMain, \PDO::PARAM_INT);
        $stmt->bindValue(':sort_order', $sortOrder, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $this->db->lastInsertId();
    }

    /**
     * Count of images already attached to a product (used to decide the
     * "no images yet → first upload is the main image" default).
     */
    public function countByProduct(int $productId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM product_images WHERE product_id = :product_id'
        );
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * A product's images ordered by display order.
     *
     * @return array<int, array<string, mixed>> Raw rows.
     */
    public function listByProduct(int $productId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM product_images WHERE product_id = :product_id ORDER BY sort_order, id'
        );
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll();
    }

    /**
     * Images for many products at once (single query, used by the storefront
     * listing so each card can show its main image without an N+1).
     *
     * @param array<int, int> $productIds
     *
     * @return array<int, array<string, mixed>> Raw rows ordered by sort_order.
     */
    public function allForProducts(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($productIds), '?'));
        $stmt = $this->db->prepare(
            "SELECT * FROM product_images
             WHERE product_id IN ({$placeholders})
             ORDER BY sort_order, id"
        );
        $stmt->execute(array_values($productIds));

        return $stmt->fetchAll();
    }

    /**
     * Clears the is_main flag on all of a product's images, so a newly
     * uploaded image can take over as the single main image.
     */
    public function clearMain(int $productId): void
    {
        $stmt = $this->db->prepare(
            'UPDATE product_images SET is_main = 0 WHERE product_id = :product_id'
        );
        $stmt->bindValue(':product_id', $productId, \PDO::PARAM_INT);
        $stmt->execute();
    }
}