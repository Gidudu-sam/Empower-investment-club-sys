-- ============================================================
--  STAGE 17 PART C — Penalty Collection: minimal schema change
--  Adds a single column to loan_penalties to represent partial
--  settlement, mirroring the existing amount_due/amount_paid
--  pattern already used by loan_installments. See
--  results/stage17_penalty_evidence/04_repayment_allocation_analysis.txt
--  for the full decision record. No other schema change is made --
--  loan_repayments.penalty_paid/journal_entry_id already exist and
--  are reused as-is.
-- ============================================================
USE `empower_db`;

ALTER TABLE `loan_penalties` ADD COLUMN IF NOT EXISTS
    `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0.00
    AFTER `penalty_amount`;
