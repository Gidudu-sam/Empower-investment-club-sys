-- ============================================================
--  Fees & Charges Module — Database Tables
--  Engine: MySQL 8+   Charset: utf8mb4
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
--  Fees Configuration Table
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fees` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_name`       VARCHAR(150) NOT NULL,
    `fee_type`       ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    `amount`         DECIMAL(15,2) NOT NULL DEFAULT 0,
    `frequency`      ENUM('one_time','annual','per_loan','monthly') NOT NULL DEFAULT 'one_time',
    `description`    TEXT NULL,
    `effective_date`  DATE NULL,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Default fees
INSERT IGNORE INTO `fees` (`id`, `fee_name`, `fee_type`, `amount`, `frequency`, `description`, `effective_date`, `is_active`) VALUES
(1, 'Registration Fee',        'fixed',      50000.00, 'one_time', 'Charged once when a new member is registered.', CURDATE(), 1),
(2, 'Annual Subscription Fee', 'fixed',      30000.00, 'annual',   'Charged once every active financial year.',      CURDATE(), 1),
(3, 'Loan Processing Fee',     'percentage',     3.00, 'per_loan', 'Calculated as a percentage of the approved loan amount.', CURDATE(), 1);

-- ------------------------------------------------------------
--  Member Fees (Charges Ledger)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `member_fees` (
    `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id`        INT UNSIGNED NOT NULL,
    `fee_id`           INT UNSIGNED NOT NULL,
    `amount`           DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status`           ENUM('pending','paid','waived','cancelled') NOT NULL DEFAULT 'pending',
    `reference_number` VARCHAR(30) NULL,
    `financial_year_id` INT UNSIGNED NULL,
    `loan_id`          INT UNSIGNED NULL COMMENT 'For loan processing fees',
    `charged_date`     DATE NOT NULL,
    `paid_date`        DATE NULL,
    `created_by`       INT UNSIGNED NULL,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mf_member` (`member_id`),
    INDEX `idx_mf_fee` (`fee_id`),
    INDEX `idx_mf_status` (`status`),
    CONSTRAINT `fk_mf_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mf_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees`(`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_mf_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Fee History (Audit of fee config changes)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `fee_history` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_id`      INT UNSIGNED NOT NULL,
    `old_amount`  DECIMAL(15,2) NULL,
    `new_amount`  DECIMAL(15,2) NULL,
    `changed_by`  INT UNSIGNED NULL,
    `changed_at`  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `remarks`     VARCHAR(255) NULL,
    CONSTRAINT `fk_fh_fee` FOREIGN KEY (`fee_id`) REFERENCES `fees`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_fh_user` FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;
