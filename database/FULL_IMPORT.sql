-- ============================================================
--  Empower Investment Club — FULL DATABASE IMPORT
--  Run this single file against a FRESH empower_db
--  Generated: 2026-08-11 — safe to re-run on empty DB
-- ============================================================

-- ============================================================
--  1. DATABASE
-- ============================================================
CREATE DATABASE IF NOT EXISTS `empower_db`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE `empower_db`;

SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
--  2. ROLES
-- ============================================================
CREATE TABLE IF NOT EXISTS `roles` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(50)  NOT NULL UNIQUE,
    `label`      VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `roles` (`name`, `label`) VALUES
    ('admin',     'Administrator'),
    ('treasurer', 'Treasurer'),
    ('member',    'Member'),
    ('cashier',   'Cashier'),
    ('viewer',    'Viewer (Read Only)');

-- ============================================================
--  3. USERS
-- ============================================================
CREATE TABLE IF NOT EXISTS `users` (
    `id`              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role_id`         INT UNSIGNED NOT NULL DEFAULT 3,
    `full_name`       VARCHAR(150) NOT NULL,
    `email`           VARCHAR(191) NOT NULL UNIQUE,
    `phone`           VARCHAR(20)  NULL,
    `password_hash`   VARCHAR(255) NOT NULL,
    `avatar`          VARCHAR(255) NULL,
    `is_active`       TINYINT(1)   NOT NULL DEFAULT 1,
    `last_login_at`   TIMESTAMP    NULL,
    `created_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_users_role`
        FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON UPDATE CASCADE
) ENGINE=InnoDB;

-- Default admin (placeholder hash — run seed.php after import to set real password)
INSERT IGNORE INTO `users` (`role_id`, `full_name`, `email`, `phone`, `password_hash`)
VALUES (1, 'System Administrator', 'admin@empower.local', NULL,
    '$2y$12$PlaceholderHashReplaceWithSeedPhp');

-- ============================================================
--  4. ACTIVITY LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `activity_logs` (
    `id`          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `user_id`     INT UNSIGNED NULL,
    `action`      VARCHAR(100) NOT NULL,
    `description` TEXT         NULL,
    `ip_address`  VARCHAR(45)  NULL,
    `created_at`  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_logs_user`
        FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  5. MEMBERS  (v2 schema — most complete)
-- ============================================================
CREATE TABLE IF NOT EXISTS `members` (
    `id`                   INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `member_number`        VARCHAR(20)     NOT NULL UNIQUE COMMENT 'EMP0001 ...',
    `first_name`           VARCHAR(80)     NOT NULL,
    `last_name`            VARCHAR(80)     NOT NULL,
    `gender`               ENUM('Male','Female','Other') NOT NULL,
    `date_of_birth`        DATE            NULL,
    `phone`                VARCHAR(20)     NOT NULL UNIQUE,
    `email`                VARCHAR(191)    NULL UNIQUE,
    `national_id`          VARCHAR(50)     NOT NULL UNIQUE,
    `station`              VARCHAR(200)    NULL COMMENT 'Workplace/School/Organization',
    `present_address`      TEXT            NULL COMMENT 'Present/Current Address',
    `home_address`         TEXT            NULL COMMENT 'Home/Permanent Address',
    `address`              TEXT            NULL,
    `next_of_kin_name`     VARCHAR(150)    NULL,
    `next_of_kin_phone`    VARCHAR(20)     NULL,
    `next_of_kin_address`  TEXT            NULL COMMENT 'Next of Kin Address',
    `next_of_kin_relation` VARCHAR(100)    NULL COMMENT 'Relationship to member',
    `join_date`            DATE            NOT NULL,
    `status`               ENUM('active','inactive') NOT NULL DEFAULT 'active',
    `passport_photo`       VARCHAR(255)    NULL COMMENT 'Filename only',
    `created_by`           INT UNSIGNED    NULL,
    `created_at`           TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`           TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_members_created_by`
        FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  6. SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `settings` (
    `id`          INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `setting_key` VARCHAR(80)     NOT NULL UNIQUE,
    `setting_val` VARCHAR(255)    NOT NULL,
    `label`       VARCHAR(150)    NULL,
    `created_at`  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `settings` (`setting_key`, `setting_val`, `label`) VALUES
    ('withdrawal_pct',             '50',                              'Withdrawal Percentage (%)'),
    ('retained_pct',               '50',                              'Retained Share Percentage (%)'),
    ('max_withdrawals_year',       '1',                               'Maximum Withdrawals Per Year'),
    ('club_name',                  'Empower Investment Club',         'Club Name'),
    ('club_motto',                 'Saving Together, Growing Together','Club Motto'),
    ('club_address',               '',                                'Club Address'),
    ('club_phone',                 '',                                'Telephone'),
    ('club_email',                 '',                                'Email Address'),
    ('club_logo',                  '',                                'System Logo Path'),
    ('currency',                   'UGX',                             'Currency'),
    ('receipt_footer',             'Thank you for your contribution.','Receipt Footer'),
    ('receipt_prefix',             'RCT',                             'Receipt Prefix'),
    ('receipt_authorized_name',    '',                                'Authorized Signature Name'),
    ('loan_threshold',             '1000000',                         'Loan Amount Threshold'),
    ('loan_rate_below',            '10',                              'Monthly Interest Rate Below Threshold (%)'),
    ('loan_rate_above',            '5',                               'Monthly Interest Rate At/Above Threshold (%)'),
    ('share_price',                '20000',                           'Share Price'),
    ('min_required_shares',        '0',                               'Minimum Required Shares');

-- ============================================================
--  7. LOAN TYPES
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_types` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`           VARCHAR(100) NOT NULL,
    `description`    TEXT NULL,
    `repayment_type` ENUM('installment','interest_only') NOT NULL DEFAULT 'installment',
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `loan_types` (`id`,`name`,`description`,`repayment_type`,`is_active`) VALUES
(1, 'Normal Loan',        'Standard personal loan for members.',                         'installment',  1),
(2, 'Business Loan',      'Loan for business investment purposes.',                      'interest_only',1),
(3, 'Asset Financing Loan','Loan for asset acquisition and financing.',                  'installment',  1),
(4, 'Executive Loan',     'Premium loan product with 1.5% flat monthly interest.',       'installment',  1),
(5, 'Old/Migrated Loan',  'Loans issued before the system was implemented.',             'installment',  1);

-- ============================================================
--  8. LOAN PRODUCT SETTINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_product_settings` (
    `id`                     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_type_id`           INT UNSIGNED NOT NULL,
    `min_amount`             DECIMAL(15,2) NOT NULL DEFAULT 100000,
    `max_amount`             DECIMAL(15,2) NOT NULL DEFAULT 10000000,
    `min_period_months`      INT UNSIGNED NOT NULL DEFAULT 1,
    `max_period_months`      INT UNSIGNED NOT NULL DEFAULT 24,
    `monthly_interest_rate`  DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `processing_fee_pct`     DECIMAL(5,2) NOT NULL DEFAULT 3.00,
    `grace_period_days`      INT UNSIGNED NOT NULL DEFAULT 0,
    `penalty_rate_per_day`   DECIMAL(5,4) NOT NULL DEFAULT 0.2500 COMMENT '0.25% per day',
    `is_active`              TINYINT(1) NOT NULL DEFAULT 1,
    `updated_at`             TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uk_lps_type` (`loan_type_id`),
    CONSTRAINT `fk_lps_type` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO `loan_product_settings` (`loan_type_id`,`min_amount`,`max_amount`,`min_period_months`,`max_period_months`,`monthly_interest_rate`,`processing_fee_pct`,`grace_period_days`,`penalty_rate_per_day`) VALUES
(1,  100000,   10000000, 1, 24, 10.00, 3.00,  0, 0.2500),
(2,  100000,   10000000, 1, 24, 10.00, 3.00,  7, 0.2500),
(3,  500000,   20000000, 1, 24,  3.00, 3.00, 14, 0.2500),
(4,  100000,   50000000, 1, 24,  1.50, 3.00,  0, 0.2500);

-- ============================================================
--  9. LOAN INTEREST BRACKETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_interest_brackets` (
    `id`           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_type_id` INT UNSIGNED NOT NULL,
    `min_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0,
    `max_amount`   DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT '0 = no upper limit',
    `monthly_rate` DECIMAL(5,2) NOT NULL DEFAULT 10.00,
    `sort_order`   INT UNSIGNED NOT NULL DEFAULT 0,
    INDEX `idx_lib_type` (`loan_type_id`),
    CONSTRAINT `fk_lib_type` FOREIGN KEY (`loan_type_id`) REFERENCES `loan_types`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT IGNORE INTO `loan_interest_brackets` (`loan_type_id`,`min_amount`,`max_amount`,`monthly_rate`,`sort_order`) VALUES
-- Normal Loan
(1,  100000,   1000000, 10.00, 1),
(1, 1100000,   5000000,  5.00, 2),
(1, 5100000,  10000000,  4.00, 3),
(1,10100000,         0,  3.00, 4),
-- Business Loan
(2,  100000,   1000000, 10.00, 1),
(2, 1100000,   5000000,  5.00, 2),
(2, 5100000,  10000000,  4.00, 3),
(2,10100000,         0,  3.00, 4),
-- Asset Financing (flat 3%)
(3, 0, 0, 3.00, 1),
-- Executive Loan (flat 1.5%)
(4, 0, 0, 1.50, 1);

-- ============================================================
--  10. LOANS  (full column set from all upgrade files)
-- ============================================================
CREATE TABLE IF NOT EXISTS `loans` (
    `id`                         INT UNSIGNED     AUTO_INCREMENT PRIMARY KEY,
    `loan_number`                VARCHAR(20)      NOT NULL UNIQUE COMMENT 'LNS-000001 ...',
    `member_id`                  INT UNSIGNED     NOT NULL,
    `loan_type_id`               INT UNSIGNED     NULL DEFAULT 1,
    `repayment_method`           ENUM('standard','interest_only','business_boost') NOT NULL DEFAULT 'standard',
    `interest_only_months`       INT UNSIGNED     NOT NULL DEFAULT 0,
    `principal_recovery_weeks`   INT UNSIGNED     NOT NULL DEFAULT 0,
    `repayment_frequency`        ENUM('weekly','monthly') NOT NULL DEFAULT 'monthly',
    `interest_mode`              ENUM('percentage','fixed') NOT NULL DEFAULT 'percentage',
    `fixed_interest_amount`      DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `interest_only_periods`      INT UNSIGNED     NOT NULL DEFAULT 0,
    `is_migrated`                TINYINT(1)       NOT NULL DEFAULT 0,
    `migrated_paid_installments` INT UNSIGNED     NOT NULL DEFAULT 0,
    `migrated_original_amount`   DECIMAL(15,2)    NULL,
    `application_date`           DATE             NULL,
    `approval_date`              DATE             NULL,
    `disbursement_date`          DATE             NULL,
    `disbursement_method`        VARCHAR(50)      NULL,
    `issue_date`                 DATE             NOT NULL,
    `due_date`                   DATE             NOT NULL,
    `loan_period`                VARCHAR(50)      NULL COMMENT 'e.g. 12 months',
    `loan_period_months`         INT UNSIGNED     NOT NULL DEFAULT 1,
    `loan_amount`                DECIMAL(15,2)    NOT NULL,
    `approved_amount`            DECIMAL(15,2)    NULL,
    `interest_rate`              DECIMAL(5,2)     NOT NULL DEFAULT 0.00 COMMENT 'Percentage %',
    `interest_amount`            DECIMAL(15,2)    NOT NULL DEFAULT 0.00,
    `processing_fee`             DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `monthly_installment`        DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `total_payable`              DECIMAL(15,2)    NOT NULL,
    `outstanding`                DECIMAL(15,2)    NOT NULL COMMENT 'Remaining balance',
    `amount_paid`                DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `interest_paid_total`        DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `penalty_total`              DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `penalty_accrued`            DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `next_payment_date`          DATE             NULL,
    `last_payment_date`          DATE             NULL,
    `completed_date`             DATE             NULL,
    `purpose`                    TEXT             NULL,
    `remarks`                    TEXT             NULL,
    `business_name`              VARCHAR(200)     NULL,
    `business_type`              VARCHAR(100)     NULL,
    `business_location`          VARCHAR(200)     NULL,
    `weekly_savings_amount`      DECIMAL(15,2)    NOT NULL DEFAULT 0,
    `guarantor_name`             VARCHAR(150)     NULL,
    `guarantor_phone`            VARCHAR(20)      NULL,
    `loan_officer`               VARCHAR(150)     NULL,
    `status`                     ENUM('pending','active','completed','overdue','defaulted') NOT NULL DEFAULT 'active',
    `recorded_by`                INT UNSIGNED     NULL,
    `created_at`                 TIMESTAMP        DEFAULT CURRENT_TIMESTAMP,
    `updated_at`                 TIMESTAMP        DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_loans_member`   FOREIGN KEY (`member_id`)    REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_loans_recorded` FOREIGN KEY (`recorded_by`)  REFERENCES `users`(`id`)   ON DELETE SET NULL,
    INDEX `idx_loans_member` (`member_id`),
    INDEX `idx_loans_status` (`status`),
    INDEX `idx_loans_due`    (`due_date`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  11. LOAN INSTALLMENTS  (full column set)
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_installments` (
    `id`               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id`          INT UNSIGNED NOT NULL,
    `installment_no`   INT UNSIGNED NOT NULL,
    `period_type`      ENUM('monthly','weekly') NOT NULL DEFAULT 'monthly',
    `payment_type`     ENUM('interest_only','principal_interest','standard') NOT NULL DEFAULT 'standard',
    `due_date`         DATE NOT NULL,
    `month_covered`    VARCHAR(20) NULL COMMENT 'e.g. Aug 2026',
    `amount_due`       DECIMAL(15,2) NOT NULL DEFAULT 0,
    `principal_due`    DECIMAL(15,2) NOT NULL DEFAULT 0,
    `interest_due`     DECIMAL(15,2) NOT NULL DEFAULT 0,
    `amount_paid`      DECIMAL(15,2) NOT NULL DEFAULT 0,
    `principal_paid`   DECIMAL(15,2) NOT NULL DEFAULT 0,
    `interest_paid_amt` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `balance_after`    DECIMAL(15,2) NOT NULL DEFAULT 0,
    `remaining`        DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status`           ENUM('pending','paid','partial','missed','overdue') NOT NULL DEFAULT 'pending',
    `paid_date`        DATE NULL,
    `created_at`       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_inst_loan` (`loan_id`),
    INDEX `idx_inst_due`  (`due_date`),
    CONSTRAINT `fk_inst_loan` FOREIGN KEY (`loan_id`) REFERENCES `loans`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
--  12. LOAN REPAYMENTS  (full column set)
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_repayments` (
    `id`               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `repayment_number` VARCHAR(20)     NOT NULL UNIQUE COMMENT 'PAY-000001',
    `payment_type`     ENUM('installment','interest','weekly_savings','principal','settlement') NOT NULL DEFAULT 'installment',
    `loan_type_id`     INT UNSIGNED    NULL,
    `loan_id`          INT UNSIGNED    NOT NULL,
    `member_id`        INT UNSIGNED    NOT NULL,
    `payment_date`     DATE            NOT NULL,
    `amount_paid`      DECIMAL(15,2)   NOT NULL,
    `principal_paid`   DECIMAL(15,2)   NOT NULL DEFAULT 0,
    `interest_paid`    DECIMAL(15,2)   NOT NULL DEFAULT 0,
    `savings_paid`     DECIMAL(15,2)   NOT NULL DEFAULT 0,
    `penalty_paid`     DECIMAL(15,2)   NOT NULL DEFAULT 0,
    `week_covered`     VARCHAR(30)     NULL,
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

-- ============================================================
--  13. LOAN PENALTIES
-- ============================================================
CREATE TABLE IF NOT EXISTS `loan_penalties` (
    `id`              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `loan_id`         INT UNSIGNED NOT NULL,
    `installment_id`  BIGINT UNSIGNED NULL,
    `member_id`       INT UNSIGNED NOT NULL,
    `days_overdue`    INT UNSIGNED NOT NULL DEFAULT 0,
    `penalty_rate`    DECIMAL(5,4) NOT NULL DEFAULT 0.2500,
    `base_amount`     DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Installment amount penalty is based on',
    `penalty_amount`  DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status`          ENUM('active','accruing','paid','waived') NOT NULL DEFAULT 'active',
    `calculated_date` DATE NOT NULL,
    `paid_date`       DATE NULL,
    `created_at`      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_lp_loan`   (`loan_id`),
    INDEX `idx_lp_member` (`member_id`),
    INDEX `idx_lp_status` (`status`),
    CONSTRAINT `fk_lp_loan`   FOREIGN KEY (`loan_id`)   REFERENCES `loans`(`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_lp_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
--  14. BUSINESS LOAN INTEREST PAYMENTS
-- ============================================================
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
    INDEX `idx_blip_loan`   (`loan_id`),
    INDEX `idx_blip_member` (`member_id`),
    CONSTRAINT `fk_blip_loan`   FOREIGN KEY (`loan_id`)   REFERENCES `loans`(`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_blip_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
--  15. BUSINESS LOAN WEEKLY SAVINGS
-- ============================================================
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
    INDEX `idx_blws_loan`   (`loan_id`),
    INDEX `idx_blws_member` (`member_id`),
    CONSTRAINT `fk_blws_loan`   FOREIGN KEY (`loan_id`)   REFERENCES `loans`(`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_blws_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
--  16. SAVINGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `savings` (
    `id`               INT UNSIGNED    AUTO_INCREMENT PRIMARY KEY,
    `member_id`        INT UNSIGNED    NOT NULL,
    `receipt_number`   VARCHAR(20)     NOT NULL UNIQUE COMMENT 'SAV-000001 ...',
    `amount`           DECIMAL(15,2)   NOT NULL,
    `payment_method`   ENUM('Cash','Airtel Money','MTN Mobile Money','Bank Transfer','Cheque','Other') NOT NULL DEFAULT 'Cash',
    `reference_number` VARCHAR(100)    NULL COMMENT 'Mobile Money code, cheque no, etc.',
    `transaction_date` DATE            NOT NULL,
    `financial_year`   YEAR            NOT NULL,
    `notes`            TEXT            NULL,
    `recorded_by`      INT UNSIGNED    NULL,
    `created_at`       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP,
    `updated_at`       TIMESTAMP       DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_savings_member`      FOREIGN KEY (`member_id`)   REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_savings_recorded_by` FOREIGN KEY (`recorded_by`) REFERENCES `users`(`id`)   ON DELETE SET NULL,
    INDEX `idx_savings_member` (`member_id`),
    INDEX `idx_savings_date`   (`transaction_date`),
    INDEX `idx_savings_year`   (`financial_year`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
--  17. WITHDRAWALS
-- ============================================================
CREATE TABLE IF NOT EXISTS `withdrawals` (
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

-- ============================================================
--  18. NOTIFICATIONS
-- ============================================================
CREATE TABLE IF NOT EXISTS `notifications` (
    `id`             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `title`          VARCHAR(255) NOT NULL,
    `message`        TEXT         NOT NULL,
    `type`           ENUM('info','success','warning','critical') NOT NULL DEFAULT 'info',
    `reference_type` VARCHAR(50)  NULL COMMENT 'loan, savings, withdrawal, system, user',
    `reference_id`   INT UNSIGNED NULL,
    `user_id`        INT UNSIGNED NULL COMMENT 'Target user (NULL = all admins)',
    `is_read`        TINYINT(1)   NOT NULL DEFAULT 0,
    `created_at`     TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
    `read_at`        TIMESTAMP    NULL,
    INDEX `idx_user_read` (`user_id`, `is_read`),
    INDEX `idx_type`      (`type`),
    INDEX `idx_ref`       (`reference_type`, `reference_id`),
    CONSTRAINT `fk_notif_user` FOREIGN KEY (`user_id`) REFERENCES `users`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ============================================================
--  19. FEES
-- ============================================================
CREATE TABLE IF NOT EXISTS `fees` (
    `id`             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_name`       VARCHAR(150) NOT NULL,
    `fee_type`       ENUM('fixed','percentage') NOT NULL DEFAULT 'fixed',
    `amount`         DECIMAL(15,2) NOT NULL DEFAULT 0,
    `frequency`      ENUM('one_time','annual','per_loan','monthly') NOT NULL DEFAULT 'one_time',
    `description`    TEXT NULL,
    `effective_date` DATE NULL,
    `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
    `created_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `fees` (`id`,`fee_name`,`fee_type`,`amount`,`frequency`,`description`,`effective_date`,`is_active`) VALUES
(1, 'Registration Fee',        'fixed',       50000.00, 'one_time', 'Charged once when a new member is registered.', CURDATE(), 1),
(2, 'Annual Subscription Fee', 'fixed',       30000.00, 'annual',   'Charged once every active financial year.',     CURDATE(), 1),
(3, 'Loan Processing Fee',     'percentage',      3.00, 'per_loan', 'Calculated as a percentage of the approved loan amount.', CURDATE(), 1);

CREATE TABLE IF NOT EXISTS `member_fees` (
    `id`                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `member_id`         INT UNSIGNED NOT NULL,
    `fee_id`            INT UNSIGNED NOT NULL,
    `amount`            DECIMAL(15,2) NOT NULL DEFAULT 0,
    `status`            ENUM('pending','paid','waived','cancelled') NOT NULL DEFAULT 'pending',
    `reference_number`  VARCHAR(30) NULL,
    `financial_year_id` INT UNSIGNED NULL,
    `loan_id`           INT UNSIGNED NULL COMMENT 'For loan processing fees',
    `charged_date`      DATE NOT NULL,
    `paid_date`         DATE NULL,
    `created_by`        INT UNSIGNED NULL,
    `created_at`        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_mf_member` (`member_id`),
    INDEX `idx_mf_fee`    (`fee_id`),
    INDEX `idx_mf_status` (`status`),
    CONSTRAINT `fk_mf_member` FOREIGN KEY (`member_id`) REFERENCES `members`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_mf_fee`    FOREIGN KEY (`fee_id`)    REFERENCES `fees`(`id`)    ON DELETE RESTRICT,
    CONSTRAINT `fk_mf_user`   FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `fee_history` (
    `id`         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `fee_id`     INT UNSIGNED NOT NULL,
    `old_amount` DECIMAL(15,2) NULL,
    `new_amount` DECIMAL(15,2) NULL,
    `changed_by` INT UNSIGNED NULL,
    `changed_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `remarks`    VARCHAR(255) NULL,
    CONSTRAINT `fk_fh_fee`  FOREIGN KEY (`fee_id`)     REFERENCES `fees`(`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_fh_user` FOREIGN KEY (`changed_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  20. SETTINGS MODULE — Financial Years, Permissions, Backups
-- ============================================================
CREATE TABLE IF NOT EXISTS `financial_years` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `name`       VARCHAR(50)  NOT NULL,
    `start_date` DATE         NOT NULL,
    `end_date`   DATE         NOT NULL,
    `status`     ENUM('active','closed','pending') NOT NULL DEFAULT 'pending',
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT `fk_fy_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `permissions` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `module`     VARCHAR(50)  NOT NULL,
    `label`      VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `permissions` (`module`, `label`) VALUES
    ('members',    'Members Management'),
    ('savings',    'Savings Management'),
    ('loans',      'Loans Management'),
    ('repayments', 'Loan Repayments'),
    ('withdrawals','Withdrawals'),
    ('reports',    'Reports'),
    ('statements', 'Statements'),
    ('settings',   'Settings'),
    ('users',      'User Management');

CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `can_view`      TINYINT(1) NOT NULL DEFAULT 1,
    `can_create`    TINYINT(1) NOT NULL DEFAULT 0,
    `can_edit`      TINYINT(1) NOT NULL DEFAULT 0,
    `can_delete`    TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uk_role_perm` (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`)       REFERENCES `roles`(`id`)       ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `database_backups` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename`   VARCHAR(255) NOT NULL,
    `file_size`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_backup_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ============================================================
--  RE-ENABLE FOREIGN KEY CHECKS
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
--  DONE — Run database/seed.php to set the admin password
-- ============================================================
SELECT 'Full import complete. Run database/seed.php to activate the admin account.' AS status;
