-- ============================================================
-- Luce Bianca — Migration 021: order coupon snapshots
-- ------------------------------------------------------------
-- Purpose: persist coupon usage on an order without making historical
-- orders depend on a mutable/deletable coupon row.
--
-- The coupons table (from 001) is the source of truth for validation:
--   code, discount_type (percentage|fixed), discount_value,
--   expires_at, is_active.
-- It intentionally has no usage limits or minimum-spend columns, so none
-- are invented here.
--
-- orders gets:
--   coupon_id      → nullable link to the coupon used (SET NULL on delete)
--   coupon_code    → immutable snapshot of the code at order time
--   discount_amount→ authoritative discount in MAD, computed server-side
--
-- Deleting a coupon therefore keeps the order's code + discount intact for
-- receipts, emails and the admin panel; only the link becomes NULL.
--
-- Totals policy:
--   subtotal_amount = merchandise lines (pre-discount)
--   discount_amount = coupon discount, capped at subtotal_amount
--   shipping_cost   = authoritative shipping (threshold uses pre-discount subtotal)
--   total_amount    = subtotal_amount - discount_amount + shipping_cost
--
-- Upgrade: after backup, apply once via api/scripts/migrate.php.
-- Safe to re-run (INFORMATION_SCHEMA guards, additive columns only).
-- ============================================================

USE lucebianca;

-- 1) coupon_id (nullable link; historical rows have no coupon)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'coupon_id');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN coupon_id INT UNSIGNED NULL AFTER shipping_cost', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) coupon_code (snapshot, survives coupon deletion)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'coupon_code');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN coupon_code VARCHAR(40) NULL AFTER coupon_id', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) discount_amount (authoritative discount, default 0 for old orders)
SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'discount_amount');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE orders ADD COLUMN discount_amount DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER coupon_code', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Index for coupon-filtered reporting / FK support
SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND INDEX_NAME = 'idx_orders_coupon');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE orders ADD KEY idx_orders_coupon (coupon_id)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 5) Foreign key: deleting a coupon preserves the order snapshot
SET @fk_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND CONSTRAINT_NAME = 'fk_orders_coupon' AND CONSTRAINT_TYPE = 'FOREIGN KEY');
SET @sql := IF(@fk_exists = 0, 'ALTER TABLE orders ADD CONSTRAINT fk_orders_coupon FOREIGN KEY (coupon_id) REFERENCES coupons(id) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
