-- ============================================================
--  Loan Repayments Table
-- ============================================================
USE `empower_db`;

DROP TABLE IF EXISTS `loan_repayments`;

CREATE TABLE `loan_repayments` (
    `id`               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `repayment_number` VARCHAR(20)     NOT NULL UNIQUE COMMENT 'PAY-000001',
    `loan_id`          INT UNSIGNED    NOT NULL,
    `member_id`        INT UNSIGNED    NOT NULL,
    `payment_date`     DATE            NOT NULL,
    `amount_paid`      DECIMAL(15,2)   NOT NULL,
    `balance_before`   DECIMAL(15,2)   NOT NULL COMMENT 'Balance before this payment',
    `balance_after`    DECIMAL(15,2)   NOT NULL COMMENT 'Balance after this payment',
    `payment_method`   ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NOT NULL DEFAULT 'Cash',
    `reference_number` VARCHAR(100)    NULL,
    `notes`            TEXT            NULL,
    `received_by`      INT UNSIGNED    NULL,
    `created_at`       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_repay_loan`   FOREIGN KEY (`loan_id`)     REFERENCES `loans`(`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_repay_member` FOREIGN KEY (`member_id`)   REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_repay_user`   FOREIGN KEY (`received_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL,

    INDEX `idx_repay_loan`   (`loan_id`),
    INDEX `idx_repay_member` (`member_id`),
    INDEX `idx_repay_date`   (`payment_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
