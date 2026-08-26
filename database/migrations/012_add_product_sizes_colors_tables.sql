-- Migration: Centralized catalog for sizes and colors
-- Date: 2026-08-24
-- Purpose: Create product_sizes and product_colors catalog tables with
--          unique normalized names, and seed distinct existing variant
--          values so no current option disappears.

CREATE TABLE product_sizes (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_sizes_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE product_colors (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(50) NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_product_colors_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed distinct existing variant values (ignore duplicates / empty)
INSERT IGNORE INTO product_sizes (name)
SELECT DISTINCT TRIM(size) FROM product_variants WHERE TRIM(size) != '';

INSERT IGNORE INTO product_colors (name)
SELECT DISTINCT TRIM(color) FROM product_variants WHERE TRIM(color) != '';
