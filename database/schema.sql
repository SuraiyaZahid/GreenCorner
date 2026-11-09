-- ===========================================================================
-- GreenCorner — Database Schema
-- ===========================================================================
-- Source of truth: GreenCorner_SRS.md §10 (Database Requirements)
--
-- Requirements implemented by this file:
--   SC-2  MySQL 8.0.16+ (CHECK constraints are only enforced from 8.0.16)
--   R-SQL-1  STRICT_TRANS_TABLES configured at the SERVER level
--   BR-1   All monetary columns are DECIMAL(10,2); no floating point
--   BR-3   Payment status is exactly pending|success|failed (no 'refunded')
--   BR-8   The five permitted (method, status, order_id) payment shapes
--   FR-1.4 users.role_id NOT NULL, FK to roles
--   FR-1.9 users.must_change_password
--   FR-1.10 server-side session expiry columns on the sessions table
--   FR-2.3 products.category_id NOT NULL
--   FR-2.8 products.is_deleted
--   FR-2.12 products.image_path + products.thumbnail_path
--   FR-3.3  cart_items UNIQUE (user_id, product_id)
--   FR-3.7  cart_items.price_at_add
--   FR-4.14 orders.order_number UNIQUE
--   FR-4.16 orders has NO payment_status column
--   FR-6.5  products are never hard-deleted
--   NFR-S-06 login_attempts backs the database-backed rate limiting (D-005)
--
-- Usage:
--   mysql -u greencorner -p greencorner < database/schema.sql
-- ===========================================================================

-- ---------------------------------------------------------------------------
-- Database
-- ---------------------------------------------------------------------------
CREATE DATABASE IF NOT EXISTS `greencorner`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `greencorner`;

-- Drop in reverse dependency order so the script is re-runnable during
-- development. Production deployments should NOT run this section.
SET FOREIGN_KEY_CHECKS = 0;
DROP TABLE IF EXISTS `payments`;
DROP TABLE IF EXISTS `order_items`;
DROP TABLE IF EXISTS `orders`;
DROP TABLE IF EXISTS `cart_items`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `products`;
DROP TABLE IF EXISTS `categories`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `sessions`;
SET FOREIGN_KEY_CHECKS = 1;

-- ---------------------------------------------------------------------------
-- roles
-- SRS §10.3. Seeded with 'User' and 'Admin'. No permissions table exists in
-- v1.0 — role capabilities live in the application layer (FR-7.1, SC-5).
-- ---------------------------------------------------------------------------
CREATE TABLE `roles` (
  `id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name` VARCHAR(50)  NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_roles_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- users
-- SRS §10.3, FR-1.1 … FR-1.4, FR-7.3, FR-6.11.
--   is_active              backs FR-7.3 (status re-checked per request)
--   must_change_password   backs FR-1.9 and FR-6.10
-- ---------------------------------------------------------------------------
CREATE TABLE `users` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `full_name`           VARCHAR(120)  NOT NULL,
  `email`               VARCHAR(190)  NOT NULL,
  `password_hash`       VARCHAR(255)  NOT NULL,
  `role_id`             INT UNSIGNED  NOT NULL,
  `is_active`           TINYINT(1)    NOT NULL DEFAULT 1,
  `must_change_password` TINYINT(1)   NOT NULL DEFAULT 0,
  `created_at`          DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_users_email` (`email`),
  KEY `idx_users_role_id` (`role_id`),
  CONSTRAINT `fk_users_role`
    FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- categories
-- SRS §10.3, FR-2.3, FR-6.6. There is no reserved "Uncategorised" category.
-- ---------------------------------------------------------------------------
CREATE TABLE `categories` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_categories_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- products
-- SRS §10.3, FR-2.1 … FR-2.12, FR-6.2 … FR-6.5.
--   price            DECIMAL(10,2) — floats are prohibited (BR-1)
--   category_id      NOT NULL, RESTRICT on delete (FR-2.3)
--   is_deleted       soft delete; the row is never hard-deleted (FR-6.5)
--   image_path       original image (FR-2.12)
--   thumbnail_path   generated thumbnail (FR-2.12)
-- The name is indexed for the LIKE search of FR-2.5.
-- ---------------------------------------------------------------------------
CREATE TABLE `products` (
  `id`             INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150)  NOT NULL,
  `description`    TEXT          NULL,
  `price`          DECIMAL(10,2) NOT NULL,
  `stock`          INT           NOT NULL DEFAULT 0,
  `image_path`     VARCHAR(255)  NOT NULL,
  `thumbnail_path` VARCHAR(255)  NOT NULL,
  `category_id`    INT UNSIGNED  NOT NULL,
  `is_deleted`     TINYINT(1)    NOT NULL DEFAULT 0,
  `created_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_products_name` (`name`),
  KEY `idx_products_category_id` (`category_id`),
  KEY `idx_products_is_deleted_created` (`is_deleted`, `created_at`),
  CONSTRAINT `fk_products_category`
    FOREIGN KEY (`category_id`) REFERENCES `categories` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- cart_items
-- SRS §10.3, FR-3.1 … FR-3.10.
--   UNIQUE (user_id, product_id) guarantees duplicate prevention at the
--   database level, even under concurrent requests (FR-3.3).
--   price_at_add is retained for change detection ONLY; the cart always
--   displays and charges the current price (FR-3.7, BR-12).
-- ---------------------------------------------------------------------------
CREATE TABLE `cart_items` (
  `id`           INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `user_id`      INT UNSIGNED  NOT NULL,
  `product_id`   INT UNSIGNED  NOT NULL,
  `quantity`     INT           NOT NULL DEFAULT 1,
  `price_at_add` DECIMAL(10,2) NOT NULL,
  `added_at`     DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cart_items_user_product` (`user_id`, `product_id`),
  KEY `idx_cart_items_product_id` (`product_id`),
  CONSTRAINT `chk_cart_items_quantity` CHECK (`quantity` >= 1),
  CONSTRAINT `fk_cart_items_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_cart_items_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- orders
-- SRS §10.3, FR-4.8, FR-4.14, FR-4.16, BR-5, BR-6.
--   There is deliberately NO payment_status column — payments.status is the
--   sole source of truth (FR-4.16).
--   The delivery_* columns form the immutable address snapshot (FR-4.8).
--   status is an ENUM of the five permitted fulfilment states (BR-7).
-- ---------------------------------------------------------------------------
CREATE TABLE `orders` (
  `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_number`    VARCHAR(32)   NOT NULL,
  `user_id`         INT UNSIGNED  NOT NULL,
  `total_amount`    DECIMAL(10,2) NOT NULL,
  `status`          ENUM('placed','processing','shipped','delivered','cancelled')
                    NOT NULL DEFAULT 'placed',
  `delivery_name`    VARCHAR(120) NOT NULL,
  `delivery_phone`   VARCHAR(40)  NOT NULL,
  `delivery_address` VARCHAR(255) NOT NULL,
  `delivery_city`    VARCHAR(100) NOT NULL,
  `created_at`      DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_orders_order_number` (`order_number`),
  KEY `idx_orders_user_id` (`user_id`),
  CONSTRAINT `fk_orders_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_items
-- SRS §10.3, BR-5, BR-6.
--   product_name_at_purchase and unit_price_at_purchase are immutable
--   snapshots, so historical order totals never change.
--   product_id is NOT NULL because a product row is never deleted (FR-6.5).
-- ---------------------------------------------------------------------------
CREATE TABLE `order_items` (
  `id`                      INT UNSIGNED  NOT NULL AUTO_INCREMENT,
  `order_id`                INT UNSIGNED  NOT NULL,
  `product_id`              INT UNSIGNED  NOT NULL,
  `product_name_at_purchase` VARCHAR(150) NOT NULL,
  `unit_price_at_purchase` DECIMAL(10,2) NOT NULL,
  `quantity`                INT           NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_order_items_order_id` (`order_id`),
  KEY `idx_order_items_product_id` (`product_id`),
  CONSTRAINT `chk_order_items_quantity` CHECK (`quantity` >= 1),
  CONSTRAINT `fk_order_items_order`
    FOREIGN KEY (`order_id`) REFERENCES `orders` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_order_items_product`
    FOREIGN KEY (`product_id`) REFERENCES `products` (`id`)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- payments
-- SRS §10.3 and §10.4 — the final approved DDL, reproduced exactly.
--
--   order_id          NULL — a declined card attempt references no order
--   success_order_id  VIRTUAL GENERATED — equals order_id when status='success'
--   uq_payments_success_order — at most one successful payment per order
--   chk_payment_order_binding — the five permitted shapes (BR-8)
-- ---------------------------------------------------------------------------
CREATE TABLE `payments` (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  order_id      INT UNSIGNED NULL,          -- NULL for failed card attempts
  method        ENUM('card','cod')          NOT NULL,
  status        ENUM('pending','success','failed') NOT NULL,
  paid_at       DATETIME NULL,
  success_order_id INT UNSIGNED
    GENERATED ALWAYS AS (
      CASE WHEN status = 'success' THEN order_id ELSE NULL END
    ) VIRTUAL,
  UNIQUE KEY uq_payments_success_order (success_order_id),
  CONSTRAINT chk_payment_order_binding
    CHECK (
         (method = 'card'  AND status = 'success'  AND order_id IS NOT NULL)
      OR (method = 'card'  AND status = 'failed'   AND order_id IS NULL)
      OR (method = 'cod'   AND status = 'pending'  AND order_id IS NOT NULL)
      OR (method = 'cod'   AND status = 'success'  AND order_id IS NOT NULL)
      OR (method = 'cod'   AND status = 'failed'   AND order_id IS NOT NULL)
    ),
  CONSTRAINT fk_payments_order
    FOREIGN KEY (order_id) REFERENCES orders (id)
    ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- order_sequence
-- Daily order-number sequence counter (decision D-012, FR-4.14).
--
-- The approved order number format is GC-YYYYMMDD-NNNN, for example
-- GC-20261102-0007. The NNNN component is a per-calendar-day counter that
-- restarts at 0001 each day.
--
-- This is a technical support table, not a business entity: it holds no
-- product, customer or order data. It exists only so that the order number
-- can be generated safely under concurrent checkouts. A COUNT() over the
-- orders table cannot do this, because two simultaneous checkouts would
-- read the same count and then both try to insert the same number. The
-- counter row is locked with SELECT ... FOR UPDATE before it is read and
-- incremented, so the two checkouts are serialised.
--
-- The UNIQUE constraint on orders.order_number remains in place as the
-- final backstop.
-- ---------------------------------------------------------------------------
CREATE TABLE `order_sequence` (
  `sequence_date` DATE         NOT NULL,
  `last_value`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`sequence_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- sessions
-- Server-side, database-backed session storage (FR-7.6).
--
-- The columns mirror the PHP session save handler's contract:
--   session_id  the session identifier (the only thing in the cookie)
--   user_id     the authenticated account, NULL for a visitor session
--   last_activity  last time the session was used -> 8 hour idle timeout
--   created_at  session creation time -> 7 day absolute timeout
--
-- The absolute limit cannot be expressed by a cookie attribute, so it is
-- enforced in the handler's own logic (FR-1.10, FR-7.6).
-- ---------------------------------------------------------------------------
CREATE TABLE `sessions` (
  `session_id`    VARCHAR(64)  NOT NULL,
  `user_id`       INT UNSIGNED NULL,
  `payload`       TEXT         NOT NULL,
  `last_activity` INT UNSIGNED NOT NULL,
  `created_at`    INT UNSIGNED NOT NULL,
  PRIMARY KEY (`session_id`),
  KEY `idx_sessions_user_id` (`user_id`),
  KEY `idx_sessions_last_activity` (`last_activity`),
  CONSTRAINT `fk_sessions_user`
    FOREIGN KEY (`user_id`) REFERENCES `users` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- login_attempts
-- Database-backed failed-login tracking (NFR-S-06, SEC-13, decision D-005).
--
-- One row per failed attempt. The source address is stored as a SHA-256
-- hash so the raw address is never persisted (SEC-7: addresses are personal
-- data and are not stored in the clear).
--
-- The policy is unchanged from Phase 1: LOGIN_MAX_ATTEMPTS failed attempts
-- from one source address within LOGIN_LOCKOUT_SECONDS produce a lockout.
-- Storing the individual attempts — rather than a single counter — means the
-- window slides correctly: an attempt that falls out of the 15-minute window
-- stops counting without any scheduled cleanup job.
-- ---------------------------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`     CHAR(64)     NOT NULL,
  `email_hash`  CHAR(64)     NULL,
  `created_at`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_ip_created` (`ip_hash`, `created_at`),
  KEY `idx_login_attempts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- Seed data: the two roles (FR-1.4, FR-6.12)
-- Idempotent — re-running does not create duplicates.
-- ---------------------------------------------------------------------------
INSERT INTO `roles` (`id`, `name`) VALUES
  (1, 'User'),
  (2, 'Admin')
ON DUPLICATE KEY UPDATE `name` = VALUES(`name`);
