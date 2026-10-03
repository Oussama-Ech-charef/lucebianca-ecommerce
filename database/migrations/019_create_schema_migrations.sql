-- ============================================================
-- Luce Bianca — Migration 019: migration tracking table
-- ------------------------------------------------------------
-- Purpose: Record applied migrations so upgrades are deterministic.
-- The runner (api/scripts/migrate.php) creates this table if missing,
-- applies pending *.sql files in numeric order inside transactions
-- where possible, and records filename + checksum + applied_at.
-- Do NOT delete rows — history is append-only.
-- ============================================================

USE lucebianca;

CREATE TABLE IF NOT EXISTS schema_migrations (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  filename VARCHAR(255) NOT NULL,
  checksum CHAR(64) NOT NULL,
  applied_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_schema_migrations_filename (filename)
) ENGINE=InnoDB;
