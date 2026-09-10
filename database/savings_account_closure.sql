-- Stage 11 — Universal Savings Account Closure Framework (2026-09)
--
-- Purely additive: one new table only. Fixed Deposit closure keeps using
-- its own certified `fixed_deposit_closure_requests` table/service
-- (Stage FD-2) completely unchanged -- this table covers the other five
-- account types (compulsory, voluntary, joint, corporate, fixed_monthly).
-- No existing table is altered, no existing row is touched.

CREATE TABLE IF NOT EXISTS `savings_account_closure_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `savings_account_id` INT UNSIGNED NOT NULL,
  `account_type` ENUM('compulsory','voluntary','joint','corporate','fixed_monthly') NOT NULL,
  `member_id` INT UNSIGNED NOT NULL COMMENT 'Holder (or, for corporate, the authorized representative''s linked member) the settlement is attributed to.',
  `reason` VARCHAR(255) NULL,
  `settlement_required` TINYINT(1) NOT NULL DEFAULT 0,
  `settlement_amount` DECIMAL(15,2) NULL COMMENT 'Balance as of request time, for display only -- the amount actually paid is always recalculated fresh at settlement time.',
  `status` ENUM('pending','approved','rejected','cancelled','paid') NOT NULL DEFAULT 'pending',
  `active_marker` TINYINT(1) NULL COMMENT '1 while pending/approved, NULL once rejected/cancelled/paid -- allows a new request after a rejection without ever allowing two simultaneously-live requests.',
  `requested_by` INT UNSIGNED NOT NULL,
  `requested_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `approved_by` INT UNSIGNED NULL,
  `approved_at` TIMESTAMP NULL DEFAULT NULL,
  `rejected_by` INT UNSIGNED NULL,
  `rejected_at` TIMESTAMP NULL DEFAULT NULL,
  `rejection_reason` TEXT NULL,
  `cancelled_by` INT UNSIGNED NULL,
  `cancelled_at` TIMESTAMP NULL DEFAULT NULL,
  `payment_method` ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NULL,
  `payment_reference` VARCHAR(100) NULL,
  `amount_paid` DECIMAL(15,2) NULL,
  `paid_by` INT UNSIGNED NULL,
  `paid_at` TIMESTAMP NULL DEFAULT NULL,
  `journal_entry_id` INT UNSIGNED NULL,
  `closed_at` TIMESTAMP NULL DEFAULT NULL,
  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sacr_active_request` (`savings_account_id`, `active_marker`),
  KEY `idx_sacr_account` (`savings_account_id`),
  KEY `idx_sacr_status` (`status`),
  KEY `idx_sacr_requested_by` (`requested_by`),
  CONSTRAINT `fk_sacr_account` FOREIGN KEY (`savings_account_id`) REFERENCES `member_savings_accounts`(`id`),
  CONSTRAINT `fk_sacr_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`),
  CONSTRAINT `fk_sacr_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_sacr_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_sacr_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_sacr_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_sacr_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `users`(`id`),
  CONSTRAINT `fk_sacr_journal` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
