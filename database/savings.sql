-- ============================================================
--  Savings Table — run against empower_db AFTER schema.sql
-- ============================================================
USE `empower_db`;

DROP TABLE IF EXISTS `savings`;

CREATE TABLE `savings` (
    `id`               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `member_id`        INT UNSIGNED    NOT NULL,
    `receipt_number`   VARCHAR(20)     NOT NULL UNIQUE  COMMENT 'SAV-000001 …',
    `amount`           DECIMAL(15,2)   NOT NULL,
    `payment_method`   ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NOT NULL DEFAULT 'Cash',
    `reference_number` VARCHAR(100)    NULL             COMMENT 'Mobile Money code, cheque no, etc.',
    `transaction_date` DATE            NOT NULL,
    `financial_year`   YEAR            NOT NULL,
    `notes`            TEXT            NULL,
    `recorded_by`      INT UNSIGNED    NULL,
    `created_at`       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_savings_member`
        FOREIGN KEY (`member_id`)  REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_savings_recorded_by`
        FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`)  ON DELETE SET NULL,

    INDEX `idx_savings_member`   (`member_id`),
    INDEX `idx_savings_date`     (`transaction_date`),
    INDEX `idx_savings_year`     (`financial_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT 'savings table created successfully' AS status;
