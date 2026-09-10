-- ============================================================
--  Loans Table — run against empower_db AFTER schema.sql
-- ============================================================
USE `empower_db`;

DROP TABLE IF EXISTS `loans`;

CREATE TABLE `loans` (
    `id`               INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    `loan_number`      VARCHAR(20)      NOT NULL UNIQUE       COMMENT 'LNS-000001 …',
    `member_id`        INT UNSIGNED     NOT NULL,
    `loan_amount`      DECIMAL(15,2)    NOT NULL,
    `interest_rate`    DECIMAL(5,2)     NOT NULL DEFAULT 0.00 COMMENT 'Percentage %',
    `interest_amount`  DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
    `total_payable`    DECIMAL(15,2)    NOT NULL,
    `outstanding`      DECIMAL(15,2)    NOT NULL             COMMENT 'Remaining balance',
    `issue_date`       DATE             NOT NULL,
    `due_date`         DATE             NOT NULL,
    `loan_period`      VARCHAR(50)      NULL                  COMMENT 'e.g. 12 months',
    `purpose`          TEXT             NULL,
    `remarks`          TEXT             NULL,
    `status`           ENUM('active','completed','overdue')  NOT NULL DEFAULT 'active',
    `recorded_by`      INT UNSIGNED     NULL,
    `created_at`       TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_loans_member`
        FOREIGN KEY (`member_id`)   REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_loans_recorded`
        FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL,

    INDEX `idx_loans_member` (`member_id`),
    INDEX `idx_loans_status` (`status`),
    INDEX `idx_loans_due`    (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
