-- Fixed Deposit Maturity, Payout & Closure Governance (Stage FD-2)
-- Purely additive: one new table. No existing table is altered.
--
-- Apply with the target database selected explicitly, e.g.:
--   mysql -u root -D empower_db < database/fixed_deposit_closure.sql
-- Deliberately no `USE` statement.

CREATE TABLE IF NOT EXISTS `fixed_deposit_closure_requests` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `savings_account_id` INT UNSIGNED NOT NULL COMMENT 'The Fixed Deposit account (member_savings_accounts.account_type=fixed_deposit)',
  `member_id` INT UNSIGNED NOT NULL,

  -- Snapshotted from the FD account at request time -- the account's own
  -- locked terms, never recalculated from a later default rate or any
  -- other source. This is what a payout is always based on.
  `principal_amount` DECIMAL(15,2) NOT NULL,
  `interest_rate` DECIMAL(10,6) NOT NULL,
  `expected_interest` DECIMAL(15,2) NOT NULL,
  `payout_amount` DECIMAL(15,2) NOT NULL COMMENT 'principal_amount + expected_interest, computed once at request time',

  `status` ENUM('pending','approved','rejected','paid','cancelled') NOT NULL DEFAULT 'pending',
  -- NULL-safe partial-uniqueness (same technique already proven by
  -- savings.uq_savings_account_period and savings.uq_fixed_deposit_principal):
  -- 1 while pending/approved, NULL once rejected/cancelled/paid -- so a new
  -- request becomes possible after a rejection, but two requests can never
  -- be simultaneously live for the same account.
  `active_marker` TINYINT(1) NULL,

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

  `notes` TEXT NULL,
  `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_fd_active_request` (`savings_account_id`, `active_marker`),
  KEY `idx_fd_closure_status` (`status`),
  KEY `idx_fd_closure_member` (`member_id`),
  KEY `fk_fd_closure_requested_by` (`requested_by`),
  KEY `fk_fd_closure_approved_by` (`approved_by`),
  KEY `fk_fd_closure_rejected_by` (`rejected_by`),
  KEY `fk_fd_closure_cancelled_by` (`cancelled_by`),
  KEY `fk_fd_closure_paid_by` (`paid_by`),
  KEY `fk_fd_closure_journal_entry` (`journal_entry_id`),

  CONSTRAINT `fk_fd_closure_savings_account` FOREIGN KEY (`savings_account_id`) REFERENCES `member_savings_accounts` (`id`),
  CONSTRAINT `fk_fd_closure_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  CONSTRAINT `fk_fd_closure_requested_by` FOREIGN KEY (`requested_by`) REFERENCES `users` (`id`),
  CONSTRAINT `fk_fd_closure_approved_by` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fd_closure_rejected_by` FOREIGN KEY (`rejected_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fd_closure_cancelled_by` FOREIGN KEY (`cancelled_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fd_closure_paid_by` FOREIGN KEY (`paid_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_fd_closure_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
