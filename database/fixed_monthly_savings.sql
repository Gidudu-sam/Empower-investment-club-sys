-- Fixed Monthly Savings (2026-09)
-- Purely additive: new enum value, two new nullable columns, one new
-- composite unique index that only ever constrains rows that explicitly
-- opt into it (deposit_period IS NULL for every existing row and every
-- non-fixed-monthly transaction going forward -- MySQL treats each NULL
-- as distinct in a UNIQUE index, so they never collide with each other).
-- No existing row is modified, no table is dropped or recreated.

USE `empower_db`;

-- 1. New account type. Existing enum values are preserved verbatim --
--    this only appends 'fixed_monthly' to the allowed set.
ALTER TABLE `member_savings_accounts`
  MODIFY COLUMN `account_type` ENUM('compulsory','voluntary','joint','corporate','fixed_monthly') NOT NULL;

-- 2. Agreed monthly contribution/minimum. NULL for every account type
--    except fixed_monthly (enforced in PHP, not by a CHECK constraint,
--    matching this schema's existing convention of app-level enum/rule
--    enforcement rather than DB constraints for business rules).
ALTER TABLE `member_savings_accounts`
  ADD COLUMN `monthly_contribution` DECIMAL(15,2) NULL
      COMMENT 'Agreed minimum monthly deposit for fixed_monthly accounts. NULL for every other account type.'
      AFTER `ownership_type`;

-- 3. Calendar-month tag for a fixed-monthly deposit ('YYYY-MM'), NULL for
--    every other transaction (deposits on other account types,
--    withdrawals, adjustments, opening balances, legacy rows).
ALTER TABLE `savings`
  ADD COLUMN `deposit_period` VARCHAR(7) NULL
      COMMENT 'YYYY-MM tag set only on a fixed_monthly deposit row -- backs the one-deposit-per-calendar-month DB constraint below. NULL for every other transaction.'
      AFTER `transaction_date`;

-- 4. The DB-level concurrency guard requested in the spec: two
--    simultaneous requests cannot both insert a fixed-monthly deposit
--    for the same account+month. Rows with deposit_period IS NULL
--    (every pre-existing row, and every non-fixed-monthly transaction
--    from today onward) are never constrained by this index.
ALTER TABLE `savings`
  ADD UNIQUE KEY `uq_savings_account_period` (`savings_account_id`, `deposit_period`);

-- 5. Account-number sequence row for the new FM- prefix. Every existing
--    account-type prefix (CS/VS/JS/CORP) has its own row here --
--    MemberSavingsAccountModel::nextAccountNumber() throws if the row is
--    missing, so this is required, not optional.
INSERT INTO `journal_number_sequences` (`prefix`, `last_number`)
VALUES ('FM', 0)
ON DUPLICATE KEY UPDATE `prefix` = `prefix`;
