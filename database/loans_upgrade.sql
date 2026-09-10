-- ============================================================
--  Loan Module Upgrade — Additional tables & columns
--  Safe to re-run (IF NOT EXISTS / ADD COLUMN IF NOT EXISTS)
-- ============================================================
USE `empower_db`;

-- ------------------------------------------------------------
--  Loan Types (configurable by admin)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_types` (
    `id`          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`        VARCHAR(100) NOT NULL,
    `description` TEXT NULL,
    `is_active`   TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `loan_types` (`id`,`name`,`description`,`is_active`) VALUES
(1, 'Normal Loan',          'Standard personal loan for members.', 1),
(2, 'Business Loan',        'Loan for business investment purposes.', 1),
(3, 'Asset Financing Loan', 'Loan for asset acquisition and financing.', 1);

-- ------------------------------------------------------------
--  Add new columns to loans table
-- ------------------------------------------------------------
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `loan_type_id` INT UNSIGNED NULL DEFAULT 1 AFTER `member_id`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `application_date` DATE NULL AFTER `loan_type_id`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `approval_date` DATE NULL AFTER `application_date`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `approved_amount` DECIMAL(15,2) NULL AFTER `loan_amount`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `loan_period_months` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `due_date`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `processing_fee` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `interest_amount`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `monthly_installment` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `processing_fee`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `amount_paid` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `outstanding`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `next_payment_date` DATE NULL AFTER `amount_paid`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `last_payment_date` DATE NULL AFTER `next_payment_date`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `completed_date` DATE NULL AFTER `last_payment_date`;

ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `loan_officer` VARCHAR(150) NULL AFTER `recorded_by`;

-- Update status ENUM to include more options
ALTER TABLE `loans` MODIFY COLUMN `status`
    ENUM('pending','active','completed','overdue','defaulted') NOT NULL DEFAULT 'active';

-- ------------------------------------------------------------
--  Installment Schedule
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_installments` (
    `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id`          INT UNSIGNED NOT NULL,
    `installment_no`   INT UNSIGNED NOT NULL,
    `due_date`         DATE NOT NULL,
    `month_covered`    VARCHAR(20) NULL COMMENT 'e.g. Aug 2026',
    `amount_due`       DECIMAL(15,2) NOT NULL DEFAULT 0,
    `amount_paid`      DECIMAL(15,2) NOT NULL DEFAULT 0,
    `remaining`        DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status`           ENUM('pending','paid','partial','missed','overdue') NOT NULL DEFAULT 'pending',
    `paid_date`        DATE NULL,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_inst_loan` (`loan_id`),
    INDEX `idx_inst_due` (`due_date`),
    CONSTRAINT `fk_inst_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;
