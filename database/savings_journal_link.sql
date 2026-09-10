-- ============================================================
--  Savings — Journal Link (Step 8)
--  Engine: InnoDB   Charset: utf8mb4
--  Safe to re-run (ADD COLUMN IF NOT EXISTS)
--
--  Purely additive: links a savings row to the journal entry it posted
--  as, matching the identical pattern already used for
--  opening_balance_batches.journal_entry_id and expenses.journal_entry_id.
--  No existing savings row's data (debit/credit/running_balance/etc.) is
--  touched by this migration.
-- ============================================================

USE `empower_db`;

ALTER TABLE `savings` ADD COLUMN IF NOT EXISTS
    `journal_entry_id` INT UNSIGNED NULL AFTER `authorized_by`;

ALTER TABLE `savings` ADD CONSTRAINT
    `fk_savings_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL;
