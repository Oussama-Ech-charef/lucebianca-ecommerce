-- ============================================================
-- Luce Bianca — Migration 002: refresh_tokens table
-- ------------------------------------------------------------
-- Enables refresh-token storage so tokens can be looked up,
-- revoked and cycled without redesigning the API later.
-- Only the SHA-256 hash of a token is stored — the raw token is
-- NEVER persisted (it is returned once to the client at issuance).
--
-- Added as a NEW numbered migration (see README.md in this
-- folder) instead of editing 001_initial_schema.sql in place.
-- ============================================================

USE lucebianca;

CREATE TABLE refresh_tokens (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED  NOT NULL,
  token_hash CHAR(64)      NOT NULL,   -- SHA-256 hex digest of the refresh token
  expires_at DATETIME      NOT NULL,   -- when the token stops being valid
  created_at TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  revoked_at DATETIME      NULL,       -- set on logout/revocation; NULL = still valid
  UNIQUE KEY uq_refresh_tokens_hash (token_hash),
  KEY idx_refresh_tokens_user (user_id),  -- reused by the FK below (no duplicate index)
  CONSTRAINT fk_refresh_tokens_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;