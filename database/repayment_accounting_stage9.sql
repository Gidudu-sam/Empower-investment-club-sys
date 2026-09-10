-- ============================================================
--  STAGE 9 — Production Readiness: Accounting & Rate Engine Fixes
--  Safe to re-run (idempotent ADD COLUMN IF NOT EXISTS / guarded INSERT).
--  Adds journal linkage to loan_repayments, closes the missing 5th
--  Normal Loan bracket, and corrects the Business Loan minimum period.
-- ============================================================
USE `empower_db`;

-- ------------------------------------------------------------
-- 1. Journal linkage for repayments (mirrors loans.journal_entry_id /
--    savings.journal_entry_id exactly)
-- ------------------------------------------------------------
ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `journal_entry_id` INT UNSIGNED NULL
    AFTER `received_by`;

-- Add the FK only if it doesn't already exist (MariaDB 10.4 has no
-- "ADD CONSTRAINT IF NOT EXISTS", so guard via information_schema).
SET @fk_exists = (
    SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS
    WHERE CONSTRAINT_SCHEMA = DATABASE()
      AND TABLE_NAME = 'loan_repayments'
      AND CONSTRAINT_NAME = 'fk_repayments_journal_entry'
);
SET @sql = IF(@fk_exists = 0,
    'ALTER TABLE `loan_repayments` ADD CONSTRAINT `fk_repayments_journal_entry` FOREIGN KEY (`journal_entry_id`) REFERENCES `journal_entries` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ------------------------------------------------------------
-- 2. Normal Loan (loan_type_id=1): close Tier 4's unbounded max and
--    add the missing Tier 5 (15.1M+ -> 2%), per the approved 5-tier rule.
-- ------------------------------------------------------------
UPDATE `loan_interest_brackets`
SET `max_amount` = 15000000.00
WHERE `loan_type_id` = 1 AND `bracket_name` = 'Tier 4: >10M';

INSERT INTO `loan_interest_brackets` (`loan_type_id`, `bracket_name`, `min_amount`, `max_amount`, `monthly_rate`, `sort_order`, `is_active`)
SELECT 1, 'Tier 5: >15M', 15100000.00, 0.00, 2.000000, 5, 1
WHERE NOT EXISTS (
    SELECT 1 FROM `loan_interest_brackets` WHERE `loan_type_id` = 1 AND `bracket_name` = 'Tier 5: >15M'
);

-- ------------------------------------------------------------
-- 3. Business Loan (loan_type_id=2): approved minimum period is 6
--    months, not 1.
-- ------------------------------------------------------------
UPDATE `loan_product_rules`
SET `min_period_months` = 6
WHERE `loan_type_id` = 2;
