-- ============================================================
-- Luce Bianca — Migration 003: admin_refresh_tokens table
-- ------------------------------------------------------------
-- Admin login (phase 4) needs refresh tokens too, but
-- refresh_tokens.user_id is a FK to users(id) ONLY — admin ids
-- live in the separate `admins` table (spec section 4: admins are
-- FULLY separate from users for security). Reusing refresh_tokens
-- for admins would either hit the FK for ids that don't exist in
-- users, or silently bind an admin's token to an unrelated user
-- row when an id happens to collide.
--
-- Decision: a dedicated admin_refresh_tokens table (same shape,
-- same hash-only policy) so each token is unambiguously tied to
-- its own principal table. Customer and admin storage stay fully
-- separate; the repository is shared via a subclass (DRY).
--
-- Added as a NEW numbered migration instead of editing an old one.
-- ============================================================

USE lucebianca;

CREATE TABLE admin_refresh_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  admin_id   INT UNSIGNED  NOT NULL,
  token_hash CHAR(64)      NOT NULL,   -- SHA-256 hex digest of the refresh token
  expires_at DATETIME      NOT NULL,   -- when the token stops being valid
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME      NULL,       -- set on logout/revocation; NULL = still valid
  UNIQUE KEY uq_admin_refresh_tokens_hash (token_hash),
  KEY idx_admin_refresh_tokens_admin (admin_id),  -- reused by the FK below
  CONSTRAINT fk_admin_refresh_tokens_admin FOREIGN KEY (admin_id)
    REFERENCES admins(id) ON DELETE CASCADE
) ENGINE=InnoDB;