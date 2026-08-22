-- Migration: Add reviews table
-- Date: 2026-08-22
-- Purpose: Customer product reviews with admin approval

CREATE TABLE IF NOT EXISTS reviews (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  rating      TINYINT UNSIGNED NOT NULL,
  comment     TEXT         NULL,
  is_approved TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reviews_product (product_id),
  KEY idx_reviews_approved (is_approved),
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id)
    REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
