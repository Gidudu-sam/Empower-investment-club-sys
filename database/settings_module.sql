-- ============================================================
--  Settings Module — Additional Tables
--  Engine: MySQL 8+   Charset: utf8mb4
-- ============================================================

USE `empower_db`;

-- ------------------------------------------------------------
--  Financial Years
-- ------------------------------------------------------------
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

-- ------------------------------------------------------------
--  Roles — add new roles if not exist
-- ------------------------------------------------------------
INSERT IGNORE INTO `roles` (`name`, `label`) VALUES
    ('cashier', 'Cashier'),
    ('viewer',  'Viewer (Read Only)');

-- ------------------------------------------------------------
--  Permissions
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `permissions` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `module`     VARCHAR(50)  NOT NULL,
    `label`      VARCHAR(100) NOT NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

INSERT IGNORE INTO `permissions` (`module`, `label`) VALUES
    ('members',       'Members Management'),
    ('savings',       'Savings Management'),
    ('loans',         'Loans Management'),
    ('repayments',    'Loan Repayments'),
    ('withdrawals',   'Withdrawals'),
    ('reports',       'Reports'),
    ('statements',    'Statements'),
    ('settings',      'Settings'),
    ('users',         'User Management');

-- ------------------------------------------------------------
--  Role Permissions (pivot table)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `role_permissions` (
    `id`            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `role_id`       INT UNSIGNED NOT NULL,
    `permission_id` INT UNSIGNED NOT NULL,
    `can_view`      TINYINT(1) NOT NULL DEFAULT 1,
    `can_create`    TINYINT(1) NOT NULL DEFAULT 0,
    `can_edit`      TINYINT(1) NOT NULL DEFAULT 0,
    `can_delete`    TINYINT(1) NOT NULL DEFAULT 0,
    UNIQUE KEY `uk_role_perm` (`role_id`, `permission_id`),
    CONSTRAINT `fk_rp_role` FOREIGN KEY (`role_id`) REFERENCES `roles`(`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rp_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Database Backups
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `database_backups` (
    `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    `filename`   VARCHAR(255) NOT NULL,
    `file_size`  BIGINT UNSIGNED NOT NULL DEFAULT 0,
    `created_by` INT UNSIGNED NULL,
    `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT `fk_backup_user` FOREIGN KEY (`created_by`) REFERENCES `users`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ------------------------------------------------------------
--  Additional settings seed data
-- ------------------------------------------------------------
INSERT IGNORE INTO `settings` (`setting_key`, `setting_val`, `label`) VALUES
    ('club_name',              'Empower Investment Club',  'Club Name'),
    ('club_motto',             'Saving Together, Growing Together', 'Club Motto'),
    ('club_address',           '',                         'Club Address'),
    ('club_phone',             '',                         'Telephone'),
    ('club_email',             '',                         'Email Address'),
    ('club_logo',              '',                         'System Logo Path'),
    ('currency',               'UGX',                      'Currency'),
    ('receipt_footer',         'Thank you for your contribution.', 'Receipt Footer'),
    ('receipt_prefix',         'RCT',                      'Receipt Prefix'),
    ('receipt_authorized_name','',                         'Authorized Signature Name'),
    ('loan_threshold',         '1000000',                  'Loan Amount Threshold'),
    ('loan_rate_below',        '10',                       'Monthly Interest Rate Below Threshold (%)'),
    ('loan_rate_above',        '5',                        'Monthly Interest Rate At/Above Threshold (%)'),
    ('share_price',            '20000',                    'Share Price'),
    ('min_required_shares',    '0',                        'Minimum Required Shares'),
    ('min_monthly_deposits',   '2',                        'Minimum Monthly Deposits (Active Status)'),
    ('min_monthly_savings',    '40000',                    'Minimum Monthly Savings Amount (Active Status)'),
    ('dormancy_months',        '6',                        'Months of Inactivity Before Dormant Status');
