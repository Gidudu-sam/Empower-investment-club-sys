-- Stage 12-D — Web Push subscription storage.
--
-- Purely additive: one new table only, no existing table touched (per
-- the brief's own preference for a dedicated table over modifying
-- `notifications`). Idempotent (CREATE TABLE IF NOT EXISTS). No
-- hardcoded `USE` -- the target database is selected by the invoking
-- `mysql` command.
--
-- One user can have many active subscriptions (desktop + phone +
-- installed PWA, etc.) -- user_id is indexed, not unique. `endpoint` is
-- globally unique per the Web Push protocol itself (each browser
-- subscription is its own unique URL), so it is the natural unique key,
-- not (user_id, endpoint) -- this also prevents the same endpoint from
-- ever being silently re-registered under a different user.

CREATE TABLE IF NOT EXISTS `push_subscriptions` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_id` INT UNSIGNED NOT NULL,
  `endpoint` VARCHAR(512) NOT NULL,
  `endpoint_hash` CHAR(64) NOT NULL COMMENT 'SHA-256 of endpoint -- endpoint itself can exceed a practical unique-index prefix length on utf8mb4; the hash is what is actually uniquely indexed.',
  `p256dh_key` VARCHAR(255) NOT NULL COMMENT 'Client public key, base64url -- part of the RFC 8291 encryption handshake, not itself secret.',
  `auth_key` VARCHAR(255) NOT NULL COMMENT 'Client auth secret, base64url -- part of the RFC 8291 encryption handshake.',
  `user_agent` VARCHAR(255) NULL COMMENT 'Informational only (e.g. distinguishing a user''s devices in a future UI) -- never used for any security decision.',
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `last_used_at` TIMESTAMP NULL DEFAULT NULL COMMENT 'Last time a push send was attempted against this subscription.',
  `last_success_at` TIMESTAMP NULL DEFAULT NULL,
  `last_failure_at` TIMESTAMP NULL DEFAULT NULL,
  `last_failure_reason` VARCHAR(255) NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_push_subscription_endpoint` (`endpoint_hash`),
  KEY `idx_push_subscription_user` (`user_id`, `is_active`),
  CONSTRAINT `fk_push_subscription_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
