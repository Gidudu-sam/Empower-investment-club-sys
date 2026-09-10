-- Fixed Deposit Account (2026-09)
-- Bank-style lump-sum term deposit -- a new sibling account type, entirely
-- additive. Does NOT touch, rename, or migrate the existing 'fixed_monthly'
-- account type or its one production account (FM-000001).
--
-- Apply with the target database selected explicitly, e.g.:
--   mysql -u root -D empower_db < database/fixed_deposit_accounts.sql
-- Deliberately no `USE` statement.

-- 1. New account type + new terminal status. Existing enum values are
--    preserved verbatim -- this only appends new values to each set.
ALTER TABLE `member_savings_accounts`
  MODIFY COLUMN `account_type` ENUM('compulsory','voluntary','joint','corporate','fixed_monthly','fixed_deposit') NOT NULL,
  MODIFY COLUMN `status` ENUM('active','dormant','closed','matured') NOT NULL DEFAULT 'active';

-- 2. Fixed Deposit metadata. NULL for every existing row and every
--    non-fixed_deposit account going forward -- zero impact on
--    fixed_monthly/compulsory/voluntary/joint/corporate.
ALTER TABLE `member_savings_accounts`
  ADD COLUMN `principal_amount` DECIMAL(15,2) NULL
      COMMENT 'Fixed Deposit only: the original lump-sum principal. NULL for every other account type.'
      AFTER `monthly_contribution`,
  ADD COLUMN `deposit_date` DATE NULL
      COMMENT 'Fixed Deposit only: the date the lump sum was deposited.'
      AFTER `principal_amount`,
  ADD COLUMN `term_months` INT UNSIGNED NULL
      COMMENT 'Fixed Deposit only: the agreed term in months (3/6/12/18/24 today; the set is a PHP-side allow-list, not a DB constraint, so more can be added later without a migration).'
      AFTER `deposit_date`,
  ADD COLUMN `maturity_date` DATE NULL
      COMMENT 'Fixed Deposit only: deposit_date + term_months, calculated and stored once at opening.'
      AFTER `term_months`,
  ADD COLUMN `interest_rate` DECIMAL(10,6) NULL
      COMMENT 'Fixed Deposit only: the agreed ANNUAL rate (%), locked at opening. Same precision as loans.interest_rate for consistency. Changing the default rate setting never alters an existing account''s stored rate.'
      AFTER `maturity_date`,
  ADD COLUMN `expected_interest` DECIMAL(15,2) NULL
      COMMENT 'Fixed Deposit only: principal x rate x (term_months/12), simple interest, computed once at opening.'
      AFTER `interest_rate`,
  ADD COLUMN `expected_maturity_amount` DECIMAL(15,2) NULL
      COMMENT 'Fixed Deposit only: principal_amount + expected_interest.'
      AFTER `expected_interest`;

-- 3. DB-level backstop against a second deposit on a Fixed Deposit account
--    (app-level validation is the primary guard; this is the concurrency/
--    defense-in-depth backstop, same NULL-safe-unique-index technique
--    already proven by fixed_monthly's uq_savings_account_period). NULL on
--    every row except the one opening-deposit row per Fixed Deposit
--    account, which is set to 1 -- every other transaction, on every other
--    account type, is completely unaffected since MySQL never treats NULLs
--    as colliding in a UNIQUE index.
ALTER TABLE `savings`
  ADD COLUMN `is_fixed_deposit_principal` TINYINT(1) NULL
      COMMENT 'Set to 1 only on a Fixed Deposit account''s one allowed opening-deposit row. NULL for every other transaction.'
      AFTER `deposit_period`,
  ADD UNIQUE KEY `uq_fixed_deposit_principal` (`savings_account_id`, `is_fixed_deposit_principal`);

-- 4. Account-number sequence row for the new FD prefix.
INSERT INTO `journal_number_sequences` (`prefix`, `last_number`)
VALUES ('FD', 0)
ON DUPLICATE KEY UPDATE `prefix` = `prefix`;

-- 5. Default annual rate, in the existing generic key-value settings
--    table (not a new config mechanism). This is a SUGGESTION only, shown
--    on the opening form and editable per account; the rate actually
--    agreed is what gets stored on the account row above, permanently.
INSERT INTO `settings` (`setting_key`, `setting_val`, `label`)
VALUES ('fixed_deposit_default_rate_pa', '10', 'Fixed Deposit Default Annual Interest Rate (%)')
ON DUPLICATE KEY UPDATE `setting_key` = `setting_key`;
