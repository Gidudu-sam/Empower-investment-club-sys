-- ============================================================
--  Empower Investment Club — DATABASE UPGRADE SCRIPT
--  Run this ONCE after restoring the old backup
--  Safe to run — uses IF NOT EXISTS / IF EXISTS checks
-- ============================================================

USE `empower_db`;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
--  LOANS TABLE — add missing columns
-- ============================================================

-- penalty_total (new code tracks this separately from penalty_accrued)
ALTER TABLE `loans`
    ADD COLUMN IF NOT EXISTS `penalty_total` DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER `interest_paid_total`;

-- completed_date
ALTER TABLE `loans`
    ADD COLUMN IF NOT EXISTS `completed_date` DATE NULL AFTER `last_payment_date`;

-- ============================================================
--  MEMBERS TABLE — add missing columns
-- ============================================================

-- email unique index may be missing in old backup — add safely
ALTER IGNORE TABLE `members`
    ADD COLUMN IF NOT EXISTS `account_number` VARCHAR(30) NULL UNIQUE AFTER `member_number`;

-- ============================================================
--  SETTINGS — add any missing keys from new version
-- ============================================================

INSERT IGNORE INTO `settings` (`setting_key`, `setting_val`, `label`) VALUES
    ('withdrawal_pct',             '50',                               'Withdrawal Percentage (%)'),
    ('retained_pct',               '50',                               'Retained Share Percentage (%)'),
    ('max_withdrawals_year',       '1',                                'Maximum Withdrawals Per Year'),
    ('club_name',                  'Empower Investment Club',          'Club Name'),
    ('club_motto',                 'Saving Together, Growing Together','Club Motto'),
    ('club_address',               '',                                 'Club Address'),
    ('club_phone',                 '',                                 'Telephone'),
    ('club_email',                 '',                                 'Email Address'),
    ('club_logo',                  '',                                 'System Logo Path'),
    ('currency',                   'UGX',                              'Currency'),
    ('receipt_footer',             'Thank you for your contribution.', 'Receipt Footer'),
    ('receipt_prefix',             'RCT',                              'Receipt Prefix'),
    ('receipt_authorized_name',    '',                                 'Authorized Signature Name'),
    ('loan_threshold',             '1000000',                          'Loan Amount Threshold'),
    ('loan_rate_below',            '10',                               'Monthly Interest Rate Below Threshold (%)'),
    ('loan_rate_above',            '5',                                'Monthly Interest Rate At/Above Threshold (%)'),
    ('share_price',                '20000',                            'Share Price'),
    ('min_required_shares',        '0',                                'Minimum Required Shares');

-- ============================================================
--  LOAN TYPES — ensure all 5 types exist
-- ============================================================

INSERT IGNORE INTO `loan_types` (`id`,`name`,`description`,`repayment_type`,`is_active`) VALUES
(1, 'Normal Loan',         'Standard personal loan for members.',           'installment',   1),
(2, 'Business Loan',       'Loan for business investment purposes.',         'interest_only', 1),
(3, 'Asset Financing Loan','Loan for asset acquisition and financing.',      'installment',   1),
(4, 'Executive Loan',      'Premium loan product with 1.5% monthly rate.',  'installment',   1),
(5, 'Old/Migrated Loan',   'Loans issued before the system was set up.',     'installment',   1);

-- ============================================================
--  LOAN PRODUCT SETTINGS — ensure defaults exist
-- ============================================================

INSERT IGNORE INTO `loan_product_settings`
    (`loan_type_id`,`min_amount`,`max_amount`,`min_period_months`,`max_period_months`,`monthly_interest_rate`,`processing_fee_pct`,`grace_period_days`,`penalty_rate_per_day`)
VALUES
(1,  100000,   10000000, 1, 24, 10.00, 3.00,  0, 0.2500),
(2,  100000,   10000000, 1, 24, 10.00, 3.00,  7, 0.2500),
(3,  500000,   20000000, 1, 24,  3.00, 3.00, 14, 0.2500),
(4,  100000,   50000000, 1, 24,  1.50, 3.00,  0, 0.2500);

-- ============================================================
--  LOAN INTEREST BRACKETS — ensure defaults exist
-- ============================================================

INSERT IGNORE INTO `loan_interest_brackets`
    (`loan_type_id`,`min_amount`,`max_amount`,`monthly_rate`,`sort_order`)
VALUES
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
-- Asset Financing
(3, 0, 0, 3.00, 1),
-- Executive Loan
(4, 0, 0, 1.50, 1);

-- ============================================================
--  FEES — ensure the 3 standard fees exist
-- ============================================================

INSERT IGNORE INTO `fees` (`id`,`fee_name`,`fee_type`,`amount`,`frequency`,`description`,`effective_date`,`is_active`) VALUES
(1, 'Registration Fee',        'fixed',       50000.00, 'one_time', 'Charged once when a new member is registered.', CURDATE(), 1),
(2, 'Annual Subscription Fee', 'fixed',       30000.00, 'annual',   'Charged once every active financial year.',     CURDATE(), 1),
(3, 'Loan Processing Fee',     'percentage',      3.00, 'per_loan', 'Calculated as a percentage of the approved loan amount.', CURDATE(), 1);

-- ============================================================
--  PERMISSIONS — ensure all module permissions exist
-- ============================================================

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

-- ============================================================
--  ROLES — ensure all 5 roles exist
-- ============================================================

INSERT IGNORE INTO `roles` (`name`, `label`) VALUES
    ('admin',     'Administrator'),
    ('treasurer', 'Treasurer'),
    ('member',    'Member'),
    ('cashier',   'Cashier'),
    ('viewer',    'Viewer (Read Only)');

-- ============================================================
--  DONE
-- ============================================================
SET FOREIGN_KEY_CHECKS = 1;

SELECT 'Upgrade complete. Your database is now up to date.' AS status;
