-- ============================================================
--  Add category_group to expense_categories
--  Safe to re-run (IF NOT EXISTS guard on column).
-- ============================================================
USE `empower_db`;

ALTER TABLE `expense_categories`
    ADD COLUMN IF NOT EXISTS `category_group` VARCHAR(80) NULL DEFAULT NULL
    AFTER `category_name`;

-- Seed sensible groups for existing categories
UPDATE `expense_categories` SET `category_group` = 'Staff & Administration' WHERE `category_name` IN ('Salaries/Allowances');
UPDATE `expense_categories` SET `category_group` = 'Office & Operations'   WHERE `category_name` IN ('Office Expenses', 'Printing/Stationery', 'Rent', 'Utilities', 'Transport', 'Communication');
UPDATE `expense_categories` SET `category_group` = 'Financial Charges'     WHERE `category_name` IN ('Bank Charges', 'Mobile Money Charges');
UPDATE `expense_categories` SET `category_group` = 'Other'                 WHERE `category_name` IN ('Corporate Social Responsibility', 'Other Expenses');
