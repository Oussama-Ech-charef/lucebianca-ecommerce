-- Migration: Add sale_price column to products table
-- Date: 2025
-- Purpose: Enable optional sale prices for products

ALTER TABLE products
ADD COLUMN sale_price DECIMAL(10,2) NULL AFTER base_price;