-- ============================================================
-- Luce Bianca — Migration 016: orders shipping breakdown
-- ------------------------------------------------------------
-- Purpose: Persist the authoritative shipping calculation so checkout,
-- order total, confirmation page, WhatsApp message, admin detail and
-- email all show the same final total (subtotal + shipping).
--
-- Policy (see docs/decisions/2026-10-03-shipping-and-inventory-policy.md):
-- - subtotal = sum(authoritative line prices), shipping_cost computed
--   server-side from settings.shipping_cost / free_shipping_threshold.
-- - If free_shipping_threshold > 0 and subtotal >= threshold → 0 shipping.
--   Otherwise shipping_cost setting (default 0 when unconfigured).
-- - total_amount = subtotal + shipping (stored, never recomputed client-side).
--
-- Upgrade: after backup, apply once. Safe to re-run (INFORMATION_SCHEMA
-- guards, portable to MySQL 8 / MariaDB 10.4).
-- ============================================================

USE lucebianca;

-- 1) subtotal_amount (line total before shipping)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'subtotal_amount');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN subtotal_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER total_amount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) shipping_cost (authoritative shipping charged for the order)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'shipping_cost');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN shipping_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER subtotal_amount', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) Backfill existing rows: subtotal = total (no shipping was charged
-- historically), shipping = 0. Only touches rows still at defaults.
UPDATE orders SET subtotal_amount = total_amount WHERE subtotal_amount = 0.00;
