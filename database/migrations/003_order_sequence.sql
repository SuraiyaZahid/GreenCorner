-- ===========================================================================
-- GreenCorner — Migration 003: order_sequence
--
-- Adds the daily order-number sequence counter introduced by decision D-012.
--
-- The order number format approved in decision C is GC-YYYYMMDD-NNNN, for
-- example GC-20261102-0007. The NNNN component is a per-calendar-day
-- counter that restarts at 0001 each day, so the counter has to be shared
-- between every checkout that happens on the same day.
--
-- The counter lives in its own table rather than in a COUNT() over the
-- orders table, because a COUNT() is not safe under concurrency: two
-- simultaneous checkouts would read the same count and would then both try
-- to insert the same order number. The row is locked with
-- SELECT ... FOR UPDATE before it is read and incremented, so the two
-- checkouts are serialised.
--
-- The table is a technical support table. It holds no business data and
-- introduces no new business behaviour: it exists only so that the order
-- number required by FR-4.14 is unique and human-readable under concurrent
-- checkouts.
--
-- This migration is idempotent: running it against a database that already
-- has the table is a no-op.
--
-- Usage:
--   mysql -u greencorner -p greencorner < database/migrations/003_order_sequence.sql
-- ===========================================================================

USE `greencorner`;

CREATE TABLE IF NOT EXISTS `order_sequence` (
  `sequence_date` DATE         NOT NULL,
  `last_value`   INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY (`sequence_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
