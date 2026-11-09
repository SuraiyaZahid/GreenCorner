-- ===========================================================================
-- GreenCorner — Migration 002: login_attempts
--
-- Adds the database-backed failed-login table introduced by decision D-005
-- (NFR-S-06, SEC-13).
--
-- This migration is idempotent: running it against a database that already
-- has the table is a no-op.
--
-- Usage:
--   mysql -u greencorner -p greencorner < database/migrations/002_login_attempts.sql
-- ===========================================================================

USE `greencorner`;

CREATE TABLE IF NOT EXISTS `login_attempts` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `ip_hash`     CHAR(64)     NOT NULL,
  `email_hash`  CHAR(64)     NULL,
  `created_at`  INT UNSIGNED NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_login_attempts_ip_created` (`ip_hash`, `created_at`),
  KEY `idx_login_attempts_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
