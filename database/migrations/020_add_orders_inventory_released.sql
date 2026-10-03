-- ============================================================
-- Luce Bianca — Migration 020: orders.inventory_released_at
-- ------------------------------------------------------------
-- Purpose: Durable, order-level inventory-release marker so stock
-- restoration is atomic and idempotent. Previously restoreStockForOrder()
-- incremented variant stock with no proof of prior restoration, so a failed
-- card callback, the expiry cron, an admin cancellation and a customer
-- cancellation racing on the same order could each restore the same stock.
--
-- With this marker every release path runs ONE transaction:
--   1. lock/conditionally update the order only when
--      inventory_released_at IS NULL,
--   2. mark it released (inventory_released_at = NOW()),
--   3. restore every order item's stock,
--   4. commit together.
-- A second claimant finds the marker set and returns safely without
-- incrementing again. A late successful payment callback for a released
-- (failed/cancelled/expired) card order is rejected instead of marking it
-- paid.
--
-- Upgrade: after backup, apply once (or via api/scripts/migrate.php).
-- Safe to re-run (INFORMATION_SCHEMA guard, additive NULL column only).
-- ============================================================

USE lucebianca;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'inventory_released_at');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN inventory_released_at DATETIME NULL AFTER payment_status', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
