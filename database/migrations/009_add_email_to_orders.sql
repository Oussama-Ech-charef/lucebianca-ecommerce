-- Migration: Add email column to orders table
-- Date: 2026-08-23
-- Purpose: Enable order confirmation emails for guest checkout

ALTER TABLE orders
ADD COLUMN email VARCHAR(255) NULL AFTER phone;
