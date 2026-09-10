-- ============================================================
--  Loan Products Enhancement — Configurable Settings + Penalties
--  Safe to re-run (IF NOT EXISTS)
-- ============================================================
USE `empower_db`;

-- ------------------------------------------------------------
--  Loan Product Settings (per loan type configuration)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_product_settings` (
    `id`                  INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_type_id`        INT UNSIGNED NOT NULL,
    `min_amount`          DECIMAL(15,2) NOT NULL DEFAULT 100000,
    `max_amount`          DECIMAL(15,2) NOT NULL DEFAULT 10000000,
    `min_period_months`   INT UNSIGNED NOT NULL DEFAULT 1,
    `max_period_months`   INT UNSIGNED NOT NULL DEFAULT 12,
    `monthly_interest_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `processing_fee_pct`  DECIMAL(5,2) NOT NULL DEFAULT 3.00,
    `grace_period_days`   INT UNSIGNED NOT NULL DEFAULT 0,
    `penalty_rate_per_day` DECIMAL(5,4) NOT NULL DEFAULT 0.2500 COMMENT '0.25% per day',
    `is_active`           TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`          TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_lps_type` (`loan_type_id`),
    CONSTRAINT `fk_lps_type` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Seed default settings for existing loan types
INSERT IGNORE INTO `loan_product_settings` (`loan_type_id`,`min_amount`,`max_amount`,`min_period_months`,`max_period_months`,`monthly_interest_rate`,`processing_fee_pct`,`grace_period_days`,`penalty_rate_per_day`) VALUES
(1, 100000, 1000000,  1, 6,  10.00, 3.00, 0, 0.2500),
(2, 500000, 5000000,  1, 12,  5.00, 3.00, 7, 0.2500),
(3, 1000000, 10000000, 3, 24,  5.00, 3.00, 14, 0.2500);

-- ------------------------------------------------------------
--  Loan Penalties
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `loan_penalties` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id`         INT UNSIGNED NOT NULL,
    `installment_id`  BIGINT UNSIGNED NULL,
    `member_id`       INT UNSIGNED NOT NULL,
    `days_overdue`    INT UNSIGNED NOT NULL DEFAULT 0,
    `base_amount`     DECIMAL(15,2) NOT NULL COMMENT 'Installment amount penalty is calculated on',
    `penalty_rate`    DECIMAL(5,4) NOT NULL DEFAULT 0.2500,
    `penalty_amount`  DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status`          ENUM('accruing','paid','waived') NOT NULL DEFAULT 'accruing',
    `calculated_date` DATE NOT NULL,
    `paid_date`       DATE NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lp_loan` (`loan_id`),
    INDEX `idx_lp_member` (`member_id`),
    INDEX `idx_lp_status` (`status`),
    CONSTRAINT `fk_lp_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_lp_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Add penalty_accrued to loans table
ALTER TABLE `loans` ADD COLUMN IF NOT EXISTS
    `penalty_accrued` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `amount_paid`;
