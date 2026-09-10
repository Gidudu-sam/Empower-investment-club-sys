-- ============================================================
--  STAGE 17 — Fees Accounting Integration
--  Adds payment-method capture and journal linkage to member_fees,
--  mirroring the existing pattern already used by loans/savings/
--  loan_repayments/expenses. No existing row's data is altered.
--  ADD COLUMN IF NOT EXISTS is idempotent; the FK ADD is not (MariaDB
--  10.4 has no ADD CONSTRAINT IF NOT EXISTS) -- if re-running after a
--  partial failure, check SHOW CREATE TABLE member_fees first.
-- ============================================================
USE `empower_db`;

ALTER TABLE `member_fees` ADD COLUMN IF NOT EXISTS
    `payment_method` ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NULL
    AFTER `status`;

ALTER TABLE `member_fees` ADD COLUMN IF NOT EXISTS
    `journal_entry_id` INT UNSIGNED NULL
    AFTER `created_by`;

ALTER TABLE `member_fees` ADD CONSTRAINT `fk_member_fees_journal_entry`
    FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL;
