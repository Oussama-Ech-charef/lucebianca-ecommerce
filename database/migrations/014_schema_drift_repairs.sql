-- ============================================================
-- Luce Bianca — Migration 014: schema drift repairs (UNTESTED)
-- ------------------------------------------------------------
-- Purpose: Repair drift identified in the 2026-09 schema audit
-- without editing frozen history (001-013). Safe to re-run:
-- every change is guarded by INFORMATION_SCHEMA checks and
-- executed via PREPARE/EXECUTE so it works on both MySQL 8
-- and MariaDB 10.4+ (InnoDB, utf8mb4). No destructive drops.
--
-- Assumed DB: MySQL 8.0.x or MariaDB 10.4+ (utf8mb4, InnoDB).
-- This file is UNTESTED — SQL was not executed (hard rule: no
-- DB connection). Syntax was validated with `php -l` only where
-- applicable and by static comparison of table/column lists.
--
-- Scope per approval 2026-09:
-- - Keep orders.email as 009 truth: VARCHAR(255) NULL, no narrowing
--   to NOT NULL or shrinking to 150. Only ensure existence.
-- - Drop wishlist idx_wishlist_user (redundant with UNIQUE composite).
-- - Keep reviews.idx_reviews_approved as MINOR/optional — add only
--   if missing, do not fail if present.
-- - No TIMESTAMP -> DATETIME conversions.
-- - No ADD COLUMN/KEY IF NOT EXISTS (MariaDB-only); use
--   INFORMATION_SCHEMA + PREPARE/EXECUTE.
-- ------------------------------------------------------------
-- Fresh installs: use ../schema.sql only (now contains the corrected
-- snapshot for 18 tables). Do NOT replay 001-013 on an empty DB
-- (see migrations/README.md — 010 duplicate, 013 IF NOT EXISTS
-- portability).
-- Upgrades: after a mysqldump backup, apply only not-yet-applied
-- migrations in order (see README checklist).
-- ============================================================

USE lucebianca;

-- ------------------------------------------------------------
-- 1) refresh_tokens (002) — create if missing
-- ------------------------------------------------------------
SET @tbl_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'refresh_tokens');
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE refresh_tokens (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at DATETIME NULL, UNIQUE KEY uq_refresh_tokens_hash (token_hash), KEY idx_refresh_tokens_user (user_id), CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE) ENGINE=InnoDB',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2) admin_refresh_tokens (003) — create if missing
-- ------------------------------------------------------------
SET @tbl_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'admin_refresh_tokens');
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE admin_refresh_tokens (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, admin_id INT UNSIGNED NOT NULL, token_hash CHAR(64) NOT NULL, expires_at DATETIME NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, revoked_at DATETIME NULL, UNIQUE KEY uq_admin_refresh_tokens_hash (token_hash), KEY idx_admin_refresh_tokens_admin (admin_id), CONSTRAINT fk_admin_refresh_tokens_admin FOREIGN KEY (admin_id) REFERENCES admins(id) ON DELETE CASCADE) ENGINE=InnoDB',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 3) users — ensure 004/005 columns exist (separate checks, additive only)
-- ------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'google_id');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL AFTER email', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_google_id');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE users ADD UNIQUE KEY uq_users_google_id (google_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verification_token');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN email_verification_token VARCHAR(255) NULL AFTER google_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_users_email_verification_token');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE users ADD UNIQUE KEY uq_users_email_verification_token (email_verification_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verification_expires_at');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN email_verification_expires_at DATETIME NULL AFTER email_verification_token', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 4) orders.email — ensure 009 column exists as VARCHAR(255) NULL (no narrowing)
-- ------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'email');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN email VARCHAR(255) NULL AFTER phone', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- If column exists as 150 NOT NULL (old snapshot), we deliberately do NOT modify — per approval no shrinking/MODIFY.

-- ------------------------------------------------------------
-- 5) orders.city / postal_code — ensure 013 columns exist (no IF NOT EXISTS, MariaDB-safe)
-- ------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'city');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '''' AFTER shipping_address', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'postal_code');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN postal_code VARCHAR(20) NOT NULL DEFAULT '''' AFTER city', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 6) newsletter_subscribers — ensure lifecycle columns exist (008/010), no type conversion
-- ------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' AND COLUMN_NAME = 'is_active');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE newsletter_subscribers ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' AND COLUMN_NAME = 'unsubscribed_at');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE newsletter_subscribers ADD COLUMN unsubscribed_at DATETIME NULL AFTER subscribed_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- Note: if unsubscribed_at already exists as TIMESTAMP (008), we keep it — no TIMESTAMP->DATETIME conversion per approval.

-- ------------------------------------------------------------
-- 7) products.sale_price — ensure 011 column exists
-- ------------------------------------------------------------
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'products' AND COLUMN_NAME = 'sale_price');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE products ADD COLUMN sale_price DECIMAL(10,2) NULL AFTER base_price', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 8) product_sizes / product_colors — create if missing (012), seed distinct values
-- ------------------------------------------------------------
SET @tbl_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_sizes');
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE product_sizes (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_product_sizes_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @tbl_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'product_colors');
SET @sql := IF(@tbl_exists = 0,
  'CREATE TABLE product_colors (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, name VARCHAR(50) NOT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, UNIQUE KEY uq_product_colors_name (name)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci',
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Seed distinct variant values if tables were just created (ignore duplicates)
INSERT IGNORE INTO product_sizes (name) SELECT DISTINCT TRIM(size) FROM product_variants WHERE TRIM(size) != '';
INSERT IGNORE INTO product_colors (name) SELECT DISTINCT TRIM(color) FROM product_variants WHERE TRIM(color) != '';

-- ------------------------------------------------------------
-- 9) reviews.idx_reviews_approved — optional (MINOR), add if missing
-- ------------------------------------------------------------
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reviews' AND INDEX_NAME = 'idx_reviews_approved');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE reviews ADD KEY idx_reviews_approved (is_approved)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Wishlist idx_wishlist_user is intentionally NOT added — redundant with UNIQUE composite per approval.
