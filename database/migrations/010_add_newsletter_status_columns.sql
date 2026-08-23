-- Migration: Add lifecycle columns to newsletter_subscribers
-- Date: 2026-08-23
-- Purpose: NewsletterRepository (subscribe re-activation, unsubscribe,
--          isSubscribed) reads/writes is_active and unsubscribed_at, but
--          every fresh migration-path install lacked those columns — the
--          first POST /api/newsletter/subscribe failed with
--          "Unknown column 'is_active'" (caught by the api/tests
--          integration suite). Aligns the table with the repository.

ALTER TABLE newsletter_subscribers
ADD COLUMN is_active TINYINT(1) NOT NULL DEFAULT 1 AFTER email,
ADD COLUMN unsubscribed_at DATETIME NULL AFTER subscribed_at;
