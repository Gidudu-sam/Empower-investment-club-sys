-- ============================================================
--  Business Boost Loan — Database Extensions
--  Extends loan_installments to support mixed monthly/weekly schedules
--  Safe to re-run
-- ============================================================
USE `empower_db`;

-- Add repayment_method to loans table
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `repayment_method` ENUM('standard','interest_only','business_boost') NOT NULL DEFAULT 'standard'
    AFTER `loan_type_id`;

-- Add interest_only_months for business boost loans
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `interest_only_months` INT UNSIGNED NOT NULL DEFAULT 0
    AFTER `repayment_method`;

-- Add principal_recovery_weeks
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `principal_recovery_weeks` INT UNSIGNED NOT NULL DEFAULT 0
    AFTER `interest_only_months`;

-- Extend loan_installments with principal/interest breakdown and period type
ALTER TABLE `loan_installments` ADD COLUMN IF NOT EXISTS
    `period_type` ENUM('monthly','weekly') NOT NULL DEFAULT 'monthly'
    AFTER `installment_no`;

ALTER TABLE `loan_installments` ADD COLUMN IF NOT EXISTS
    `payment_type` ENUM('interest_only','principal_interest','standard') NOT NULL DEFAULT 'standard'
    AFTER `period_type`;

ALTER TABLE `loan_installments` ADD COLUMN IF NOT EXISTS
    `principal_due` DECIMAL(15,2) NOT NULL DEFAULT 0
    AFTER `payment_type`;

ALTER TABLE `loan_installments` ADD COLUMN IF NOT EXISTS
    `interest_due` DECIMAL(15,2) NOT NULL DEFAULT 0
    AFTER `principal_due`;

ALTER TABLE `loan_installments` ADD COLUMN IF NOT EXISTS
    `principal_paid` DECIMAL(15,2) NOT NULL DEFAULT 0
    AFTER `interest_due`;

ALTER TABLE `loan_installments` ADD COLUMN IF NOT EXISTS
    `interest_paid_amt` DECIMAL(15,2) NOT NULL DEFAULT 0
    AFTER `principal_paid`;

ALTER TABLE `loan_installments` ADD COLUMN IF NOT EXISTS
    `balance_after` DECIMAL(15,2) NOT NULL DEFAULT 0
    AFTER `interest_paid_amt`;

-- Update loan_interest_brackets to match exact policy
DELETE FROM `loan_interest_brackets` WHERE `loan_type_id` IN (1, 2);

INSERT INTO `loan_interest_brackets` (`loan_type_id`,`min_amount`,`max_amount`,`monthly_rate`,`sort_order`) VALUES
(1, 100000,   1000000,  10.00, 1),
(1, 1100000,  5000000,   5.00, 2),
(1, 5100000, 10000000,   4.00, 3),
(1, 10100000,       0,   3.00, 4),
(2, 100000,   1000000,  10.00, 1),
(2, 1100000,  5000000,   5.00, 2),
(2, 5100000, 10000000,   4.00, 3),
(2, 10100000,       0,   3.00, 4)
ON DUPLICATE KEY UPDATE `monthly_rate` = VALUES(`monthly_rate`);
