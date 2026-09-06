-- Migration: Add city and postal_code to orders
-- Date: 2026-08-27
-- Purpose: Backend now requires separate city/postal_code (orders.city,
--          orders.postal_code) alongside shipping_address (street). Fresh
--          installs via schema.sql already have these columns; this migration
--          patches databases created from 001_initial_schema which lacked them.
--          Safe for existing orders: existing rows get empty-string defaults and
--          remain valid; new orders must supply real values (validated in
--          OrderService).

-- Idempotent, phpMyAdmin-compatible (no PREPARE/EXECUTE) — MySQL 8.0.23+
-- supports ADD COLUMN IF NOT EXISTS, so re-running the file never errors
-- and preserves column order via AFTER.
ALTER TABLE orders ADD COLUMN IF NOT EXISTS city VARCHAR(100) NOT NULL DEFAULT '' AFTER shipping_address;
ALTER TABLE orders ADD COLUMN IF NOT EXISTS postal_code VARCHAR(20) NOT NULL DEFAULT '' AFTER city;
