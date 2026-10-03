-- ============================================================
-- Luce Bianca — Migration 018: newsletter signed unsubscribe
-- ------------------------------------------------------------
-- Purpose: Replace "any email unsubscribes anyone" with a signed,
-- single-purpose unsubscribe token. The token is stateless HMAC
-- (email|expiry|signature with JWT_SECRET) so no new PII is stored;
-- this migration adds an optional audit column only.
--
-- Upgrade: after backup, apply once. Safe to re-run.
-- ============================================================

USE lucebianca;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'newsletter_subscribers' AND COLUMN_NAME = 'unsubscribe_count');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE newsletter_subscribers ADD COLUMN unsubscribe_count INT UNSIGNED NOT NULL DEFAULT 0', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
