-- ============================================================
-- Luce Bianca — Migration 017: customer password reset
-- ------------------------------------------------------------
-- Purpose: One-time expiring password-reset tokens for users table.
-- Flow: POST /api/auth/forgot-password (always 200, anti-enumeration) →
-- email link /reset-password?token=… → POST /api/auth/reset-password.
-- Tokens are stored hashed (SHA-256), single-use, 1h expiry, revoked on
-- use and on password change. Rate-limited (5/15min per IP + per email).
--
-- Upgrade: after backup, apply once. Safe to re-run.
-- ============================================================

USE lucebianca;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_reset_hash');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN password_reset_hash CHAR(64) NULL AFTER email_verification_expires_at', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @col_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'password_reset_expires_at');
SET @sql := IF(@col_exists = 0, 'ALTER TABLE users ADD COLUMN password_reset_expires_at DATETIME NULL AFTER password_reset_hash', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @idx_exists := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_users_password_reset_hash');
SET @sql := IF(@idx_exists = 0, 'ALTER TABLE users ADD KEY idx_users_password_reset_hash (password_reset_hash)', 'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
