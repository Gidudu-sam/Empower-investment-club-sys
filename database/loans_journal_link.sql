-- ============================================================
--  Loans — Journal Link (Step 9)
--  Engine: InnoDB   Charset: utf8mb4
--  Safe to re-run (ADD COLUMN IF NOT EXISTS)
--
--  Purely additive: links a loan row to the journal entry its
--  disbursement posted as, same pattern already used for
--  opening_balance_batches/expenses/savings.journal_entry_id.
--  No existing loan row's data is touched by this migration.
-- ============================================================

USE `empower_db`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `journal_entry_id` INT UNSIGNED NULL AFTER `disbursement_method`;

ALTER TABLE `loans` ADD CONSTRAINT
    `fk_loans_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries`(`id`) ON DELETE SET NULL;
