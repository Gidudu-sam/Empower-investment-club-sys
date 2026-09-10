-- ============================================================
--  Loan Engine v2 — Interest Brackets + Business Loan Tracking
--  Safe to re-run
-- ============================================================
USE `empower_db`;

-- ------------------------------------------------------------
--  Interest Brackets (configurable per loan product)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_interest_brackets` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_type_id`  INT UNSIGNED NOT NULL,
    `min_amount`    DECIMAL(15,2) NOT NULL DEFAULT 0,
    `max_amount`    DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '0 = no upper limit',
    `monthly_rate`  DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `sort_order`    INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX `idx_lib_type` (`loan_type_id`),
    CONSTRAINT `fk_lib_type` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed Normal Loan brackets
INSERT IGNORE INTO `loan_interest_brackets` (`loan_type_id`,`min_amount`,`max_amount`,`monthly_rate`,`sort_order`) VALUES
(1, 100000,   1000000,  10.00, 1),
(1, 1100000,  5000000,   5.00, 2),
(1, 5100000, 10000000,   4.00, 3),
(1, 10100000,       0,   3.00, 4);

-- Seed Business Loan brackets (same as Normal)
INSERT IGNORE INTO `loan_interest_brackets` (`loan_type_id`,`min_amount`,`max_amount`,`monthly_rate`,`sort_order`) VALUES
(2, 100000,   1000000,  10.00, 1),
(2, 1100000,  5000000,   5.00, 2),
(2, 5100000, 10000000,   4.00, 3),
(2, 10100000,       0,   3.00, 4);

-- Asset Financing: flat 3% (single bracket, no range check)
INSERT IGNORE INTO `loan_interest_brackets` (`loan_type_id`,`min_amount`,`max_amount`,`monthly_rate`,`sort_order`) VALUES
(3, 0, 0, 3.00, 1);

-- ------------------------------------------------------------
--  Business Loan Monthly Interest Payments
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `business_loan_interest_payments` (
    `id`            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id`       INT UNSIGNED NOT NULL,
    `member_id`     INT UNSIGNED NOT NULL,
    `month_covered` VARCHAR(20) NOT NULL COMMENT 'e.g. Aug 2026',
    `due_date`      DATE NOT NULL,
    `interest_due`  DECIMAL(15,2) NOT NULL DEFAULT 0,
    `interest_paid` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `paid_date`     DATE NULL,
    `status`        ENUM('pending','paid','partial','overdue') NOT NULL DEFAULT 'pending',
    `created_at`    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_blip_loan` (`loan_id`),
    INDEX `idx_blip_member` (`member_id`),
    CONSTRAINT `fk_blip_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_blip_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Update loan_product_settings with correct values
UPDATE `loan_product_settings` SET 
    `monthly_interest_rate` = 10.00, 
    `processing_fee_pct` = 3.00,
    `min_amount` = 100000,
    `max_amount` = 10000000,
    `min_period_months` = 1,
    `max_period_months` = 12
WHERE `loan_type_id` = 1;

UPDATE `loan_product_settings` SET 
    `monthly_interest_rate` = 10.00, 
    `processing_fee_pct` = 3.00,
    `min_amount` = 100000,
    `max_amount` = 10000000,
    `min_period_months` = 1,
    `max_period_months` = 12
WHERE `loan_type_id` = 2;

UPDATE `loan_product_settings` SET 
    `monthly_interest_rate` = 3.00, 
    `processing_fee_pct` = 3.00,
    `min_amount` = 500000,
    `max_amount` = 20000000,
    `min_period_months` = 1,
    `max_period_months` = 24
WHERE `loan_type_id` = 3;

-- Add repayment_type to loan_types
ALTER TABLE `loan_types` ADD COLUMN IF NOT EXISTS
    `repayment_type` ENUM('installment','interest_only') NOT NULL DEFAULT 'installment'
    AFTER `description`;

UPDATE `loan_types` SET `repayment_type`='installment' WHERE `id`=1;
UPDATE `loan_types` SET `repayment_type`='interest_only' WHERE `id`=2;
UPDATE `loan_types` SET `repayment_type`='installment' WHERE `id`=3;
