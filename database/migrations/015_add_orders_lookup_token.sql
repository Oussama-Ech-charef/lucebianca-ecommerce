-- ============================================================
-- Luce Bianca — Migration 015: orders.lookup_token (UNTESTED)
-- ------------------------------------------------------------
-- Purpose: Privacy for guest checkout. GET /api/orders/{id} is
-- currently public and enumerable — sequential ids leak
-- customer PII (name, phone, email, address, items, amount).
-- This adds a non-guessable, single-purpose lookup token.
--
-- Assumed DB: MySQL 8.0.x or MariaDB 10.4+ (InnoDB, utf8mb4).
-- This file is UNTESTED — SQL was not executed (hard rule: no
-- DB connection). Validated by static comparison and `php -l`
-- on PHP files only.
--
-- Design:
-- - lookup_token VARCHAR(64) NOT NULL after email, UNIQUE.
-- - Generated in PHP as bin2hex(random_bytes(32)) (64 hex chars,
--   256-bit entropy). Stored plain with UNIQUE to enforce
--   single-purpose capability. Not hashed — guest needs the raw
--   value for confirmation link; hash would require extra lookup.
-- - Backfill for existing rows uses portable UUID fallback:
--   LOWER(CONCAT(REPLACE(UUID(),'-',''),REPLACE(UUID(),'-','')))
--   (64 hex chars, available on both MySQL/MariaDB).
--
-- Upgrade paths:
-- - Fresh install: snapshot (schema.sql) already contains the column.
-- - Existing DB: after backup, run this file alone. It is safe to
--   re-run — every step is guarded by INFORMATION_SCHEMA + PREPARE.
-- ============================================================

USE lucebianca;

-- 1) Add column if missing (NULL first for backfill, then tighten)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'lookup_token');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN lookup_token VARCHAR(64) NULL AFTER email', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) Backfill NULL/empty tokens for existing orders (portable, 64 hex)
-- Only fills rows where token is NULL or empty string.
UPDATE orders SET lookup_token = LOWER(CONCAT(REPLACE(UUID(), '-', ''), REPLACE(UUID(), '-', ''))) WHERE lookup_token IS NULL OR lookup_token = '';

-- 3) Ensure no NULL remains after backfill (if backfill succeeded, next alters are safe)
-- Add UNIQUE if missing (check STATISTICS, not COLUMNS)
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'uq_orders_lookup_token');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE orders ADD UNIQUE KEY uq_orders_lookup_token (lookup_token)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Enforce NOT NULL after backfill (additive, no data loss)
-- Only modifies if column is currently nullable.
SET @is_nullable := (SELECT IS_NULLABLE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'lookup_token');
SET @sql := IF(@is_nullable = 'YES', 'ALTER TABLE orders MODIFY COLUMN lookup_token VARCHAR(64) NOT NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Note: existing orders created before this migration will have a newly
-- generated token unknown to the guest. Those guests must contact support
-- or, if logged-in, use GET /api/account/orders. New orders return the
-- token at creation time (201) and the frontend stores it for confirmation.
