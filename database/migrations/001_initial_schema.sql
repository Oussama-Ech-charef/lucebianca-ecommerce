-- ============================================================
-- Luce Bianca — Database Schema
-- Engine: InnoDB | Charset: utf8mb4 (full Arabic support)
-- Source: lucebianca-project-full.md, section 4
-- ============================================================

CREATE DATABASE IF NOT EXISTS lucebianca
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

USE lucebianca;

-- ------------------------------------------------------------
-- 1) users: customer accounts (Guest Checkout is available, so an account is not required)
-- ------------------------------------------------------------
CREATE TABLE users (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100)  NOT NULL,
  email         VARCHAR(150)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,  -- password_hash() with bcrypt/argon2, never plain text
  phone         VARCHAR(20)   NULL,
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_email (email)      -- prevents duplicate emails
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 2) admins: admin panel accounts — fully separate from users (security requirement)
-- ------------------------------------------------------------
CREATE TABLE admins (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100)  NOT NULL,
  email         VARCHAR(150)  NOT NULL,
  password_hash VARCHAR(255)  NOT NULL,
  role          VARCHAR(30)   NOT NULL DEFAULT 'admin',
  created_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 3) categories: product categories
-- ------------------------------------------------------------
CREATE TABLE categories (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  slug       VARCHAR(120) NOT NULL,      -- for clean URLs (SEO)
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_slug (slug)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 4) products: the base product (example: "Casual Luxury Tee")
--    meta_title / meta_description are required for SEO (agreed decision)
-- ------------------------------------------------------------
CREATE TABLE products (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name             VARCHAR(150)   NOT NULL,
  slug             VARCHAR(180)   NOT NULL,
  description      TEXT           NULL,
  base_price       DECIMAL(10,2)  NOT NULL,   -- DECIMAL, not FLOAT, to avoid rounding errors in prices
  category_id      INT UNSIGNED   NULL,
  is_active        TINYINT(1)     NOT NULL DEFAULT 1,  -- 0 = product paused (hidden from the site without being deleted)
  meta_title       VARCHAR(160)   NULL,
  meta_description VARCHAR(320)   NULL,
  created_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_slug (slug),
  KEY idx_products_category (category_id),  -- index: fast filtering by category
  CONSTRAINT fk_products_category FOREIGN KEY (category_id)
    REFERENCES categories(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 5) product_variants: every color/size combination of a product
--    (example: Casual Luxury Tee - Black - M)
--    FIX: added a UNIQUE constraint on (product_id, size, color) to prevent
--    accidentally creating two identical variants for the same product.
-- ------------------------------------------------------------
CREATE TABLE product_variants (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id     INT UNSIGNED   NOT NULL,
  size           VARCHAR(10)    NOT NULL,   -- S, M, L, XL...
  color          VARCHAR(50)    NOT NULL,
  sku            VARCHAR(60)    NOT NULL,   -- unique stock reference
  price          DECIMAL(10,2)  NULL,       -- if NULL, fall back to the product's base_price
  stock_quantity INT UNSIGNED   NOT NULL DEFAULT 0,
  UNIQUE KEY uq_variant_sku (sku),
  UNIQUE KEY uq_variant_combo (product_id, size, color),  -- FIX: no duplicate size/color per product
  KEY idx_variant_product (product_id),     -- index: fast lookup of a product's variants
  CONSTRAINT fk_variant_product FOREIGN KEY (product_id)
    REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 6) product_images: multiple images per product (agreed requirement)
--    is_main = the image shown first on /shop
--    sort_order = display order in the gallery (Swiper.js)
-- ------------------------------------------------------------
CREATE TABLE product_images (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id INT UNSIGNED  NOT NULL,
  image_url  VARCHAR(500)  NOT NULL,  -- Cloudinary URL only, never the image itself
  is_main    TINYINT(1)    NOT NULL DEFAULT 0,
  sort_order SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  KEY idx_images_product (product_id),
  CONSTRAINT fk_images_product FOREIGN KEY (product_id)
    REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 7) orders: user_id can be NULL because of Guest Checkout
-- ------------------------------------------------------------
CREATE TABLE orders (
  id               INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id          INT UNSIGNED  NULL,       -- NULL = guest order, no account
  status           ENUM('pending','processing','shipped','delivered','cancelled')
                     NOT NULL DEFAULT 'pending',
  total_amount     DECIMAL(10,2) NOT NULL,
  shipping_address VARCHAR(255)  NOT NULL,
  customer_name    VARCHAR(100)  NOT NULL,
  phone            VARCHAR(20)   NOT NULL,
  payment_method   ENUM('card','cod','whatsapp') NOT NULL,  -- the agreed-on options
  payment_status   ENUM('pending','paid','failed') NOT NULL DEFAULT 'pending',
  created_at       TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_orders_user (user_id),
  KEY idx_orders_status (status),           -- index: fast filtering in the admin panel
  CONSTRAINT fk_orders_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 8) order_items: line items of each order (product + chosen quantity)
--    price_at_purchase: records the price at purchase time, even if the price
--    changes later.
--    NOTE ON FIX: this table intentionally uses ON DELETE RESTRICT on
--    product_variant_id so a variant that has been ordered can never be
--    hard-deleted. See the "Issues found & fixed" notes below for how this
--    interacts with product_variants' CASCADE rule.
-- ------------------------------------------------------------
CREATE TABLE order_items (
  id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id           INT UNSIGNED  NOT NULL,
  product_variant_id INT UNSIGNED  NOT NULL,
  quantity           SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  price_at_purchase  DECIMAL(10,2) NOT NULL,
  KEY idx_items_order (order_id),
  KEY idx_items_variant (product_variant_id),  -- FIX: explicit index, product lookups by variant stay fast
  CONSTRAINT fk_items_order FOREIGN KEY (order_id)
    REFERENCES orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_items_variant FOREIGN KEY (product_variant_id)
    REFERENCES product_variants(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 9) coupons: discount codes (optional)
-- ------------------------------------------------------------
CREATE TABLE coupons (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code           VARCHAR(40)   NOT NULL,
  discount_type  ENUM('percentage','fixed') NOT NULL,
  discount_value DECIMAL(10,2) NOT NULL,
  expires_at     DATETIME      NULL,
  is_active      TINYINT(1)    NOT NULL DEFAULT 1,
  UNIQUE KEY uq_coupons_code (code)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 10) contact_messages: messages from the /contact form
--     is_read: powers the read/unread flag in /admin/messages
-- ------------------------------------------------------------
CREATE TABLE contact_messages (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name       VARCHAR(100) NOT NULL,
  email      VARCHAR(150) NOT NULL,
  message    TEXT         NOT NULL,
  is_read    TINYINT(1)   NOT NULL DEFAULT 0,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 11) reviews: customer reviews — is_approved gates publishing until admin approves
-- ------------------------------------------------------------
CREATE TABLE reviews (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id  INT UNSIGNED NOT NULL,
  user_id     INT UNSIGNED NULL,
  rating      TINYINT UNSIGNED NOT NULL,  -- 1 to 5
  comment     TEXT         NULL,
  is_approved TINYINT(1)   NOT NULL DEFAULT 0,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_reviews_product (product_id),
  CONSTRAINT fk_reviews_product FOREIGN KEY (product_id)
    REFERENCES products(id) ON DELETE CASCADE,
  CONSTRAINT fk_reviews_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_reviews_rating CHECK (rating BETWEEN 1 AND 5)  -- guards against out-of-range ratings
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 12) wishlist: customer wishlist
-- ------------------------------------------------------------
CREATE TABLE wishlist (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id    INT UNSIGNED NOT NULL,
  product_id INT UNSIGNED NOT NULL,
  created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_wishlist_user_product (user_id, product_id),  -- prevents the same product being added twice
  CONSTRAINT fk_wishlist_user FOREIGN KEY (user_id)
    REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_wishlist_product FOREIGN KEY (product_id)
    REFERENCES products(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 13) newsletter_subscribers: email signups for offers
-- ------------------------------------------------------------
CREATE TABLE newsletter_subscribers (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email         VARCHAR(150) NOT NULL,
  subscribed_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_newsletter_email (email)
) ENGINE=InnoDB;

-- ------------------------------------------------------------
-- 14) settings: store settings (key-value) — powers /admin/settings
--     All settings (shipping, payment, notifications, SEO...) are stored
--     here and read live by the site.
-- ------------------------------------------------------------
CREATE TABLE settings (
  setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,  -- example: 'shipping_free_threshold'
  setting_value TEXT         NULL,                   -- the value (text, number, or JSON)
  updated_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;