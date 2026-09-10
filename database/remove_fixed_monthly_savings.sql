-- Remove Fixed Monthly Savings (2026-09) -- full purge per explicit
-- user decision. Symmetric reversal of database/fixed_monthly_savings.sql.
--
-- Confirmed by direct forensic query before writing this file: the one
-- real account (FM-000001, id=3140, member SSALI FRANK/EMP0001, opened
-- 2026-09-02) has ZERO rows in `savings` and therefore zero linked
-- journal_entries/journal_lines -- deleting it does not orphan any
-- accounting record or create a GL imbalance.
--
-- Run this against ONE database at a time -- never hardcode `USE`, per
-- this project's own standing migration rule (a past incident where a
-- leftover USE line created an empty table in production).

-- 1. Delete the real account's holder row, then the account itself.
--    Order matters: savings_account_holders has no ON DELETE CASCADE
--    from member_savings_accounts in this schema, so the holder row
--    must go first or it would be left pointing at a deleted account.
DELETE FROM `savings_account_holders` WHERE `account_id` = 3140;
DELETE FROM `member_savings_accounts` WHERE `id` = 3140 AND `account_type` = 'fixed_monthly';

-- 2. Drop the DB-level concurrency guard before dropping the column it
--    indexes.
ALTER TABLE `savings` DROP INDEX `uq_savings_account_period`;

-- 3. Drop the two additive columns fixed_monthly_savings.sql introduced.
ALTER TABLE `savings` DROP COLUMN `deposit_period`;
ALTER TABLE `member_savings_accounts` DROP COLUMN `monthly_contribution`;

-- 4. Remove the enum value -- safe now that no row references it (the
--    DELETE above already confirmed zero remaining fixed_monthly rows).
ALTER TABLE `member_savings_accounts`
  MODIFY COLUMN `account_type` ENUM('compulsory','voluntary','joint','corporate','fixed_deposit') NOT NULL;

-- 5. Remove the now-unused FM- account-number sequence row.
DELETE FROM `journal_number_sequences` WHERE `prefix` = 'FM';
