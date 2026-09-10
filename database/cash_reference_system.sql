-- Cash Reference Number system (2026-09). Additive, fully reversible.
-- Adds a NEW, permanent identifier populated ONLY for payment_method =
-- 'Cash', alongside the existing internal transaction numbers (SAV-,
-- PAY-, FEE-, EXP-, OI-) and the existing free-text external payment
-- reference field. Neither existing identifier is touched, renamed, or
-- replaced. No historical row is backfilled -- every pre-existing
-- transaction keeps cash_reference_number = NULL forever.
--
-- Sequence mechanism: reuses the already-proven, row-locked
-- `journal_number_sequences` + `SELECT ... FOR UPDATE` pattern already in
-- live use for 14 other prefixes (JE, IV, EXP, OI, RB, ADJ, OB, INV,
-- INVTX, CS, VS, JS, CORP, FD) -- no new sequence table, no MAX()+1.
--
-- Run this against ONE database at a time -- never hardcode `USE`, per
-- this project's own standing migration rule.

-- 1. New nullable, UNIQUE-per-table column on every Cash-enabled
--    transaction table in scope for this stage. UNIQUE indexes in
--    MySQL/MariaDB permit multiple NULLs, so non-Cash rows are
--    unaffected.
ALTER TABLE `savings`
  ADD COLUMN `cash_reference_number` VARCHAR(20) NULL AFTER `reference_number`,
  ADD UNIQUE KEY `uq_savings_cash_reference` (`cash_reference_number`);

ALTER TABLE `loan_repayments`
  ADD COLUMN `cash_reference_number` VARCHAR(20) NULL AFTER `reference_number`,
  ADD UNIQUE KEY `uq_repayments_cash_reference` (`cash_reference_number`);

ALTER TABLE `member_fees`
  ADD COLUMN `cash_reference_number` VARCHAR(20) NULL AFTER `reference_number`,
  ADD UNIQUE KEY `uq_fees_cash_reference` (`cash_reference_number`);

ALTER TABLE `expenses`
  ADD COLUMN `cash_reference_number` VARCHAR(20) NULL AFTER `reference_number`,
  ADD UNIQUE KEY `uq_expenses_cash_reference` (`cash_reference_number`);

ALTER TABLE `other_income_transactions`
  ADD COLUMN `cash_reference_number` VARCHAR(20) NULL AFTER `reference_number`,
  ADD UNIQUE KEY `uq_oi_cash_reference` (`cash_reference_number`);

-- 2. Register the six approved Cash Reference prefixes. Each starts at
--    0, so the first generated value is CHS-000001 / CHR-000001 / etc.
--    CHW is deliberately NOT created (approved decision: do not
--    implement Cash Withdrawal as a separate prefix at this stage).
INSERT INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES
  ('CHS', 0),
  ('CHR', 0),
  ('CHA', 0),
  ('CHL', 0),
  ('CHE', 0),
  ('CHO', 0)
ON DUPLICATE KEY UPDATE `prefix` = `prefix`;

-- 3. Harden the pre-existing FEE- internal transaction number, per the
--    approved item 10: FeeModel::generateReference() is being migrated
--    from an unsafe MAX()+1 read (no row lock, no DB uniqueness at all)
--    onto the same safe journal_number_sequences + FOR UPDATE mechanism.
--    Seeded to the CURRENT maximum existing FEE- number (confirmed via
--    direct query before writing this migration), never to 0 -- seeding
--    to 0 would immediately collide with the 48 existing FEE-000001..048
--    rows already in production. The exact seed value is computed live
--    below rather than hardcoded, so this migration is correct no matter
--    which database it runs against (isolated clone today, production
--    later), and a genuine UNIQUE constraint is added as the permanent,
--    database-level backstop this field never had before.
SET @fee_max = (SELECT COALESCE(MAX(CAST(SUBSTRING(reference_number, 5) AS UNSIGNED)), 0) FROM `member_fees`);
INSERT INTO `journal_number_sequences` (`prefix`, `last_number`) VALUES ('FEE', @fee_max)
ON DUPLICATE KEY UPDATE `last_number` = GREATEST(`last_number`, @fee_max);

ALTER TABLE `member_fees`
  ADD UNIQUE KEY `uq_fees_reference_number` (`reference_number`);
