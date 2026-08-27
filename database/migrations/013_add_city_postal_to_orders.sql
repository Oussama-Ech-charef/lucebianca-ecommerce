-- Migration: Add city and postal_code to orders
-- Date: 2026-08-27
-- Purpose: Backend now requires separate city/postal_code (orders.city,
--          orders.postal_code) alongside shipping_address (street). Fresh
--          installs via schema.sql already have these columns; this migration
--          patches databases created from 001_initial_schema which lacked them.
--          Safe for existing orders: existing rows get empty-string defaults and
--          remain valid; new orders must supply real values (validated in
--          OrderService).

-- city
SET @city_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'city'
);
SET @sql := IF(@city_exists = 0,
  "ALTER TABLE orders ADD COLUMN city VARCHAR(100) NOT NULL DEFAULT '' AFTER shipping_address",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- postal_code
SET @postal_exists := (
  SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'orders' AND COLUMN_NAME = 'postal_code'
);
SET @sql := IF(@postal_exists = 0,
  "ALTER TABLE orders ADD COLUMN postal_code VARCHAR(20) NOT NULL DEFAULT '' AFTER city",
  'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
