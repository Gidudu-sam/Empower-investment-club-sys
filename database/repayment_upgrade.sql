-- ============================================================
--  Loan Repayment Upgrade — Multi-product payment support
--  Safe to re-run (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- ============================================================
USE `empower_db`;

-- Add payment_type to loan_repayments
ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `payment_type` ENUM('installment','interest','weekly_savings','principal','settlement') NOT NULL DEFAULT 'installment'
    AFTER `repayment_number`;

ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `loan_type_id` INT UNSIGNED NULL AFTER `payment_type`;

ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `principal_paid` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `amount_paid`;

ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `interest_paid` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `principal_paid`;

ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `savings_paid` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `interest_paid`;

ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `penalty_paid` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `savings_paid`;

ALTER TABLE `loan_repayments` ADD COLUMN IF NOT EXISTS
    `week_covered` VARCHAR(30) NULL AFTER `penalty_paid`;

-- Business Loan Weekly Savings Tracking
CREATE TABLE IF NOT EXISTS `business_loan_weekly_savings` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id`     INT UNSIGNED NOT NULL,
    `member_id`   INT UNSIGNED NOT NULL,
    `week_start`  DATE NOT NULL,
    `week_end`    DATE NOT NULL,
    `amount_due`  DECIMAL(15,2) NOT NULL DEFAULT 0,
    `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `paid_date`   DATE NULL,
    `status`      ENUM('pending','paid','partial','missed') NOT NULL DEFAULT 'pending',
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_blws_loan` (`loan_id`),
    INDEX `idx_blws_member` (`member_id`),
    CONSTRAINT `fk_blws_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_blws_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Add business loan fields to loans table
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `business_name` VARCHAR(200) NULL AFTER `loan_officer`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `business_type` VARCHAR(100) NULL AFTER `business_name`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `business_location` VARCHAR(200) NULL AFTER `business_type`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `weekly_savings_amount` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `business_location`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `guarantor_name` VARCHAR(150) NULL AFTER `weekly_savings_amount`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `guarantor_phone` VARCHAR(20) NULL AFTER `guarantor_name`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `interest_paid_total` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `amount_paid`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `disbursement_date` DATE NULL AFTER `approval_date`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `disbursement_method` VARCHAR(50) NULL AFTER `disbursement_date`;
