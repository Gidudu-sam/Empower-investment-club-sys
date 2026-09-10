-- ============================================================
--  STAGE 17 PART E — Withdrawal Transaction Schema
--  Adds the minimum columns required to distinguish annual-
--  compulsory from voluntary withdrawals, trace which savings
--  account was drawn from, and link to the posted journal entry.
--  See results/stage17_withdrawal_evidence/05_withdrawal_schema.txt
--  for field-by-field justification. No existing row is altered.
-- ============================================================
USE `empower_db`;

ALTER TABLE `withdrawals` ADD COLUMN IF NOT EXISTS
    `withdrawal_type` ENUM('annual_compulsory','voluntary') NOT NULL DEFAULT 'annual_compulsory'
    AFTER `financial_year`;

ALTER TABLE `withdrawals` ADD COLUMN IF NOT EXISTS
    `savings_account_id` INT UNSIGNED NULL
    AFTER `member_id`;

ALTER TABLE `withdrawals` ADD COLUMN IF NOT EXISTS
    `journal_entry_id` INT UNSIGNED NULL
    AFTER `processed_by`;

-- Partial-uniqueness technique: MariaDB 10.4 has no native filtered/partial
-- unique index, so a STORED generated column is used instead -- NULL for
-- any non-annual row, which a UNIQUE KEY never treats as a duplicate
-- (standard, well-established MySQL/MariaDB behavior: multiple NULLs in a
-- unique-indexed column do not conflict). This lets 'voluntary' withdrawals
-- repeat freely within a financial year while still enforcing exactly one
-- 'annual_compulsory' withdrawal per member per financial year -- the
-- correct type-scoped replacement for the old blanket uq_member_year.
ALTER TABLE `withdrawals` ADD COLUMN IF NOT EXISTS
    `annual_year_key` INT UNSIGNED
    GENERATED ALWAYS AS (IF(`withdrawal_type` = 'annual_compulsory', `financial_year`, NULL)) STORED
    AFTER `withdrawal_type`;

-- Drop the old blanket (member_id, financial_year) uniqueness and replace
-- with the type-scoped version. Idempotent-guarded: only acts if the old
-- index still exists / the new one doesn't yet.
SET @old_idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND INDEX_NAME = 'uq_member_year'
);
SET @sql = IF(@old_idx_exists > 0, 'ALTER TABLE `withdrawals` DROP INDEX `uq_member_year`', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @new_idx_exists = (
    SELECT COUNT(*) FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND INDEX_NAME = 'uq_member_annual_year'
);
SET @sql = IF(@new_idx_exists = 0, 'ALTER TABLE `withdrawals` ADD UNIQUE KEY `uq_member_annual_year` (`member_id`, `annual_year_key`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_account_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND CONSTRAINT_NAME = 'fk_wd_savings_account'
);
SET @sql = IF(@fk_account_exists = 0, 'ALTER TABLE `withdrawals` ADD CONSTRAINT `fk_wd_savings_account` FOREIGN KEY (`savings_account_id`) REFERENCES `member_savings_accounts` (`id`)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_journal_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'withdrawals' AND CONSTRAINT_NAME = 'fk_wd_journal_entry'
);
SET @sql = IF(@fk_journal_exists = 0, 'ALTER TABLE `withdrawals` ADD CONSTRAINT `fk_wd_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
