-- ============================================================
--  Phase 6: Withdrawals & Share Retention
-- ============================================================
USE `empower_db`;

-- ── Settings table (configurable percentages) ────────────────
CREATE TABLE IF NOT EXISTS `settings` (
    `id`          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(80)     NOT NULL UNIQUE,
    `setting_val` VARCHAR(255)    NOT NULL,
    `label`       VARCHAR(150)    NULL,
    `created_at`  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Default policy values
INSERT IGNORE INTO `settings` (`setting_key`, `setting_val`, `label`) VALUES
    ('withdrawal_pct',        '50',   'Withdrawal Percentage (%)'),
    ('retained_pct',          '50',   'Retained Share Percentage (%)'),
    ('max_withdrawals_year',  '1',    'Maximum Withdrawals Per Year');

-- ── Withdrawals table ────────────────────────────────────────
DROP TABLE IF EXISTS `withdrawals`;

CREATE TABLE `withdrawals` (
    `id`                      INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `withdrawal_number`       VARCHAR(20)     NOT NULL UNIQUE COMMENT 'WDL-000001',
    `member_id`               INT UNSIGNED    NOT NULL,
    `financial_year`          YEAR            NOT NULL,
    `total_available_savings` DECIMAL(15,2)   NOT NULL COMMENT 'Savings balance at time of withdrawal',
    `withdrawal_percentage`   DECIMAL(5,2)    NOT NULL,
    `retained_percentage`     DECIMAL(5,2)    NOT NULL,
    `withdrawal_amount`       DECIMAL(15,2)   NOT NULL COMMENT 'Cash paid out',
    `retained_amount`         DECIMAL(15,2)   NOT NULL COMMENT 'Kept as permanent shares',
    `withdrawal_date`         DATE            NOT NULL,
    `payment_method`          ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NOT NULL DEFAULT 'Cash',
    `reference_number`        VARCHAR(100)    NULL,
    `remarks`                 TEXT            NULL,
    `processed_by`            INT UNSIGNED    NULL,
    `created_at`              TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`              TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    CONSTRAINT `fk_wd_member`    FOREIGN KEY (`member_id`)    REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_wd_processed` FOREIGN KEY (`processed_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL,

    UNIQUE KEY `uq_member_year` (`member_id`, `financial_year`) COMMENT 'One withdrawal per member per year',
    INDEX `idx_wd_member` (`member_id`),
    INDEX `idx_wd_year`   (`financial_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
