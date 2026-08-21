-- ============================================================
-- Luce Bianca — Migration 004: users.google_id (Google Sign-In)
-- ------------------------------------------------------------
-- Phase 13 adds customer Google OAuth. Google's ID token carries a
-- unique `sub` per account; storing it lets the API (a) re-login a
-- returning Google user and (b) link a Google login to an existing
-- email account (create-or-link by email) without opening a duplicate
-- row. NULL = not a Google-linked account. Unique so one Google
-- account can never attach to two users.
--
-- password_hash stays NOT NULL: Google-created users get an unusable
-- random hash so password_verify() always fails for them (they have no
-- password), while the schema's NOT NULL invariant is preserved.
--
-- Added as a NEW numbered migration instead of editing 001/002/003.
-- ============================================================

USE lucebianca;

ALTER TABLE users
  ADD COLUMN google_id VARCHAR(255) NULL AFTER email,
  ADD UNIQUE KEY uq_users_google_id (google_id);