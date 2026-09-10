-- ============================================================
--  Loan Engine v3 — Frequency, Interest Mode, Phases
-- ============================================================
USE `empower_db`;

-- Add repayment frequency to loans
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `repayment_frequency` ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly'
    AFTER `repayment_method`;

-- Add interest mode (percentage or fixed)
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `interest_mode` ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage'
    AFTER `repayment_frequency`;

-- Add fixed recurring interest amount (for manual/fixed mode)
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `fixed_interest_amount` DECIMAL(15,2) NOT NULL DEFAULT 0
    AFTER `interest_mode`;

-- Add interest-only period in the loan's own frequency units
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `interest_only_periods` INT UNSIGNED NOT NULL DEFAULT 0
    AFTER `fixed_interest_amount`;

-- Add Old/Migrated loan fields
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `is_migrated` TINYINT(1) NOT NULL DEFAULT 0
    AFTER `interest_only_periods`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `migrated_paid_installments` INT UNSIGNED NOT NULL DEFAULT 0
    AFTER `is_migrated`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `migrated_original_amount` DECIMAL(15,2) NULL
    AFTER `migrated_paid_installments`;

-- Add Old/Migrated Loan type if not exists
INSERT IGNORE INTO `loan_types` (`id`, `name`, `description`, `repayment_type`, `is_active`) VALUES
(5, 'Old/Migrated Loan', 'Loans issued before the system was implemented.', 'installment', 1);
