-- ============================================================
--  Executive Loan — New loan product + extended periods
-- ============================================================
USE `empower_db`;

-- Add Executive Loan type
INSERT IGNORE INTO `loan_types` (`id`, `name`, `description`, `repayment_type`, `is_active`) VALUES
(4, 'Executive Loan', 'Premium loan product with 1.5% flat monthly interest for any amount.', 'installment', 1);

-- Add interest bracket for Executive Loan (flat 1.5% for any amount)
INSERT IGNORE INTO `loan_interest_brackets` (`loan_type_id`, `min_amount`, `max_amount`, `monthly_rate`, `sort_order`) VALUES
(4, 0, 0, 1.50, 1);

-- Add product settings for Executive Loan
INSERT IGNORE INTO `loan_product_settings` (`loan_type_id`, `min_amount`, `max_amount`, `min_period_months`, `max_period_months`, `monthly_interest_rate`, `processing_fee_pct`, `grace_period_days`, `penalty_rate_per_day`) VALUES
(4, 100000, 50000000, 1, 24, 1.50, 3.00, 0, 0.2500);

-- Update all existing products to allow up to 24 months
UPDATE `loan_product_settings` SET `max_period_months` = 24 WHERE `loan_type_id` IN (1, 2, 3);
