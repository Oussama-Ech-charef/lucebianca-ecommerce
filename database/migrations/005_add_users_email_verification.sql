-- ============================================================
-- Luce Bianca — Migration 005: users email verification
-- ------------------------------------------------------------
-- Phase 16 adds email verification for password-based customer
-- registration, delivered via Resend. Three columns:
--
--   email_verified              0/1 flag — 1 after the user clicks the
--                               verification link. Informational only:
--                               login/checkout are never blocked on it.
--   email_verification_token    single-use opaque token (bin2hex of 32
--                               random bytes) emailed to the user; NULL
--                               = none pending / already verified. Unique
--                               so one token can never verify two accounts.
--   email_verification_expires_at  when the token stops being valid
--                               (24 h after issue).
--
-- Google OAuth accounts are created with email_verified = 1 (Google has
-- already verified them) and never carry a token. The registration email
-- is best-effort: if sending fails the account still exists, just
-- unverified, and the user can re-request via /account or the resend
-- endpoint.
--
-- Added as a NEW numbered migration instead of editing 001/002/003/004.
-- ============================================================

USE lucebianca;

ALTER TABLE users
  ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER email,
  ADD COLUMN email_verification_token VARCHAR(255) NULL AFTER google_id,
  ADD COLUMN email_verification_expires_at DATETIME NULL AFTER email_verification_token,
  ADD UNIQUE KEY uq_users_email_verification_token (email_verification_token);