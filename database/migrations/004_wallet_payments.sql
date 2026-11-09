-- ===========================================================================
-- GreenCorner — Migration 004: bKash and Nagad merchant payments
--
-- Adds the manual mobile-wallet payment methods to the schema.
--
-- WHY A SCHEMA CHANGE IS NEEDED
--
-- The `payments` table deliberately carries a CHECK constraint
-- (`chk_payment_order_binding`) that permits exactly five (method, status,
-- order_id) shapes, and `method` is an ENUM of ('card','cod'). A bKash or a
-- Nagad payment cannot be expressed in that vocabulary at all: the method
-- value does not exist, and the status vocabulary has no way to say
-- "submitted by the customer, not yet verified by the store".
--
-- Rather than loosen the constraint — which is what protects the card/COD
-- shapes (BR-8) — this migration:
--
--   1. WIDENS `payments.method` and `payments.status` to admit the wallet
--      methods and the `pending_verification` status. The five original card
--      and COD shapes remain valid and unchanged; the CHECK constraint is
--      replaced with one that still enforces the binding rules for EVERY
--      method, including the new ones.
--   2. ADDS `payment_ref`, the record of a manual merchant-payment
--      submission: the sender number, the transaction id, the amount, the
--      reference the customer was shown, and who verified it and when.
--   3. ADDS `refunds`, the refund record. A refund is never marked
--      successful without a gateway confirmation.
--   4. ADDS the `paid_unfulfillable` fulfilment status to `orders.status`,
--      for the case where a verified wallet payment cannot be fulfilled
--      because the product is no longer available.
--
-- PRESERVING EXISTING DATA
--
-- Every statement here is additive. No row is deleted, and no existing
-- card or COD payment is rewritten: the five permitted shapes from BR-8
-- keep their exact meaning, and `success_order_id` keeps its meaning as
-- "the order this successful payment belongs to", so an order can still
-- never carry two successful payments.
--
-- This migration is idempotent: running it against a database that already
-- has the new shape is a no-op.
--
-- Usage:
--   mysql -u greencorner -p greencorner < database/migrations/004_wallet_payments.sql
-- ===========================================================================

USE `greencorner`;

-- ---------------------------------------------------------------------------
-- 1. payments.method — admit bKash and Nagad
-- ---------------------------------------------------------------------------
-- MODIFY COLUMN re-declares the ENUM. MySQL preserves the existing rows,
-- and the values are appended, so the ordinal of 'card' and 'cod' does not
-- change and no stored value is reinterpreted.
ALTER TABLE `payments`
  MODIFY COLUMN `method` ENUM('card','cod','bkash','nagad') NOT NULL;

-- ---------------------------------------------------------------------------
-- 2. payments.status — admit pending_verification
-- ---------------------------------------------------------------------------
-- 'pending_verification' is the state of a wallet payment the customer has
-- submitted but the store has not yet verified. It is deliberately NOT
-- 'success': a submitted transaction id is not proof of payment.
ALTER TABLE `payments`
  MODIFY COLUMN `status` ENUM('pending','success','failed','pending_verification') NOT NULL;

-- ---------------------------------------------------------------------------
-- 3. payments — the widened CHECK constraint
-- ---------------------------------------------------------------------------
-- The original constraint cannot be dropped and re-added under a different
-- name in one statement on every supported MySQL version, so the existing
-- constraint is dropped and a new one with the same name is added.
--
-- The new constraint keeps the five original shapes exactly as they were and
-- adds the wallet shapes:
--
--   bkash/nagad + pending_verification + order_id NOT NULL
--       the customer submitted the payment; the store has not verified it
--   bkash/nagad + success              + order_id NOT NULL
--       an Admin verified the payment
--   bkash/nagad + failed               + order_id NOT NULL
--       an Admin rejected the payment
--
-- A successful payment MUST reference an order, and a failed CARD payment
-- still references no order — the original BR-8 rule is unchanged.
ALTER TABLE `payments` DROP CHECK `chk_payment_order_binding`;

ALTER TABLE `payments`
  ADD CONSTRAINT `chk_payment_order_binding`
  CHECK (
         (method = 'card'  AND status = 'success'  AND order_id IS NOT NULL)
      OR (method = 'card'  AND status = 'failed'   AND order_id IS NULL)
      OR (method = 'cod'   AND status = 'pending'  AND order_id IS NOT NULL)
      OR (method = 'cod'   AND status = 'success'  AND order_id IS NOT NULL)
      OR (method = 'cod'   AND status = 'failed'   AND order_id IS NOT NULL)
      OR (method IN ('bkash','nagad') AND status = 'pending_verification' AND order_id IS NOT NULL)
      OR (method IN ('bkash','nagad') AND status = 'success'              AND order_id IS NOT NULL)
      OR (method IN ('bkash','nagad') AND status = 'failed'               AND order_id IS NOT NULL)
    );

-- ---------------------------------------------------------------------------
-- 4. payment_ref — a manual merchant-payment submission
-- ---------------------------------------------------------------------------
-- One row per customer submission of a bKash or Nagad payment.
--
--   transaction_id  UNIQUE — the same transaction id can never be submitted
--                    twice, across every customer and every order. The
--                    uniqueness is enforced by the database, not only by the
--                    application, so a concurrent submission cannot slip
--                    through a check-then-insert race.
--   status          pending_verification | verified | rejected
--   verified_by     the Admin who verified or rejected it
--   reference_code  the reference the customer was shown, so the store can
--                    match the payment against the wallet statement
--   amount          the amount the customer was told to send, captured at
--                    the time of submission so a later price change cannot
--                    rewrite history
CREATE TABLE IF NOT EXISTS `payment_ref` (
  `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`      INT UNSIGNED NOT NULL,
  `method`          ENUM('bkash','nagad') NOT NULL,
  `sender_number`   VARCHAR(20)  NOT NULL,
  `transaction_id`  VARCHAR(64)  NOT NULL,
  `amount`          DECIMAL(10,2) NOT NULL,
  `reference_code`  VARCHAR(64)  NOT NULL,
  `status`          ENUM('pending_verification','verified','rejected') NOT NULL DEFAULT 'pending_verification',
  `verified_by`     INT UNSIGNED NULL,
  `verified_at`     DATETIME     NULL,
  `rejection_reason` VARCHAR(255) NULL,
  `created_at`      DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_payment_ref_transaction_id` (`transaction_id`),
  KEY `idx_payment_ref_payment_id` (`payment_id`),
  KEY `idx_payment_ref_status` (`status`),
  KEY `idx_payment_ref_verified_by` (`verified_by`),
  CONSTRAINT `chk_payment_ref_amount` CHECK (`amount` >= 0),
  CONSTRAINT `fk_payment_ref_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT,
  CONSTRAINT `fk_payment_ref_verified_by`
    FOREIGN KEY (`verified_by`) REFERENCES `users` (`id`)
    ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 5. refunds — the refund record
-- ---------------------------------------------------------------------------
--   status  refund_pending | refunded | refund_failed
--
-- A refund is created as `refund_pending`. It becomes `refunded` ONLY
-- after the gateway confirms it. A failed refund becomes `refund_failed`,
-- which flags the case for Admin review. There is no code path that sets
-- `refunded` without a confirmation.
CREATE TABLE IF NOT EXISTS `refunds` (
  `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`        INT UNSIGNED NOT NULL,
  `amount`            DECIMAL(10,2) NOT NULL,
  `status`            ENUM('refund_pending','refunded','refund_failed') NOT NULL DEFAULT 'refund_pending',
  `reason`            VARCHAR(255) NOT NULL,
  `gateway_reference` VARCHAR(190) NULL,
  `failure_detail`    VARCHAR(255) NULL,
  `confirmed_at`      DATETIME     NULL,
  `created_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`        DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_refunds_payment_id` (`payment_id`),
  KEY `idx_refunds_status` (`status`),
  CONSTRAINT `chk_refunds_amount` CHECK (`amount` > 0),
  CONSTRAINT `fk_refunds_payment`
    FOREIGN KEY (`payment_id`) REFERENCES `payments` (`id`)
    ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------------
-- 6. orders.status — admit paid_unfulfillable
-- ---------------------------------------------------------------------------
-- The state of an order whose payment has been verified but which cannot be
-- fulfilled because the product is no longer available. The customer is
-- refunded; the order is not simply cancelled, because the money WAS
-- received.
ALTER TABLE `orders`
  MODIFY COLUMN `status` ENUM('placed','processing','shipped','delivered','cancelled','paid_unfulfillable')
    NOT NULL DEFAULT 'placed';
