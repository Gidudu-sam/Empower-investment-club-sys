-- ============================================================
--  Internal Voucher — Member Subledger (Savings only, this phase)
--  Engine: InnoDB   Charset: utf8mb4
--
--  Purely additive. No existing column, row, or FK is altered or
--  dropped. Every existing internal_vouchers row gets member_id,
--  savings_account_id, savings_id, balance_before, balance_after
--  = NULL (new nullable columns, no backfill) -- existing plain
--  GL-to-GL vouchers are byte-identical after this migration.
--  Every existing accounts row gets requires_subledger=0 via
--  DEFAULT -- only account id=17 (code 2020, Members' Savings) is
--  explicitly flagged below.
--
--  Mirrors database/member_account_adjustments_module.sql's FK/
--  index/comment conventions.
--
--  NOTE: deliberately no USE statement here -- the caller's CLI/
--  connection database selection is authoritative. A stray USE line
--  in an earlier draft of this file silently redirected this exact
--  migration into production ahead of testing (2026-09-01); verified
--  harmless afterward (purely additive, zero existing rows touched),
--  but the line is removed so it can never happen again.
-- ============================================================

ALTER TABLE `accounts`
  ADD COLUMN `requires_subledger` TINYINT(1) NOT NULL DEFAULT 0
      COMMENT 'Whether posting to this account requires selecting a specific member + subledger account (Internal Voucher Member Subledger feature)'
      AFTER `is_active`,
  ADD COLUMN `subledger_type` VARCHAR(20) NULL
      COMMENT 'Which subledger backs this account when requires_subledger=1, e.g. "savings". NULL when requires_subledger=0. Only "savings" is implemented; loans/shares have no subledger wiring.'
      AFTER `requires_subledger`;

-- Flag only Members' Savings (id=17, code 2020) -- savings-only this phase.
UPDATE `accounts` SET `requires_subledger` = 1, `subledger_type` = 'savings' WHERE `id` = 17;

ALTER TABLE `internal_vouchers`
  ADD COLUMN `member_id` INT UNSIGNED NULL
      COMMENT 'Set only when primary_account_id.requires_subledger=1 (Savings only, this phase)'
      AFTER `contra_account_id`,
  ADD COLUMN `savings_account_id` INT UNSIGNED NULL
      COMMENT 'member_savings_accounts.id this voucher posts against, when member_id is set'
      AFTER `member_id`,
  ADD COLUMN `savings_id` INT UNSIGNED NULL
      COMMENT 'The savings-table row this voucher created at posting time (member subsidiary ledger side of the dual-write)'
      AFTER `journal_entry_id`,
  ADD COLUMN `balance_before` DECIMAL(15,2) NULL
      COMMENT 'Member savings account balance snapshot immediately before posting'
      AFTER `savings_id`,
  ADD COLUMN `balance_after` DECIMAL(15,2) NULL
      COMMENT 'Member savings account balance snapshot immediately after posting'
      AFTER `balance_before`,
  ADD KEY `idx_voucher_member` (`member_id`),
  ADD KEY `idx_voucher_savings_account` (`savings_account_id`),
  ADD CONSTRAINT `fk_voucher_member` FOREIGN KEY (`member_id`) REFERENCES `members` (`id`),
  ADD CONSTRAINT `fk_voucher_savings_account` FOREIGN KEY (`savings_account_id`) REFERENCES `member_savings_accounts` (`id`),
  ADD CONSTRAINT `fk_voucher_savings` FOREIGN KEY (`savings_id`) REFERENCES `savings` (`id`) ON DELETE SET NULL;
